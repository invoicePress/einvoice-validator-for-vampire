# eInvoice Validator for VAMPIRE — Changelog

## 2.0.2

- The two download buttons of the rule report on the viewer page show their text in the language of the site.
- If files of the plugin are missing or incomplete after an upload, the plugin stays off with a notice in the admin area instead of stopping the whole website.

## 2.0.1

- Maintenance release without visible changes.

## 2.0.0

- The button "Validate with VAMPIRE" in the eInvoice Viewer now comes from this plugin and needs the viewer 3.0.0; update the viewer first. An app can enter this website as the source of its check ( /wp-json/einvoice/v1/validate/vampire ). Visitors may use that address within the daily uploads per role, and every check there counts against them.

## 1.11.1

- The note under the report no longer claims that the tool does not validate anything itself.

## 1.11.0

- A note of the level "information" appears blue in the document view, no longer like an error.
- The plugin is free and licensed under the GPL, version 2 or later; it comes through wordpress.org.
- New rule packages are installed as a ZIP file, without a shop account.
- New design "Kit S" on the settings page: the validator in the colours of the specialPress websites.

## 1.10.0

- The daily upload limit can now be set per role, with its own row for guests; 0 means no access.
- The eInvoice Viewer 2.35.0 shows a button "Validate with VAMPIRE" that checks the invoice on display with this plugin.
- New: rule profiles from the Rule Studio can be installed on the settings page; their findings appear in the report with the profile's name.
- Answers appear in the language of the page, also for logged-in users whose profile language differs.

## 1.9.0

- New setting "Positive check report": the report lists every rule with its result, also the satisfied ones, and can be downloaded and printed.
- New setting "Check against all editions": ZUGFeRD and Factur-X invoices are checked from the newest edition downwards, and a table shows the result per edition.
- An invoice that names an edition the rule package does not contain yet, such as XRechnung 4.0, now gets "No result" with the reason instead of "Rejected".
- New rule packages can be uploaded as a ZIP from your shop account.
- The invoice view no longer marks an IBAN with wrong check digits; the validation report is not affected.
- The package now names the origin and licence of every rule set and contains the licence texts.
- The note above the marked fields now says exactly what the viewer checks.
- The bundled translations now also appear when the plugin is network-activated and on older WordPress versions.

## 1.8.1

- The hint under the drop zone and all other text without a font of its own now use the font of the selected design kit instead of the theme's font.
- The invoice view marks fields that break a rule following from the invoice itself, with a translated note below each field.

## 1.8.0

- Rules of ZUGFeRD / Factur-X EXTENDED and EXTENDED-CTC-FR are now explained only with the Extended Profiles add-on; without it, such a finding shows the validator's rule id and message.
- Long CII invoices are checked much faster; 400 invoice lines now take about a third of the previous time.
- With the reverse proxy setting switched on, the daily limit now counts the address the proxy added, not the one a visitor can send along themselves.

## 1.7.0

With the Extended Profiles add-on active, findings on fields of an extension (BT-X) show their value from the document.
Without the add-on, a report that names such fields says so above the result.

## 1.6.0

- New: the design kit of the validator can be chosen under Settings → E-Invoice Validator VAMPIRE. The choice applies to all eInvoice tools on the site, and Kit H remains the default.
- A finding about an attachment of the invoice now names that attachment.

## 1.5.1

- Maintenance release without visible changes.

## 1.5.0

- The validation report is now shown in the same view as the Validation Explorer, with a plain-language explanation and the affected field for each finding.
- Notes about a report that could only be read in part now appear in the language of the site.

## 1.4.5

- The settings menu entry is now called “E-Invoice Validator VAMPIRE”.
- The upload area and the report look the same as in the other e-invoice tools.

## 1.4.0 – 1.4.4

- The upload area and the report take on the look of the other e-invoice tools.

## 1.3.0

Numbers and dates in the document view now follow the selected language: German writes
`5.900,00 EUR` and `01.12.2017`, English `5,900.00 EUR` and `2017-12-01`, French `5 900,00 EUR`.
Where a text is missing in the selected language, the English one is shown together with a note
saying so.

## 1.2.0

The shortcode is `[sppv_validator]`, carrying this plugin's own prefix. No other plugin can claim
the same name.

## Before 1.2.0

Development versions that were never shipped.

---

*eInvoice Validator for VAMPIRE is part of the eInvoice tools by [specialPress](https://www.specialpress.de) ·
[invoicePress.de](https://invoicepress.de)*
