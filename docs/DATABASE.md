# Database

State: the schema of every registered module is implemented (about 260 tables once migrated). Sections 1–3 describe
`database/migrations/` and each module's migration directory (`app/Modules/<M>/Database/Migrations`); section 4
outlines tables that are still unbuilt. Section 5 is the dedicated-database strategy the spec asks for (3.4);
**only its routing seam exists today.** A table is documented here only if it exists in a migration.

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
| Secrets | Encrypted casts (`two_factor_secret`, recovery codes, `app_installations.settings`, `social_accounts` tokens, `automation_workflows.webhook_secret`, `automation_webhook_endpoints.secret`, `ai_proposals.payload`, `communication_provider_accounts.secrets`, `ai_provider_accounts.secrets`) or a one-way hash (`invitations.token_hash`, registration token/password, `impersonations.token_hash`, unsubscribe and survey tokens). Never plain |
| Migrations | Reversible (`down()` on every one). `DB::prohibitDestructiveCommands()` is on in production. **A migration that has been committed is never edited**: databases that already ran it would never get the change. A schema change is a new, additive migration with a later timestamp, guarded with `Schema::hasColumn()` / `hasTable()` so a fresh install and an already-migrated database both end in the same shape (examples: `support_tickets.resolution_first_breached_at`, `email_marketing_deliveries.send_epoch`) |
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
| `communication_provider_accounts` | Central, **no `tenant_id`** | `ProviderAccount` | `UsesCentralConnection`; operator-owned messaging accounts, `tenant_ids` is an allow-list (section 3i, ADR 0013) |
| `communication_account_claims` | Central, **no `tenant_id`** | `AccountClaim` | `connection_id` points at a tenant-owned connection; platform-wide uniqueness (section 3i, ADR 0016) |
| `ai_provider_accounts` | Central, **no `tenant_id`** | `AiProviderAccount` | Operator-owned AI provider accounts; never read for a workspace (section 3h.0) |
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

## 3f. Phase 7 HR Management tables

Module migrations live in `app/Modules/Hrm/Database/Migrations` and are loaded by `HrmServiceProvider`. All
tables carry `tenant_id` and ULID keys.

| Area | Tables | Important constraints |
|---|---|---|
| Employees | `hrm_employees`, `hrm_designations`, `hrm_number_sequences`, `hrm_settings` | `UNIQUE(tenant_id, employee_number)`; `UNIQUE(tenant_id, owner_id)` (the optional linked membership); self-referencing `manager_id`; `national_id` encrypted text; hourly cost as unsigned integer minor units; the counter row is locked `FOR UPDATE` in the creating transaction |
| Employee records | `hrm_employment_history`, `hrm_emergency_contacts`, `hrm_documents`, `hrm_assets` | The history has `created_at` only and the model refuses update/delete; document paths are `TenantStorage` paths, never public |
| Attendance | `hrm_shifts`, `hrm_shift_assignments`, `hrm_holidays`, `hrm_attendance_entries`, `hrm_attendance_days`, `hrm_regularizations` | `UNIQUE(tenant_id, holiday_on)`; `UNIQUE(tenant_id, employee_id, open_marker)` allows one open entry per employee; `UNIQUE(tenant_id, employee_id, work_date)` on days; instants in UTC, dates and `HH:MM` shift times are workspace-local |
| Leave | `hrm_leave_types`, `hrm_leave_policies`, `hrm_leave_requests`, `hrm_leave_approvals`, `hrm_leave_ledger` | Units are integers (one day = 2); `UNIQUE(tenant_id, idempotency_key)` on the append-only ledger; `UNIQUE(tenant_id, leave_request_id, step)` on approvals; owner/department/branch copies on requests, entries, days and corrections for scoped access |
| Operations | `hrm_automation_action_receipts` | `UNIQUE(tenant_id, action, idempotency_key)` |

See `docs/HRM.md`.

## 3g. Phase 7 Payroll tables

Module migrations live in `app/Modules/Payroll/Database/Migrations` and are loaded by `PayrollServiceProvider`. All
tables carry `tenant_id` and ULID keys; employees are referenced by a plain ULID column with **no foreign key** to
HRM tables (Payroll reads HRM only through contracts). Money is unsigned `BIGINT` minor units next to a `currency`.

