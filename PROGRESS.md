# PROGRESS

Where the project stands, what was decided and what comes next. Update it at the end of every session.
Rules for working in this repository are in `CLAUDE.md`; the product specification is `MASTER_PROMPT.md`.

## Status

**Phase 1 (Architecture & Foundation) is complete as of 2026-10-10.** Phase 2 has not been started.

## What Phase 1 delivered

| Area | What exists |
|---|---|
| Tenancy | Workspace per subdomain (`{tenant}.APP_DOMAIN`), resolved from the hostname only. Fail-closed row scope (`BelongsToTenant`): no tenant, no query. Tenant-prefixed cache on every store, tenant-aware rate limiter, `TenantStorage` for files, tenant context carried by queued jobs and enforced on them |
| Identity | Platform-wide `users` (unique email) with one `tenant_memberships` row per workspace. Host-only sessions bound to one workspace, "remember me" bound to it too, workspace switcher with one-time handoff tokens, password reset, TOTP two-step verification with recovery codes, replay protection and lockout |
| Team | Members list (search, role change, suspend, remove), invitations by email (hashed one-time token, expiry, withdraw, resend), acceptance for new and existing accounts |
| Access | Tenant-scoped roles (Owner, Admin, Manager, Employee + custom), permissions declared by modules and synced with `permissions:sync`, checked through `AccessChecker` behind `Gate::before`. Nobody can grant, remove or outrank a permission they do not hold |
| Audit | Append-only, tenant-scoped trail (`AuditLogger`, `Auditable`), sensitive keys redacted, audit page with filters and cursor pagination |
| Super Admin | Separate host, guard and table (`platform_users`), mandatory two-step verification, dashboard (counts, live service checks, recent workspaces, panel activity), read-only workspace directory, `platform:create-admin` command |
| Module system | `ModuleManifest`, `ModuleRegistry`, `ModuleServiceProvider`; navigation and dashboard widgets built from manifests; contracts for entitlements (`EntitlementChecker`), automation triggers/actions (`AutomationRegistry`) and domain events (`TenantDomainEvent`). The only module is `core` |
| Design system | "Warm frosted glass": tokens in `resources/css/app.css`, 30 shared components, floating glass shell, light and dark themes, command palette, three layouts. Text contrast on glass is tested (see below) |
| Languages | English and Bengali for every screen and every server message; numerals (Latin/Bengali) are a separate per-person choice; dates, numbers and money are formatted for the workspace |
| Pages | 20 Inertia pages: sign-in, two-step challenge, forgot/reset password, invitation, dashboard, members, roles (list, editor), audit log, profile, security, landing page, error pages, and the five Super Admin screens |
| Tooling | Pest, Vitest, Pint, Larastan level 6, vue-tsc, GitHub Actions workflow, Sail `compose.yaml`, development-only seeders |
| Docs | `README.md`, `CLAUDE.md`, `docs/` (architecture, database, modules, security, testing, deployment, and short notes for the phases to come), five ADRs, `resources/js/README.md` |

## Verification on 2026-10-10

Run natively on Laragon (Windows 10): PHP 8.4.13, MySQL 8.0.30, Redis 5.0.14, Node 24.19.

| Check | Command | Result |
|---|---|---|
| Code style | `vendor/bin/pint --test` | passed |
| Static analysis | `vendor/bin/phpstan analyse` (level 6) | no errors |
| Backend tests | `php artisan test` | 660 passed, 5035 assertions |
| Type check | `npm run typecheck` | no errors |
| Frontend tests | `npm run test` | 69 passed |
| Production build | `npm run build` | built |
| Database | `php artisan migrate --seed` on an empty database | succeeded (run on 2026-10-08; migrations unchanged since, and the seeder is now under test) |
| Visual review | every page in a real browser: light, dark, 390px, Bengali | done on 2026-10-08, no console errors |

Among the 660 backend tests: cross-tenant isolation for every tenant-owned model and every route that takes an id,
cache and storage isolation, queue context, the whole sign-in surface, role and member rules, entitlements with a
fixture module, and the demo seeder the local setup depends on.

### Not verified

