# Oyosend

A multi-tenant business SaaS platform: one workspace per company on its own subdomain, with apps (HRM, projects,
CRM, invoicing, marketing, support, automation) added through a built-in App Store. The full product
specification is [`MASTER_PROMPT.md`](MASTER_PROMPT.md).

**Current state: Phases 1–8 are complete. The Phase 9 marketing apps (Email, SMS, WhatsApp, Social) and the Automation, AI, Support and Reports apps are built and pass the automated checks but have had no independent review or live-provider test. Phase 13 production hardening has begun (health probes, queue and index audits, backup verification, a benchmark tool) and is not finished.** What exists:

- **Foundation (Phase 1):** subdomain tenancy with fail-closed isolation, sign-in with two-factor authentication,
  memberships and invitations, tenant-scoped roles and permissions, an audit trail, the module / entitlement /
  automation contracts, the design system and layouts, a workspace dashboard and a Super Admin panel, in English
  and Bengali.
- **Core SaaS (Phase 2):** sign-up with email confirmation and first-run setup; workspace settings and logo;
  branches and departments; permissions that reach a person's own, their department's, their branch's or all
  records; shared contacts and companies; plans, subscriptions with trials, invoices and payments; plan limits and
  a metering service; Super Admin management of workspaces, plans and invoices, with audited support access.
- **App Store (Phase 3):** compiled first-party app catalogue with immutable manifest versions; catalogue,
  details and My Apps pages; free install and paid invoice checkout; tenant-scoped installations, licences and
  separately billed subscription items; dependency/lifecycle handling and entitlement-gated execution.
- **CRM & Sales (Phase 4):** shared-customer leads, pipelines, deals, activities, customer profiles and record
  content/timelines; contact/company saved segments; exact-money product, quote, order, contract and lifetime-value
  workflows; scoped permissions, retry-safe automation actions, bilingual UI and an invoicing hand-off event.
- **Project Management (Phase 5):** templates, scoped projects and tasks; list, Kanban, calendar and Gantt
  projections; milestones, sprints, dependencies, collaboration and client access; integer-money budgets and
  expenses; timesheets, workload, approvals, reporting and retry-safe automation actions.

- **Invoicing, HR Management, Payroll and Talent (Phases 6 and 7):** customer invoices, payments, credit notes and
  recurring billing; employees, attendance and leave; payroll runs with four-eyes approval and immutable payslips;
  recruitment, onboarding, performance and training. HR apps share read contracts and never import each other's models.
- **Communication platform (Phase 8):** a per-member notification centre, a message outbox with consent, suppression,
  quiet hours and rate limits, templates, an interaction timeline and signed provider webhooks. Providers: the
  platform mailer, SMTP, Twilio, a generic HTTP SMS gateway and the WhatsApp Cloud API, configured by environment
  references or by operator provider accounts typed into the Super Admin panel (encrypted, write-only; ADR 0013).
- **Marketing and engagement apps (Phase 9):** Email Marketing, SMS Marketing and WhatsApp Business send only through
  core Communication contracts; Social Media schedules and publishes to Facebook Pages, LinkedIn and X behind an
  approval workflow. No provider adapter has been run against its live service.
- **Workflow Automation, AI Assistant, Customer Support and Reports (Phases 10 to 12):** versioned JSON workflows with a
  visual editor that run as the member who published them; a paid assistant whose changes are proposals a person
  confirms; tickets, SLAs and a knowledge base; and a Reports app that runs the reports every other app publishes.

The 16 registered modules and their 251 permissions are tabulated in [`docs/MODULES.md`](docs/MODULES.md). CRM,
Projects, Invoicing, HRM, Payroll and Talent are the production business apps. `migrate --seed` synchronizes catalogue
entries (as well as permissions), and deployments run `apps:sync --prune` explicitly. The store test
suite retains a deliberately separate `test_crm` fixture. One payment gateway (SSLCommerz) is implemented
but has not been run against the provider's sandbox; until credentials are set, invoices are paid by recording
an offline payment in the Super Admin panel. See [`docs/BILLING.md`](docs/BILLING.md).

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
php artisan apps:sync
npm install
composer dev
```

`composer dev` serves the app on `127.0.0.1:8000`, runs a queue listener and Vite. Browsers resolve `*.localhost`
to loopback, so no hosts-file edits are needed. Open the hostnames below, not `http://127.0.0.1:8000`: every
route is bound to a hostname, so the bare IP answers 404.

| Area | URL |
|---|---|
| Central site | http://localhost:8000 (sign up at `/register`) |
| Super Admin panel | http://admin.localhost:8000 |
| Workspaces | http://acme.localhost:8000 and http://globex.localhost:8000 |

