# Backup and restore

This page records what has been exercised and what has not. Nothing here has been run against a production
database, a managed MySQL service, S3 storage or point-in-time recovery. The application does not schedule or
take backups itself; it ships one verification tool (`ops:verify-backup`).

## 1. What a complete backup contains

| Data | Where | Notes |
|---|---|---|
| The database | MySQL, one shared database for every tenant | Sessions, queue-related tables (`failed_jobs`), audit trail and billing included |
| Tenant files | `storage/app/private/tenants/{tenant_id}/` (local) or the object-storage bucket | Reached only through `TenantStorage`; the database holds paths, not content |
| `APP_KEY` (and `APP_PREVIOUS_KEYS`) | Environment | Encrypted columns (2FA secrets, App Store settings, social tokens, workflow webhook secrets, AI proposals, messaging and AI provider account secrets, encrypted queued payloads) are unreadable without it. Payment gateway credentials are not in the database at all. Store it apart from the database backup; the dump itself is secret-bearing |
| `.env` | Server | Not in git |
| Redis | Queue and cache | Not a system of record: pending and delayed jobs are lost with it, failed jobs are in MySQL |

## 2. Taking a database backup

```bash
mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 \
  -h "$DB_HOST" -u "$DB_USERNAME" -p "$DB_DATABASE" | gzip > oyosend-$(date +%F).sql.gz
```

- Name the database as the last argument, **without** `--databases`/`--all-databases`: those add `CREATE
  DATABASE` and `USE`, and `ops:verify-backup` refuses a dump that contains them.
- `--single-transaction` gives a consistent InnoDB snapshot without locking. Add binary logs
  (`log_bin`, `binlog_expire_logs_seconds`) if point-in-time recovery is needed; that was not set up or tested.
- Copy the dump off the server and keep it encrypted; it contains every tenant's data.
- Retention, schedule, RPO and RTO targets are **not defined** by this project. Decide them before launch.

## 3. Verifying a backup: `ops:verify-backup`

```bash
php artisan ops:verify-backup /path/to/oyosend-2026-10-12.sql.gz
php artisan ops:verify-backup dump.sql --source=oyosend      # also compare counts with a live database
php artisan ops:verify-backup dump.sql --database=acme_restore_check --keep
```

`app/Platform/Console/VerifyBackupCommand.php`. What it does, in order:

1. Refuses unless the scratch name is letters, digits and single underscores ending in `_restore_check` (no leading,
   trailing or doubled underscore before the suffix; at most 64 characters; default `oyosend_restore_check`), is not
   the application's database, and does not exist yet. It also refuses a connection that is not `mysql`, and a
   `--source` that is not a plain database name. A database that already has that name is **never
   touched**, not even dropped.
2. Reads the whole dump once and refuses it if it holds `USE`, `CREATE/DROP DATABASE|SCHEMA` (also inside a
   `/*!40000 ... */` version comment), `SOURCE`, `SYSTEM` or any backslash client command (`\!`, `\.`, `\u` ...)
   anywhere outside a string literal, including after a `;` in the middle of a line, because the mysql client
   would follow those out of the scratch database or run commands. The restore is also started with
   `--binary-mode`, which makes the client ignore its backslash commands, so a statement the scan missed still
   cannot shell out. The scan tracks string literals (also across lines); a line it cannot scan is refused.
3. Creates the scratch database over its own PDO connection and streams the dump (`.sql` or `.sql.gz`) into the
   `mysql` client (`--mysql-bin`, `MYSQL_BIN` or `platform.ops.mysql_bin`; the password goes in `MYSQL_PWD`,
   not on the command line). The application's own connection is never used and `migrate:fresh` is not involved.
4. Counts base tables, fails if `migrations`, `users`, `tenants` or `tenant_memberships` is missing or the
   `migrations` table is empty, and prints the row counts of the core tables. With `--source` each count is
   compared with the live database (read-only `COUNT(*)`); any difference fails the run.
5. Drops the scratch database in a `finally` block (not with `--keep`), only because this run created it.

Run it with a restore account that is granted privileges **only** on `*_restore_check` databases (`CREATE`,
`DROP`, `INSERT`, ... on `` `%\_restore\_check`.* ``) plus read on the `--source` database if you use that option,
not with the application's own account: a malicious dump then has nothing else to damage even if the scan were
bypassed. Point `--connection` at a connection configured with that account.

Exit code 0 means "restored and the core tables are present (and equal to `--source`)". It does **not** prove
that application behaviour is intact: it runs no migrations, boots no tenant and does not decrypt anything.
Row counts of tables outside the core list (for example `support_tickets`) are restored but not compared.

## 4. Restoring for real (procedure, not rehearsed end to end)

1. Put the application in maintenance mode (`php artisan down`), stop Horizon, stop the scheduler.
2. Restore the dump into a **new** database and run `ops:verify-backup` against that same dump first.
3. Point `DB_DATABASE` at the restored database, restore `APP_KEY`/`.env`, and restore tenant files.
4. `php artisan migrate --force`, `permissions:sync`, `apps:sync --prune`, `config:cache`.
5. Flush Redis cache (`cache:clear`); queue contents are gone. Review `failed_jobs`.
6. `php artisan up`, restart Horizon and the scheduler; check `GET /health/ready`
   (`docs/DEPLOYMENT.md` section 8).
7. Sign in as a Super Admin and a workspace owner and open a few pages.

Restoring a single tenant out of the shared backup is not supported (see `docs/DATABASE.md` section 5).

## 5. What has actually been exercised

All on a developer machine: Windows 10, Laragon, MySQL 8.0.30, PHP 8.4.13, other databases and test runs
active on the same MySQL server at the same time. Timings are therefore pessimistic and noisy.

| Exercise | Result |
|---|---|
| Pest `VerifyBackupCommandTest`: refusals (bad name, application database, missing file, `USE`, `CREATE DATABASE`, versioned `DROP DATABASE`, `\!`, `SOURCE`), hand-written dump with quotes/backslashes restored and dropped, `.sql.gz`, missing required table fails and still drops, a pre-existing database of that name is left alone | Pass |
| Schema-only `mysqldump` of a fully migrated `*_testing` database (260 tables, 0.6 MB) restored by `ops:verify-backup --source=` | Passed; 553 s restore (table creation with foreign keys on a loaded server), scratch dropped |
| Fifteen core and data tables of a seeded `*_testing` database (20 000 contacts, 10 000 tickets, 8 MB) restored | Passed; 61 s; every core count matched the source; scratch dropped |

## 6. Not exercised

- A production-size dump, restore time at that size, or the memory behaviour of `mysql` on it.
- Binary-log point-in-time recovery, replication, managed-database snapshots.
- Restoring tenant files from a bucket or filesystem snapshot, or the match between files and database rows.
- Decrypting restored data with a restored `APP_KEY` (2FA secrets, credentials).
- `migrate`, `permissions:sync` and `apps:sync` after a restore, and the application running on the restored copy.
- A mysqldump from a different MySQL major version or from MariaDB.
- A restore that happens while the application is taking writes (the procedure above stops it first).
