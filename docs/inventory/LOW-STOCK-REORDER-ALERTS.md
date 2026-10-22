# Low-stock alerts and branch reorder thresholds

## What this adds

- An inventory-manager page at `/inventory/low-stock` for the active organization and branch.
- Branch-specific reorder thresholds on `inventory_balances.reorder_threshold`.
- When the branch threshold is `NULL`, the product's existing `products.low_stock_threshold` is used as the fallback.
- A branch threshold of `0` explicitly disables alerts for that product in that branch.
- A live low-stock count and an optional low-stock-only filter.
- Quantity values and thresholds keep three decimal places, matching inventory balance precision.
- Tenant scoping and the `manageInventory` authorization policy guard the read/update endpoints.

## Installation

1. Extract the patch ZIP into the repository root and preserve relative paths.
2. Run `php artisan migrate`.
3. Run `php artisan test --filter=LowStockTest` and `npm run build`.
4. If those pass, run the complete test suite and strict health check before committing.

## Behavior and limitations

- The suggested reorder quantity is a simple informational estimate: `max(0, 2 * effective threshold - current stock)`. It is not a purchase order.
- This stage shows in-app alerts only. It does not dispatch email, push, or queued notifications.
- The global threshold in a product record remains unchanged when a branch threshold is saved.
- To return to the global threshold, clear the branch threshold and save. To disable alerts only for the current branch, save `0`.
