# Deployment

**Nothing on this page has been run in production.** Phases 1 and 2 were verified only natively on Windows
(Laragon) with `php artisan serve`. Production hardening is Phase 13 (sections 8 and 10 to 12, `docs/BACKUP_RESTORE.md` and `docs/PERFORMANCE.md` record what
was exercised). Section 9 lists what is unverified.

Target stack (spec section 2): Nginx, PHP-FPM 8.4, MySQL 8, Redis, Horizon under Supervisor or systemd, cron,
S3-compatible storage, GitHub Actions.

## 1. Environment variables

Source of truth: `.env.example`. Values below are the ones that matter for this application.

| Variable | Production value | Notes |
|---|---|---|
| `APP_ENV` | `production` | Turns on mandatory platform 2FA, strict password rules, `DB::prohibitDestructiveCommands()`; `DemoSeeder` refuses to run |
| `APP_DEBUG` | `false` | |
| `APP_KEY` | generated once | Encrypts 2FA secrets, App Store settings, social tokens, workflow webhook secrets, AI proposals, messaging and AI provider account secrets, and queued mail that carries a token. Back it up outside the server. Rotate with `APP_PREVIOUS_KEYS` |
| `APP_DOMAIN` | `example.com` | Root domain. Read by `config/platform.php`, `config/tenancy.php`, `config/horizon.php` |
| `ADMIN_SUBDOMAIN` | `admin` (default) | Not in `.env.example`. Super Admin panel host label |
| `APP_URL` | `https://example.com` | Must be the central site, with scheme and port: workspace URLs are built from it (`TenantHost::url()`) |
| (timezone) | — | Not a setting: the application clock is always UTC (`config/app.php`). Each workspace has its own display timezone; the default for new ones is in `config/platform.php` |
| `DB_*` | MySQL 8 | `DB_CONNECTION=mysql` is also `tenancy.database.central_connection` |
| `SESSION_DRIVER` | `database` | The only driver that has been run |
| `SESSION_DOMAIN` | `null` | Keep null: sessions are host-only by design (ADR 0003) |
| `SESSION_SECURE_COOKIE` | `true` | A commented hint in `.env.example`; uncomment it wherever the site is served over HTTPS |
| `CACHE_STORE` | `redis` | Inside a tenant context every configured store is key-prefixed per tenant |
| `QUEUE_CONNECTION` | `redis` | Required by Horizon |
| `REDIS_CLIENT` | `predis` | The config default is `phpredis`; this project sets `predis` everywhere |
| `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT` | | |
| `REDIS_QUEUE_RETRY_AFTER` | > 300 | Default is 330 (`config/queue.php`). Keep it above the `background` supervisor's 300 s timeout (see section 4) |
| `CACHE_PREFIX`, `REDIS_PREFIX`, `HORIZON_PREFIX` | optional | Default to values derived from `APP_NAME`. Set them if two environments share a Redis |
| `SCOUT_DRIVER` | `database` | `config/scout.php` falls back to `collection` if unset |
| `MAIL_*` | real provider | Never `log` in production: it writes invitation and reset links to the log |
| `PLATFORM_REQUIRE_2FA` | — | Ignored in production; 2FA is always required there |
| `FILESYSTEM_DISK` | `local` | |
| `TENANT_FILESYSTEM_DISK` | `local` or `s3` | Disk for tenant files. `s3` needs `league/flysystem-aws-s3-v3`, which is **not installed** |
| `AWS_*` | | For the `s3` disk |
| `HORIZON_DOMAIN`, `HORIZON_PATH` | defaults | Default to the admin host and `horizon` |
| `BROADCAST_CONNECTION` | `log` (realtime off) or `reverb` | Realtime inbox updates (ADR 0035, section 4a). Anything but `reverb` with every `REVERB_*` value set is "off": pages poll |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | random; the same values on the Reverb server | Only with `reverb`. The secret signs channel subscriptions and never reaches a browser; the key and the id are letters and digits |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | e.g. `127.0.0.1`, `8080`, `http` | Where the application publishes events to Reverb's HTTP API (from the queue worker) |
| `REVERB_CLIENT_HOST`, `REVERB_CLIENT_PORT`, `REVERB_CLIENT_SCHEME` | e.g. `ws.example.com`, `443`, `https` | Where browsers open the websocket; default to the three above. Also added to the CSP `connect-src` |
| `VITE_APP_NAME` | | Read at build time |
| `TRUSTED_PROXIES` | the proxy's address(es), or `*` | `config/trustedproxy.php`. Required behind a load balancer: without it the audit trail and throttles see the proxy's address and generated URLs may use `http` |
| `SECURITY_CSP` | `report-only`, then `enforce` | The Content-Security-Policy is sent report-only until it has been watched in a browser (`docs/SECURITY.md` section 5b) |
| `HEALTH_ALLOWED_IPS` | empty (default: the bearer token is required); or the monitor/LB addresses or CIDR blocks | Who may read `GET /health/ready` (section 8). IP allow-listing needs a correct `TRUSTED_PROXIES`: behind a same-host reverse proxy every caller appears as `127.0.0.1`, so never list loopback there; use the token |
| `HEALTH_TOKEN` | empty (off) or a long random string | Alternative to the IP list: `Authorization: Bearer {token}` |
| `HEALTH_SCHEDULER_MAX_AGE`, `HEALTH_QUEUE_BACKLOG` | `180` s, `1000` jobs | Thresholds of the scheduler-heartbeat and queue-backlog checks |
| `MYSQL_BIN` | `mysql` | The mysql client `php artisan ops:verify-backup` uses (`docs/BACKUP_RESTORE.md`) |
| `AAMARPAY_STORE_ID`, `AAMARPAY_SIGNATURE_KEY`, `AAMARPAY_SANDBOX` | merchant credentials; `false` for live | Empty = the gateway is off. BDT only. **Never run against its sandbox: verify before going live** |
| `ANTHROPIC_API_KEY`, `ANTHROPIC_BASE_URL`, `AI_ANTHROPIC_MODEL` | optional | The AI Assistant's platform credential **fallback**, used only while no operator AI provider account exists (`docs/AI.md` section 9). Default model `claude-sonnet-5-5`, base URL `https://api.anthropic.com`. Without a key or an account the app says `not_configured` |
| `AI_PROVIDER`, `AI_TIMEOUT_SECONDS`, `AI_MAX_OUTPUT_TOKENS`, `AI_MAX_TURN_TOKENS`, `AI_TENANT_RPM`, `AI_MEMBER_RPM`, `AI_REDACTION_DEFAULT` | defaults (`anthropic`, 60, 1024, 20000, 60, 20, `false`) | Cost and rate guardrails (`config/ai.php`). `AI_PROVIDER=fake` is honoured only in `local` / `testing` |
| `SOCIAL_FACEBOOK_CLIENT_ID`, `_CLIENT_SECRET`, `_API_VERSION`; `SOCIAL_LINKEDIN_*` (same three, version `YYYYMM`); `SOCIAL_X_CLIENT_ID`, `_CLIENT_SECRET` | the operator's platform apps | Social adapters (`config/social.php`). Empty = that platform shows "not configured". Never stored in the database |
| `SOCIAL_REDIRECT_BROKER` | `true` (default) | One redirect URI for every workspace: `https://APP_DOMAIN/social/oauth/callback/{platform}`. Register that URI with each platform app. `false` falls back to per-workspace URIs |
| `COMMUNICATION_*` entries | optional | Only the names you reference from `config/communication.php` `credentials` (for example `COMMUNICATION_SMS_MAIN_SECRET`, `COMMUNICATION_WHATSAPP_TOKEN`, `COMMUNICATION_WHATSAPP_APP_SECRET`, `COMMUNICATION_WHATSAPP_VERIFY_TOKEN`). The alternative is an operator provider account in the Super Admin panel (ADR 0013); both work together |
| `REGISTRATION_MODE` | `open`, `approval` or `closed` | Default only; the panel's Settings page overrides it |
| `BILLING_ISSUER_NAME`, `_ADDRESS`, `_EMAIL`, `_TAX_ID` | the company that issues invoices | Printed on every invoice |
| `BILLING_INVOICE_PREFIX` | `INV` (default) | Invoice numbers are `{prefix}-{year}-{6 digits}` |
| `SSLCOMMERZ_STORE_ID`, `SSLCOMMERZ_STORE_PASSWORD`, `SSLCOMMERZ_SANDBOX` | merchant credentials; `false` for live | Empty = the gateway is off. **Run it against the sandbox before going live: it never has been** |