- **Laravel Sail / Docker.** `compose.yaml` is committed but this machine has no Docker. It also builds PHP 8.5
  and MySQL 8.4, where everything above ran on 8.4 and 8.0 (ADR 0004).
- **The GitHub Actions workflow** has never run: the repository has no remote.
- **Horizon** cannot run on Windows (needs `pcntl`/`posix`). `composer dev` uses `queue:listen` instead; the
  Horizon configuration is untested.
- **Real mail delivery.** Mail was only sent to the `log` and `array` transports.
- **S3 storage.** The driver package is not installed.
- **End-to-end browser tests.** Playwright arrives in Phase 2, as the specification says.

## Decisions

All dated 2026-10-08. The longer reasoning is in `docs/adr/`.

| Decision | Why |
|---|---|
| stancl/tenancy 3.10 for tenant, domain and lifecycle; our own scope, cache prefixing and storage API on top (ADR 0001) | The package supports Laravel 13 in single-database mode, but its scope fails open with no tenant and its cache isolation needs tagging. Ours fails closed |
| spatie/laravel-permission 8 in teams mode for schema and assignment; checks go through our own `AccessChecker` (ADR 0002) | A permission must also depend on the workspace's entitlement to the module and carry a scope, which the package's check API cannot express |
| One `users` row per person across workspaces; sessions host-only and bound to a workspace (ADR 0003) | One login for people in several companies, without a session in one workspace being valid in another |
| Verified natively on Laragon; Sail shipped unverified (ADR 0004) | No Docker on the development machine |
| Own design system; fonts under OFL-1.1 (ADR 0005) | A distinct identity, and the only licence exception: fonts |
| TypeScript pinned to `~6.0` | vue-tsc 3.3 cannot drive TypeScript 7 yet |
| `ext-pcntl`/`ext-posix` declared in `composer.json` `config.platform` | Lets Composer install Horizon on Windows; Horizon itself still only runs on Linux |
| Tests run on a real MySQL database (`oyosend_testing`) | The behaviour under test is MySQL's (JSON, indexes, foreign keys); `TestCase` refuses any database not named `*_testing` |
| Sanctum and Reverb not installed | Both confirmed compatible with Laravel 13; nothing uses them until there is an API and realtime |
| Glass made more opaque than first drawn; soft status fills are chips of about 60% opacity | Measured contrast on glass over the backdrop discs was below WCAG AA in the dark theme. `tests/js/contrast.test.ts` now recomputes it from the stylesheet |
| "Remember me" tied to the issuing workspace with a second, encrypted cookie | The framework's cookie names a person and no workspace; a stolen copy would otherwise open every workspace that person belongs to |
| Nobody can change, suspend or remove a member who holds a permission they lack | Otherwise "manage members" alone was enough to remove an Admin |
| `platform:create-admin` command, password typed in and never an argument | There was no way to create the first Super Admin outside the local seeder |
| Sign-in and landing copy describe only what exists today | The specification forbids presenting unfinished features as done |

## Known issues and debt

Security and correctness
- A password-reset token can be redeemed on any workspace host, not only the one it was requested on (low impact:
  the person still has to be a member there to sign in).
- Trusted proxies, security headers and a Content Security Policy are not configured. Needed before the first
  deployment behind a load balancer.
- After a 404 for an unknown workspace the previously active tenant stays initialised in the PHP process. Harmless
  under PHP-FPM; needs a per-request reset before adopting Octane.
- The dedicated-database seam (`tenants.db_connection`) is reserved but not usable; the gaps are listed in
  `docs/DATABASE.md`.
- Audit entries written by `Auditable` include technical columns (`tenant_id`, `guard_name`).

Product gaps inside Phase 1's own scope
- No email verification and no way to change an email address (it needs a verification step).
- The Super Admin panel has no language switch and no audit-log page of its own; its workspace directory is
  read-only.
- System role names (Owner, Admin, Manager, Employee) are stored data and appear in English in the Bengali
  interface. Numbers inside server-written messages ignore the numerals preference.
- The password hint states the production rule (12 characters, mixed case, a number); local development accepts 8.

Frontend
- A dialog opened from a menu item, or whose opener has since left the page, has nowhere to return focus to;
  the pages that need it handle it themselves (`Core/Components/Team/focus.ts`). It belongs in `Modal`.
  `DataTable` has no row-details slot; the audit page works around it.
