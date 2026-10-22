# Metrik POS — Customer Receivables Aging

The organization-wide report summarizes positive customer account balances and open credit invoices in four age buckets: 0–30, 31–60, 61–90 and over 90 days. Open invoice due amounts are calculated from the current invoice total less recorded sale payments and are grouped by the sale's date. The report also flags customers whose balance exceeds a configured credit limit.

Opening balances and other positive account balances not represented by currently open credit invoices are shown separately as **unaged balance**. This prevents the report from assigning an invented age to a balance that has no open invoice date.

The report is read-only. It does not update customer balances, sales, or payments. It is scoped to the active organization and includes all its branches because customer balances are maintained at organization level. CSV export uses the same search filter and applies text-cell escaping to reduce spreadsheet formula injection risk.

## Apply

Extract the ZIP into the Laravel project root, preserving paths. It adds `CustomerReceivablesController`, a new Inertia page and a feature test; updates `routes/web.php`; and adds a report link to the existing Customers page. No migration or package installation is required.

## Verify

```powershell
php artisan optimize:clear
php artisan route:list --path=customers/receivables
php artisan test --filter=CustomerReceivablesTest
npm run build
php artisan test
php artisan metrik:health-check --strict
```

Aging is calculated from sale date because the current schema does not define a separate invoice due date. Reconcile the output against known test invoices and customer account balances before using it for collection decisions.
