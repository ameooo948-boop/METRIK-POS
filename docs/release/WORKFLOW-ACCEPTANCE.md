# Metrik POS — End-to-End Workflow Acceptance

Run these scenarios against a dedicated staging database with disposable test organizations. Never run destructive scenarios against live customer data.

## Scenario 1: First organization

1. Register a new owner.
2. Confirm an organization and its primary branch are created.
3. Create a second branch.
4. Add existing users as cashier, inventory manager, and accountant.
5. Verify each role can access only permitted screens and branches.

**Evidence to capture:** organization/branch IDs, role matrix results, and relevant test output. Do not capture passwords or secrets.

## Scenario 2: Stock receipt to sale

1. Create a category and a product with a unique SKU/barcode.
2. Record a purchase receipt for a supplier.
3. Confirm inventory balance and movement ledger.
4. Open a cashier shift with opening cash.
5. Sell part of the received stock and use cash payment.
6. Confirm receipt, sale items, payment, stock movement, and cash movement agree.
7. Retry the same sale UUID and verify idempotency.

## Scenario 3: Sale return

1. Return part of a completed sale.
2. Mark an item resellable and verify stock increases by the accepted quantity.
3. Return a damaged item without restocking it.
4. Verify refund amount, return state, inventory ledger, and cash expectation.
5. Retry the same return operation and verify no duplicate refund or stock movement.
6. Attempt to return more than the remaining eligible quantity and verify rejection.

## Scenario 4: Supplier settlement and purchase return

1. Create a purchase invoice with a partial payment.
2. Record a second payment and verify the outstanding amount.
3. Return a portion of eligible received stock.
4. Confirm inventory, supplier balance, and cash movement reconcile.
5. Attempt an overpayment/over-return and verify the configured policy is enforced.

## Scenario 5: Offline sale and retry

1. Install/load the PWA while online and synchronize the catalog.
2. Disable network access.
3. Create a supported cash sale and verify it appears as pending locally.
4. Restore network and synchronize.
5. Simulate a request timeout after server commit and retry the same UUID.
6. Verify a single central sale and one inventory decrement.
7. Simulate changed price/insufficient stock and confirm a conflict is visible, not silently discarded.

## Scenario 6: Organization boundary

1. Create Organization A and Organization B with different products and users.
2. As a user of A, try to access B's resource IDs for products, sales, exports, returns, suppliers, team members, notifications, and reports.
3. Repeat using manipulated request payloads and branch IDs.
4. Verify denied requests do not disclose private record contents.

## Scenario 7: Worker and report export

1. Request a report export.
2. Stop the worker and verify the job remains pending.
3. Start the worker and verify one export and one completion notification.
4. Retry a failed job and verify no duplicate user-visible records.
5. Try downloading the export as another user/organization and verify denial.

## Scenario 8: Backup and recovery

1. Create a backup using the project's backup script.
2. Restore it into a separate database/environment.
3. Run migrations/status checks as appropriate and validate key record counts and relationships.
4. Compare a small set of known balances and totals.
5. Record restore duration and any manual steps.

## Acceptance record

For each scenario record:

- Date and environment:
- Git commit:
- Tester:
- Result: PASS / FAIL / BLOCKED
- Evidence/log path (without secrets):
- Defect IDs and follow-up owner:
