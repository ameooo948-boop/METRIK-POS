# Barcode, product CSV import, and export

## Scope

- Product catalog export is restricted to users with `manageCatalog` permission and scoped to the current organization.
- The exported `initial_stock` column represents the current branch's quantity. When a row's SKU already exists, importing it updates catalog fields but never changes stock. `initial_stock` is only applied when the SKU creates a new product, which allows a CSV from one organization to bootstrap a different organization without accidentally adjusting live inventory.
- CSV import accepts UTF-8, including Excel's UTF-8 BOM, and up to 5,000 data rows in a file no larger than 10 MB.
- Use the downloaded template's exact English headers: `sku,name,description,category,unit_code,barcode,purchase_price,selling_price,low_stock_threshold,initial_stock,is_active`.
- Required columns: `sku`, `name`, `unit_code`, `purchase_price`, and `selling_price`.
- `category` must already exist in the active organization. `unit_code` must match an existing unit code. Import does not create categories or units implicitly.
- Prices support up to two decimal places, quantities up to three. `is_active` accepts `1/0`, `true/false`, `yes/no`, `active/inactive`, or `نشط/متوقف`.
- For an existing SKU, the base unit cannot be changed through CSV because doing so can invalidate unit conversions and historical transactions. Catalog data and active state can be updated; a new barcode may be added as an additional barcode. The existing barcode is not removed.
- Repeated SKUs within the same file are treated as an error. Duplicate barcodes belonging to another product are rejected. Valid rows commit independently; bad rows are skipped and up to 50 row-level errors are shown on the products page.
- Barcode values should be formatted as text in spreadsheet software so leading zeros are preserved. Export escapes CSV formula-like text cells.

## Barcode lookup

The shared product search scope still performs partial matching by product name, SKU, or barcode. Exact barcode matches are ranked ahead of partial matches so a keyboard-wedge scanner that submits a full barcode finds the exact product first. The POS still requires its usual checkout checks (branch stock, price validation, and open shift).

## Routes

- `GET /products/export` — export current organization's product catalog and current branch balance as CSV.
- `GET /products/template` — download a blank CSV template.
- `POST /products/import` — import a CSV file under a dedicated request-rate limit.

All three routes require `manageCatalog` authorization.

## Validation

Run after extracting the ZIP into the project root:

```powershell
php artisan migrate
php artisan test --filter=ProductCatalogCsvTest
php artisan test --filter=ProductManagementTest
php artisan test --filter=PointOfSaleTest
npm run build
```

Then run the full suite and health check. The package does not introduce a database migration; it uses the existing products, barcodes, units, categories, and branch balances tables.
