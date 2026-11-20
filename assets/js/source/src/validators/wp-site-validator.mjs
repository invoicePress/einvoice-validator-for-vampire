// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Entry of the validator plugins ( KoSIT, Mustang, VAMPIRE ) for hosts of interface 8: registers the entries the plugin wrote
 * into `einvoiceSiteValidators` in front of this module ( `site-validator.mjs` ).
 */

import { registerSiteValidators } from './site-validator.mjs';

registerSiteValidators();
