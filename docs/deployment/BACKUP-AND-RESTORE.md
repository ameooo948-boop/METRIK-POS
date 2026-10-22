# Metrik POS — Database Backup and Restore (Windows)

## Backup

Run PowerShell from the project root, or pass `-ProjectRoot` explicitly:

```powershell
.\scripts\backup-metrik-database.ps1 -ProjectRoot 'C:\laravel\metrik-pos' -BackupDirectory 'D:\MetrikPOSBackups' -RetentionDays 30
```

Supported `.env` connection types are `mysql`, `mariadb`, and `sqlite`. MySQL/MariaDB require `mysqldump` on `PATH`; SQLite requires `sqlite3` on `PATH`. The script reads connection settings locally and does not print the password. Backups are written outside the project by default to `%USERPROFILE%\MetrikPOSBackups`; copy them to a protected off-device location as part of the real backup policy.

Run backups on a schedule and monitor failures. A backup file is not enough: restore it periodically in a separate test database/machine and verify records and application workflows.

## MySQL/MariaDB restore

1. Confirm the backup and target DB name. Stop application writes and queue workers or use an isolated staging target.
2. Take a separate backup of the current target before restoration.
3. In a **Command Prompt** (not PowerShell), use MySQL client tools and redirect the dump into the intended database:

```bat
mysql.exe --host=127.0.0.1 --port=3306 --user=YOUR_DB_USER YOUR_DB_NAME < "D:\MetrikPOSBackups\metrikpos-YYYYMMDD-HHMMSS.sql"
```

Enter the database password when prompted, or use a protected client option file. Do not put a password on the command line or commit it to a script. This command imports into the target database; it does not create the database for you.

## SQLite restore

1. Stop PHP's web server and all queue workers.
2. Use `sqlite3`'s `.backup` command to create a safety copy of the current database, then replace the database file with the `.sqlite` backup.
3. Handle any `-wal` and `-shm` sidecar files only while all connections are stopped; do not overwrite a database that is still receiving writes.
4. Restart the application and verify login, product counts, recent sales, stock movements, and reports.

## Important safety rules

- Do not run `migrate:fresh` against a database with business data.
- Never restore directly over production as an experiment; demonstrate restore in an isolated environment first.
- Database backups do not automatically include files stored outside the database (e.g. report exports or uploaded files). Back up required private storage separately and test those restores too.
- Protect backups as sensitive business data and encrypt them at rest and in transit when moved off-device.
