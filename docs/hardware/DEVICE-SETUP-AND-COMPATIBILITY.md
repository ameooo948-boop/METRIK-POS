# Metrik POS — Device setup and compatibility (Phase 35)

## What this phase provides

- A local device setup page at `/pos/devices`.
- Browser-local preferences for 58 mm / 80 mm receipt width and scanner suffix guidance.
- A keyboard-wedge scanner input test. The existing POS product search form remains the transaction search path; the device test page does not add products to a sale.
- A printable thermal printer test slip.
- A diagnostic parser for one common EAN-13 variable-measure label format.
- Browser capability information for print, local storage, Web Serial, WebUSB, connection status, and secure context.

## Scanner

Most USB/Bluetooth POS scanners can work as keyboard-wedge devices: configure the scanner to emit the barcode as keystrokes and append Enter. In POS, focus the product search box and scan; the existing search form submits on Enter. Test using `/pos/devices` before using it with customers.

## Thermal printer

Set the default paper width, save settings, then print the test slip. In the browser print dialog select the correct printer and paper width. Configure scale to 100% if supported, turn browser headers/footers off, and set margins to none/minimum according to the driver. Actual feed/cut behavior depends on the printer driver.

The receipt width preference is saved in this browser only and read by the receipt page. It is not synchronized to the user's account.

## Cash drawer

A normal browser page cannot reliably send raw ESC/POS commands to a drawer port. A drawer attached to a receipt printer may be controlled by a supported printer command/driver; a network/USB drawer may need a manufacturer utility or a trusted local bridge. This phase intentionally does not try to open a drawer through arbitrary local HTTP endpoints.

## Scale / variable-measure barcode

The diagnostic parser supports this common 13-digit shape only:

- 2-digit configured prefix
- 5-digit product identifier (PLU/SKU fragment)
- 5-digit payload (weight or price, according to decimals configured)
- 1-digit EAN-13 check digit

Scale vendors configure different prefix, PLU length, payload position, decimals, and whether the payload represents weight or price. Confirm the exact label format in the scale manual. The parser page is a diagnostic preview; it is **not yet wired into checkout** and must not be used as a final price/weight authority. Server-side product pricing and stock validation remain unchanged.

## Browser device APIs

Web Serial and WebUSB availability is only shown as a capability hint. The app does not request permissions or talk directly to these devices. Browser support usually depends on browser version and secure context (HTTPS or localhost).

## Test commands

```powershell
php artisan route:list --path=pos/devices
php artisan test --filter=HardwareDevicesPageTest
npm run build
php artisan test
php artisan metrik:health-check --strict
```

No database migration is added. Do not use `migrate:fresh` for this phase because it is unnecessary and destructive to existing data.

## Manual verification checklist

1. Open `/pos/devices`, confirm the page loads and preferences can be saved/refreshed in the same browser.
2. Scan a known item barcode into the device test input and confirm it appears, followed by Enter.
3. From POS, scan a known item while the product search box is focused; confirm search submits.
4. Print the test slip on both supported widths and compare to the physical paper.
5. Open a sale receipt, change paper width, navigate away and reopen a receipt; confirm the width preference persisted.
6. Confirm the cash drawer is not represented as directly supported unless a vendor driver / approved bridge is separately installed and tested.
7. Compare any scale label against the scale manufacturer's format; parser success alone is not evidence that the code is correct for the store.
