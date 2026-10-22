# Offline POS sync operations

## Queue states

- `pending`: waiting for network or a retry.
- `syncing`: a browser tab is sending the request. If that tab closes, the next sync can recover the entry after obtaining the scope lease.
- `needs_review`: server validation or a conflict requires a human decision. Later entries in the same sale scope stay queued behind the first conflict.
- `synced`: server response recorded locally. Keep records until the application's existing local retention/cleanup policy allows removal; never purge unsynced entries.

## Multi-tab behavior

`syncPendingOfflineSales` uses a lease record in IndexedDB's existing `meta` store, keyed by organization/branch/cashier/shift scope. Acquisition, renewal, and release use read-write IndexedDB transactions. The lease lasts 90 seconds and is renewed every 25 seconds while sync is in progress. This prevents ordinary duplicate work from two tabs on the same browser profile. The server's UUID idempotency is still required because a sleeping tab or browser crash can outlive the lease.

If the UI says that another tab is syncing, do not force-clear the lease. Wait for the other tab to finish, or wait for the lease expiry after confirming the other tab is closed.

## Recovery

1. Restore network access and authenticate into the same organization, branch, cashier, and shift context.
2. Open POS and select **مزامنة الآن**.
3. Resolve `needs_review` entries by comparing the physical till, receipt/local reference, current price, product stock, and original shift. Do not silently change UUIDs or mark entries synced.
4. If an operation appears locally pending but was already accepted by the server, retry the same UUID and identical payload first; server idempotency should return the saved sale.
5. Confirm central sales, inventory movements, cash movements, and local queue states before closing the shift.

Do not put real customer information in bug reports. Export only the minimum diagnostics needed, and keep browser profile/site data intact until all offline entries are reconciled.