With `MAIL_MAILER=log`, the links in mail (invitation, password reset, sign-up confirmation, invoices) are
written to `storage/logs/laravel.log`. Mail is queued, so the queue listener has to be running.

Subscriptions move in time (next invoice, overdue, expiry) only when `php artisan billing:run` runs. In
production the scheduler runs it hourly; locally run it by hand, or `php artisan schedule:work`.

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
./vendor/bin/sail artisan apps:sync
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

The seeder also creates four example plans - Free, Starter, Business, Enterprise - with placeholder prices and
seat limits. Acme starts a 14-day trial of Business; Globex is on Free, which allows five members: it has
four, so one more invitation is accepted and the next is refused - the seat limit at work. To pay an invoice locally, open it in the Super Admin panel
(Invoices) and record a payment.

Outside `local` nothing is seeded. Create the first Super Admin with `php artisan platform:create-admin
you@example.com`: it runs in an interactive terminal and asks for the name and password. Plans and workspaces
are then created in the panel; workspaces can also sign up on the central site.

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
| `npm run test:e2e` | Playwright critical flows in Chrome against the isolated `oyosend_e2e_testing` database |
| `php artisan permissions:sync [--prune]` | Write the permissions declared by modules to the database. Run after adding a permission and on every deploy |
| `php artisan apps:sync [--prune]` | Mirror compiled optional manifests into the App Store; `--prune` unpublishes modules removed from the build |
| `php artisan platform:create-admin {email} [--name=] [--role=]` | Create a Super Admin panel account (`super_admin` or `support_admin`). Interactive; the password is asked for |
| `php artisan billing:run [--tenant=slug]` | Advance base and app billing: invoices, overdue, expiry and cancellation. Scheduled hourly; safe to run by hand |
| `php artisan apps:run-scheduled` | Dispatch due manifest jobs for entitled tenants; scheduled every minute |
| `php artisan ops:verify-backup {dump}` | Restore a mysqldump into a scratch `*_restore_check` database, check the core tables, drop it (`docs/BACKUP_RESTORE.md`) |
| `php artisan ops:benchmark` | Seed synthetic rows into a `*_testing` database and time key list queries with `EXPLAIN` (`docs/PERFORMANCE.md`) |

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
| [`docs/BILLING.md`](docs/BILLING.md) | Plans, subscriptions, invoices, payments, gateways, limits |
| [`docs/APP_STORE.md`](docs/APP_STORE.md) | Catalogue sync, licences, lifecycle, separate app billing and entitlement gates |
| [`docs/CRM.md`](docs/CRM.md) / [`docs/PROJECTS.md`](docs/PROJECTS.md) | Production business-module workflows and boundaries |
| [`docs/INDEX.md`](docs/INDEX.md) | Every document and ADR, by topic |
| [`docs/AUTOMATION.md`](docs/AUTOMATION.md), [`docs/WORKFLOWS.md`](docs/WORKFLOWS.md) | Trigger/action contracts and the workflow engine |
| [`docs/COMMUNICATION.md`](docs/COMMUNICATION.md), [`docs/EMAIL_MARKETING.md`](docs/EMAIL_MARKETING.md), [`docs/SMS_MARKETING.md`](docs/SMS_MARKETING.md), [`docs/WHATSAPP.md`](docs/WHATSAPP.md), [`docs/SOCIAL.md`](docs/SOCIAL.md) | Messaging platform, marketing apps and social publishing |
| [`docs/AI.md`](docs/AI.md), [`docs/SUPPORT.md`](docs/SUPPORT.md), [`docs/REPORTS.md`](docs/REPORTS.md) | AI Assistant, Customer Support, Reports & Analytics |
| [`docs/HRM.md`](docs/HRM.md), [`docs/PAYROLL.md`](docs/PAYROLL.md), [`docs/TALENT.md`](docs/TALENT.md), [`docs/INVOICING.md`](docs/INVOICING.md) | HR, payroll, talent and invoicing apps |
| [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md) | Outside services and integration rules |
| [`docs/BACKUP_RESTORE.md`](docs/BACKUP_RESTORE.md), [`docs/PERFORMANCE.md`](docs/PERFORMANCE.md) | Backup verification and the benchmark baseline |
| [`docs/adr/`](docs/adr) | Decisions (0001 to 0019): tenancy, RBAC, identity/sessions, local environment, design system, billing, scopes, sign-up/support access, the App Store, domain boundaries, the workflow editor, provider accounts, marketing contracts, reporting, account claims, workflow authority, AI and operator tooling |
