# Database

State: Phase 5 Projects schema is implemented. Sections 1–3d describe the schema in `database/migrations/` and
the CRM/Projects module migration directories. Section 4 outlines later tables. Section 5 is the dedicated-database strategy
the spec asks for (3.4); **only its routing seam exists today.**

One MySQL 8 database, `utf8mb4` / `utf8mb4_unicode_ci`, strict mode. The application clock is UTC
(`config/app.php`); a tenant's timezone is applied when values are displayed.

## 1. Conventions

| Rule | Detail |
|---|---|
| Primary keys | ULID (`$table->ulid('id')->primary()`, model trait `HasUlids`, stored lower-case, `CHAR(26)`) for every tenant-owned and identity table. Auto-increment only for lookup/pivot tables (`permissions`, `tenant_domains`, framework tables) |
| `tenant_id` | `foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete()`. Always indexed. Model uses `BelongsToTenant` |
| Unique indexes | Lead with `tenant_id`: `UNIQUE(tenant_id, …)`. Validation mirrors it: `Rule::unique(...)->where('tenant_id', …)` (see `RoleController::validated()`) |
| Composite indexes | Start with `tenant_id`, then the common filter or sort column |
| Money | Integer minor units (paisa/cents) in an unsigned `BIGINT` named `amount` or `{what}_amount`, next to a 3-letter `currency` on the same row. Never floats or decimals. `App\Core\Billing\Support\Money` turns it into a decimal string or text where the server needs one |
| Enums | Short `VARCHAR` cast to a PHP enum (`status`, `role`, `scope`). No MySQL `ENUM` |
| Polymorphic columns | Store the morph alias (`user`, `role`), never a class name. `Relation::requireMorphMap()` is on; aliases come from module manifests |
| Soft deletes | Only where a record must be recoverable. Today: `tenants`, `contacts`, `companies` |
| Secrets | Encrypted casts (`two_factor_secret`, recovery codes, `app_installations.settings`) or a one-way hash (`invitations.token_hash`, registration token/password, `impersonations.token_hash`). Never plain |
| Migrations | Reversible (`down()` on every one). `DB::prohibitDestructiveCommands()` is on in production |
| Raw queries | `DB::table()` bypasses both the tenant scope and the connection resolver. It must filter by `tenant_id` itself and is acceptable only for central tables |
| Search filters | User text goes into `LIKE` only through `App\Core\Support\Like` (`contains()`, `escape()`), so `%` and `_` are matched as typed |

Intentional exceptions to "unique indexes lead with `tenant_id`": `users.email` (a user is a platform-wide
identity, ADR 0003); `invitations.token_hash` (the SHA-256 of a 64-character random token);
`subscriptions.tenant_id` itself (one base subscription per tenant); `app_entitlements.license_id` (a globally
safe licence identifier); and three platform-wide identifiers on billing tables — `invoices.number`,
`payments.reference` and `payments(gateway, gateway_reference)` — which must be unique across tenants because a
gateway and an accountant see them without a tenant. Catalogue constraints (`apps.module_key`,
`app_versions(app_id, version)`) have no tenant because the compiled catalogue is platform-wide.

"One live contact per email address" is not a unique index either: deleted contacts are kept, so validation
enforces it among the rows that are not deleted.

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
| `invitations` | Tenant-owned | `Invitation` | `BelongsToTenant` (follows the tenant connection) |
| `contacts`, `companies` | Tenant-owned | `Contact`, `Company` | `BelongsToTenant` (follow the tenant connection); record scopes through `HasRecordOwner` |
| `branches`, `departments` | Tenant-scoped rows, pinned to the central connection | `Branch`, `Department` | `BelongsToTenant` + `UsesCentralConnection`: memberships reference them and authorization reads them on every request |
| `plans` | Central catalogue | `Plan` | Same for every tenant; managed in the panel |
| `apps`, `app_versions` | Central catalogue | `CatalogApp`, `AppVersion` | Synced from compiled manifests by `apps:sync`; a row alone cannot load code |
| `subscriptions`, `invoices`, `payments`, `usage_records` | Central, keyed by `tenant_id` | `Subscription`, `Invoice`, `Payment`, `UsageRecord` | `BelongsToTenant` + `UsesCentralConnection`: a workspace sees only its own; the panel crosses tenants explicitly |
| `app_installations`, `app_entitlements`, `subscription_items` | Central, keyed by `tenant_id` | `AppInstallation`, `AppEntitlement`, `SubscriptionItem` | `BelongsToTenant` + `UsesCentralConnection`; separately licensed app state and history |
| `platform_sequences`, `platform_settings` | Central | — / `PlatformSettings` | Not tenant data |
| `workspace_registrations` | Central | `WorkspaceRegistration` | A sign-up before any tenant exists |
| `impersonations` | Central, keyed by `tenant_id` | `Impersonation` | Platform's record of support sessions; read by the panel and by the redeeming workspace |
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
| `trial_ends_at`, `suspended_at` | ts | `suspended_at` is set and cleared by the panel's suspend / reactivate. `trial_ends_at` is not read: the trial lives on the subscription |
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
- `role_has_permissions.scope` is `own`, `department`, `branch` or `all`. Only a permission declared `scopable`
  can be narrower than `all` (ADR 0007).
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

