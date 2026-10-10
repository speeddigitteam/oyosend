# Oyosend

A multi-tenant business SaaS platform: one workspace per company on its own subdomain, with apps (HRM, projects,
CRM, invoicing, marketing, support, automation) added through a built-in App Store. The full product
specification is [`MASTER_PROMPT.md`](MASTER_PROMPT.md).

**Current state: Phase 1 (Architecture & Foundation).** What exists is the foundation: subdomain tenancy with
fail-closed isolation, sign-in with two-factor authentication, memberships and invitations, tenant-scoped roles and
permissions, an audit trail, the module / entitlement / automation contracts, the design system and layouts, a
workspace dashboard and a Super Admin panel, in English and Bengali. No business app, billing or App Store
exists yet.

## Stack

| | |
|---|---|
| Backend | PHP 8.4, Laravel 13, MySQL 8, Redis (predis), Horizon, Scout (`database` driver) |
| Tenancy / RBAC | stancl/tenancy 3.10 (single database), spatie/laravel-permission 8 (teams mode) |
| Frontend | Inertia 3, Vue 3 (`<script setup lang="ts">`), TypeScript (strict), Tailwind CSS 4, vue-i18n, reka-ui |
| Quality | Pest 5, Vitest 5, Pint, Larastan (level 6), vue-tsc |

## Prerequisites

Either Docker (for Laravel Sail), or natively: PHP 8.4 with `pdo_mysql`, Composer 2, Node 24 with npm (CI uses
Node 22), MySQL 8 and Redis. On Windows, Laragon provides all of these; this is the setup the project has been
run on.

## Local setup, natively (verified)

```bash
composer install
php -r "copy('.env.example', '.env');"
```

Edit `.env`:

```dotenv
APP_URL=http://localhost:8000
DB_HOST=127.0.0.1
DB_USERNAME=root          # your MySQL credentials
DB_PASSWORD=
REDIS_HOST=127.0.0.1
MAIL_MAILER=log
```

Create two databases, `oyosend` and `oyosend_testing`, then:

```bash
php artisan key:generate
php artisan migrate --seed
npm install
composer dev
```

`composer dev` serves the app on `127.0.0.1:8000`, runs a queue listener and Vite. Browsers resolve `*.localhost`
to loopback, so no hosts-file edits are needed. Open the hostnames below, not `http://127.0.0.1:8000`: every
route is bound to a hostname, so the bare IP answers 404.

| Area | URL |
|---|---|
| Central site | http://localhost:8000 |
| Super Admin panel | http://admin.localhost:8000 |
| Workspaces | http://acme.localhost:8000 and http://globex.localhost:8000 |

With `MAIL_MAILER=log`, invitation and password-reset links are written to `storage/logs/laravel.log`.
Invitation mail is queued, so the queue listener has to be running.

## Local setup with Laravel Sail (not verified)

`compose.yaml` (MySQL, Redis, Mailpit) is committed and `.env.example` already carries Sail's values, but Docker
is not installed on the machine this was built on, so **nobody has run this path yet**
(see [ADR 0004](docs/adr/0004-local-environment.md)). Expected steps:

```bash
composer install
cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
```

URLs are the same without the port (`http://acme.localhost`); Mailpit is on http://localhost:8025. Work the queue
with `sail artisan horizon`. The test database `oyosend_testing` is created by
`docker/mysql/create-testing-database.sh` when the MySQL volume is first initialised. Note that `compose.yaml`
builds Sail's PHP 8.5 runtime and MySQL 8.4, while everything so far was verified on PHP 8.4.13 and MySQL 8.0.30.

## Demo logins

Seeded by `database/seeders/DemoSeeder.php` in the `local` environment only. Every password is `password`.

| Where | Email | Role |
|---|---|---|
| Super Admin | `admin@oyosend.test` | Super admin |
| Acme (`acme`) | `owner@acme.test` | Owner |
| | `admin@acme.test` | Admin |
| | `manager@acme.test` | Manager |
| | `employee@acme.test` | Employee |
| Globex (`globex`, Bengali by default) | `owner@globex.test` | Owner |
| | `admin@globex.test` | Admin |
| | `employee@globex.test` | Employee |
| Both workspaces | `farhan@oyosend.test` | Manager in Acme, Employee in Globex (use it to try the workspace switcher) |

The Super Admin must enrol in two-factor authentication on first sign-in (any TOTP authenticator app). To skip
that locally, set `PLATFORM_REQUIRE_2FA=false` in `.env`; the switch is ignored in production.

Outside `local` nothing is seeded. Create the first Super Admin with `php artisan platform:create-admin
you@example.com`: it runs in an interactive terminal and asks for the name and password. There is no command or
screen to create a workspace yet (Phase 2).

## Daily commands

| Command | Does |
|---|---|
| `composer dev` | App server on `:8000`, queue listener, Vite |
| `composer test` | Pest (`php artisan test`) against `oyosend_testing` |
| `composer lint` | `pint --test` (run `vendor/bin/pint` to fix) |
| `composer analyse` | Larastan |
| `composer check` | lint + analyse + test |
| `npm run dev` / `npm run build` | Vite dev server / production build |
| `npm run typecheck` | `vue-tsc --noEmit` |
| `npm run test` | Vitest. On Windows Git Bash, start it from `D:/…`, not `/d/…` (see `docs/TESTING.md`) |
| `php artisan permissions:sync [--prune]` | Write the permissions declared by modules to the database. Run after adding a permission and on every deploy |
| `php artisan platform:create-admin {email} [--name=] [--role=]` | Create a Super Admin panel account (`super_admin` or `support_admin`). Interactive; the password is asked for |

Never run `migrate:fresh` or any destructive database command against anything but the disposable test database.

CI is `.github/workflows/ci.yml` (Pint, Larastan, Pest, vue-tsc, Vitest, build). It has not run on GitHub yet.

## Where to read next

| File | For |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | Project rules in one page: locked decisions, conventions, never-do list |
| `PROGRESS.md` | What is done, known issues, next steps |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Areas and hosts, request lifecycle, tenancy, authorization, queues |
| [`docs/DATABASE.md`](docs/DATABASE.md) | Tables, conventions, dedicated-database strategy |
| [`docs/MODULES.md`](docs/MODULES.md) | How to add a module |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Tenant isolation layer by layer, authentication, known limitations |
| [`docs/TESTING.md`](docs/TESTING.md) | Running tests, the test database, helpers |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Environment, DNS/TLS, Nginx, workers, deploy steps |
| [`resources/js/README.md`](resources/js/README.md) | Frontend guide and the design language |
| `docs/APP_STORE.md`, `BILLING.md`, `AUTOMATION.md`, `INTEGRATIONS.md` | What exists today for each, and what is planned |
| [`docs/adr/`](docs/adr) | Decisions: tenancy, RBAC, identity and sessions, local environment, design system |
