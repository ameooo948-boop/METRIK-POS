# Metrik POS — Organization Sales Tax

## Scope

This implementation adds configurable, organization-wide, tax-exclusive sales tax to new POS sales. Tax is disabled by default for existing organizations. The organization owner can enable it and set a rate from 0.00% to 100.00% in **Dashboard → إعدادات المؤسسة → ضريبة المبيعات**.

The system does not select or certify a legally required rate. Configure the rate and invoicing workflow only after confirming the applicable rules for the business and jurisdiction. This feature is an operational calculation, not a certified tax-return or e-invoicing integration.

## Calculation rules

- Product prices are treated as **before tax**.
- Sale-line discounts are applied before tax.
- Each discounted line's tax is calculated in integer cents and rounded half-up; invoice tax is the sum of line taxes.
- The effective rate and tax amount are stored on the sale and sale lines. Changing current organization settings does not rewrite completed invoices.
- The amount due, customer receivable, payment and cash movement include tax.
- For a return, tax is refunded proportionally from the original sale-line tax snapshot. The last partial return for a line receives the remaining rounding cents so total tax refunds do not exceed the original line tax.
- Reports show sales and returns as charged/refunded (tax-inclusive), display net tax collected separately, and calculate gross/net operating profit from tax-exclusive sale-line values.
- Offline checkout stores its estimated tax-inclusive total. Sync verifies that estimate against current server-side prices and tax settings; a mismatch must be reviewed rather than silently changing the amount already collected.

## Data and rollout

Migrations add a tax-rate snapshot to `sales`, a tax total to `sale_returns`, and per-return-line tax amounts. Back up the database before deploying and run `php artisan migrate --force` in the deployment procedure. Existing invoices receive a zero rate snapshot by default and retain their existing total; the system does not retroactively recompute them.

## Acceptance tests

- Owner can read/update settings without replacing other organization settings.
- Non-owner members cannot read/update tax settings.
- Invalid rates and malformed enabled values are rejected.
- Default-disabled sales retain the historic pre-tax total.
- Enabled checkout applies the configured rate after discounts and snapshots the rate.
- Offline sync rejects a stale estimated total if tax settings changed while disconnected.
- Sale returns use the original sale-line tax and report net tax collected correctly.
- Receipts and sale details display tax; reports and CSV exports expose tax separately from tax-exclusive profit.

## Important limitation

Only one general organization-wide rate is supported. There is no product tax category, exemption, tax-included pricing, compound tax, jurisdiction/rate history, legally mandated invoice identity, or official tax submission integration in this implementation. Do not represent the feature as full statutory tax compliance without those requirements being researched and implemented.
