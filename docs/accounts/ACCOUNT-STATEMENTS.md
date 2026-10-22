# Metrik POS — Customer and Supplier Statements

## Scope

This patch adds organization-scoped, chronological account statements for existing customers and suppliers. It introduces no database migrations and does not write or alter accounting transactions.

- Customer statement source: `customer_account_transactions`.
- Supplier statement source: `supplier_transactions`.
- Default period: first day of the current month through today.
- Filters: `from` and `to` in `YYYY-MM-DD` format. Invalid/reversed ranges are rejected.
- Pagination: 50 transactions per page; running balance is reconstructed from the full ledger before the first displayed line, so later pages carry the right balance.
- Exports: CSV using the same dates and chronological ordering, UTF-8 BOM for spreadsheet compatibility, with formula-leading text guarded against CSV injection.
- Access: statement data is scoped to the current organization and requires the customer or purchasing viewing capability.

## Balance convention

Customer account balance is `debit - credit`; an amount owed by the customer is positive. Supplier balance is `credit - debit`; an amount owed to the supplier is positive. Opening balance includes all recorded transactions dated before the `from` date. Closing balance equals opening balance plus/minus all movements in the selected period. Current balance is calculated from the full ledger.

## Routes

- `GET /customers/{customerId}/statement` — customer statement UI
- `GET /customers/{customerId}/statement/export` — customer CSV
- `GET /suppliers/{supplierId}/statement` — supplier statement UI
- `GET /suppliers/{supplierId}/statement/export` — supplier CSV

No new migrations or packages are needed.

## Important accounting note

These statements report posted ledger transactions. They do not synthesize missing historical records from invoices/payments nor reconcile legacy balances automatically. Confirm that existing transaction services create one ledger entry for each posted sale/collection, purchase invoice/payment, and purchase return before treating the report as a formally reconciled accounting statement.
