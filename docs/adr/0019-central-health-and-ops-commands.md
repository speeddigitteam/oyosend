# ADR 0019 — Platform health probes and operator commands live in `App\Platform`, outside tenant context

- **Status:** Accepted
- **Date:** 2026-10-13
- **Phase:** 13 (production hardening, in progress)

## Context

Running one shared database for every workspace needs answers that no workspace page can give: is the instance alive,
is it ready to take traffic, is the scheduler running (billing and every app job depend on it), is the queue backed
up, can a backup actually be restored, and is a list query still using its index. These answers are platform-wide, so
they must not run in a tenant context, must not leak tenant data, and must not become a way to reach a database they
were not pointed at. Until Phase 13 the only check was the Super Admin dashboard's `SystemHealth::checks()` (database,
cache, queue, failed jobs).

## Decision

1. **Two probes, outside the `web` group.** Registered in `bootstrap/app.php` on every host with no session, cookie,
   tenant or CSRF middleware:
   - `GET /health` (`health.live`): `{"status":"ok"}` to anyone. Liveness only. The framework's `GET /up` also exists.
   - `GET /health/ready` (`health.ready`, `throttle:60,1`): readable only from an address in `HEALTH_ALLOWED_IPS`
     (default loopback, CIDR allowed) or with `Authorization: Bearer {HEALTH_TOKEN}` (constant-time compare). Anyone
     else gets **404**, so the endpoint's existence is not disclosed.

2. **Readiness is a fixed set of checks with a critical/advisory split.** `SystemHealth::readiness()` returns
   `database`, `cache`, `queue` (critical), `redis` (critical when cache, queue or session uses it), `storage` (write
   and read back on the `local` disk), `migrations` (any migration file, module paths included, not yet run; critical),
   and the advisory `scheduler`, `queue_backlog` and `failed_jobs`. Status is `unavailable` and HTTP 503 when a
   critical check fails, `degraded` with 200 when only an advisory one does, and `?strict=1` makes advisory failures
   503. An exception becomes the generic detail `check failed`; the exception goes only to the log. The body holds
   platform-wide counts and service names, no tenant names or ids and no credentials.

3. **The scheduler proves itself.** A named closure `platform:scheduler-heartbeat` (`Schedule::call`, every minute,
   not an Artisan command) writes a timestamp to the central cache (`cache.central`, never a tenant-prefixed store).
   The `scheduler` check fails when it is missing or older than `HEALTH_SCHEDULER_MAX_AGE` (default 180 s). Because
   billing and every app's scheduled job depend on the scheduler, this is the one operational signal a deployment
   cannot lack.

4. **Operator commands are namespaced `ops:` and refuse to touch the wrong database.**
   - `ops:verify-backup {dump}` restores a mysqldump into a scratch database it creates itself. The name must end in
     `_restore_check`, must not exist and must not be the application database. Before executing anything it refuses
     a dump containing `USE`, `CREATE|DROP DATABASE|SCHEMA`, `SOURCE`, `SYSTEM` or a `\!` client command (also inside
     version comments). It streams `.sql` or `.sql.gz` into the `mysql` client with the password in `MYSQL_PWD`,
     checks that `migrations`, `users`, `tenants` and `tenant_memberships` exist, prints core row counts (compared
     with `--source` when given, read-only), and drops the scratch database only if this run created it.
   - `ops:benchmark` seeds synthetic contacts and tickets into a workspace of a `*testing` database, times key list
     queries and prints `EXPLAIN` for each. It refuses production and any other database name.

5. **Structural audits are tests, not documents.** `QueueDefinitionsTest` (every queued class names a queue a Horizon
   supervisor works, explicit tries, timeout under the supervisor's, backoff, tenant context),
   `TenantIndexesTest` (every `tenant_id` table has an index led by `tenant_id` and every unique index leads with it,
   with a reasoned exemption list), `SecurityHeadersTest` and `VerifyBackupCommandTest` run in the suite and fail when
   a later module violates the rule.

## Consequences

- A load balancer or monitor gets a cheap liveness probe and an IP- or token-gated readiness report without a session.
  Behind a proxy `TRUSTED_PROXIES` must be set first, otherwise the IP gate sees the proxy's address.
- The readiness body names the database and the cache and queue drivers; it is therefore gated, not public.
- A backup is proven restorable (core tables present and, with `--source`, equal in row count), not proven correct:
  no migration, no decryption with the restored key and no application boot is performed. Timings recorded in
  `docs/BACKUP_RESTORE.md` are one Windows machine with a busy MySQL.
- Nothing is scheduled for backups; the application ships a verifier, not a backup system. Retention, RPO and RTO are
  not defined.
- `ops:benchmark` measures the database only (no HTTP, Redis or queue). It found no missing index and one
  PHP-bound report (`docs/PERFORMANCE.md`); it is a baseline, not a capacity statement.
- None of this has run on Linux, behind a real proxy, against a down Redis or under Horizon. Per-tenant queue
  concurrency and fairness are not measured (`docs/DEPLOYMENT.md` section 12).

## Alternatives considered

- **A public readiness endpoint with the full report.** Rejected: it would disclose infrastructure names.
- **Expose health only on the Super Admin panel.** Rejected: a load balancer cannot hold a platform session or 2FA.
- **A third-party monitoring or backup package.** Not adopted: a new dependency needing the licence and Laravel 13
  check, and none was needed for these four probes and two commands.
- **Restore into the application's database name or a database the operator names freely.** Rejected: one wrong
  argument would destroy data; a mandatory `_restore_check` suffix on a database the command itself creates removes
  the case.
- **Heartbeat as a database row.** Rejected: a write per minute to the shared database for a value the central cache
  holds; the cache's absence correctly reads as "no heartbeat".
