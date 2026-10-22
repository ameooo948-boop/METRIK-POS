# Stage 40 acceptance checklist

- [ ] New registration sends a verification notification.
- [ ] Unverified accounts are redirected away from workspace routes.
- [ ] A valid signed verification link verifies only its authenticated owner; invalid, expired, reused, or cross-user links fail safely.
- [ ] Verification resend and password-reset requests are rate limited.
- [ ] Password reset form, one-time token, expiry, password policy, and remember-token rotation work.
- [ ] Password recovery response does not expose whether an email address exists.
- [ ] Owners/managers can edit products without changing stock quantities or inventory movement history.
- [ ] Products can be deactivated and reactivated without hard deletion.
- [ ] Categories can be renamed and archived/reactivated; archived categories cannot be assigned to new products or imported rows.
- [ ] Suppliers can be edited and deactivated/reactivated; deactivated suppliers are excluded from new purchase invoices/orders.
- [ ] Cashiers cannot invoke catalog or supplier mutations directly.
- [ ] Cross-organization product, category, and supplier IDs return not-found and cannot be mutated.
- [ ] Existing product, sales, purchase, supplier-ledger, and report tests still pass.
- [ ] Production build succeeds; mail configuration and canonical HTTPS URL are verified on staging.
