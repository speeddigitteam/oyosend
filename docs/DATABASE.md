# Database

State: end of Phase 1. Sections 1–3 describe the schema that exists (`database/migrations/`). Section 4 is an
outline of tables later phases will add. Section 5 is the dedicated-database strategy the spec asks for (3.4);
**only its routing seam exists today.**

One MySQL 8 database, `utf8mb4` / `utf8mb4_unicode_ci`, strict mode. The application clock is UTC
(`config/app.php`); a tenant's timezone is applied when values are displayed.

## 1. Conventions

| Rule | Detail |
|---|---|
| Primary keys | ULID (`$table->ulid('id')->primary()`, model trait `HasUlids`, stored lower-case, `CHAR(26)`) for every tenant-owned and identity table. Auto-increment only for lookup/pivot tables (`permissions`, `tenant_domains`, framework tables) |
| `tenant_id` | `foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete()`. Always indexed. Model uses `BelongsToTenant` |
| Unique indexes | Lead with `tenant_id`: `UNIQUE(tenant_id, …)`. Validation mirrors it: `Rule::unique(...)->where('tenant_id', …)` (see `RoleController::validated()`) |
| Composite indexes | Start with `tenant_id`, then the common filter or sort column |
| Money | Integer minor units (paisa/cents) plus a 3-letter currency code. Never floats or decimals. No money column exists yet, so the column naming is not established: decide it with the first one and record it here |
| Enums | Short `VARCHAR` cast to a PHP enum (`status`, `role`, `scope`). No MySQL `ENUM` |
| Polymorphic columns | Store the morph alias (`user`, `role`), never a class name. `Relation::requireMorphMap()` is on; aliases come from module manifests |
| Soft deletes | Only where a record must be recoverable. Today: `tenants` only |
| Secrets | Encrypted casts (`two_factor_secret`, `two_factor_recovery_codes`) or a one-way hash (`invitations.token_hash`). Never plain |
| Migrations | Reversible (`down()` on every one). `DB::prohibitDestructiveCommands()` is on in production |
| Raw queries | `DB::table()` bypasses both the tenant scope and the connection resolver. It must filter by `tenant_id` itself and is acceptable only for central tables |
| Search filters | User text goes into `LIKE` only through `App\Core\Support\Like` (`contains()`, `escape()`), so `%` and `_` are matched as typed |

Two intentional exceptions to "unique indexes lead with `tenant_id`": `users.email` (a user is a platform-wide
identity, ADR 0003) and `invitations.token_hash` (the SHA-256 of a 64-character random token).

## 2. Central vs tenant-owned

"Central" tables hold platform data and stay in the central database even if a tenant later gets a dedicated one.

