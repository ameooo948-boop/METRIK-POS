# Metrik POS — Branch Stock Transfers

## What this stage adds

- Transfer stock from the currently selected active branch to another active branch within the same organization.
- Create the header, transfer item snapshots, both inventory balance changes, and both stock movement entries in one database transaction.
- Keep quantities exact to three decimal places using `DecimalQuantity` integer-milli arithmetic.
- Store a client operation UUID and canonical payload hash. A repeated request with the same UUID and payload returns the original transfer; reusing the UUID for different quantities or branches is rejected.
- List transfer records associated with the current branch and show a link from the products page for users who can manage inventory.

## Safety rules

1. The source branch comes from the server-resolved current branch. It is never accepted from the browser payload.
2. The destination must be active and belong to the current organization; the source and destination must differ.
3. Every product must be active and belong to the same organization.
4. Available stock is rechecked under row locks on the server. Client-supplied availability is only a UX hint.
5. Insufficient stock or any database error rolls the transaction back so balances and movements cannot be partially applied.
6. The operation is immediate and atomic: there is no in-transit or approval workflow in this stage.

## Install

Extract the ZIP into the project root while preserving paths, review `git diff`, then run:

```powershell
php artisan migrate
php artisan test --filter=StockTransferTest
php artisan test
npm run build
```

Do not run `migrate:fresh` against a database containing real data.

## Suggested manual acceptance

1. Create or select two active branches in the same organization.
2. In the source branch, confirm a product has a positive balance.
3. Transfer a decimal quantity such as `1.125` and verify source is reduced, destination increased, and `transfer_out`/`transfer_in` movements exist.
4. Submit the exact same request UUID twice and verify the stock changed only once.
5. Try a quantity larger than source availability; verify the operation is rejected and neither branch balance changes.
6. Try targeting another organization's branch; verify validation rejects it.
7. Confirm a user without inventory-management permission cannot open or submit transfers.
