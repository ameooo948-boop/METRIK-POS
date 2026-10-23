# Metrik POS logo refresh

This patch introduces a custom blue-gradient M mark and a consistent horizontal wordmark for the light marketing/authentication UI, plus a light wordmark variant for the dark legacy dashboard header. The PWA app icons and favicon are updated to match. The Service Worker static cache version is bumped so its fallback pages and brand assets are refreshed.

## Apply
1. Merge the archive into the project root, preserving its directory paths.
2. Run `php artisan optimize:clear`.
3. Run `npm run build`.
4. Open the landing page, login/register flow, dashboard, and offline screens at both desktop and mobile sizes.
5. If an older logo remains, unregister the Service Worker or use DevTools → Application → Storage → Clear site data, then reload.

No database changes or migrations are included.
