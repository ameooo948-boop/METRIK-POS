# Inventory valuation report

## What it provides

- A branch-specific product valuation page at `/inventory/valuation`.
- Search by product name, SKU, or barcode, plus a category filter.
- A CSV export that respects the same filters and organization/branch scoping.
- Totals for catalog items in the current filtered result, items with zero-or-negative quantity, stock cost value, stock retail value, and estimated gross profit at current list prices.

## Calculation and scope

For each product, the current branch balance is multiplied by the current purchase price and selling price. Missing branch balances are treated as zero. Products are scoped to the signed-in user's resolved organization, while balances are scoped to the currently selected branch. This report is read-only: it does not alter quantities or create inventory movements.

The margin is an estimate, not realized net profit. It does not subtract discounts, returns, taxes, rent, wages, or other expenses. Valuation uses the current purchase price on the product record rather than a historical weighted-average cost. The report includes inactive products so any remaining stock can still be valued.

## Access control

Both the page and CSV export require the organization's `manageInventory` permission. CSV cells beginning with formula-significant characters are escaped to reduce spreadsheet formula-injection risk. Responses use private, no-store cache headers.

## Validation

Run:

```powershell
php artisan test --filter=InventoryValuationTest
php artisan test --filter=InventoryStockCountTest
npm run build
php artisan test
php artisan metrik:health-check --strict
```