## 3a. Phase 2 tables

Migrations `2026_10_10_000010` to `000050`. "ownership" = the three columns `HasRecordOwner` needs: `owner_id`
ULID null FK → `tenant_memberships` (null on delete), `department_id` ULID null FK → `departments` (null on
delete), `branch_id` ULID null FK → `branches` (null on delete).

### tenants (added columns) — `000040`

| Column | Type | Notes |
|---|---|---|
| `legal_name`, `email`, `website` | varchar(255), null | The company behind the workspace; printed on its invoices from the platform |
| `phone` | varchar(40), null | |
| `address`, `city`, `postal_code`, `country`, `tax_id` | varchar(500) / (120) / (20) / char(2) / varchar(60), null | `country` is ISO 3166-1 alpha-2 |
| `logo_path` | varchar(255), null | Tenant-relative path on the tenant disk (`TenantStorage`) |
| `onboarded_at` | ts | null = first-run setup still to do. Existing rows were set to `created_at` |

`trial_ends_at` on `tenants` is still not read by any code: the trial lives on the subscription.

### tenant_memberships (added columns) — `000010`

`branch_id` ULID null FK → `branches` (null on delete), `department_id` ULID null FK → `departments` (null on
delete); indexes `(tenant_id, branch_id)` and `(tenant_id, department_id)`.

### branches, departments — `000010`

| Table | Columns | Keys and indexes |
|---|---|---|
| `branches` | `id` ULID, `tenant_id` FK (cascade), `name` varchar(120), `code` varchar(30) null, `phone` varchar(40) null, `address` varchar(500) null, timestamps | `UNIQUE(tenant_id, name)`, `UNIQUE(tenant_id, code)` |
| `departments` | `id` ULID, `tenant_id` FK (cascade), `name` varchar(120), `code` varchar(30) null, `description` varchar(255) null, timestamps | `UNIQUE(tenant_id, name)`, `UNIQUE(tenant_id, code)` |

Several rows may have no `code`: MySQL does not compare NULLs in a unique index.

### companies, contacts — `000020`

| Table | Columns | Keys and indexes |
|---|---|---|
| `companies` | `id` ULID, `tenant_id` FK (cascade), `name` varchar(200), `email`, `phone` varchar(40), `website`, `industry` varchar(120), `tax_id` varchar(60), `address` varchar(500), `city` varchar(120), `country` char(2), `notes` text (all nullable but `name`), ownership, timestamps, `deleted_at` | Indexes `(tenant_id, name)`, `(tenant_id, owner_id)`, `(tenant_id, department_id)`, `(tenant_id, branch_id)` |
| `contacts` | `id` ULID, `tenant_id` FK (cascade), `company_id` ULID null FK → `companies` (null on delete), `first_name` varchar(100), `last_name` varchar(100) null, `email` null, `phone` varchar(40) null, `job_title` varchar(120) null, `address`, `city`, `country`, `notes` (null), ownership, timestamps, `deleted_at` | Indexes `(tenant_id, email)`, `(tenant_id, phone)`, `(tenant_id, company_id)`, `(tenant_id, first_name, last_name)`, and the three ownership indexes |

- These two tables are the one shared customer identity (spec section 0). A module attaches its own data by
  `contact_id` / `company_id`; it does not keep a customer table of its own. Writes go through
  `App\Core\Contacts\ContactDirectory`.
- Deleting is a soft delete. A soft-deleted company's contacts stay and lose their `company_id` (done by
  `ContactDirectory`, because the foreign key only acts on a hard delete).

### plans — `000030`

| Column | Type | Notes |
|---|---|---|
| `id` | ULID, PK | |
| `key` | varchar(40), **unique** | `starter`. Stable; what a sign-up link names |
| `name`, `description` | varchar(80) / varchar(255) null | |
| `currency` | char(3) | |
| `monthly_amount`, `yearly_amount` | unsigned bigint, null | null = not sold for that interval; 0 = free |
| `trial_days` | unsigned smallint, default 0 | |
| `modules` | json | Keys of the optional modules the plan includes |
| `limits` | json | Limit key → maximum; a missing key means unlimited |
| `is_public`, `is_active` | boolean, default true | Public: offered to workspaces. Active: accepts new subscribers. A plan is archived, never deleted |
| `sort_order` | smallint, default 0 | |
| timestamps | | Index `(is_active, is_public, sort_order)` |

### subscriptions — `000030`

| Column | Type | Notes |
|---|---|---|
| `id` | ULID, PK | |
| `tenant_id` | ULID, FK → `tenants` (cascade), **unique** | One per tenant; history is in invoices and the audit trail |
| `plan_id` | ULID, FK → `plans` (restrict) | |
| `status` | varchar(20) | `SubscriptionStatus`: `trialing`, `active`, `past_due`, `expired`, `canceled` |
| `interval` | varchar(10) | `BillingInterval`: `monthly`, `yearly` |
| `currency`, `amount` | char(3), unsigned bigint | Price per interval, fixed when the plan was taken; renewals use it |
| `trial_ends_at`, `current_period_start`, `current_period_end`, `grace_ends_at` | ts | `current_period_end` null = no end (free plan). While past due it holds the boundary the unpaid period began at |
| `cancel_at_period_end`, `canceled_at`, `ended_at` | boolean, ts, ts | |
| `pending_plan_id`, `pending_interval` | ULID null FK → `plans` (null on delete), varchar(10) null | A downgrade waiting for the paid period to end |
| timestamps | | Indexes `(status, current_period_end)`, `(status, trial_ends_at)` |

### invoices — `000030`

| Column | Type | Notes |
|---|---|---|
| `id` | ULID, PK | |
| `tenant_id` | ULID, FK (cascade) | |
| `subscription_id` | ULID null, FK → `subscriptions` (null on delete) | |
| `number` | varchar(30), **unique** | `INV-2026-000042`, one counter for the platform |
| `kind` | varchar(20) | `InvoiceKind`: base `subscription`, `renewal`, `change`; app `app_purchase`, `app_renewal` |
| `status` | varchar(20) | `InvoiceStatus`: `open`, `paid`, `void` |
| `plan_id`, `interval`, `period_start`, `period_end` | ULID null FK → `plans` (null on delete), varchar(10) null, ts, ts | What paying it buys |
| `currency`, `subtotal_amount`, `credit_amount`, `total_amount` | char(3), unsigned bigint ×3 | `total = subtotal − credit` |
| `lines` | json | `[{type: "plan", plan, interval, amount}, {type: "credit", amount}]`, as issued |
| `billed_to` | json, null | The company profile as it was when issued |
| `issued_at`, `due_at`, `paid_at`, `voided_at` | timestamp, ts ×3 | |
| timestamps | | Indexes `(tenant_id, status)`, `(tenant_id, issued_at)`, `(status, due_at)` |

### payments — `000030`

| Column | Type | Notes |
|---|---|---|
| `id` | ULID, PK | |
| `tenant_id`, `invoice_id` | ULID, FK (cascade) | |
| `gateway` | varchar(40) | A gateway key, or `offline` for money recorded by an operator |
| `method` | varchar(40), null | `VISA`, `bank_transfer`, … |
| `status` | varchar(20) | `PaymentStatus`: `pending`, `succeeded`, `failed`, `canceled` |
| `currency`, `amount` | char(3), unsigned bigint | Always the invoice's total |
| `reference` | varchar(40), **unique** | Our transaction id (a ULID), the one given to the gateway |
| `gateway_reference` | varchar(120), null | The provider's transaction id. `UNIQUE(gateway, gateway_reference)`: recorded once, however often its notification arrives |
| `paid_at`, `failed_at`, `failure_reason` | ts, ts, varchar(255) null | |
| `details` | json, null | What the gateway's verification answered, or the note of an offline payment. Never card data or credentials |
| `recorded_by` | ULID null, FK → `platform_users` (null on delete) | Offline payments |
| timestamps | | Indexes `(tenant_id, status)`, `(invoice_id, status)` |

### usage_records, platform_sequences — `000030`

| Table | Columns | Keys and indexes |
|---|---|---|
| `usage_records` | `id` ULID, `tenant_id` FK (cascade), `meter` varchar(60), `quantity` unsigned bigint, `idempotency_key` varchar(120), `meta` json null, `occurred_at` timestamp, `created_at` | `UNIQUE(tenant_id, idempotency_key)`; index `(tenant_id, meter, occurred_at)` |
| `platform_sequences` | `name` varchar(60) PK, `value` unsigned bigint | One row per counter; `invoice` is the only one |

### workspace_registrations — `000040`

| Column | Type | Notes |
|---|---|---|
| `id` | ULID, PK | |
| `email`, `name`, `company` | varchar | |
| `password` | varchar(255) | Hashed. Used only if the address has no account when the sign-up is confirmed |
| `slug` | varchar(63) | The address being reserved |
| `locale` | varchar(8) | |
| `plan_id`, `interval` | ULID null FK → `plans` (null on delete), varchar(10) null | |
| `token_hash` | char(64), **unique** | SHA-256 of the emailed token |
| `expires_at`, `completed_at` | timestamp, ts | Waiting = not completed and not expired |
| `tenant_id` | ULID null, FK → `tenants` (null on delete) | Set when completed |
| `ip_address`, timestamps | | Indexes `(slug, completed_at, expires_at)`, `(email, completed_at)` |

Pruned daily: unconfirmed rows a day after they expire, completed rows after a month.

### platform_settings, impersonations — `000050`

| Table | Columns | Keys and indexes |
|---|---|---|
| `platform_settings` | `key` varchar(100) PK, `value` json, timestamps | Only settings that differ from `config/platform.php` are stored |
| `impersonations` | `id` ULID, `platform_user_id` FK → `platform_users` (cascade), `tenant_id` FK (cascade), `user_id` FK → `users` (cascade), `reason` varchar(500), `token_hash` char(64) null **unique**, `token_expires_at` ts, `started_at` ts, `expires_at` timestamp, `ended_at` ts, `ip_address`, timestamps | Indexes `(tenant_id, created_at)`, `(platform_user_id, created_at)` |
## 3b. Phase 3 App Store tables

Migration `2026_10_10_000060_create_app_store_tables.php`.

### apps, app_versions

| Table | Columns | Keys and indexes |
|---|---|---|
| `apps` | `id` ULID, `module_key` varchar(60), name, description, category, icon, `is_featured`, `is_popular`, `is_published`, `current_version_id` null FK, timestamps | `UNIQUE(module_key)`; indexes for published/category/name and featured catalogue views |
| `app_versions` | `id` ULID, `app_id` FK, version, compatibility, JSON `features`, `screenshots`, `offers`, `dependencies`, `permissions`, `routes`, `scheduled_jobs`, `settings`, release notes, SHA-256 `manifest_hash`, `published_at`, timestamps | `UNIQUE(app_id, version)` |

The two-way current-version relationship is created after both tables exist. `apps:sync` is the only catalogue
writer: it copies registered optional manifests, refuses a changed hash for an existing version, advances
`current_version_id`, and can unpublish missing modules with `--prune`. Old versions are retained.

### app_installations

| Column | Type | Notes |
|---|---|---|
| `id` | ULID, PK | |
| `tenant_id`, `app_id` | ULID FKs | Tenant cascades; catalogue app restricts deletion; `UNIQUE(tenant_id, app_id)` |
| `app_version_id` | ULID null FK | Version last installed/activated; null on version deletion |
| `status` | varchar(24) | `pending_payment`, `active`, `inactive`, `uninstalled` |
| `deactivation_reason` | varchar(24) null | `manual` or `licence_expired`; prevents a renewal from overriding a deliberate manual deactivation |
| `settings` | LONGTEXT null | Whole typed settings array encrypted by the model's `encrypted:array` cast; no plaintext secret column |
| `installed_at`, `activated_at`, `deactivated_at`, `uninstalled_at` | ts | State history timestamps; the row is reused on reinstall |

Index `(tenant_id, status)`. Uninstall updates this row; it never deletes the module's business data.

### subscription_items

