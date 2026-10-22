# Stage 38 — Backup and Restore Acceptance Checklist

## Automated checks

- [ ] `php artisan metrik:backup` returns zero and prints a verified archive filename.
- [ ] The ZIP contains exactly one database artifact plus `manifest.json` and all configured file payloads.
- [ ] `manifest.json` is format version 2 and includes SHA-256 checksums for every payload file.
- [ ] `php artisan metrik:backup-check` returns zero while the latest backup is fresh.
- [ ] `php artisan metrik:backup-check` returns non-zero when the directory is empty or the latest archive is stale.
- [ ] A corrupted ZIP or changed payload fails verification.
- [ ] `php artisan metrik:restore <archive> --verify-only` succeeds for a valid archive.
- [ ] Restore extraction to a new private directory passes the payload checksum checks and SQLite `PRAGMA integrity_check` (or, for MySQL, the SQL dump is imported into a staging DB).
- [ ] The restore command does not overwrite the live database and rejects destination paths in `public/`.
- [ ] Retention keeps the configured number of timestamped verified archives and warns if expired files cannot be removed.
- [ ] A failed backup leaves no archive with a final `metrik-backup_*.zip` name.

## Operational checks

- [ ] Scheduler is running every minute on the actual host.
- [ ] Daily backup and hourly stale/integrity check appear in scheduler output/logs.
- [ ] Optional email failure alerts arrive at a real monitored address, or an external monitor detects non-zero exits/log events.
- [ ] Backup destination is private, writable, has enough capacity, and is not nested in paths included in the backup.
- [ ] At least one encrypted off-site copy exists; this command does not encrypt or upload archives by itself.
- [ ] Monthly restore-drill record includes archive ID, recovered record checks, RPO/RTO, operator, result, and corrective actions.

## Known limitations

- MySQL restore payload extraction validates archive and checksum integrity; SQL import into a staging database remains an explicit operator step.
- Encryption/off-site replication, disk-capacity alerting, and retention compliance across remote storage must be supplied by infrastructure/monitoring configuration.
- Do not mark this stage complete until a restore drill succeeds on the deployment target's PHP/database versions.
