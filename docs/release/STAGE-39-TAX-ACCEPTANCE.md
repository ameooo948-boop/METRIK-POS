# Stage 39 — Sales tax acceptance checklist

- [ ] Back up the production database before deploying these migrations.
- [ ] Run migrations in staging and confirm all existing sales retain their original totals.
- [ ] Confirm tax is disabled by default for existing organizations.
- [ ] Configure the intended organization rate with the business owner/accountant before enabling it.
- [ ] Run `php artisan test --filter=OrganizationTaxSettingsTest`.
- [ ] Run `php artisan test --filter=PointOfSaleTest`.
- [ ] Run `php artisan test --filter=OfflineSaleSyncTest`.
- [ ] Run `php artisan test --filter=SaleReturnTest`.
- [ ] Run `php artisan test --filter=ReportsAndExpensesTest` and `php artisan test --filter=QueuedReportExportTest`.
- [ ] Run the full `php artisan test` suite and `npm run build`.
- [ ] Perform a staging smoke test: tax-disabled sale, discounted taxable sale, cash/credit sale, partial return, non-restockable return, report summary/CSV, and offline sale sync.
- [ ] Verify receipt totals equal subtotal minus discounts plus tax and equal the payment/receivable amount.
- [ ] Verify the report's tax collected equals recorded sales tax less processed return tax; profit is tax-exclusive.
- [ ] Confirm no one except the organization owner can alter the rate.
- [ ] Do not enable production tax until the business's legally required rate and invoice requirements have been confirmed.