| Column | Type | Notes |
|---|---|---|
| `id`, `tenant_id`, `app_id`, `installation_id` | ULID/FKs | Central app commercial item scoped to one workspace and installation |
| `offer_key`, `billing_type`, `status` | varchar | Type: free/monthly/yearly/one-time/usage-based/enterprise; states pending, trialing, active, past due, canceled, expired |
| `currency`, `amount`, `quantity` | char(3) null, unsigned bigint, unsigned int | Offer snapshot; amount is per unit in minor units |
| trial / current period / grace dates | ts | Same time model as base subscriptions, but independent |
| `cancel_at_period_end`, `canceled_at`, `ended_at` | bool / ts | Renewal cancellation and final state |

Indexes `(tenant_id, app_id, status)`, `(status, current_period_end)` and `(status, trial_ends_at)` feed tenant
reads and `billing:run`.

### app_entitlements

| Column | Type | Notes |
|---|---|---|
| `id`, `tenant_id`, `app_id`, `installation_id` | ULID/FKs | Tenant-scoped licence for one installed app |
| `subscription_item_id` | ULID null FK | Null for free/manual licence; null on item deletion so the historical grant remains |
| `status`, `source` | varchar | Status active/expired/revoked; source free/purchase/trial/manual |
| `license_id` | ULID, unique | Public-safe licence identifier, independent of the row id |
| `valid_from`, `valid_until`, `revoked_at` | timestamps | Effective time window; null `valid_until` means no scheduled end |

Indexes `(tenant_id, app_id, status)` and `(tenant_id, status, valid_until)`. An entitlement grants access only
while it is effective **and** its installation is active; dependency validation can still remove it from the
effective module set.

### invoices (added columns)

`app_id` and `subscription_item_id` are nullable FKs for `app_purchase` / `app_renewal` invoices. Base-plan
invoices continue to use `subscription_id` and `plan_id`. Index `(tenant_id, app_id, status)` supports app invoice
history. Invoice lines remain immutable snapshots.

## 3c. Phase 4 CRM tables

Module migrations live in `app/Modules/CRM/Database/Migrations` and are loaded by `CRMServiceProvider`.

| Area | Tables | Important constraints |
|---|---|---|
| Pipeline | `crm_lead_sources`, `crm_pipelines`, `crm_pipeline_stages` | Names and stage positions are unique inside the tenant/pipeline; won and lost are explicit terminal flags |
| Work | `crm_leads`, `crm_deals`, `crm_deal_stage_history`, `crm_activities` | Shared contact/company FKs; owner/department/branch indexes; immutable stage-change rows; activity reminder and idempotent automation-key indexes |
| Record content | `crm_notes`, `crm_tags`, `crm_taggables`, `crm_custom_fields`, `crm_custom_field_values`, `crm_segments`, `crm_attachments`, `crm_timeline_entries` | Lead/deal/contact/company content, contact/company saved segments, tenant-first uniqueness, private storage paths and ordered customer timeline indexes |
| Catalogue | `crm_products`, `crm_price_lists`, `crm_price_list_items` | SKU and price-list names are tenant-unique; quantity is thousandths; prices are integer minor units |
| Selling | `crm_quotes`, `crm_quote_lines`, `crm_sales_orders`, `crm_sales_order_lines`, `crm_contracts` | Unique tenant document numbers, bounded integer snapshotted lines/totals, optional same-customer/currency deal link, one order per accepted quote, explicit lifecycle statuses |
| Lifecycle | `crm_customer_profiles`, `crm_sales_targets` | One contact/company profile per tenant; exact lifetime values are a currency-to-minor-unit JSON map recognized from a linked deal or standalone order, never both; targets have owner scope and date indexes |
| Automation safety | `crm_automation_action_receipts` | Durable action output with `UNIQUE(tenant_id, action, idempotency_key)` so a retry cannot repeat a mutation |

All tables use ULIDs and carry `tenant_id`; every tenant-owned unique/index begins with it. Shared customer rows
are referenced, not copied. Removing a contact/company nulls the reference where the business record must remain.
Money columns are unsigned integer minor units, quantities are `*_milli`, and rates are basis points or integer
percentages. See `docs/CRM.md` for the workflows and status rules.

## 3d. Phase 5 Project Management tables

Module migrations live in `app/Modules/Projects/Database/Migrations` and are loaded by
`ProjectsServiceProvider`.

