# Sales invoices and thermal receipts

## What changed

- Added a dedicated, compact receipt page at `GET /sales/{saleId}/receipt` (`sales.receipt`).
- The page is protected by the existing organization scoping and `SalePolicy::view` authorization.
- Added entry points from the sale list and sale details page.
- Supports a 58 mm or 80 mm on-screen paper width, item snapshots, customer/cashier/branch details, discounts/tax/total, amounts collected, remaining due, payment methods, and receipt reprints.
- The page uses browser print and doesn't depend on a PDF package or a specific printer vendor.

## Apply

Extract this ZIP into the Laravel project root, preserving paths. No database migration is included.

## Print setup

1. Open a sale and choose **إيصال حراري** (thermal receipt).
2. Select 58 mm or 80 mm in the page.
3. In the browser print dialog, select the actual thermal printer and the matching paper width. The page controls the content width; the printer driver controls the paper feed and may require margins set to none/minimum.
4. Disable browser headers/footers if they are enabled in the print dialog.
5. Print one test receipt before high-volume use; printer drivers and font rendering differ.

## Validation

Run these from the project root:

```powershell
php artisan test --filter=SaleReceiptTest
php artisan test --filter=SaleReturnTest
npm run build
php artisan test
php artisan metrik:health-check --strict
```

The feature test checks that an authorized user can open the receipt and that an organization cannot access another organization's receipt. The source bundle itself should be syntax-checked as well as tested on the target project.