Settings that are code, not environment (`config/platform.php`): reserved subdomains; tenant defaults (`en`,
`Asia/Dhaka`, `BDT`); interface locales (`en`, `bn`); recovery codes (8); 2FA challenge lifetime (600 s);
2FA lockout (10 wrong codes, 900 s); invitation lifetime (7 days); workspace handoff lifetime (60 s); sign-up
link lifetime (24 h); support session length (30 min); the currencies a workspace can choose. Grace days (7),
invoice lead days (7), the offline payment instructions and the registration mode start from that file and are
then changed in the panel (stored in `platform_settings`).

## 2. DNS and TLS

- Point `APP_DOMAIN` and `*.APP_DOMAIN` at the web tier. Tenants and the admin panel are all one label deep, so
  one wildcard covers them.
- The certificate needs both names: `example.com` and `*.example.com` (a wildcard does not cover the apex).
  Public CAs issue wildcards only through a DNS-01 challenge.
- If `APP_DOMAIN` is itself a subdomain (`app.example.com`), the wildcard is `*.app.example.com`.
- Custom tenant domains are not supported.

## 3. Nginx (sketch)

One server block serves every host. The `Host` header must reach PHP unchanged: the application picks the area
and the tenant from it.

```nginx
server {
    listen 80;
    server_name example.com *.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name example.com *.example.com;

    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;

    root /var/www/oyosend/current/public;
    index index.php;
    client_max_body_size 20m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ ^/index\.php(/|$) {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

- Add a catch-all default server that drops other hostnames, so arbitrary `Host` headers never reach PHP.
- If TLS ends on a load balancer in front of Nginx, set `TRUSTED_PROXIES`. Without it client IPs in the audit
  log and the login throttle are the proxy's, HSTS is not sent, and generated links may use `http`.
- The application sends its own security headers (`docs/SECURITY.md` section 5b); do not add a second,
  different Content-Security-Policy here.
- Payment gateways call back to `https://{tenant}.APP_DOMAIN/billing/payments/{reference}/return` and
  `/billing/gateways/{gateway}/notify`. Both must be reachable from the provider's servers; do not put them
  behind an IP allow-list that excludes it, or basic auth.

