# RTEX hourly and load invoicing

The RTEX tab in upload.php has an Invoicing Method selector. Hourly and load-based
rows can coexist for a driver in the same week. Switching views controls entry,
weekly review, and invoice export; it does not convert existing rows.

## Load workflow

1. Select Load-based and add a job to the RTEX Job Rate Key. Choose tonnage
   ($ per US ton) or mileage ($ per billable mile), a positive rate, and an optional
   default P.O./work order.
2. Use Add RTEX Row for manual BOL entry, or Import BOLs for images/PDFs.
3. For imports, select the job for the batch and optionally its driver. Otherwise,
   the existing truck matching rules suggest a driver. Each image/PDF page must
   contain one BOL.
4. Review the draft's date, ticket, quarry, customer, order, product, vehicle,
   purchase order, quantity, and matched driver. Save Reviewed Load posts it to
   both the RTEX invoice rows and driver payouts.
5. Use the weekly load review to edit/delete rows or export a ZIP containing one
   Excel invoice per job.

Drafts are session-backed, limited to 50 pending rows, and are not payouts.
Uploaded files are limited to 25 MB each (also subject to PHP upload limits).
The original image is not archived by this workflow; the saved row retains its
source filename and BOL fields. Keep source BOLs in the existing document archive.

For the supplied HMA/Helmcamp Christmas Quarry example, ticket 228820 is dated
2026-09-10, net US tons are 22.66, the truck is R&E03, and the P.O. is EK10524.
The net-pound value 45320 and metric-ton value 20.56 are not the billable tonnage.
Mileage is not present on this BOL and must be supplied for a mileage job.
OCR output is ordinary field data; it is never executed as instructions.

## Calculation and history

- Tonnage pay = net US tons × job rate, rounded to cents.
- Mileage pay = billable miles × job rate, rounded to cents.
- The server calculates totals from the Job Rate Key; a submitted total or rate
  cannot override them.
- Saved rows snapshot the job name, rate basis, and rate. Changing/deleting a job
  key does not reprice history. Editing a load retains its saved pricing unless
  another job is selected or Apply current Job Rate Key rate is checked.
- The BOL's actual P.O. can override the job's default work order.
- A BOL requires a valid matched driver before it can be posted to payouts.
- Same-date/ticket duplicates are rejected. Hourly entry/import also checks for
  an existing load with that identity.

Load rows have zero hours, so RTEX's hourly broker fee and hourly base-rate
reduction do not apply to them. The existing RTEX percentage broker setting, if
chosen, still applies to the vendor's gross pay. No new load-specific fee is
assumed. Driver statement columns show Quantity with tons or miles for loads;
hourly statements keep their existing pay calculation.

## Driver and invoice fuel surcharges

Both FSC percentages are configured in RTEX Job Rate Key, independently per job.
New keys start at 0%. Each saved load retains its base and FSC rates until the
current job rates are explicitly reapplied. Broker fees remain vendor-wide.

- Driver FSC = round(base load freight × Driver FSC Rate / 100, 2) per load.
  Driver PDF, CSV, and Excel statements show a separate dated/ticketed FSC line
  and a Driver Fuel Surcharge summary. It is included once in driver net pay,
  insurance/fuel allocation availability, and balance calculations.
- Brokerage is calculated on base freight only. Driver FSC is never added to
  driver_payouts.tss_pay and is not subject to brokerage.
- Invoice FSC = round(base load freight × RTEX Invoice FSC Rate / 100, 2) per
  load. It is calculated only when exporting RTEX load invoices by job.
  Each Excel line includes Base Freight, Invoice FSC Rate (%), Invoice FSC,
  and Invoice Total; footer totals include the invoice FSC.
- Invoice FSC does not enter driver payouts, driver FSC, or driver statements.
  Hourly work receives neither of these load-based surcharges.

For example, $1,000 base freight with a 10% broker fee, 15% Driver FSC, and 25%
Invoice FSC produces a $100 broker fee and $150 Driver FSC. Driver net before
other deductions is $1,050. The RTEX invoice total is independently $1,250.

includes/rtex_fsc.php supplies validation and shared calculations. Schema setup
preserves legacy loads by copying their former weekly percentages into the load.
Off-cycle statements use the load's saved FSC percentage.

## Storage and deployment

Deploy upload.php, reports.php, includes/payout_net_helpers.php, includes/rtex_fsc.php, and all includes/rtex_load* files together.
upload.php loads the new helpers/actions/forms; no separate endpoint is required.

ensure_rtex_load_schema() runs after the existing RTEX table initializer. It adds
billing_mode (default hourly), job/rate snapshots, tons, miles, BOL metadata,
source filename, a linked driver_payout_id, and updated_at to rtex_payout_rows.
The generated load_ticket_number column is NULL for hourly rows; a unique
(load_ticket_number, work_date) index prevents duplicate loads while retaining
the existing hourly source-line index. The initializer also creates rtex_job_rates.
The migration is repeatable and requires the same CREATE/ALTER privileges used
by the application's existing schema initializers.