| Area | Tables | Important constraints |
|---|---|---|
| Configuration | `payroll_settings`, `payroll_number_sequences`, `payroll_structures`, `payroll_structure_components` | `UNIQUE(tenant_id)` on settings; `UNIQUE(tenant_id, kind)` on the counters, locked `FOR UPDATE` in the approving transaction; `UNIQUE(tenant_id, structure_id, code)` |
| Salaries | `payroll_profiles`, `payroll_salary_assignments` | `UNIQUE(tenant_id, employee_id)` on profiles (placement copies, `account_number` encrypted); revisions are immutable (`created_at` only, model refuses update/delete), `UNIQUE(tenant_id, employee_id, revision)` and `(…, effective_from)` |
| Runs | `payroll_periods`, `payroll_runs`, `payroll_payslips`, `payroll_payslip_lines`, `payroll_payments` | `UNIQUE(tenant_id, starts_on, ends_on)`; `UNIQUE(tenant_id, period_id, regular_marker)` one regular run per period; `UNIQUE(tenant_id, reverses_run_id)`; `UNIQUE(tenant_id, number)` (assigned at approval); `UNIQUE(tenant_id, run_id, employee_id)`; `UNIQUE(tenant_id, idempotency_key)` on the append-only payment ledger; `RESTRICT` foreign keys so locked payslips cannot be removed by a cascade |
| Inputs | `payroll_items`, `payroll_loans`, `payroll_loan_installments` | `UNIQUE(tenant_id, loan_id, sequence)`; owner/department/branch copies for scoped access |
| Compliance | `payroll_rule_sets`, `payroll_rule_slabs` | `UNIQUE(tenant_id, code, effective_from)`; `UNIQUE(tenant_id, rule_set_id, position)`; no preloaded rows |
| Operations | `payroll_automation_action_receipts` | `UNIQUE(tenant_id, action, idempotency_key)` |

See `docs/PAYROLL.md`.

## 3g2. Phase 9 Social tables

Module migrations live in `app/Modules/Social/Database/Migrations` and are loaded by `SocialServiceProvider`. All
tables carry an indexed `tenant_id` and every unique index starts with it.

| Group | Tables | Notes |
|---|---|---|
| Accounts | `social_accounts` | `UNIQUE(tenant_id, platform, external_id)`; `access_token` / `refresh_token` are `text` holding Laravel `encrypted` values, never read back to a browser |
| Content | `social_posts`, `social_post_targets`, `social_post_media`, `social_comments` | posts carry owner/department/branch; `UNIQUE(tenant_id, post_id, account_id)` and `UNIQUE(tenant_id, idempotency_key)` on targets; instants in UTC |
| Publishing history | `social_publish_attempts`, `social_post_metrics` | metric figure columns are nullable: null means the platform did not return it |
| Library | `social_media`, `social_campaigns`, `social_categories`, `social_hashtag_sets`, `social_imports` | `UNIQUE(tenant_id, sha256)` on media; `UNIQUE(tenant_id, file_sha256)` on imports |
| Operations | `social_settings`, `social_automation_action_receipts` | `UNIQUE(tenant_id)` on settings; `UNIQUE(tenant_id, action, idempotency_key)` |

See `docs/SOCIAL.md`.

## 3g2b. SMS Marketing tables

Module migrations live in `app/Modules/SmsMarketing/Database/Migrations` and are loaded by
`SmsMarketingServiceProvider`. All tables carry an indexed `tenant_id` and every unique index starts with it.

