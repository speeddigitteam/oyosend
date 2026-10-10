# ADR 0004 — Local environment and toolchain

- **Status:** Accepted. The Sail setup is **unverified** (see Consequences).
- **Date:** 2026-10-08
- **Phase:** 1

## Context

The spec locks Laravel Sail (Docker) with MySQL 8, Redis and Mailpit for local development, `*.localhost`
subdomains, Pest for backend tests, and requires every package's Laravel 13 compatibility to be checked before it
is installed.

Environment check on the development machine (2026-10-08, Windows 10, everything through Laragon):

| Tool | Version | | Tool | Version |
|---|---|---|---|---|
| PHP | 8.4.13 | | MySQL | 8.0.30 |
| Composer | 2.8.5 | | Redis | 5.0.14 |
| Node | 24.19 | | Docker | **not installed** |

Compatibility verified against Laravel 13 on Packagist before installing (MIT unless noted):

| Installed | Version | | Installed | Version |
|---|---|---|---|---|
| stancl/tenancy | 3.10.1 | | laravel/horizon | 5.50 |
| spatie/laravel-permission | 8.3 | | laravel/scout | 11.9 |
| inertiajs/inertia-laravel | 3.5 | | laravel/sail (dev) | 1.68 |
| tightenco/ziggy | 2.6 | | pestphp/pest (dev) | 5.3 |
| predis/predis | 3.6 | | larastan/larastan (dev) | 3.13 |
| pragmarx/google2fa | 9.1 | | bacon/bacon-qr-code | 3.1 (BSD-2-Clause) |

Compatible but deliberately **not installed** until the phase that uses them: `laravel/sanctum` 4.3 (API tokens,
Phase 2+) and `laravel/reverb` 1.12 (realtime, Phase 8). Frontend packages are listed in ADR 0005.

## Decision

Keep Sail as the documented, committed local environment — `compose.yaml` (MySQL, Redis, Mailpit) is in the
repository and `.env.example` carries Sail values — and support running natively as an equal second path, because
that is the only one that could be exercised here. All Phase 1 verification ran natively on Laragon with
`php artisan serve` on `*.localhost:8000`.

1. **Horizon.** `laravel/horizon` requires `ext-pcntl` and `ext-posix`, which do not exist on Windows.
   `composer.json` declares both under `config.platform` so the package installs. Horizon itself runs only on
   Linux/Sail; on Windows the queue is worked by `php artisan queue:listen` (started by `composer dev`).
2. **Test database.** Tests run against a real MySQL database, `oyosend_testing`, not SQLite: `pdo_sqlite` is not
   enabled on this machine, production is MySQL, and the code uses MySQL-only SQL
   (`SUBSTRING_INDEX` in `AuditLogController`).
3. **Redis client.** `REDIS_CLIENT=predis` everywhere, so no `phpredis` extension is needed on Windows, CI or Sail.
4. **Subdomains.** Browsers resolve `*.localhost` to loopback on their own; no hosts-file edits and no `lvh.me`.

## Consequences

- **Nobody has run `sail up` on this project.** Treat `compose.yaml`, `docker/mysql/create-testing-database.sh`
  and the Sail values in `.env.example` as untested until someone with Docker confirms them. Known difference
  from what was verified: `compose.yaml` builds Sail's PHP 8.5 runtime and the `mysql:8.4` image, not
  PHP 8.4.13 and MySQL 8.0.30.
- `config.platform` makes Composer assume `pcntl` and `posix` exist on every machine. Any package that needs them
  will install on Windows and fail only when it runs.
- Horizon's supervisors (`config/horizon.php`) and its dashboard have not been run. `composer dev` covers the
  same queue names with a single listener.
- The database, Redis and mail hosts differ between the two paths (`mysql` / `redis` / `mailpit` under Sail,
  `127.0.0.1` and `MAIL_MAILER=log` natively), as does `APP_URL` (port 80 vs 8000). `README.md` lists both.
- `.github/workflows/ci.yml` is a third environment (PHP 8.4, MySQL 8.0, Redis 7, Node 22; Node is 24 locally).
  It has never run on GitHub, so it is as unverified as Sail.
- On Windows, Vitest has to be started from a path with an upper-case drive letter (`D:/…`, not `/d/…`).
- Composer reports `nette/schema` and `nette/utils` (transitive) as `BSD-3-Clause | GPL-2.0-only | GPL-3.0-only`.
  They are used under BSD-3-Clause; nothing GPL-only is installed.
