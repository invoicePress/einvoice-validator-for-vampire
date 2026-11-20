// SPDX-License-Identifier: GPL-2.0-or-later
// Rebuilds the bundles in ../ from the sources in this folder. Needs Node.js 18 or newer and esbuild 0.28.2:
//
//     npm install --no-save esbuild@0.28.2
//     node build.mjs
//
// The results are written to ../ and replace the shipped bundles; with the same esbuild version they are byte-identical.

import { build } from 'esbuild';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const out = process.argv[ 2 ] || join( here, '..' );

const common = {"bundle":true,"format":"esm","target":["es2022"],"charset":"utf8"};

await build( { ...common, ...{"minify":true,"sourcemap":true}, absWorkingDir: here, entryPoints: [ join( here, "src/model/index.mjs" ) ], outfile: join( out, "einvoice-model.bundle.js" ) } );
await build( { ...common, ...{"minify":true,"sourcemap":false}, absWorkingDir: here, entryPoints: [ join( here, "src/validators/wp-site-validator.mjs" ) ], outfile: join( out, "einvoice-site-validator.bundle.js" ) } );
await build( { ...common, ...{"minify":true,"sourcemap":false,"legalComments":"none"}, absWorkingDir: here, entryPoints: [ join( here, "src/batch/wp-batch.mjs" ) ], outfile: join( out, "einvoice-batch.bundle.js" ) } );
