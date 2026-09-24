# TSS Fuel Surcharge Rate Key

On upload.php, select TSS. The Fuel Surcharge Rate Key sits above the LS Detail upload.
Add a start date, end date, FSC type (Tonnage or Mileage), mileage match (Exact or
Range), mileage value(s), and a dollar rate. Exact uses Minimum / Exact Miles;
Maximum Miles is disabled. Dates and mileage endpoints are inclusive. Mileage
is compared to two decimal places. Overlapping dates and mileage criteria are
not allowed for the same FSC type, including an exact value inside a range.

Create a new dated rule for each week's rates. For example:

| Dates | Type | Match | Miles | Rate |
|---|---|---|---|---|
| September 20–26, 2026 | Tonnage | Range | 0–50 | $2.50/ton |
| September 20–26, 2026 | Mileage | Exact | 50 | $0.75/mile |
| September 27–October 3, 2026 | Tonnage | Range | 0–50 | $3.00/ton |

Include an FSC Type column in LS Detail (Fuel Surcharge Type is also accepted).
Every nonempty row must contain Tonnage, Mileage, or None. A matching tonnage
rule uses Net Weight (Tons) × rate; a mileage rule uses Mileage × rate, without
requiring tons. None saves a zero rate and amount and needs no matching rule.
The rule always uses Delivery Date, regardless of the selected pickup/delivery
name-matching mode. Old spreadsheet Fuel Surcharge Rate columns are ignored.

Blank/invalid types, missing quantities, invalid dates, and missing or ambiguous
rules produce spreadsheet row-number errors before any raw rows or payouts are
inserted. Correct the file or key and upload again. Entirely blank rows are skipped.

The importer saves each load's type, rate, and amount (rounded to cents). Editing
or deleting a key never recalculates saved loads. The TSS weekly review allows
manual correction of the saved rate/type and shows the FSC amount below the type.
Saving a reviewed load recomputes the amount from its corrected quantity and rate.
Selecting None clears its rate and amount. Updating a date or mileage in review
preserves the saved rate; enter a corrected rate explicitly when required.

Existing loads retain their previous FSC calculations until individually edited.
The shared payroll helper uses saved FSC amounts for new loads and falls back to
the original calculation for legacy rows, without adding FSC to base freight.
Schema setup automatically creates tss_fsc_rules and adds the nullable
fuel_surcharge_amount column to ls_detail_raw when upload.php opens. Deploy
upload.php and both tss_fsc include files together with payout_net_helpers.php.

Validation:

    php org/scripts/test_tss_fsc.php
    php org/scripts/test_tss_fsc.php --database

The database option uses only a disposable database on localhost port 33079,
with the same local test credentials as the RTEX suite. It never loads production
configuration. Tests cover inclusive boundaries, mixed types, None, invalid data,
overlap validation, weekly changes, historical snapshots, and payroll totals.
