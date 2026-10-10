# Deployment

**Nothing on this page has been run in production.** Phase 1 was verified only natively on Windows (Laragon) with
`php artisan serve`. Production hardening is Phase 13. Section 9 lists what is unverified.

Target stack (spec section 2): Nginx, PHP-FPM 8.4, MySQL 8, Redis, Horizon under Supervisor or systemd, cron,
S3-compatible storage, GitHub Actions.

## 1. Environment variables

Source of truth: `.env.example`. Values below are the ones that matter for this application.

| Variable | Production value | Notes |
|---|---|---|
| `APP_ENV` | `production` | Turns on mandatory platform 2FA, strict password rules, `DB::prohibitDestructiveCommands()`; `DemoSeeder` refuses to run |
| `APP_DEBUG` | `false` | |
| `APP_KEY` | generated once | Encrypts 2FA secrets and queued invitation mail. Back it up. Rotate with `APP_PREVIOUS_KEYS` |
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
| `BROADCAST_CONNECTION` | `log` | No realtime yet |
| `VITE_APP_NAME` | | Read at build time |

Settings that are code, not environment (`config/platform.php`): reserved subdomains; tenant defaults (`en`,
`Asia/Dhaka`, `BDT`); interface locales (`en`, `bn`); recovery codes (8); 2FA challenge lifetime (600 s);
2FA lockout (10 wrong codes, 900 s); invitation lifetime (7 days); workspace handoff lifetime (60 s).

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
- If TLS ends on a load balancer in front of Nginx, configure trusted proxies in `bootstrap/app.php`
  (`$middleware->trustProxies(...)`). It is not configured today, so client IPs in the audit log and the login
  throttle would be the proxy's.
- Security headers (HSTS, CSP, frame options) are not set by the application; add them here until they are.

## 4. Queue workers

Horizon (`config/horizon.php`) runs two supervisors on the `redis` connection:

| Supervisor | Queues | Timeout | Memory | `maxProcesses` (production / local) |
|---|---|---|---|---|
| `interactive` | `notifications`, `webhooks`, `default` | 60 s | 128 MB | 10 / 2 |
| `background` | `billing`, `automation`, `campaigns`, `ai` | 300 s | 256 MB | 10 / 2 |

Both auto-balance (`time` strategy) with 3 tries. Run one `php artisan horizon` per server under Supervisor or
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
- Failed jobs go to the `failed_jobs` table; on the Super Admin dashboard any failed job makes that health check
  fail and shows the count.

## 5. Scheduler

```cron
* * * * * cd /var/www/oyosend/current && php artisan schedule:run >> /dev/null 2>&1
```

Nothing is scheduled yet (`routes/console.php` has only `inspire`). Horizon's metrics graphs stay empty until
`horizon:snapshot` is scheduled.

## 6. Deploy sequence

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan permissions:sync          # every deploy; idempotent
php artisan config:cache
php artisan route:cache               # untested with this app, see section 9
php artisan view:cache
php artisan horizon:terminate
```

- `permissions:sync` writes the permissions declared by module manifests into `permissions`. Skipping it after a
  release that adds permissions makes role editing fail with "The permission catalogue is out of date".
  Use `--prune` only on purpose: it deletes undeclared permissions with all their grants in every tenant.
- Migrations all live in `database/migrations` and run against the one central database. The package's
  `tenants:migrate` is not used. Destructive commands (`migrate:fresh`, `db:wipe`, …) are blocked in production.
- `php artisan db:seed --force` in production only syncs permissions; demo data is local-only.
- **First Super Admin:** `php artisan platform:create-admin {email} [--name=] [--role=super_admin|support_admin]`
  (`app/Platform/Console/CreatePlatformAdminCommand.php`). It must be run in an interactive terminal: the
  password is asked for and confirmed, never passed as an argument, and has to pass `Password::defaults()`
  (12+ characters, mixed case, numbers, uncompromised in production). The account enrols in two-step
  verification at its first sign-in. The creation is written to the audit trail (`platform.admin.created`).
- **First tenant:** there is still no command or screen to create a workspace outside the local demo seeder
  (Phase 2). Until then use `php artisan tinker` with `app(TenantProvisioner::class)->provision(...)`.

## 7. Secrets

- `.env` lives on the server only (git-ignored), readable by the PHP user only.
- Losing `APP_KEY` makes every stored 2FA secret unreadable. Keep a copy outside the server.
- Database, Redis, mail and object-storage credentials come from the environment; none are in the repository.
- Demo passwords (`password`) exist only in `DemoSeeder`, which throws outside `local`.

## 8. Backups

Nothing is automated or tested yet. What has to be covered:

| Data | Where | Suggested |
|---|---|---|
| Database (all tenants, sessions, audit trail) | MySQL | Daily full backup plus binary logs for point-in-time recovery |
| Tenant files | `storage/app/private/tenants/{tenant_id}/` or the bucket | Bucket versioning or filesystem snapshot |
| `APP_KEY` and `.env` | Server | Secret store, separate from the database backup |
| Redis | Queue and cache only | Not a system of record; pending jobs are lost with it |

Restoring a single tenant from a shared-database backup is not possible without the export tooling described in
`docs/DATABASE.md` section 5. A restore drill has not been done.

Health: `GET /up` (framework). The Super Admin dashboard runs live checks of database, cache, queue and failed
jobs (`App\Platform\Support\SystemHealth`; each returns `key`, `ok`, `detail`, `count`).

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
- The Super Admin panel renders in `APP_LOCALE` only; it has no language switch.