## 4. Queue workers

Horizon (`config/horizon.php`) runs two supervisors on the `redis` connection:

| Supervisor | Queues | Timeout | Memory | `maxProcesses` (production / local) |
|---|---|---|---|---|
| `interactive` | `notifications`, `webhooks`, `default` | 60 s | 128 MB | 10 / 2 |
| `background` | `billing`, `automation`, `campaigns`, `ai` | 300 s | 256 MB | 10 / 2 |

Both auto-balance (`time` strategy) with 3 tries, and every queue has a long-wait threshold (`waits`: 60 s for `default`, `notifications` and `webhooks`; 120 s for `billing`, `automation` and `ai`; 300 s for `campaigns`). Run one `php artisan horizon` per server under Supervisor or
systemd, and `php artisan horizon:terminate` on every deploy so workers pick up new code.

```ini
[program:oyosend-horizon]
command=php /var/www/oyosend/current/artisan horizon
user=www-data
autostart=true
autorestart=true
stopwaitsecs=360
stdout_logfile=/var/www/oyosend/shared/storage/logs/horizon.log
redirect_stderr=true
```

- Dashboard: `https://admin.APP_DOMAIN/horizon`, super admins with 2FA only.
- `REDIS_QUEUE_RETRY_AFTER` (default 330 s) must stay above the longest worker timeout (300 s, the `background`
  supervisor); below it, a job still running is handed to a second worker. Raise both together.
