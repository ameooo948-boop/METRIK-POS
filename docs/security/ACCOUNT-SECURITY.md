# Account security — Stage 40

## Email verification

- New registrations dispatch Laravel's `Registered` event. Since `User` implements `MustVerifyEmail`, Laravel sends the signed verification notification.
- Workspace routes are protected by `verified`; the notice, signed verification URL, resend endpoint, and logout remain available to an authenticated but unverified user.
- Verification URLs are signed, rate limited, and checked against both the authenticated user ID and the SHA-1 hash of the current email address.
- Resending a verification notification is rate limited.

## Password recovery

- The email request and reset endpoints are rate limited.
- Laravel's configured password broker controls token storage, expiration, and one-time use.
- The response for a reset-link request is intentionally generic to reduce account enumeration.
- A successful reset replaces the password and rotates the remember token, invalidating existing persistent-login cookies.
- Reset submissions require a confirmed password of at least eight characters with letters, mixed case, and numbers.

## Deployment checklist

1. Configure a production mailer and verify outbound mail delivery before enforcing email verification for customers.
2. Use HTTPS with a correct `APP_URL`; signed verification links depend on the canonical URL.
3. Keep the `password_reset_tokens` table migrated and protect the database and mail credentials.
4. Test expired, invalid, reused, and cross-user verification/reset links.
5. Review throttle limits against actual mail-provider quotas and abuse patterns.
