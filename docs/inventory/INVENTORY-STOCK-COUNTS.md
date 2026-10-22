# Metrik POS — Physical inventory counts

## Purpose

This feature records a physical count for the currently selected branch, stores a snapshot of the book quantity and counted quantity for each line, applies quantity variances, and writes each non-zero adjustment to the inventory movement ledger.

## Workflow

1. Open **Inventory → Physical count** at `/inventory/counts`.
2. Search for an active product and add it after the physical quantity has been counted.
3. Enter the actual quantity (up to three decimal places). A quantity of `0` is valid.
4. Save the count. The server checks that the live inventory quantity still equals the snapshot loaded by the form. If it changed while the user was counting, the entire transaction is rejected and the user must refresh and recount that item.
5. Review the count history and variances.

## Safety and consistency

- All submitted products must be active and belong to the current organization.
- The operation is scoped to the user's current organization and branch and requires the `manageInventory` permission.
- A database transaction locks the branch, products, and balance rows while calculating variances and writing adjustments.
- A non-zero variance updates the balance and creates one `inventory_movements` row of type `count_adjustment`, linked polymorphically to the count record.
- A zero variance is still preserved in the count detail, but does not create a needless movement row.
- The `client_operation_uuid` plus a canonical payload hash prevents duplicate submissions from applying stock changes twice; reusing the key for a different payload is rejected.
- Counts with more than 1,000 lines are rejected. Use multiple physical-count sessions for very large catalogs.

## Apply and verify

Run the migration after extracting the patch:

```powershell
php artisan migrate
php artisan test --filter=InventoryStockCountTest
npm run build
```

Then run the full checks if the focused tests pass:

```powershell
php artisan test
npm run build
php artisan metrik:health-check --strict
```

Do not use `migrate:fresh` against a database containing important data.