- Horizon needs `ext-pcntl` and `ext-posix` (Linux). Without it:
  `php artisan queue:work redis --queue=notifications,webhooks,default,billing,automation,campaigns,ai`.
- Every queued class under `app/` declares its queue (`onQueue()`), `$tries`, `$timeout` (never above its
  supervisor's worker timeout: 60 s on `notifications`/`webhooks`/`default`, 300 s on the rest) and, when it
  retries, `$backoff`; every one except the central sign-up mail implements `RequiresTenantContext`. The one
  exception to the explicit values is `RunScheduledAppJob`, which copies them from the compiled module job it runs.
  `tests/Unit/Hardening/QueueDefinitionsTest.php` enforces this, that all seven queue names in CLAUDE.md are
  worked by a supervisor, and that supervisor timeouts stay below `REDIS_QUEUE_RETRY_AFTER`.
- Failed jobs go to the `failed_jobs` table; on the Super Admin dashboard any failed job makes that health check
  fail and shows the count.

## 4a. Realtime (Reverb), optional

The unified inbox updates at once while a Reverb server runs (ADR 0035); without it the inbox polls every 15/20
seconds and nothing else changes. **The Reverb server package is not installed in this application yet**:
`laravel/reverb` 1.12.0 requires PSR-7 2 and would downgrade Guzzle 8 to 7 (the open decision in ADR 0035). The
application side (publishing, the signed channel, the page client) is complete and speaks the Pusher protocol to any
Reverb server with the same app id, key and secret.

Once Reverb is installed (`composer require laravel/reverb`, then `php artisan vendor:publish --tag=reverb-config`;
the server process listens on `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT`, e.g. `127.0.0.1` / `8080`):

- run `php artisan reverb:start` under the process supervisor like the queue workers, and restart it on deploy
  (`php artisan reverb:restart`);
- put it behind the TLS proxy on its own host name, and point browsers there with `REVERB_CLIENT_*`:

```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name ws.example.com;
    # certificates as above

    location / {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_read_timeout 120s;
        proxy_pass http://127.0.0.1:8080;
    }
}
```

- the application publishes with `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8080`, `REVERB_SCHEME=http` (from the queue
  workers: the signal is a job on `notifications`), so Reverb's HTTP API need not be public;
- `php artisan config:cache` after changing any `REVERB_*` value. Realtime is on only when every value is present and
  well formed (`App\Core\Realtime\Realtime`); otherwise `/broadcasting/auth` answers 404 and pages poll.

Not verified: no Reverb server has been run against this application (ADR 0035, "Not verified").

## 5. Scheduler

```cron
* * * * * cd /var/www/oyosend/current && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled in `routes/console.php`:

| Command | When | Does |
|---|---|---|
| `billing:run` | hourly | Advances base-plan and separately billed app subscriptions: invoices, past due, grace, expiry and cancellation. **Billing does not move without the scheduler** |
| `apps:run-scheduled` | every minute | Enters each accessible workspace and dispatches due compiled module jobs only when entitled; the worker re-checks the compiled definition and entitlement before execution |
| `platform:scheduler-heartbeat` | every minute | Not an Artisan command: a named closure (`Schedule::call(...)->name(...)`) that writes the time to the central cache; `GET /health/ready` reports its age (section 8) |
| `model:prune` (sign-ups) | daily | Removes registrations whose confirmation link expired |

`billing:run` is safe to run by hand and to run twice; `--tenant=acme` limits it to one workspace. Horizon's
metrics graphs stay empty until `horizon:snapshot` is scheduled. `apps:run-scheduled` also accepts `--tenant`;
the scheduler uses `withoutOverlapping`, and a central-cache tenant/module/job/time-slot key makes repeated runs
idempotent. Its every-minute/hourly/daily frequencies are evaluated in each tenant's timezone.

## 6. Deploy sequence

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan permissions:sync          # every deploy; idempotent
php artisan apps:sync --prune          # after providers and migrations are live; idempotent
php artisan config:cache
php artisan route:cache               # untested with this app, see section 9
php artisan view:cache
php artisan horizon:terminate
```

- `permissions:sync` writes the permissions declared by module manifests into `permissions`. Skipping it after a
  release that adds permissions makes role editing fail with "The permission catalogue is out of date".
  Use `--prune` only on purpose: it deletes undeclared permissions with all their grants in every tenant.
- `apps:sync --prune` mirrors the optional manifests compiled into this release. It adds an immutable snapshot of
  versioned catalogue/contract fields, refuses changed versioned fields under an existing version, and
  unpublishes apps no longer in the build only when no live installation, active licence or live commercial item
  remains.
  Run it after migrations and permission sync. It never deletes installations, licences or tenant business data.
- Migrations all live in `database/migrations` and run against the one central database. The package's
  `tenants:migrate` is not used. Destructive commands (`migrate:fresh`, `db:wipe`, …) are blocked in production.
- `php artisan db:seed --force` in production syncs permission and compiled app catalogue reference data without
  pruning; demo data is local-only. Deployments still run the explicit `apps:sync --prune` step above.
- **First Super Admin:** `php artisan platform:create-admin {email} [--name=] [--role=super_admin|support_admin]`
  (`app/Platform/Console/CreatePlatformAdminCommand.php`). It must be run in an interactive terminal: the
  password is asked for and confirmed, never passed as an argument, and has to pass `Password::defaults()`
  (12+ characters, mixed case, numbers, uncompromised in production). The account enrols in two-step
  verification at its first sign-in. The creation is written to the audit trail (`platform.admin.created`).
- **First plans:** create them in the panel (Plans). Without a public, active plan, sign-up still works and
  the workspace simply has no subscription.
- **First tenant:** create it in the panel (Workspaces → Create workspace; the owner is mailed a link to choose
  a password), or let people sign up at `https://APP_DOMAIN/register` when registration is not `closed`.

### Operator commands (Artisan)

| Command | Use |
|---|---|
| `php artisan permissions:sync [--prune]` | After a release that adds permissions, and on every deploy |
| `php artisan apps:sync [--prune]` | Mirror compiled app manifests into the catalogue; every deploy |
| `php artisan apps:run-scheduled [--tenant=]` | Dispatch due app jobs; scheduled every minute |
| `php artisan billing:run [--tenant=]` | Advance base and app subscriptions; scheduled hourly |
| `php artisan platform:create-admin {email}` | First Super Admin; interactive |
| `php artisan ops:verify-backup {dump} [--source=] [--database=*_restore_check] [--keep]` | Restore a mysqldump into a scratch database and check core tables (`docs/BACKUP_RESTORE.md`) |
| `php artisan ops:benchmark [--rows=] [--tickets=] [--detail] [--cleanup]` | Time key list queries with `EXPLAIN`; refuses production and any database not named `*testing` (`docs/PERFORMANCE.md`) |
| `php artisan horizon:terminate`, `horizon:snapshot` | Framework; see section 4 and the checklist |

The `tenants:*` commands of the tenancy package (`tenants:migrate`, `tenants:run`, ...) are registered but are not part
of this application's workflow (one shared database).

## 7. Secrets

- `.env` lives on the server only (git-ignored), readable by the PHP user only.
- Losing `APP_KEY` makes every stored 2FA secret unreadable. Keep a copy outside the server.
- Database, Redis, mail and object-storage credentials come from the environment; none are in the repository.
- Demo passwords (`password`) exist only in `DemoSeeder`, which throws outside `local`.

## 8. Backups

Nothing is scheduled by the application: take dumps with your own cron or managed-database snapshots. What has
to be covered:

| Data | Where | Suggested |
|---|---|---|
| Database (all tenants, sessions, audit trail) | MySQL | Daily full backup plus binary logs for point-in-time recovery |
| Tenant files | `storage/app/private/tenants/{tenant_id}/` or the bucket | Bucket versioning or filesystem snapshot |
| `APP_KEY` and `.env` | Server | Secret store, separate from the database backup |
| Redis | Queue and cache only | Not a system of record; pending jobs are lost with it |

A dump can be proven restorable with `php artisan ops:verify-backup {dump}` (scratch database, row counts of the
core tables, dropped afterwards). Procedure, limits and what has actually been exercised:
`docs/BACKUP_RESTORE.md`. Restoring a single tenant from a shared-database backup is not possible without the
export tooling described in `docs/DATABASE.md` section 5.

### Health endpoints

| Endpoint | Who | Answers |
|---|---|---|
| `GET /health` | anyone, any host | `{"status":"ok"}` and nothing else (liveness: PHP serves the request). The framework's `GET /up` also exists |
| `GET /health/ready` | an address in `HEALTH_ALLOWED_IPS` (default none) or the `HEALTH_TOKEN` bearer; **404** for everyone else | The checks below as JSON; **503** when a critical one fails, 200 with `"status":"degraded"` when only an advisory one does; `?strict=1` makes advisory failures 503 too |

Registered in `bootstrap/app.php` on every host without session or tenant middleware. The report holds
platform-wide counts and service names only: no tenant names, ids or credentials. A failed check reports the generic detail `check failed`; the exception goes to the log only.

| Check | Critical | Fails when |
|---|---|---|
| `database` | yes | `select 1` fails |
| `cache` | yes | the put/pull round trip fails |
| `queue` | yes | the queue connection cannot report its size |
| `redis` | yes | cache, queue or session uses Redis and `PING` fails; reports `not used` otherwise |
| `storage` | yes | the `local` disk cannot write and read back a file |
| `migrations` | yes | any migration file (module paths included) is not in the `migrations` table; `count` = pending |
| `scheduler` | no | no heartbeat in the central cache, or older than `HEALTH_SCHEDULER_MAX_AGE`; `count` = age in seconds |
| `queue_backlog` | no | any Horizon queue holds more than `HEALTH_QUEUE_BACKLOG` jobs; `count` = total waiting |
| `failed_jobs` | no | `failed_jobs` is not empty |

`App\Platform\Support\SystemHealth::checks()` (database, cache, queue, failed jobs) is what the Super Admin
dashboard shows and was not changed; `readiness()` returns its first three plus the rows above. Not checked: mail,
payment gateways, object storage, Horizon's own process status.

## 9. Not yet production-verified

- Laravel Sail / `compose.yaml` (Docker is not installed on the development machine; ADR 0004).
- Horizon and its dashboard (cannot run on Windows).
- The Nginx block, wildcard TLS and anything behind a proxy.
- `config:cache` / `route:cache`. Module routes are registered in provider `boot()`
  (`ModuleServiceProvider::loadTenantRoutes()`) without a `routesAreCached()` check.
- The `s3` disk for tenant files, and signed URLs from it.
- A real mail provider, backups and restore.
- Session drivers other than `database`; cache stores other than `redis` (`array` and `database` are exercised
  by the test suite).
- `.github/workflows/ci.yml` has never run on GitHub (`docs/TESTING.md` section 6).
- Long-lived application servers (Octane): not used, and the tenant context is not ended after a 404 for an
  unknown workspace (`docs/SECURITY.md` section 8).
- The Content-Security-Policy in enforce mode; trusted proxies; HSTS (all need a real HTTPS deployment).
- SSLCommerz against its sandbox and live hosts; gateway callbacks arriving from outside.
- The scheduler under cron (`billing:run` was only run by hand and by the test suite), including the
  `platform:scheduler-heartbeat` that `/health/ready` reads.
- `/health/ready` behind a real proxy or load balancer (the client address it gates on depends on `TRUSTED_PROXIES`),
  against a Redis that is down, and on Linux. The `redis` check was only run against a local Redis that was up.
- Horizon's supervisors as configured (names, timeouts, balancing) were checked by a test of the config file only;
  Horizon never ran here (Windows has no `pcntl`).
- Backup and restore beyond the small drills in `docs/BACKUP_RESTORE.md` section 5; timings in
  `docs/PERFORMANCE.md` are one Windows machine.
- Playwright specs were not run in Phase 13.

## 10. Security headers, cookies, proxy and HTTPS (audit)

Audited in Phase 13; covered by `tests/Feature/Hardening/SecurityHeadersTest.php` unless noted.

| Item | State |
|---|---|
| `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` (camera, geolocation, payment off; microphone allowed to the workspace itself only) | Sent on every `web` response by `App\Core\Http\Middleware\SecurityHeaders`, on all three areas |
| `Strict-Transport-Security` | Only when the request arrived over HTTPS (behind a TLS-terminating proxy that needs `TRUSTED_PROXIES`). One year, `includeSubDomains`, no `preload` |
| Content-Security-Policy | `report-only` by default, `SECURITY_CSP=enforce` to block, `off` to disable. Per-response script nonce, `frame-ancestors 'none'`, `object-src 'none'`, `base-uri` and `form-action` self; the Vite dev server is allowed only while `npm run dev` runs. **There is no `report-uri`/`report-to`**: violations are visible only in a browser console, and the policy has not been watched in a real browser across every page |
| Session cookie | Host-only (`SESSION_DOMAIN` null), `HttpOnly`, `SameSite=Lax`, `Secure` defaults to true when `APP_ENV=production`. The test asserts configuration, not a `Set-Cookie` over real TLS |
| `/health`, `/health/ready` | Outside the `web` group: JSON, no cookies, no CSP header |

The header set was already complete, so no header was added. Cross-origin isolation headers (`COOP`, `COEP`, `CORP`)
are not sent; they were not evaluated against the payment redirects.

## 11. Production checklist

Per release; "once" items are first-install only. None of it has been executed against a production host
(section 9).

1. `.env` from `.env.example`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `APP_DOMAIN`, a generated
   `APP_KEY` (once; back it up outside the server), database, Redis, mail, `TRUSTED_PROXIES`,
   `SESSION_SECURE_COOKIE=true`, `SECURITY_CSP`, `HEALTH_ALLOWED_IPS` or `HEALTH_TOKEN`.
2. `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`.
3. `php artisan migrate --force`, then `php artisan permissions:sync`, then `php artisan apps:sync --prune`
   (read the `--prune` notes in section 6 first).
4. `php artisan storage:link` (once), only if public-disk assets are used; tenant files go through the application.
5. `php artisan config:cache` and `view:cache`. `route:cache` is untested (section 9).
6. Horizon under Supervisor or systemd (section 4) and `php artisan horizon:terminate` on deploy. Schedule
   `horizon:snapshot` yourself if you want its metrics graphs; the application does not.
7. Cron `* * * * * php artisan schedule:run` (section 5); `GET /health/ready` should show `scheduler` ok within
   three minutes.
8. First Super Admin and plans (section 6, once).
9. Backups scheduled, and one restore verified with `ops:verify-backup` (`docs/BACKUP_RESTORE.md`).
10. Monitor `/health` (liveness) and `/health/ready` (readiness) and alert on `failed_jobs`.

## 12. Per-tenant fairness (audit, gaps recorded)

One queue and worker pool serves every tenant, so fairness rests on per-tenant limits in the code that produces
work. What exists (found in code, not load-tested):

| Area | Per-tenant limit found |
|---|---|
| Campaigns | Email Marketing: `send_per_minute` per workspace, ceiling `email_marketing.send_per_minute_ceiling` (600). SMS Marketing: `throttle_per_minute`, daily cap and `batch_ceiling` (500) per run. WhatsApp Marketing: `chunk_size` 50 per job, no per-minute ceiling found. Communication: `communication.rate_limits` per channel per workspace. Usage limits through `UsageLimits` where a module declares one |
| Automation | `runs_per_minute_workflow` and `runs_per_minute_tenant` checked in `RunStarter`; the `automation.runs` usage limit; throttled inbound webhooks |
| AI | `ai.limits.tenant_per_minute` (60) and `member_per_minute` (20) in `AiGateway`, plus a token usage limit |

Gaps, recorded and not papered over:

- No per-tenant **concurrency** cap or tenant-fair dequeue: a tenant that legitimately fills `campaigns` or
  `automation` delays every other tenant on that queue. Horizon balances by queue, not by tenant.
- The limits above apply when work is produced; nothing bounds how many jobs one tenant may have waiting.
- `apps:run-scheduled` enqueues per entitled tenant per minute with no per-tenant cap on jobs per module.
- No test exercises these limits under queue contention.

