# Metrik POS — Customers, Customer Accounts and Credit Sales

## Included
- Tenant-scoped customer directory, profile/contact fields, optional per-organization unique code, active/inactive state, and account opening balance.
- Customer association on paid checkout invoices and offline cash invoices once synchronized.
- Credit checkout for an active customer only, with a positive credit limit and a transactionally checked remaining limit.
- Customer account ledger with debit entries for credit invoices and credit entries for collections.
- Invoice-specific collections (partial or full), as well as collections against the general/opening balance without silently allocating those payments to an invoice.
- Cash collections require an open cash shift for the current user and create a `cash_in` movement so shift reconciliation includes the receipt. Electronic collections are recorded without touching cash.
- Tenant-scoped lookup and unique operation UUIDs for collection idempotency.

## Balance convention
A positive `customers.balance` means the customer owes the organization. The balance is cached on the customer record and a corresponding `customer_account_transactions` row is written atomically for opening balances, credit sales, and collections. Do not edit this balance directly in SQL or through an application form.

## Credit checkout rules
- `credit_limit = 0.00` means credit sale is disabled for the customer.
- A credit sale requires an active customer in the current organization and must not increase their balance above the configured credit limit.
- Credit checkout is not allowed while offline. Offline cash sales can still carry a customer ID and that ID is validated by the server when the sale syncs.
- Credit invoices start with `status = due`; a partial collection changes the status to `partially_paid`, and full settlement changes it to `paid`.
- The current sale-return workflow only accepts paid invoices. Settle an on-account invoice before using the existing sale-return workflow. This keeps the existing cash refund / stock restocking rules unchanged in this increment.

## Apply and verify
```powershell
php artisan migrate
php artisan test --filter=CustomerAccountTest
npm run build
php artisan test
php artisan metrik:health-check --strict
```

Review the migration output and `git diff --check`. Do not use `migrate:fresh` on a database containing business data.


تقارير المبيعات تحتسب قيمة الفواتير المدفوعة والآجلة والمدفوعة جزئيًا ضمن المبيعات وتكلفة البضاعة المباعة؛ قسم طرق الدفع يعرض التحصيلات المسجلة فقط. تحصيل الرصيد الافتتاحي يُسجل في كشف حساب العميل وحركة النقدية إن كان التحصيل نقديًا.