- No `Textarea` component; `Button` labels cannot wrap; `SegmentedControl` has no arrow-key movement.
- Wide tables scroll sideways on phones instead of collapsing.

Contracts thinner than the specification
- `ModuleManifest` has no routes list, scheduled jobs or install/uninstall hooks yet (specification section 15).
  They belong with the App Store in Phase 3.
- `CoreModulesEntitlementChecker` entitles every workspace to `core` and to nothing else. It is the seam Phase 2
  replaces, not an entitlement system.
- Inertia page names are checked for existence only where a test asserts the component; all 20 are asserted
  today.

Environment
- On Windows, run Vitest from a path with an upper-case drive letter (`cd D:/...`). From Git Bash's `/d/...` every
  test file fails before it starts.
- On this machine PHPUnit was installed from source because an indexer held vendor files open, and a few temporary
  directories remain under `vendor/composer/`. Neither is in the repository.
- The local demo database still holds sign-in entries from the visual review, and there is an empty extra database
  `oyosend_alt_testing`. `php artisan migrate:fresh --seed` and `DROP DATABASE oyosend_alt_testing` clear both.

## Deferred, by phase

- **Phase 2:** self-registration and onboarding, company settings, branches and departments (the `own` /
  `department` / `branch` permission scopes exist but nothing narrows by them yet), shared contacts and companies,
  plans, billing, payment gateways, real entitlements, usage limits and metering, Super Admin tenant and plan
  management, impersonation with audit, Playwright.
- **Phase 3:** App Store, module install lifecycle, the rest of the manifest.
- **Phase 8:** notification centre and channels. **Phase 10:** the automation engine (its trigger and action
  registry already exists). **Phase 13:** hardening, backups, load testing.
- Whenever first needed: Sanctum (API tokens), Reverb (realtime), Meilisearch, S3, custom domains
  (`tenant_domains.type` reserves `custom`).

## Needs the owner's input

1. **Production domain** for `APP_DOMAIN`, and a wildcard DNS record and certificate for it.
2. **Registration model:** open self-service, or sign-up that a Super Admin approves? (`tenants.status` already has
   `pending`.)
3. **Payment gateways** for Phase 2 (for Bangladesh: bKash, Nagad, SSLCommerz? cards through whom?) and the plan
   and pricing structure. No gateway will be simulated in production code.
4. **Mail provider** for transactional mail.
5. **A Git remote**, so the CI workflow can run for the first time.
6. **Docker**, if Sail is to be the supported local setup: it must be run once to be verified.
7. **A native reader's pass over the Bengali wording.** It is complete and consistent (glossary in
   `resources/js/Core/i18n/locales/bn/index.ts`) but has not been read by a person.
8. **Name and mark.** The "oyosend" wordmark and two-disc mark were drawn for this build; confirm or replace.

## Next steps (Phase 2, in order)

1. Before new features: run Sail once and the CI workflow once; add trusted-proxy and security-header
   configuration; decide the items above.
2. Shared core records the later apps depend on: branches, departments, contacts, companies - each with
   `assertTenantIsolated()` and the scope-narrowing rules for `own` / `department` / `branch`.
3. Company settings (profile, language, timezone, currency, logo through `TenantStorage`).
4. Plans, subscriptions and the payment gateway abstraction; then entitlements, usage limits and metering behind
   the existing `EntitlementChecker` contract.
5. Self-registration and onboarding, with email verification.
6. Super Admin: create, suspend and reactivate workspaces, manage plans, impersonate with an audit entry, panel
   audit log.
7. Playwright for sign-in, invitation, role change and registration.

## Session log

- **2026-10-08** - Phase 1 built and verified. Backend foundation, design system and shell written directly; the
  remaining pages, the test suite, the documentation and the Bengali translation were produced by parallel agents
  and then reviewed, integrated and corrected (contrast, cursor pagination on the audit page, member hierarchy,
  workspace-bound "remember me", named rate limiters across tenants, invitation email language).
- **2026-10-10** - Final checks re-run on the finished tree, documentation brought back in step with the code,
  last commits made. Nothing of Phase 2 was started.
