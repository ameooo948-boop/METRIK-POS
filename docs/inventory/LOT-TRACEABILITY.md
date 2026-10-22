# Stage 41 — Inventory lot traceability

## Scope
- Existing positive lot balances receive a migration-time opening-balance record; the migration does not invent historical sale allocations.
- A sale item stores the exact lot quantities consumed by FEFO.
- Returns are attributed to those sold lots. Restocked returns replenish the original lot; non-restocked returns consume the returnable allocation without replenishing stock.
- Branch transfers move tracked quantity from source lots to same-code destination lots and retain expiry/manufacture metadata. Untracked stock remains untracked.
- Stock-out adjustments, negative stock-count variances and purchase returns consume existing tracked quantities in expiry order; positive stock adjustments/count variances remain untracked until the operator registers the batch.
- Lot-level movements record signed quantities, balance-after values, user, reason and source operation.
- Expiry status and date validation use the organization timezone.

## Rollout
1. Take a verified backup before migration.
2. Merge the patch and run `php artisan migrate`.
3. Run the focused InventoryLots, PointOfSale, SaleReturn, StockTransfer, StockAdjustment and SupplierSettlement/PurchaseReturn tests, then the full suite.
4. Audit existing tracked quantities after deployment: this migration does not backfill lot allocations for sales made before Stage 41. Historical returns from those sales continue to restock as untracked inventory. Existing lot movement history is initialized with a migration-time opening balance, not reconstructed prior transactions.
5. For operations where only part of a product balance has a registered lot, tracked operations are applied to tracked quantities first and the remainder stays untracked.

## Acceptance criteria
- Every new tracked sale has immutable lot allocation rows.
- Duplicate sale/return/transfer operations do not duplicate lot movement effects.
- A restocked return only restores quantities from the original lot allocations.
- Transfer maintains lot code and expiry on destination branches.
- Lot remaining quantities never go negative and tracked quantity must not exceed the branch product balance.
- Lot movement records are tenant- and branch-scoped.