| Table | Ownership | Model | Isolation |
|---|---|---|---|
| `tenants`, `tenant_domains` | Central | `Tenant`, `Domain` | Not tenant data |
| `platform_users`, `platform_password_reset_tokens` | Central | `PlatformUser` | `platform` guard only |
| `users`, `password_reset_tokens`, `sessions` | Central | `User` | No scope. Reach people through `tenant_memberships` |
| `permissions` | Central catalogue | `Permission` | Same for every tenant; written only by `permissions:sync` |
| `tenant_memberships` | Tenant-scoped rows, pinned to the central connection | `TenantMembership` | `BelongsToTenant` + `UsesCentralConnection` |
| `roles`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | Tenant-owned rows, default connection | `Role` | Scoped by hand (ADR 0002) |
| `invitations` | Tenant-owned | `Invitation` | `BelongsToTenant` (the only model that follows the tenant connection today) |
| `audit_logs` | Tenant-scoped on read; `tenant_id` null for platform events; default connection | `AuditLog` | `ScopedToTenant` |
| `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | Framework | — | — |

## 3. Phase 1 tables

`ts` = nullable `TIMESTAMP`. "timestamps" = `created_at` + `updated_at`.

### tenants — `2026_10_01_000010`

| Column | Type | Notes |
|---|---|---|
| `id` | ULID, PK | |
| `name` | varchar(255) | |
| `slug` | varchar(63), **unique** | Also the subdomain label, hence the DNS label limit |
| `status` | varchar(20), default `active`, **index** | `TenantStatus`: `pending`, `active`, `suspended`. Only `active` allows access |
| `locale` | varchar(8), default `en` | |
| `timezone` | varchar(64), default `Asia/Dhaka` | The workspace's clock: display, the audit log's date filters, the expiry day in invitation mail |
| `currency` | char(3), default `BDT` | |
| `db_connection` | varchar(64), null | null = shared database. Set only when a tenant is moved (section 5) |
| `trial_ends_at`, `suspended_at` | ts | Not read by any code yet |
| `data` | json, null | Package "virtual columns": attributes not listed in `Tenant::getCustomColumns()` land here |
| timestamps, `deleted_at` | | Soft deletes; a soft-deleted tenant no longer resolves |

### tenant_domains — `2026_10_01_000010`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, PK, auto-increment | |
| `tenant_id` | ULID, FK → `tenants` (cascade on update and delete) | |
| `domain` | varchar(255), **unique** | The label (`acme`) for `subdomain` rows; a full hostname for `custom` rows |
| `type` | varchar(20), default `subdomain` | `subdomain` or `custom`. `custom` is reserved, not routed |
| `is_primary` | boolean, default false | |
| `verified_at` | ts | |
| timestamps | | |

Index `(tenant_id, is_primary)`. This is the table tenant resolution queries.

### platform_users — `2026_10_01_000020`

| Column | Type | Notes |
|---|---|---|
| `id` | ULID, PK | |
| `name`, `password` | varchar(255) | Password hashed by cast |
| `email` | varchar(255), **unique** | |
| `role` | varchar(32), default `support_admin` | `PlatformRole`: `super_admin`, `support_admin` |
| `two_factor_secret`, `two_factor_recovery_codes` | text, null | Encrypted casts |
| `two_factor_confirmed_at`, `last_login_at` | ts | |
| `remember_token`, timestamps | | |

`platform_password_reset_tokens` (`email` PK, `token`, `created_at`) backs the `platform_users` password broker.
No reset route exists for platform users yet.

### users — `2026_10_01_000020`

| Column | Type | Notes |
|---|---|---|
| `id` | ULID, PK | |
| `name`, `password` | varchar(255) | |
| `email` | varchar(255), **unique** (platform-wide) | |
| `email_verified_at` | ts | Set on invitation acceptance and by the seeder; not enforced |
| `locale` | varchar(8), null | Personal interface language; also the language of an invitation mail sent to this address |
| `preferences` | json, null | Today: `numerals` = `latn` or `beng` |
| `two_factor_secret`, `two_factor_recovery_codes` | text, null | Encrypted casts |
| `two_factor_confirmed_at`, `last_login_at` | ts | |
| `remember_token`, timestamps | | |

`password_reset_tokens` (`email` PK, `token`, `created_at`): `users` broker, 60-minute expiry.
`sessions` (`id` PK, `user_id` ULID null **index**, `ip_address`, `user_agent`, `payload`, `last_activity`
**index**): shared by both guards, so `user_id` has no foreign key and may hold either kind of id.

### tenant_memberships — `2026_10_01_000020`

| Column | Type | Notes |
|---|---|---|
| `id` | ULID, PK | The id workspace routes bind (`{membership}`) |
| `tenant_id` | ULID, FK → `tenants` (cascade) | |
| `user_id` | ULID, FK → `users` (cascade) | |
| `status` | varchar(20), default `active` | `MembershipStatus`: `active`, `suspended` |
| `is_owner` | boolean, default false | The owner holds every permission implicitly |
| `joined_at`, `last_seen_at` | ts | `last_seen_at` is written at most every 5 minutes |
| timestamps | | |

`UNIQUE(tenant_id, user_id)`; indexes `(tenant_id, status)` for member lists and `(user_id, status)` for the
workspace switcher. Nothing in the schema enforces a single owner per tenant; the application does.

### permissions, roles and pivots — `2026_10_01_000030`

`spatie/laravel-permission` schema in teams mode, adapted for ULIDs (ADR 0002).

| Table | Columns | Keys and indexes |
|---|---|---|
| `permissions` | `id` bigint AI, `name` varchar(150), `guard_name` varchar(40), `module` varchar(60), timestamps | `UNIQUE(name, guard_name)`; index `module` |
| `roles` | `id` ULID, `tenant_id` ULID null FK → `tenants` (cascade), `name` varchar(80), `guard_name` varchar(40), `description` varchar(255) null, `is_system` boolean default false, timestamps | `UNIQUE(tenant_id, name, guard_name)` |
| `model_has_roles` | `role_id` ULID FK → `roles` (cascade), `model_type` varchar(100), `model_id` ULID, `tenant_id` ULID FK → `tenants` (cascade) | PK `(tenant_id, role_id, model_id, model_type)`; index `(model_id, model_type)` |
| `model_has_permissions` | `permission_id` bigint FK → `permissions` (cascade), `model_type`, `model_id` ULID, `tenant_id` ULID FK (cascade) | PK `(tenant_id, permission_id, model_id, model_type)`; index `(model_id, model_type)` |
| `role_has_permissions` | `permission_id` FK (cascade), `role_id` FK (cascade), `scope` varchar(16) default `all` | PK `(permission_id, role_id)`; index `role_id` |

- `roles.tenant_id` is nullable because the package allows global roles. None are created; every role belongs to
  a tenant. System roles (`Owner`, `Admin`, `Manager`, `Employee`, `is_system = true`) are created by
  `TenantProvisioner` from `Access\DefaultRoles`. The roles page lists them in that order
  (`Role::SYSTEM_ORDER`), then custom roles by name.
- `model_has_roles.tenant_id` leads the primary key, so the same user holds different roles per tenant.
- `role_has_permissions.scope` is `own`, `department`, `branch` or `all`. Every grant is written as `all` today.
- `model_has_permissions` (direct grants to a user) is **not read** by `PermissionResolver`. Do not use it.
- `permissions.module` is derived from the first segment of the name and is what the entitlement check keys on.

### invitations — `2026_10_01_000040`

| Column | Type | Notes |
|---|---|---|
| `id` | ULID, PK | |
| `tenant_id` | ULID, FK → `tenants` (cascade) | |
| `email` | varchar(255) | Lower-cased by `InviteMember`; compared case-insensitively with the account on acceptance |
| `role_id` | ULID, FK → `roles` (cascade) | |
| `invited_by` | ULID null, FK → `users` (null on delete) | |
| `token_hash` | char(64), **unique** | SHA-256 of the emailed token; the token itself is never stored |
| `expires_at` | timestamp | `platform.invitations.expires_after_days` (7) |
| `accepted_at`, `revoked_at` | ts | State is derived: pending = both null and not expired |
| timestamps | | |

Indexes `(tenant_id, email)` and `(tenant_id, accepted_at, revoked_at)`. "One open invitation per address" cannot
be a unique index because accepted and revoked rows are kept; `InviteMember` enforces it under a row lock.

### audit_logs — `2026_10_01_000050`

| Column | Type | Notes |
|---|---|---|
| `id` | ULID, PK | |
| `tenant_id` | ULID null, FK → `tenants` (cascade) | null = platform-level event |
| `actor_type`, `actor_id` | varchar(40) null, ULID null | Morph alias + id: `user`, `platform_user`, or null for the system |
| `actor_label` | varchar(255) null | Denormalised so the trail still reads after the actor is deleted |
| `event` | varchar(100) | Dotted: `member.invited`, `auth.login`, `role.updated` |
| `subject_type`, `subject_id`, `subject_label` | varchar(100) / varchar(40) / varchar(255), null | |
| `properties` | json, null | `old` / `attributes` for changes; redacted by `AuditLogger` |
| `ip_address`, `user_agent` | varchar(45) / varchar(512), null | |
| `created_at` | timestamp, default current | Append-only: there is no `updated_at` |

Indexes `(tenant_id, created_at)` for the trail, `(tenant_id, event)`, `(subject_type, subject_id)`,
`(actor_type, actor_id)`. The model throws on update and delete. The tenant foreign key cascades, so
force-deleting a tenant removes its trail; tenants are soft-deleted in normal operation.

### Framework tables — `0001_01_01_*`

`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`: stock Laravel. `cache` and `jobs` are used only
when `CACHE_STORE` / `QUEUE_CONNECTION` is `database`; the project runs both on Redis.

## 4. Later tables (outline only)

Names come from spec section 23. Nothing below is designed in detail or migrated. Spec 3.4 names plans, apps and
the platform's invoices to tenants as central data; rows marked † are our reading of that rule for tables it does
not name, to be confirmed when they are designed.

| Group | Phase | Tables | Ownership | Fixed by the spec |
|---|---|---|---|---|
| Core SaaS | 2 | `branches`, `departments` | Tenant-owned | Needed before the `branch` / `department` access scopes can be evaluated |
| Core SaaS | 2 | `contacts`, `companies` | Tenant-owned | One shared customer identity reused by CRM, marketing, support and projects; no duplicate customer tables |
| Core SaaS | 8 | `notifications` | Tenant-owned | In-app, email, SMS, WhatsApp, realtime; read/unread; delivery tracking |
| Core SaaS | not assigned | `files`, `integrations`, `api_credentials`, `webhooks` | Tenant-owned | Files polymorphic, private, tenant-prefixed; credentials encrypted; webhook idempotency by provider event id |
| Billing | 2 | `plans` | Central | Starter, Business, Enterprise, Custom; monthly and yearly |
| Billing | 2 | `invoices` (platform → tenant) | Central, keyed by `tenant_id` | Distinct from the Invoicing app's customer invoices (Phase 6) |
| Billing | 2 | `subscriptions`, `subscription_items`, `payments` | Central, keyed by `tenant_id` † | Payment confirmed only by backend gateway verification |
| Billing | 2 | `usage_records` | Central, keyed by `tenant_id` † | Unique idempotency key per event |
| App Store | 3 | `apps`, `app_versions` | Central catalogue | Apps are first-party modules; tenants cannot upload code |
| App Store | 3 | `app_installations`, `app_entitlements` | Central, keyed by `tenant_id` † | Entitlement only after server-side payment verification; uninstall never deletes business data |
| Automation | 10 | `workflows`, `workflow_versions` | Tenant-owned | Versioned JSON graphs; only published versions execute |
| Automation | 10 | `workflow_executions`, `workflow_steps`, `workflow_logs` | Tenant-owned | Persisted step state, step-level logs, idempotency |

## 5. Dedicated tenant databases

**Status: strategy only.** No tenant has a dedicated database, no tooling exists, and none of the steps below has
been run. Per spec 3.4, no tenant data is moved without explicit authorization and verified backups.

### What exists

- `tenants.db_connection` (null = shared).
- `App\Core\Tenancy\Support\TenantConnectionResolver::resolve()` returns that name when it is a configured
  connection, otherwise null.
- `BelongsToTenant::getConnectionName()` asks the resolver on every query; `UsesCentralConnection` pins platform
  models to `tenancy.database.central_connection`.
- ULID keys everywhere, so rows keep their ids when copied.
- `config/tenancy.php` keeps the package's multi-database settings (`database.managers`,
  `migration_parameters.--path = database/migrations/tenant`). That directory does not exist yet.

### Strategy

| Topic | Approach |
|---|---|
| Shared vs dedicated | Shared is the default (`db_connection` null). A dedicated tenant names a connection; its tenant-owned tables live there and central tables (section 2) stay put |
| Routing | Through `TenantConnectionResolver` only. No model, job or query picks a tenant connection itself |
| Schema versioning | One set of migration files for tenant-owned tables, applied to the shared database and to every dedicated one. Each database's own `migrations` table is its schema version; a deploy is complete only when all are at the same version |
| Moving a tenant | 1. authorization and a verified backup. 2. block writes (suspend the workspace, drain its queued jobs). 3. create the database and migrate it. 4. copy rows `WHERE tenant_id = ?` table by table in foreign-key order, ids unchanged. 5. validate. 6. set `db_connection`, flush the tenant's cache. 7. reopen. 8. keep the shared rows until sign-off, then purge them |
| Export / import | The same per-table extraction plus the tenant's files (`tenants/{tenant_id}/` on the tenant disk). Import is the reverse into an empty tenant. Also the basis of the "data export" feature in spec section 5 |
| Backup / restore | Shared database: whole-database backups plus binary logs; restoring one tenant from them needs the export tooling. Dedicated database: per-database backup and restore. Files: object-storage versioning. Nothing is automated yet (`docs/DEPLOYMENT.md`) |
| Validation | Before the switch: identical schema version; per-table row counts and checksums for the tenant; no orphaned foreign keys; a read-only pass through the application inside `TenantContext::run($tenant, …)`; the cross-tenant test suite against the dedicated connection |
| Rollback / recovery | The switch is one column. Rolling back is setting it to null while the shared rows still exist, so they are not purged before sign-off. Writes made after the switch must be copied back or are lost, so keep the verification window short. Otherwise restore from the step-1 backup |

### Gaps to close before the first move

Found while reading the Phase 1 code:

1. The resolver falls back to the shared database when the named connection is not configured
   (`TenantConnectionResolver.php:30`). For a tenant that has been moved this must become a hard failure, or its
   writes land in the shared database.
2. Nothing registers a tenant's connection at runtime; `db_connection` must name an entry that already exists in
   `config/database.php`.
3. Raw `DB::table()` calls ignore the resolver. `RoleManager::delete()` reads `invitations` that way
   (`RoleManager.php:89`).
4. Foreign keys cannot cross databases: `invitations.role_id → roles`, `invitations.invited_by → users` and every
   `tenant_id → tenants` on a moved table would have to be dropped and enforced in code.
5. `roles`, their pivots and `audit_logs` use the default connection because their models do not go through the
   resolver. Decide whether they move with the tenant or stay central.
6. `DB::transaction()` opens on the default connection. A write to a tenant connection inside it is not atomic
   with the central writes around it.
