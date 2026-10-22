# Stage 42 — Offline synchronization and physical device acceptance

## Scope

This stage hardens browser-side offline sync against two open POS tabs attempting to synchronize the same cashier/branch/shift queue at the same time. A short IndexedDB lease is stored in the existing `meta` store; no database migration or IndexedDB version bump is required. The server's `client_operation_uuid` idempotency validation remains the final protection against duplicate financial effects.

## Automated checks

Run from the project root in PowerShell:

```powershell
.\scripts\verify-metrik-offline.ps1
```

The script checks the offline/device routes, `OfflineSaleSyncTest`, `PointOfSaleTest`, `HardwareDevicesPageTest`, Composer non-dev platform requirements, the Vite production build, and strict runtime health. It writes a log under `storage/logs/` and does not print environment secrets or modify application data.

Run the complete suite separately before a production release:

```powershell
php artisan test
composer check-platform-reqs --no-dev
npm run build
php artisan metrik:health-check --strict
```

## Manual staging tests — required on real target hardware

- [ ] Use a staging database and a dedicated test cashier. Open one shift and sync the catalog.
- [ ] Open two tabs for the same cashier, branch, and shift. With pending offline sales present, trigger sync in both tabs at nearly the same time. One tab should report that synchronization is already running in another tab; the server should create one sale per operation UUID.
- [ ] Disconnect the network, make at least three cash sales, verify each shows a local reference and that the UI does not claim a central invoice number exists yet.
- [ ] Refresh the page and restart the browser. Confirm the queued entries remain in IndexedDB and pending counts remain visible.
- [ ] Reconnect. Verify entries sync in chronological order and every local UUID maps to exactly one central sale and one set of stock/cash movements.
- [ ] Submit the same operation UUID and identical payload twice using the test endpoint. The second request should return the original sale without changing stock twice.
- [ ] Submit a reused UUID with a changed cart. It must be rejected for review; it must not create another sale or alter inventory.
- [ ] Create a stale-price conflict and an insufficient-stock conflict. The UI must show `needs_review`, preserve the local record, and stop later sales in that scope from jumping ahead of the conflict.
- [ ] Close or replace a test shift before attempting to sync a sale recorded against that shift. Confirm that it is rejected for manual review rather than silently reassigned.
- [ ] Test a USB/Bluetooth keyboard-wedge barcode scanner in both the device setup page and live POS search. Scan known and unknown codes, including a scanner configured to append Enter.
- [ ] Print the device test slip and a real receipt on each supported printer width (58 mm and/or 80 mm). Check paper width, clipping, scaling, Arabic glyphs, and the driver's feed/cut behavior.
- [ ] Repeat printer/scanner tests on the actual browser, OS, and hardware combination used at the checkout counter.
- [ ] Confirm there are no pending or `needs_review` sales before closing the shift. Reconcile the cash drawer physically against the shift report.

## Operational safeguards

- Do not clear browser site data, uninstall the browser, or use private browsing while offline sales are pending. IndexedDB is device/browser-local; it is not a substitute for the central database or a backup.
- Never manually edit IndexedDB records or mark an entry `synced` to hide a conflict.
- The lock is a client coordination mechanism, not a distributed lock across separate computers. Separate devices may sync independently; server-side stock locks, operation UUID idempotency, organization/branch/shift validation, and manual conflict review remain authoritative.
- If a browser tab is killed or suspended, the lease expires after 90 seconds. Retry after the expiry if no other tab is visibly syncing.
- Physical hardware tests cannot be proven by CI alone. Record browser/OS/printer/scanner models and the tester/date in the deployment ticket.
- The app only treats keyboard-wedge scanning and browser print dialogs as supported generic paths. Raw ESC/POS, cash drawer control, and direct USB/serial device access require a separately approved manufacturer tool or local bridge and must be tested independently.
