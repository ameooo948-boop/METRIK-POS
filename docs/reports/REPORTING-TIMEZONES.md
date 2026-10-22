# Organization-local report dates

Metrik stores Laravel timestamps as UTC. A report's `from_date` and `to_date` are business-calendar dates interpreted in the current organization's configured `timezone`.

The report service converts the inclusive local date range into UTC start and exclusive-end boundaries before filtering `sales.sold_at` and `sale_returns.returned_at`. Its daily aggregates map each timestamp to the organization's local calendar day using UTC bounds for each day. This works without requiring MySQL timezone tables and handles timezone offset changes.

Date-only fields such as `expenses.expense_date` remain filtered as dates and are not converted from UTC. The report cache key includes the organization's timezone. A successful checkout and customer account collection now emit `OperationalReportDataChanged` so the existing cache invalidation listener refreshes totals and payment-method summaries.

Regression scenario: with the server clock at `2026-10-22 21:30:00 UTC` and organization timezone `Africa/Cairo`, the organization-local date is `2026-10-23`. A sale at that instant must be included in the report for local date `2026-10-23`, not lost because its stored UTC date is still `2026-10-22`.
