# Metrik POS Security Review — Stage 15

This patch adds conservative web-response security headers and regression tests for selected tenant-isolation and least-privilege boundaries. It is not a substitute for a professional penetration test.

## Changes

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy` disables camera, microphone, and geolocation in this application
- Authenticated GET/HEAD web responses are marked `private, no-store` to reduce risk of private application data being retained by shared browser/proxy caches
- Feature tests verify foreign-organization sale-detail and report-export access is denied, and a cashier cannot open reports/expenses

No generic `Content-Security-Policy` is imposed: Inertia/Vite/PWA deployments need a carefully configured nonce/hash strategy; an unverified generic policy may break assets or application behavior.

## Manual review checklist before production

- Verify all authenticated resource queries are scoped by the active organization and branch where appropriate.
- Verify roles cannot escalate privileges by changing request payloads, route IDs, or active organization/branch session values.
- Verify backups can be restored and secrets are absent from Git.
- Use HTTPS, secure session cookies, production error pages, and a production-safe logging level.
- Review dependency advisories and test the exact deployed commit.
- Test offline-device loss and pending-sale handling; IndexedDB is not a secure vault and must not contain payment credentials or unnecessary customer data.
- Configure mail/payment integrations with least-privilege credentials and validate incoming webhooks.
