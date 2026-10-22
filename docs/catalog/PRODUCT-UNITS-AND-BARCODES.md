# Product Units, Conversions, and Multiple Barcodes

## What this patch changes

- Adds explicit purchase and selling prices and an active state to each product-unit conversion.
- Stores the selected unit, conversion-factor snapshot, and quantity expressed in the base unit on sale and purchase invoice lines.
- Adds a product settings page at `/products/{productId}/units` to manage package units and associate multiple barcodes with the units they represent.
- Allows point of sale to select a unit automatically when an exact barcode is scanned.
- Allows purchases to be received in a package unit. Inventory balances and inventory movements are still recorded in the product's base unit.
- Converts sale-return and purchase-return quantities using the factor captured on the original document, so changing a conversion later does not change the historical conversion used for those returns.
- Updates pending offline sales to reserve local stock using base-unit quantities and includes the chosen unit in the offline payload.

## Example

If a product uses `piece` as its base unit and a carton contains 12 pieces:

- The conversion factor for `carton` is `12.000`.
- Buying 2 cartons at EGP 48 per carton adds 24 pieces to branch inventory and records a purchase line of 2 cartons.
- Selling 2 cartons at EGP 18 per carton subtracts 24 pieces and records a sale line of 2 cartons.
- The product's `purchase_price` remains the estimated cost of one base unit. An explicit package purchase price is stored on the conversion when the receiving invoice is saved.

A blank package price is derived from the base-unit price multiplied by the conversion factor. Enter explicit prices for cases where pack pricing differs from the base-unit arithmetic.

## Apply the patch

1. Back up the database and commit or stash current changes.
2. Extract the ZIP into the project root, preserving relative paths.
3. Review `PATCH-INSTRUCTIONS.txt` and `git diff --check`.
4. Run `php artisan migrate`. Do not use `migrate:fresh` on a database containing real data.
5. Run the focused tests, then the full test suite and production health check.

## Compatibility and operational notes

- The existing application stores an initial factor-1 conversion row for a product's base unit. This patch hides that legacy row from alternate-unit choices so the base unit appears exactly once.
- Old sale and purchase lines are backfilled with a factor of 1 and `quantity_in_base = quantity`, preserving their interpretation as base-unit lines.
- Conversion changes affect new transactions only. Sale and purchase lines retain snapshots for historical reports and returns.
- Disabling a conversion is blocked while barcodes remain assigned to it. Remove the package barcodes first if that unit should no longer be scannable.
- This patch does not implement weighed-label parsing or unit conversion between different stock-keeping items; it converts a selected product unit to that product's base unit.
- As in the previous expiry-lot stage, sale-return stock is returned to the branch balance but not reassigned to the exact source inventory lot. Do not rely on lot-level expiry traceability for returned units until lot-aware returns are implemented.
- A purchase receipt adds the returned base-unit quantity to stock; automatic creation of expiry lots for purchases is not introduced in this phase.

## Validation

```powershell
php artisan migrate
php artisan test --filter=ProductUnitConversionTest
php artisan test --filter=PointOfSaleTest
php artisan test --filter=PurchaseInvoiceTest
npm run build
php artisan test
php artisan metrik:health-check --strict
```

## Expected files

See `PATCH-INSTRUCTIONS.txt` for the exact added/modified file list and a suggested dedicated commit command.