Load saves and deletes update invoice rows and their linked driver_payouts rows
in one InnoDB transaction. Failed validation, duplicate keys, or payout failures
roll back the operation. Existing hourly rows use their original handlers.

Files:
- includes/rtex_load_helpers.php: schema, validation, transactional storage,
  BOL parsing/OCR page extraction, and load invoice workbook builder.
- includes/rtex_load_actions.php: mode/session state and POST actions.
- includes/rtex_load_form.php: job-rate key, manual entry, and draft review.
- includes/rtex_load_review.php: weekly review and edit forms.
- includes/rtex_load_ui.js: quantity requirements and calculation previews.
- reports.php: RTEX quantity/rate labels and load-vs-hourly statement calculation.

OCR reuses the existing Nickel Rock command helpers. Images require Tesseract.
Text PDFs use pdftotext. Scanned PDFs require pdftoppm and Tesseract. Configure
TESSERACT_CMD, PDFTOTEXT_CMD, and PDFTOPPM_CMD when executables are not on PATH.
If tools cannot read a file, the page reports the error and manual entry remains
available. It never posts a guessed OCR result automatically.

## Verification

Run PHP syntax checks on the changed files, then:

    php -d extension=zip scripts/test_rtex_loads.php

The test imports named functions without running upload.php/config.php. It covers
the supplied BOL layout, US-ton selection, tonnage/mileage arithmetic, invalid
quantities/dates, hourly regression behavior, and generated invoice formulas/XML.

For integration checks, use a disposable MariaDB instance on localhost port 33079,
with root password lonestar-local-test-only, then run:

    php -d extension=zip -d extension=mysqli scripts/test_rtex_loads.php --database

The test creates a uniquely named database and drops only that database afterward.
It must not run against a live database. It verifies schema repeatability,
historical pricing, reassignments, duplicate rejection, rollback, linked-payout
recreation, deletion, and POST validation.

Add --ui to write a browser fixture in the OS temporary directory. Opening it
runs 18 checks of live calculations, quantity requirements, historical-rate
preview, selection controls, and form associations. This does not connect to
the application's live database.


FSC verification:

    php -d extension=zip -d extension=mysqli scripts/test_rtex_fsc.php --database

This runs the 56 RTEX checks plus 42 FSC checks, including the 15%/25%
example, brokerage exclusion, actual net-pay and pre-deduction calculations,
per-job rate isolation, separate statement rows, invoice formulas, historical migration,
and job percentage validation. It uses the same disposable local database described
above. The browser fixture also verifies both job fields, defaults, layout,
form association, saved job values, and percentage validation.

Manual Add RTEX Row uses Job Rate Key, Matched Driver, BOL Date, Ticket / BOL #, Truck No., P.O. / Work Order, and one quantity field. The quantity unit follows the selected job (net US tons or miles); changing units clears the quantity to avoid reusing tons as miles. Provider, customer, BOL job number, and product are optional metadata retained in import review and editing, but omitted from manual entry.

The load-based RTEX Weekly Review displays Truck No. and omits Quarry / Provider and Miles columns. Mileage remains available for job calculations and invoice exports.

Weekly Review Edit uses the same entry fields as Add RTEX Row, prefilled with the saved quantity. The existing option to apply the current job rate remains available. Saving preserves omitted BOL metadata and the secondary quantity.

RTEX driver statements show Driver Fuel Surcharge only in the summary; individual load FSC rows are omitted. The owner payout report uses the same RTEX broker and FSC calculation as driver statements and retains insurance, allocated fuel, miscellaneous adjustments, and open-balance deductions.

Invoice exports use includes/templates/rtex_invoice.xlsx, based on the supplied example, including its logo, company address, Bill to details, merged cells, column widths, and gray header at row 11. Loads begin at row 12. Invoice numbers use LS plus the selected week start and the job export index; invoice date is the Saturday ending that week. Upload the template together with includes/rtex_load_helpers.php and includes/rtex_load_actions.php. Each export replaces sample rows with all current saved loads and recalculates formula positions and totals.

FSC rates are configured per job in RTEX Job Rate Key. Both driver and invoice
percentages default to zero for job keys and are copied to each new load. Editing
a saved load preserves its base and FSC rates unless Apply current Job Rate Key
base and FSC rates is selected (or the job changes). Existing loads receive their
previous weekly FSC percentages during the automatic schema migration. The legacy
weekly settings remain only for historical migration; new calculations use saved
load rates. Driver FSC remains excluded from broker fees and invoice FSC remains
excluded from driver pay.
