// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Runs a list of checks one after the other, or a few at a time, and stays polite to a validation service that says
 * "too many requests".
 *
 *   await runQueue( items, async ( item ) => …, { concurrency: 2, signal, onUpdate } )
 *
 * The worker returns anything; the queue stores it as `item.result`. A thrown error becomes `item.error`, unless it is a
 * refusal for too many requests ( see `retryDelay()` ).
 *
 * ⚠️ **NO LONG SINGLE REQUEST AND NO QUEUE ON A SERVER.** Every invoice is its own request, started by the browser. The
 * service never holds a batch, a cancelled batch leaves nothing behind there, and a web server with a time limit of thirty
 * seconds per request is never asked for more than one invoice.
 *
 * ⚠️ **ONE WAIT FOR ALL WORKERS.** With two workers and a service that allows ten checks a minute, both run into the 429.
 * A wait per worker would let the second one knock again while the first one waits, and each knock counts against the
 * window. The queue therefore keeps one moment until which nobody starts.
 *
 * @since 26.10.2026
 */

/** How often one invoice is tried again after "too many requests". */
export const MAX_RETRIES = 5;

/**
 * Longer than this, and waiting is no longer worth it: the batch stops and lists the rest as not checked.
 *
 * ⚠️ TWO KINDS OF 429 WITH THE SAME STATUS. The Validator Bridge limits per minute ( `rate_limited`, Retry-After of a few
 * seconds ), the eInvoice API per day ( `quota_exceeded`, Retry-After until midnight UTC ). Waiting a minute is what the
 * user expects of a batch; waiting eleven hours in an open browser tab is not.
 */
export const MAX_WAIT_SECONDS = 120;

/**
 * How long to wait before the next try, or -1 to stop the batch.
 *
 * @param {Error & {status?: number, retryAfter?: (number|null), stop?: boolean}} err
 * @param {number} attempt 0 for the first retry
 * @return {number} milliseconds, 0 for "no retry, a normal error", -1 for "stop the batch"
 */
export function retryDelay( err, attempt ) {

	if ( ! err ) return 0;

	if ( err.stop ) return -1;

	if ( Number( err.status ) !== 429 ) return 0;

	const after = Number( err.retryAfter );

	if ( Number.isFinite( after ) && after > MAX_WAIT_SECONDS ) return -1;

	if ( attempt >= MAX_RETRIES ) return 0;

	// ⚠️ WITHOUT Retry-After ( a service that does not expose the header to the browser ): 2, 4, 8, 16, 32 seconds.
	if ( ! Number.isFinite( after ) || after <= 0 ) return Math.min( MAX_WAIT_SECONDS, 2 ** ( attempt + 1 ) ) * 1000;

	// One second more than asked for: the window of the bridge closes on the full second.
	return ( after + 1 ) * 1000;

}

/**
 * Reads `Retry-After` in seconds. An HTTP date is turned into seconds from now as well.
 *
 * @param {string|null} value
 * @return {number|null}
 */
export function parseRetryAfter( value ) {

	const s = String( value || '' ).trim();

	if ( s === '' ) return null;

	if ( /^\d+$/.test( s ) ) return Number( s );

	const at = Date.parse( s );

	return Number.isFinite( at ) ? Math.max( 0, Math.ceil( ( at - Date.now() ) / 1000 ) ) : null;

}

/**
 * A pause that ends early when the batch is cancelled.
 *
 * @param {number} ms
 * @param {AbortSignal} [signal]
 * @return {Promise<void>}
 */
function pause( ms, signal ) {

	return new Promise( ( resolve ) => {
		if ( signal && signal.aborted ) { resolve(); return; }
		const timer = setTimeout( resolve, ms );
		if ( signal ) signal.addEventListener( 'abort', () => { clearTimeout( timer ); resolve(); }, { once: true } );
	} );

}

/**
 * Runs the worker over every item whose `state` is `'waiting'`.
 *
 * States of an item: `waiting` → `running` → `done` | `error` | `skipped`. `skipped` carries `skipReason`: `'cancelled'`
 * or `'stopped'` ( the service refused for longer than `MAX_WAIT_SECONDS`, or the worker said stop ); the error that
 * stopped the batch is returned as `stoppedBy`.
 *
 * @param {object[]} items
 * @param {(item: object) => Promise<*>} worker
 * @param {{concurrency?: number, signal?: AbortSignal, onUpdate?: Function, onWait?: Function, sleep?: Function}} [opts]
 * @return {Promise<{stoppedBy: (Error|null), ms: number}>}
 */
export async function runQueue( items, worker, opts = {} ) {

	const concurrency = Math.max( 1, Math.min( 8, Number( opts.concurrency ) || 1 ) );
	const signal = opts.signal || null;
	const onUpdate = typeof opts.onUpdate === 'function' ? opts.onUpdate : () => {};
	const onWait = typeof opts.onWait === 'function' ? opts.onWait : () => {};
	const sleep = typeof opts.sleep === 'function' ? opts.sleep : pause;

	const start = Date.now();

	let next = 0;
	let notBefore = 0;
	let stoppedBy = null;

	const skipRest = ( reason ) => {
		for ( const it of items )
			if ( it.state === 'waiting' ) { it.state = 'skipped'; it.skipReason = reason; onUpdate( it ); }
	};

	const take = () => {
		while ( next < items.length && items[ next ].state !== 'waiting' ) next++;
		return next < items.length ? items[ next++ ] : null;
	};

	const one = async ( item ) => {

		item.state = 'running';
		onUpdate( item );

		const t0 = Date.now();

		for ( let attempt = 0; ; attempt++ ) {

			// ⚠️ The common wait first: another worker may have run into the limit while this one was being started.
			const wait = notBefore - Date.now();
			if ( wait > 0 ) await sleep( wait, signal );

			if ( signal && signal.aborted ) { item.state = 'skipped'; item.skipReason = 'cancelled'; onUpdate( item ); return; }

			try {

				item.result = await worker( item );
				item.state = 'done';
				item.ms = Date.now() - t0;
				onUpdate( item );
				return;

			} catch ( err ) {

				const delay = retryDelay( err, attempt );

				if ( delay < 0 ) {
					stoppedBy = stoppedBy || err;
					item.state = 'skipped';
					item.skipReason = 'stopped';
					item.error = err;
					onUpdate( item );
					skipRest( 'stopped' );
					return;
				}

				if ( delay === 0 ) {
					item.state = 'error';
					item.error = err;
					item.ms = Date.now() - t0;
					onUpdate( item );
					return;
				}

				notBefore = Math.max( notBefore, Date.now() + delay );
				item.retries = attempt + 1;
				onWait( { item, seconds: Math.round( delay / 1000 ), err } );

			}

		}

	};

	const lane = async () => {
		for ( let item = take(); item; item = take() ) {
			if ( signal && signal.aborted ) break;
			if ( stoppedBy ) break;
			await one( item );
		}
	};

	await Promise.all( Array.from( { length: concurrency }, lane ) );

	if ( signal && signal.aborted ) skipRest( 'cancelled' );

	return { stoppedBy, ms: Date.now() - start };

}
