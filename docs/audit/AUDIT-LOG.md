# Metrik POS — Audit Log

## What is recorded

The audit observer records `created`, `updated`, and `deleted` events for the allow-listed Eloquent business models registered in `AppServiceProvider`. It includes the current authenticated actor, organization, contextual branch when available, timestamp, source IP, user-agent, request ID, entity type/ID, changed field names, and a limited before/after snapshot.

Snapshots use an explicit allow-list of business fields. Credential fields, tokens, email addresses, phone numbers, addresses, free-text notes/descriptions, and similar sensitive fields are excluded from value snapshots. The team-membership middleware records role/access-management route actions because those changes are written to pivot tables and do not emit ordinary model events.

## Access and tenant isolation

`GET /audit-log` and `GET /audit-log/export` require the `viewAuditLogs` organization policy, currently limited to organization owners and admins. Both queries filter by the active organization before applying user-controlled filters. Branch filtering only narrows the active organization's records. The export applies the same filters and caps one export at 10,000 rows.

The interface offers search, event/entity/branch/date filters, pagination, before/after safe-value details, and CSV export. No web route is provided to edit or delete audit rows.

## Apply

1. Extract this patch at the project root, preserving relative paths.
2. Run `php artisan migrate` (do not run `migrate:fresh`).
3. Run `php artisan route:list --path=audit-log`.
4. Run `php artisan test --filter=AuditLogTest` and `npm run build`.
5. Run the full test suite and `php artisan metrik:health-check --strict`.

## Important coverage boundary

Eloquent model events are captured for the explicit allow-list, and team-membership pivot actions are logged by request middleware. Bulk `Model::query()->update/delete()` and raw query-builder or direct SQL changes do not emit model events and therefore are not recorded by this observer unless a caller adds an explicit audit write. For a compliance-sensitive deployment, review each critical write path and database permissions, and consider tamper-evident external retention. This table is application-level audit history, not cryptographically immutable evidence.