| Group | Tables | Notes |
|---|---|---|
| Configuration | `sms_marketing_settings`, `sms_marketing_sender_ids` | `UNIQUE(tenant_id)` on settings; `segment_cost_amount` is an unsigned `BIGINT` of minor units next to `currency`; `UNIQUE(tenant_id, value)` on sender ids (registration columns are the workspace's own record) |
| Audience | `sms_marketing_groups`, `sms_marketing_group_members` | `UNIQUE(tenant_id, name)`; members reference `contacts.id` (`UNIQUE(tenant_id, group_id, contact_id)`), never a copy |
| Content | `sms_marketing_templates`, `sms_marketing_campaigns`, `sms_marketing_campaign_groups` | `UNIQUE(tenant_id, name)`; campaigns carry owner/department/branch and a copy of the text |
| Delivery | `sms_marketing_recipients` | `UNIQUE(tenant_id, campaign_id, contact_id)`; `message_id` is the communication message id; the phone is E.164 |
| Operations | `sms_marketing_imports`, `sms_marketing_automation_action_receipts` | `UNIQUE(tenant_id, action, idempotency_key)` on receipts |

Metered usage (`sms_marketing.segments_per_month`) is recorded in the platform's `usage_records`, not here. See
`docs/SMS_MARKETING.md`.
## 3g3. Email Marketing tables

Module migrations live in `app/Modules/EmailMarketing/Database/Migrations` and are loaded by
`EmailMarketingServiceProvider`. All tables carry an indexed `tenant_id` and every unique index starts with it.

| Group | Tables | Notes |
|---|---|---|
| Audience | `email_marketing_lists`, `email_marketing_subscriptions`, `email_marketing_segments` | `UNIQUE(tenant_id, name)` on lists; a subscription is `UNIQUE(tenant_id, list_id, contact_id)` and points at the shared `contacts` table, with consent evidence columns and a `properties` JSON map; segments hold declarative `rules` JSON |
| Content | `email_marketing_templates`, `email_marketing_campaigns`, `email_marketing_sequences`, `email_marketing_sequence_steps` | `blocks` JSON holds typed blocks, never HTML; campaigns carry owner/department/branch and the A/B settings; `UNIQUE(tenant_id, sequence_id, position)` on steps |
| Sending | `email_marketing_deliveries`, `email_marketing_enrollments` | `UNIQUE(tenant_id, send_key)` is the idempotency key (`campaign:{id}:{contact}` or `sequence:{enrollment}:{step}`); `message_id` is a plain column (the communication message), not a foreign key; `UNIQUE(tenant_id, sequence_id, contact_id)` on enrolments |
| Operations | `email_marketing_imports`, `email_marketing_settings`, `email_marketing_automation_action_receipts` | imports are `UNIQUE(tenant_id, list_id, file_sha256)` and keep counts and errors only; `UNIQUE(tenant_id)` on settings; `UNIQUE(tenant_id, action, idempotency_key)` |

Index names are explicit (`em_...`) because MySQL limits identifiers to 64 characters. See `docs/EMAIL_MARKETING.md`.

## 3h. Phase 7 Talent tables

Module migrations live in `app/Modules/Talent/Database/Migrations` and are loaded by `TalentServiceProvider`. All
tables carry `tenant_id` and ULID keys; employees are referenced by a plain ULID column with **no foreign key** to
HRM tables. Placement columns (`owner_id` to `tenant_memberships`, `department_id`, `branch_id`) make scoped
access work. Money is unsigned `BIGINT` minor units next to a `currency`; review scores are integer hundredths.

| Area | Tables | Important constraints |
|---|---|---|
| Configuration | `talent_settings`, `talent_pipelines`, `talent_pipeline_stages`, `talent_rejection_reasons` | `UNIQUE(tenant_id)` on settings; `UNIQUE(tenant_id, name)`; `UNIQUE(tenant_id, pipeline_id, name)` |
| Recruitment | `talent_job_openings`, `talent_candidates`, `talent_applications`, `talent_application_history`, `talent_candidate_documents`, `talent_notes` | `UNIQUE(tenant_id, opening_id, candidate_id)`; history is append-only (`created_at` only, model refuses update/delete) with plain-id stage columns; email is indexed, not unique (duplicates are detected, not forbidden); document paths are `TenantStorage` paths |
| Interviews and offers | `talent_interviews`, `talent_interview_panel`, `talent_scorecards`, `talent_offers` | instants in UTC plus the zone name; `UNIQUE(tenant_id, interview_id, employee_id)` on panel and scorecards; `UNIQUE(tenant_id, application_id, live_marker)` allows one live offer |
| Onboarding and probation | `talent_checklist_templates`, `talent_template_tasks`, `talent_checklists`, `talent_checklist_tasks`, `talent_probations`, `talent_probation_reviews` | `UNIQUE(tenant_id, kind, name)`; `UNIQUE(tenant_id, source_key)` makes event-driven starts idempotent; `UNIQUE(tenant_id, employee_id, open_marker)` one open probation |
| Performance | `talent_review_forms`, `talent_review_form_items`, `talent_review_cycles`, `talent_reviews`, `talent_review_ratings`, `talent_goals`, `talent_goal_updates` | weights in basis points (sum 10 000, validated); `UNIQUE(tenant_id, cycle_id, employee_id)`; `UNIQUE(tenant_id, review_id, item_id, party)`; goal updates append-only |
| Training | `talent_courses`, `talent_training_sessions`, `talent_enrolments` | `UNIQUE(tenant_id, title)`; `UNIQUE(tenant_id, course_id, employee_id, open_marker)` one open enrolment |
| Operations | `talent_reminders`, `talent_automation_action_receipts` | `UNIQUE(tenant_id, reminder_key)` makes reminders idempotent; `UNIQUE(tenant_id, action, idempotency_key)` |

See `docs/TALENT.md`.

## 3h.0. Phase 11 AI Assistant tables

All tenant-owned (`tenant_id` first in every unique index). No credential or provider payload is stored.

| Group | Tables | Notes |
|---|---|---|
| Configuration | `ai_settings` | `UNIQUE(tenant_id)`: enabled flags, `data_scopes` (JSON list), `retention_days`, `redact_pii` |
| Conversations | `ai_conversations`, `ai_messages` | A conversation belongs to one `membership_id` (cascade); messages hold text, a tool trace of names/ids, token counts and the prompt version. Deleted by retention |
| Proposals | `ai_proposals` | Encrypted `payload`, `status` (pending, confirmed, rejected, failed, expired), `expires_at`; cascade with the conversation |
| Operations | `ai_automation_action_receipts` | `UNIQUE(tenant_id, action, idempotency_key)` |

Token usage is kept in the shared `usage_records` (`meter = ai.tokens`, idempotency key per provider call). See `docs/AI.md`.

`ai_provider_accounts` (`2026_10_25_000200`) is **platform-owned**: central connection, no `tenant_id`, model
`AiProviderAccount`, written only from the Super Admin panel (`platform.ai.*`).

| Column | Notes |
|---|---|
| `id` | ULID |
| `driver` | `anthropic` or `openai_compatible` (`AiDriver`) |
| `name` | `UNIQUE`; shown only in the panel |
| `model`, `base_url` | The model id; the endpoint (required for `openai_compatible`, optional for `anthropic`) |
| `is_enabled`, `is_default` | One enabled account is the platform default; index `(is_default, is_enabled)` |
| `secrets` | `longText`, `encrypted:array`, hidden: key `api_key`. Write-only |
| `last_test_status`, `last_test_reason`, `last_tested_at` | Outcome of "test connection": `ok` or `failed` with a safe reason code, never provider text |

A workspace never sees or chooses an account. It holds an encrypted secret in the database, which ADR 0013 expressly
did not cover (it names AI keys as staying in the environment): see ADR 0018 for the open decision.

## 3h.1. Customer Support tables

Migration `app/Modules/Support/Database/Migrations/2026_10_13_000100_create_support_tables.php`, loaded by
`SupportServiceProvider`. Every table carries an indexed `tenant_id` and every unique index leads with it. Tickets
reference the shared `contacts` / `companies`; the module has no customer table.

| Group | Tables | Notes |
|---|---|---|
| Configuration | `support_settings`, `support_number_sequences`, `support_categories`, `support_tags` | `UNIQUE(tenant_id)` on settings; `UNIQUE(tenant_id, kind)` counter row locked `FOR UPDATE` for gap-free ticket numbers; `UNIQUE(tenant_id, name)` |
| Routing | `support_agents`, `support_queues`, `support_queue_agents`, `support_assignment_rules` | `UNIQUE(tenant_id, membership_id)`; `UNIQUE(tenant_id, queue_id, agent_id)`; `last_assigned_agent_id` is the round-robin pointer; rules are equality columns, no expressions |
| SLA | `support_calendars`, `support_sla_policies` | weekly windows, holidays and per-priority targets are JSON validated to `HH:MM` and integer minutes |
| Tickets | `support_tickets`, `support_ticket_tag`, `support_messages`, `support_attachments`, `support_ticket_events` | `UNIQUE(tenant_id, number)`, `UNIQUE(tenant_id, source_key)` on tickets and on messages (inbound/automation idempotency); `owner_id` / `department_id` / `branch_id` follow the record-owner column contract and `owner_id` is the assignee; SLA columns hold due instants (UTC), stored remaining minutes while paused and one-time `*_breached_at` stamps; events are append-only; attachment `path` is a `TenantStorage` path and hidden from serialization |
| Satisfaction | `support_csat_surveys` | `UNIQUE(tenant_id, token_hash)`; only the SHA-256 of the token is stored and it is nulled once answered, superseded or expired |
| Knowledge base | `support_kb_categories`, `support_kb_articles`, `support_kb_article_versions` | `UNIQUE(tenant_id, slug)`; `UNIQUE(tenant_id, article_id, version)`; versions are immutable |
| Operations | `support_canned_responses`, `support_automation_action_receipts` | `UNIQUE(tenant_id, name)`; `UNIQUE(tenant_id, action, idempotency_key)` |

See `docs/SUPPORT.md`.

## 3i. Phase 8 Communication tables

Migration `app/Modules/Communication/Database/Migrations/2026_10_12_000100_create_communication_tables.php`. All
tables are tenant-owned (`tenant_id` first in every unique index). Documented in `docs/COMMUNICATION.md`.

| Group | Tables | Notes |
|---|---|---|
| Notifications | `communication_notifications`, `communication_notification_preferences` | notification: member (`tenant_memberships`), translation keys + JSON params, route name + params, `read_at`, `archived_at`, `UNIQUE(tenant_id, membership_id, dedupe_key)`; preference: `UNIQUE(tenant_id, membership_id, channel, category)` |
| Configuration | `communication_settings`, `communication_channel_connections` | one settings row per tenant; one connection per `(tenant_id, channel)` holding provider key, non-secret JSON settings and `credential_ref` / `webhook_secret_ref` **names** - never a secret |
| Templates | `communication_templates` | `UNIQUE(tenant_id, slug, channel, locale)` |
| Outbox and log | `communication_messages` | `UNIQUE(tenant_id, idempotency_key)`; status, attempts, `next_attempt_at`, provider message id (indexed for webhooks); contact/company/member links nullable; owner/department/branch scope columns |
| Timeline | `communication_interactions` | contact and/or company, `occurred_at`, `UNIQUE(tenant_id, dedupe_key)`, owner/department/branch scope columns; `message_id` links outbox messages |
| Consent and suppression | `communication_consents`, `communication_suppressions`, `communication_unsubscribe_tokens` | consent: `UNIQUE(tenant_id, contact_id, channel, purpose)` (current decision; history in the audit trail); suppression: `UNIQUE(tenant_id, channel, address, scope)`; tokens: SHA-256 hash only, `UNIQUE(tenant_id, token_hash)`, expiry |
| Operations | `communication_webhook_events`, `communication_automation_action_receipts` | `UNIQUE(tenant_id, provider, event_id)` (no payload kept); `UNIQUE(tenant_id, action, idempotency_key)` |

Foreign keys to `contacts`, `companies` and `tenant_memberships` are `nullOnDelete` (consent and tokens of a
contact cascade). Contact merges are handled by `CommunicationContactMergeReference`.

### communication_provider_accounts - `2026_10_12_000300`

**Platform-owned, not tenant-owned**: central connection, no `tenant_id`, model `ProviderAccount`
(`UsesCentralConnection`). Operator-created messaging provider accounts (ADR 0013); written only from the Super
Admin panel.

| Column | Notes |
|---|---|
| `id` | ULID primary key. A workspace's `credential_ref` is `acct_` + the lowercased id |
| `channel`, `provider` | `email` / `sms` / `whatsapp`; the adapter key the account was created for (`smtp`, `twilio`, `http_sms`, `whatsapp_cloud`). The account answers only to that provider |
| `name` | Label shown in the workspace's credential dropdown. `UNIQUE(channel, name)` |
| `is_enabled` | Disabled accounts resolve to nothing |
| `settings` | JSON, non-secret (host, sender, endpoint, sender id) |
| `secrets` | `longText`, **encrypted** (`encrypted:array` cast with `APP_KEY`), hidden from serialization. Keys: `secret`, `webhook_secret`, `verify_token`. Never selected for a page |
| `tenant_ids` | JSON, null = every workspace; otherwise the workspace ids allowed to use it |

Index `(channel, is_enabled)`. This is the only table that stores messaging secrets, and the only exception to
"credentials stay in the environment" that ADR 0013 records; payment gateway credentials are never stored. (The AI
provider accounts table below is a second secret-bearing central table; see its note.)

### communication_account_claims - `2026_10_12_000200`

**Platform-wide, not tenant-owned**: no `tenant_id`, model `AccountClaim` (no tenant scope). A registry that lets a
provider account value (a WhatsApp `phone_number_id`) be connected by one workspace only, which a tenant-first unique
index cannot express (ADR 0016).

| Column | Notes |
|---|---|
| `id` | ULID |
| `connection_id` | FK to `communication_channel_connections` (`cascadeOnDelete`): the owning connection. It is the only tenant link |
| `provider`, `account_key`, `account_value` | What is claimed: provider key, the field name (`phone_number_id`), the value |
| unique | `(provider, account_key, account_value)` (`communication_account_claims_account_unique`), deliberately without `tenant_id`; index `connection_id` |

Written only by `ChannelConnections::save()` (inside the connection's transaction; a second claim of the same value is
a `UniqueConstraintViolationException` turned into the form error "This account is already connected to another
workspace"); the connection's old claims are replaced on every save and removed with it (cascade). It is read and
deleted through `connection_id`, never listed across tenants. It is an intentional exception to "unique indexes lead
with `tenant_id`"; `TenantIndexesTest` does not see it because the table has no `tenant_id` column.

## 3j. Phase 10 Workflow Automation tables

Migration `app/Modules/Automation/Database/Migrations/2026_10_20_000100_create_automation_tables.php`. All tables are
tenant-owned (ULID keys, `tenant_id` first in every unique index). Documented in `docs/WORKFLOWS.md`. Every
foreign key to `tenant_memberships` is `nullOnDelete`.

| Group | Tables | Notes |
|---|---|---|
| Definition | `automation_workflows` | name, status (`draft` / `active` / `paused` / `archived`), `actor_membership_id` (the member the workflow runs as: its last publisher), `published_version_id`, `trigger_key` + `trigger_config` copied from the published version for one indexed match, `webhook_token_hash` (SHA-256; `UNIQUE(tenant_id, webhook_token_hash)`), `webhook_secret` (encrypted cast: HMAC needs the secret), `next_run_at` for cron schedules; indexes `(tenant_id, status, trigger_key)` and `(tenant_id, status, next_run_at)` |
| Versions | `automation_workflow_versions` | `UNIQUE(tenant_id, workflow_id, number)`; `graph` JSON, `graph_hash` (SHA-256 of the canonical graph, set at publish), status `draft` / `published` / `archived`. The model refuses any change to a published row except archiving it, and refuses deleting it |
| Runs | `automation_runs` | `UNIQUE(tenant_id, workflow_id, idempotency_key)` makes trigger delivery idempotent; `status`, `mode` (`live` / `dry_run`), `source`, `depth` + `parent_run_id` (workflow-triggers-workflow chains), `context` JSON (trigger payload, step outputs, variables, loop/join state), `locked_until` (the executor's lease), `resume_at`, `cancel_requested`, `graph` snapshot for test runs only; index `(tenant_id, status, resume_at)` drives the sweeper |
| Steps | `automation_run_steps` | one row per node execution: `status`, `attempt`, `sequence`, `scope` (parallel branch), `available_at` (retry backoff), `resume_at` (delay/schedule/approval expiry), redacted `input` / `output`, `logs` (one entry per attempt), `error_code` / `error_message`; index `(tenant_id, run_id, status, available_at)` |
| Approvals | `automation_approvals` | `UNIQUE(tenant_id, run_step_id)`; approver (permission name or membership), `prevent_self_approval`, `requested_by_membership_id` (the person the run acts for), decision, `expires_at` + `on_timeout`; index `(tenant_id, status, expires_at)` |
| Endpoints | `automation_webhook_endpoints` | `UNIQUE(tenant_id, name)`; `url`, `secret` (encrypted cast, write-only, hidden from serialization), `is_active`, last call facts |

The spec's `workflow_executions` / `workflow_steps` / `workflow_logs` are `automation_runs` / `automation_run_steps`
(the step row carries its attempt log). Run history has no retention job yet.

## 3k. Reports & Analytics tables

Migration `app/Modules/Reports/Database/Migrations/2026_10_22_000100_create_reports_tables.php`, loaded by
`ReportsServiceProvider`.

| Table | Notes |
|---|---|
| `reports_saved_reports` | A stored report setup, never data or a query. `tenant_id` FK; `owner_membership_id` -> `tenant_memberships` (`nullOnDelete`); `provider_key`, `columns` (JSON, null = defaults), `filters` (JSON, declared keys only); `range_mode` (`last_days` / `fixed`), `range_days`, `range_from`, `range_to`; `visibility` (`private` / `role` / `department`), `role_id` -> `roles` and `department_id` -> `departments`, both `nullOnDelete`. `UNIQUE(tenant_id, owner_membership_id, name)`; indexes lead with `tenant_id` |

See `docs/REPORTS.md`.

## 3k2. WhatsApp Business tables

Module migrations live in `app/Modules/WhatsappMarketing/Database/Migrations` and are loaded by
`WhatsappMarketingServiceProvider`. All tables carry an indexed `tenant_id` and every unique index starts with it. No
table stores a provider token, secret or verify token (the connection is a credential reference in the
communication app), and none stores money (cost is "not reported").

| Group | Tables | Notes |
|---|---|---|
| Configuration | `whatsapp_settings`, `whatsapp_agents`, `whatsapp_templates`, `whatsapp_quick_replies`, `whatsapp_flows` | `UNIQUE(tenant_id)` on settings; `UNIQUE(tenant_id, membership_id)` on agents; `UNIQUE(tenant_id, name, language)` on templates (status carries `status_source` provider/admin); `UNIQUE(tenant_id, shortcut)`; flows hold only the closed step vocabulary as JSON |
| Audience | `whatsapp_lists`, `whatsapp_list_members` | members reference `contacts`; `UNIQUE(tenant_id, list_id, contact_id)` |
| Campaigns | `whatsapp_campaigns`, `whatsapp_campaign_recipients` | campaign has owner/department/branch and snapshots of template name, language, category, body and list name; `UNIQUE(tenant_id, campaign_id, contact_id)`; recipient `state` and `reason` mirror the communication platform's answer |
| Conversations | `whatsapp_conversations`, `whatsapp_messages`, `whatsapp_windows` | `UNIQUE(tenant_id, phone)` (E.164); owner/department/branch of the assignee; `UNIQUE(tenant_id, provider_message_id)` is the inbound de-duplication key; message `billable` and `pricing_category` are nullable (null = not reported); `attachments` JSON is metadata only; windows are the metered unit |
| Operations | `whatsapp_automation_receipts` | `UNIQUE(tenant_id, action, idempotency_key)` |

See `docs/WHATSAPP.md`.

## 4. Later tables (outline only)

Names come from spec section 23. Nothing below is designed in detail or migrated. Spec 3.4 names plans, apps and
the platform's invoices to tenants as central data; rows marked † are our reading of that rule for tables it does
not name, to be confirmed when they are designed.

| Group | Phase | Tables | Ownership | Fixed by the spec |
|---|---|---|---|---|
| Core SaaS | 8 | `notifications` | Tenant-owned | Built as `communication_notifications` and the message log (section 3i); realtime (Reverb) is not built |
| Core SaaS | not assigned | `files`, `integrations`, `api_credentials`, `webhooks` | Tenant-owned | Files polymorphic, private, tenant-prefixed; credentials encrypted; webhook idempotency by provider event id |
| Billing | not assigned | coupons, refunds | Central † | Spec section 16 lists them; nothing is designed |
| Automation | 10 | `workflows`, `workflow_versions`, `workflow_executions`, `workflow_steps`, `workflow_logs` | Tenant-owned | Built as `automation_*` (section 3j) |

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
