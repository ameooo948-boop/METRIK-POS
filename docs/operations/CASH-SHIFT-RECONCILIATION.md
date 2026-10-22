# Cash shift reconciliation and audit

## What is included
- A same-branch shift history with date/status filters and CSV export.
- A read-only shift report showing opening cash, movement ledger, cash returns, counted denominations, stored close snapshot and a recalculated expected total.
- Optional cash denomination counts and closing notes when closing a shift. When denomination counts are supplied, the server verifies the count total equals the stated closing amount.
- Cash refunds (`cash_return`) are deducted from expected cash. Cash movements are tenant- and branch-scoped; cashiers see only their own shifts, while permitted managers/report viewers can review shifts in the selected branch.

## Apply
Extract the ZIP over the repository preserving paths, then run `php artisan migrate`. No destructive reset is needed. The new migration adds nullable JSON and notes columns; existing rows remain valid.

## Verification
Run `php artisan test --filter=CashShiftReconciliationTest`, `php artisan test --filter=PointOfSaleTest`, `npm run build`, and then the full suite. Manually review a cash refund, a cash in/out movement, and one counted close. The historical report displays stored expected cash separately from the recalculated amount so earlier closes can be audited if a refund had been omitted from the old calculation.
