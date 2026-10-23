# Metrik POS — Home, Auth, Offline & Reports Hotfix

## Included
- Redesigned Arabic RTL landing page with a rotating interactive hero, local promotional images, feature cards, and clear registration/login actions.
- Refreshed shared authentication layout and Login, Register, Forgot Password, Reset Password, and Verify Email pages with a light palette, readable text, responsive layout, inline validation errors, and password visibility controls.
- Updated `public/offline.html` to a light color theme and added `public/offline-unavailable.html` for non-POS navigations that fail offline.
- Updated the service worker cache version and routing: `/pos` can use the offline cashier; other failed navigations show an explanatory offline page instead of raw text.
- Hardened reports index so it can still display the sales report if the `report_exports` table is missing. In that situation, export history is disabled and a clear migration notice is shown.

## Install
Extract/merge this ZIP into the Laravel project root (`C:\laravel\metrik-pos`) preserving original paths. Do not overwrite the whole project.

```powershell
cd C:\laravel\metrik-pos
php artisan optimize:clear
npm run build
php artisan test --filter=ReportsAndExpensesTest
```

If the reports page says the export queue table is missing, inspect migration status and apply database migrations safely:

```powershell
php artisan migrate:status
php artisan migrate --force
```

Do not run `migrate:fresh` on data you need to keep.

After a service worker update, open DevTools > Application > Service Workers, unregister the old worker once, then Application > Storage > Clear site data and reload with Ctrl+Shift+R. Verify `/pos` offline fallback and confirm `/reports` offline shows the new explanatory page.

## Reports 500 note
The migration-table check prevents a missing `report_exports` table from taking down the whole report page. Another database/query error can still produce HTTP 500. If it persists, capture the exact latest exception from `storage/logs/laravel.log` immediately after loading `/reports`; the status code alone cannot identify the root cause.

Promotional crops are local mockup imagery; they are not real customer or sales data. Offline fallback is read-only except the dedicated offline POS flow in `offline.html`.