| Area | Tables | Important constraints |
|---|---|---|
| Setup and delivery | `project_categories`, `project_templates`, `projects`, `project_members` | Shared contact/company and membership FKs; owner/department/branch scope indexes; report range and stable name/id pagination indexes; integer budget and currency |
| Planning | `project_tasks`, `project_task_assignees`, `project_task_watchers`, `project_task_dependencies`, `project_task_checklist_items`, `project_milestones`, `project_sprints` | Project-local hierarchy/dependencies; ordered status/date indexes; assignment and watcher uniqueness; recurrence/reminder keys |
| Collaboration | `project_task_comments`, `project_comment_mentions`, `project_attachments`, `project_client_access` | Explicit internal comments, unique mentions, private polymorphic files and hashed revocable client tokens |
| Time and money | `project_time_entries`, `project_expenses` | Integer minutes/rates/amounts, currency, worker/project/status/date aggregation indexes and stable date/created/id pagination indexes |
| Governance | `project_approvals`, `project_activity_history` | Entity-targeted decisions and immutable ordered project/task history |
| Automation safety | `project_automation_action_receipts` | `UNIQUE(tenant_id, action, idempotency_key)` prevents replayed mutations |

Every table uses a ULID key and carries `tenant_id`; tenant-owned unique keys and indexes begin with it. Project
client/team identity is referenced from core, not copied. Money uses unsigned integer minor units, durations use
integer minutes and timestamps represent UTC. See `docs/PROJECTS.md` for access and integration boundaries.

## 3e. Phase 6 Invoicing tables

Module migrations live in `app/Modules/Invoicing/Database/Migrations` and are loaded by `InvoicingServiceProvider`.
These are customer invoices; the platform's own `invoices` table (3a) is unrelated.

| Area | Tables | Important constraints |
|---|---|---|
| Documents | `invoicing_invoices`, `invoicing_invoice_lines` | `UNIQUE(tenant_id, number)` (nullable: drafts have none), `UNIQUE(tenant_id, source_key)` for idempotent creation from a quote, recurring period or automation key; customer/seller snapshot columns; integer subtotal/discount/tax/total/paid/credited/balance; owner/department/branch scope indexes; `UNIQUE(tenant_id, invoice_id, position)` |
| Settlement | `invoicing_payments`, `invoicing_credit_notes`, `invoicing_credit_note_lines` | `UNIQUE(tenant_id, idempotency_key)` on payments; reversal columns instead of deletes; `UNIQUE(tenant_id, number)` on credit notes |
| Numbering | `invoicing_number_sequences` | `UNIQUE(tenant_id, kind, period)`; the row is locked `FOR UPDATE` inside the issuing transaction |
| Configuration | `invoicing_settings`, `invoicing_tax_rates` | One settings row per tenant; tax rate names unique per tenant |
| Recurring and sources | `invoicing_recurring_templates`, `invoicing_billable_time` | Next-run index; billable time keyed `UNIQUE(tenant_id, time_entry_id)` with opaque project/entry ids (no foreign key into Projects) |
| Operations | `invoicing_reminder_logs`, `invoicing_automation_action_receipts` | `UNIQUE(tenant_id, invoice_id, reminder_key)`; `UNIQUE(tenant_id, action, idempotency_key)` |

Money is unsigned integer minor units next to a `currency`; quantities are `quantity_milli`; rates are basis
points. See `docs/INVOICING.md`.

## 4. Later tables (outline only)

Names come from spec section 23. Nothing below is designed in detail or migrated. Spec 3.4 names plans, apps and
the platform's invoices to tenants as central data; rows marked † are our reading of that rule for tables it does
not name, to be confirmed when they are designed.

| Group | Phase | Tables | Ownership | Fixed by the spec |
|---|---|---|---|---|
| Core SaaS | 8 | `notifications` | Tenant-owned | In-app, email, SMS, WhatsApp, realtime; read/unread; delivery tracking |
| Core SaaS | not assigned | `files`, `integrations`, `api_credentials`, `webhooks` | Tenant-owned | Files polymorphic, private, tenant-prefixed; credentials encrypted; webhook idempotency by provider event id |
| Billing | not assigned | coupons, refunds | Central † | Spec section 16 lists them; nothing is designed |
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
4. Foreign keys cannot cross databases: `invitations.role_id → roles`, `invitations.invited_by → users`,
   `contacts` / `companies` `.owner_id → tenant_memberships`, `.department_id → departments` and
   `.branch_id → branches`, and every `tenant_id → tenants` on a moved table would have to be dropped and
   enforced in code.
5. `roles`, their pivots and `audit_logs` use the default connection because their models do not go through the
   resolver. Decide whether they move with the tenant or stay central.
6. `DB::transaction()` opens on the default connection. A write to a tenant connection inside it is not atomic
   with the central writes around it.
