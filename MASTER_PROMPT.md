# MASTER PROMPT — PROFESSIONAL ALL-IN-ONE MULTI-TENANT BUSINESS SAAS PLATFORM (v2)

> **How to use this prompt:** Paste this whole file into your AI coding agent at the start of the project. This prompt intentionally asks the agent to complete **Phase 1 only** and then stop for review. For every later phase, start a new session with: *"Read CLAUDE.md, PROGRESS.md and docs/, then implement Phase N."*

---

## 0. LOCKED PROJECT DECISIONS

These decisions are final unless the project owner changes them. Do not override them silently. If one turns out to be technically impossible, stop and explain why, propose an alternative, and wait for approval.

| Topic | Decision |
|---|---|
| Architecture | Modular monolith, single shared MySQL database, tenant_id on every tenant-owned table |
| Tenant resolution | Subdomain-based: `{tenant}.APP_DOMAIN`. Central/marketing site on the root domain. Custom domains are a future feature — design for it, do not build it now |
| Local development | Use `*.localhost` subdomains (or `lvh.me`) so subdomain routing works locally without DNS setup |
| Super Admin | Separate auth guard, separate route group under `admin.APP_DOMAIN`, separate Inertia layout. Super Admin users live in a separate `platform_users` table, never in tenant `users` |
| Primary keys | ULIDs for all tenant-owned business tables (eases future dedicated-DB migration and prevents ID enumeration). Auto-increment allowed only for pure lookup/pivot tables |
| Tenancy package | Prefer `stancl/tenancy` (single-database mode) **only if** a release officially supports Laravel 13. Otherwise build a small custom tenancy layer (tenant resolver middleware, `BelongsToTenant` trait with global scope, tenant context service). Record the choice in `docs/adr/` |
| RBAC package | `spatie/laravel-permission` with **teams mode** (team = tenant), if Laravel 13 compatible. Otherwise a custom equivalent. Record in an ADR |
| Search | Laravel Scout. Use the `database` driver in Phase 1; design so Meilisearch can be switched in later for Bengali and cross-module search |
| Backend tests | Pest |
| Frontend tests | Vitest for components; Playwright for critical end-to-end flows (added from Phase 2 onward) |
| Local environment | Laravel Sail (Docker) with MySQL 8, Redis, Mailpit, and (later) Meilisearch |
| Default locale | Languages: English (default) and Bengali. Default currency: BDT. Default timezone: Asia/Dhaka. All configurable per tenant |
| Money | Store money as integer minor units (paisa/cents) plus a currency code. Never use floats for money |
| Library licensing | Use only MIT, BSD, Apache-2.0, or ISC licensed packages. Do not use GPL/AGPL or commercial-license libraries (e.g., commercial Gantt or email-builder libraries) without explicit approval |
| Customer identity | One shared `contacts` (people) and `companies` (organizations) model in the platform core, reused by CRM, Email/SMS/WhatsApp marketing, Support, and Projects. Modules attach their own data to these records; they do not create duplicate customer tables |

---

## 1. YOUR ROLE

Act as a Principal Software Architect, Senior Laravel Engineer, Senior Vue.js Engineer, SaaS Product Architect, Database Architect, Security Engineer, UI/UX Designer, QA Engineer, and DevOps Engineer.

Your responsibility is to design and implement a complete, production-grade, scalable, secure, modular, multi-tenant SaaS platform from scratch.

This is a completely new project. Do not treat it as an extension of an existing ERP or CRM.

**Project Vision:**

Build a professional All-in-One Business Management, Collaboration, Sales, Marketing, AI, and Automation SaaS Platform with a built-in App Store.

The platform combines HRM, project management, CRM, invoicing, social media management, email marketing, SMS marketing, WhatsApp communication, customer support, and advanced workflow automation.

Use Odoo, Zoho, HubSpot, ClickUp, Monday.com, Shopify App Store, Zapier, Make, and n8n as conceptual product references only. Do not copy their proprietary code, interfaces, or intellectual property. The product must have its own professional design, architecture, and user experience.

---

## 2. MANDATORY TECHNOLOGY STACK

**Backend**
- PHP 8.4+
- Laravel 13
- MySQL 8+
- Redis
- Laravel Horizon
- Laravel Scheduler
- Laravel Reverb
- Laravel Sanctum
- Laravel Policies and Gates
- Laravel Scout

**Frontend**
- Vue 3 with Composition API and `<script setup lang="ts">`
- Inertia.js
- Tailwind CSS
- TypeScript (strict mode)
- Pinia only where shared client state is genuinely needed
- Vue Flow for the visual workflow editor
- Reusable Vue components
- Responsive layouts

**Infrastructure**
- Nginx
- Supervisor or systemd for queue workers
- S3-compatible object storage
- GitHub Actions CI/CD
- Docker-compatible deployment configuration (Laravel Sail for local)
- Secure environment configuration

Rules:
- Verify framework and package compatibility (check the package's composer.json / release notes) before installing anything.
- Prefer native Laravel and Vue capabilities.
- Do not introduce unnecessary frameworks, microservices, or complexity.
- Every new dependency must be justified in the relevant ADR or PROGRESS.md entry.

---

## 3. CORE ARCHITECTURE

Build a modular monolith with a single shared MySQL database initially.

### 3.1 Module structure

Organize code by domain module, for example:

```
app/
  Core/            # tenancy, auth, RBAC, entitlements, audit, notifications, files, contacts
  Modules/
    AppStore/
    Billing/
    Automation/
    CRM/
    Projects/
    HRM/
    Invoicing/
    Marketing/     # Email, SMS, WhatsApp, Social (sub-modules)
    Support/
    AI/
resources/js/
  Core/ (layouts, components, composables, types)
  Modules/<Module>/Pages, Components
```

Each module owns its routes, controllers, models, migrations, policies, events, jobs, service provider, and tests. Modules communicate through events and documented service contracts, never by reaching into another module's internals.

### 3.2 Required capabilities

1. Multiple independent tenants
2. Multiple users per tenant (a user may belong to more than one tenant — design the membership table accordingly)
3. Multiple branches and departments per tenant
4. Tenant-specific roles and permissions
5. Tenant-specific subscriptions
6. Tenant-specific installed applications
7. Tenant-specific settings and integrations
8. Central billing and subscription management
9. Tenant-specific workflow automation
10. Strict tenant data isolation
11. Module-based feature activation
12. Independent app licensing
13. Usage-based billing
14. API-first integration capabilities
15. Event-driven cross-module communication

### 3.3 Tenant isolation

Every tenant-owned record carries `tenant_id` and is protected by a global scope plus authorization policies.

Prevent cross-tenant data leaks through:

- Database queries and relationships
- Route model binding
- API endpoints
- Background jobs (jobs must carry and re-establish tenant context, and fail closed if it is missing)
- Cache (tenant-prefixed keys)
- File storage (tenant-prefixed paths, private by default)
- Search results
- Exports
- WebSocket channels (channel authorization must check tenant membership)
- Notifications
- Automation executions
- Unique constraints (must be composite with `tenant_id`, e.g., `UNIQUE(tenant_id, email)`)

### 3.4 Future dedicated database support

Design so selected large tenants can later move to dedicated MySQL databases without changing the user experience.

- Separate central platform data (tenants, plans, apps, platform users, invoices to tenants) from tenant business data at the architectural level.
- All tenant-model access goes through a tenant-aware connection resolver so the connection can later be switched per tenant.
- Document a strategy (in `docs/DATABASE.md`) for: shared vs dedicated tenants, tenant DB routing, schema versioning, tenant migration, data export/import, backup/restore, migration validation, rollback and recovery.

Do not migrate any tenant data without explicit authorization and verified backups.

---

## 4. PLATFORM USER TYPES

- **Platform Super Admin** — manages the entire SaaS platform.
- **Platform Support Admin** — performs explicitly authorized, audited support actions.
- **Tenant Owner** — controls company settings, subscriptions, installed apps, users, and billing.
- **Tenant Admin** — manages authorized company operations.
- **Department Manager** — controls assigned departments and teams.
- **Employee** — accesses assigned features.
- **Sales Agent** — uses CRM and sales tools.
- **Marketing Manager** — controls campaigns and marketing integrations.
- **Project Manager** — manages authorized projects and resources.
- **External Client** — limited customer-facing portal where enabled (separate guard).

Implement flexible RBAC: custom roles, granular permissions, scoped access (own / department / branch / all), and audit logs.

Permissions are registered by each module (e.g., `crm.leads.view`, `crm.leads.edit`). A permission is only effective if the tenant also holds the entitlement for that module.

Never grant access based only on frontend menu visibility.

---

## 5. CORE SAAS PLATFORM

One centralized foundation reused by all applications:

- Tenant registration and company onboarding
- Email verification
- Login, logout, password recovery
- Two-factor authentication (TOTP + recovery codes)
- Company profile and tenant workspace
- Subscription and trial management
- Branches and departments
- User invitations and user management
- Custom roles and permissions
- Activity logs and audit logs
- Notification center
- File manager and document attachments (polymorphic, private, tenant-scoped)
- Shared contacts and companies (see Section 0)
- Global search
- Personal and company settings
- Localization, timezone, currency settings
- English and Bengali language support (including Bengali numerals and date display as a user preference)
- Email and system notification preferences
- API credentials management
- Webhook configuration
- Security settings and login session management
- Usage monitoring
- Data export
- Data retention settings

---

## 6. HRM & PAYROLL APPLICATION

**Employee Management:** employee directory, profiles, employee ID generation, department, designation, employment type, reporting manager, employment history, documents, emergency contacts, asset management, lifecycle management.

**Attendance:** manual attendance, shift-based attendance, clock-in/out, regularization, late arrival, early departure, overtime, holiday calendar, reports, optional biometric/device integration (interface only until a device is chosen).

**Leave:** leave types, policies, balances, requests, approval workflows, leave calendar, reports.

**Payroll:** salary structures, payroll periods, earnings and deductions, allowances, bonuses (including festival bonus), overtime calculation, loans and advances, payroll processing, salary approval, payslip generation (PDF), payment status tracking, configurable country-specific tax and compliance rules (Bangladesh rules as the first configurable rule set; never hardcode tax slabs).

**Additional HR:** recruitment, job openings, candidate pipeline, interview scheduling, onboarding, probation tracking, performance reviews, KPIs, training, employee self-service, offboarding, HR dashboard, HR analytics.

Connect HRM with Projects (timesheets, workload) and Automation.

---

## 7. PROJECT MANAGEMENT APPLICATION

Project creation, templates, categories, status, members, client association (shared companies/contacts), ownership, tasks, subtasks, checklists, Kanban board, list view, calendar view, Gantt chart (MIT/permissively-licensed library or custom component), milestones, task dependencies, priorities, comments, attachments, mentions, watchers, assignment, recurring tasks, due date reminders, time tracking, timesheets, workload, resource allocation, budgets, expenses, profitability (using Invoicing data), sprints, activity history, approval workflows, reporting, dashboard, client collaboration portal.

Integrate resources and timesheets with HRM. Allow CRM deals to create projects through authorized workflows.

---

## 8. CRM & SALES APPLICATION

**Core CRM:** leads, contacts and companies (shared core records), customer accounts, lead sources, lead scoring, assignment, qualification, pipelines, stages, opportunities/deals, deal history, sales activities, tasks, meetings, calls, follow-up reminders, notes, attachments, tags, custom fields, segmentation, unified customer interaction timeline.

**Sales:** products and services catalog, price lists, quotations, proposals, sales orders where needed, contracts, sales targets, forecasts, sales reports, conversion reports, pipeline analytics, customer lifecycle tracking. Accepted quotations hand off to the Invoicing module.

**Integrations:** website lead forms, Facebook Lead Ads, email marketing, SMS, WhatsApp Business, Projects, Support, Automation.

Implement duplicate contact detection and configurable merging. Maintain one reliable customer identity and interaction history across modules.

---

## 9. INVOICING & PAYMENTS APPLICATION (tenant → their customers)

This is distinct from Section 16 (platform billing of tenants).

- Customer invoices (from quotations, projects, or manual)
- Invoice numbering sequences per tenant
- Tax/VAT lines (configurable rates)
- Credit notes
- Payment recording (manual, bank transfer, and gateway payments where the tenant connects a gateway)
- Partial payments and outstanding balances
- Recurring invoices
- Invoice PDFs and email delivery
- Payment reminders
- Receivables reports
- Emits `invoice.paid` / `payment.received` events for the Automation engine and Project profitability

Full double-entry accounting is out of scope for now; design so an Accounting app can be added later.

---

## 10. SOCIAL MEDIA MANAGEMENT APPLICATION

Possible integrations: Facebook Pages, Instagram Professional accounts, LinkedIn, YouTube, TikTok, Pinterest, other officially supported platforms.

Only implement capabilities supported by each platform's official APIs and the permissions the platform has actually granted.

**Features:** account connections via OAuth, connected account management, encrypted token storage, token refresh where supported, content creation, drafts, scheduling, publishing queue, content calendar, media library, image/video attachments, multi-account scheduling, platform-specific previews, bulk scheduling, hashtag management, content categories, team collaboration, approval workflow, campaign grouping, publishing status, failed post handling, engagement and performance analytics, campaign reports.

**Where officially supported:** comment management, social inbox, message assignment, social listening, engagement alerts, competitor analysis, ad performance integrations.

Unsupported API actions must never be simulated as working. The UI must clearly explain when a feature needs authorization, platform app review, or is unavailable.

---

## 11. EMAIL MARKETING APPLICATION

Contact lists, subscribers (linked to shared contacts), import/export, segmentation, custom subscriber properties, marketing consent tracking, unsubscribe management, suppression lists, campaign creation, drag-and-drop email builder (permissively-licensed, e.g., GrapesJS-based or custom), HTML templates, template library, personalization variables, bulk campaigns, scheduled campaigns, drip campaigns, welcome and follow-up sequences, behavioral automation, A/B testing, duplication, preview, test sends, delivery reports, bounce processing, complaint handling, open tracking where technically available, click tracking, conversion attribution, analytics.

**Provider abstraction:** SMTP, Amazon SES, Mailgun, SendGrid, Brevo, others.

Implement domain verification guidance, SPF/DKIM/DMARC setup guidance, sender reputation monitoring, throttling, rate limits, queue-based sending, provider webhooks (signature-verified), failed delivery management.

Follow anti-spam, unsubscribe, consent, and privacy requirements.

---

## 12. SMS & WHATSAPP APPLICATION

**SMS:** provider abstraction (Bangladeshi and international providers), sender ID configuration (masking/non-masking per local regulator rules), contact groups, templates, Unicode/Bengali SMS with correct segment counting, personalization, bulk and scheduled campaigns, transactional and marketing SMS, OTP, delivery status, history, credit balance, usage tracking, failed message retry, analytics.

**WhatsApp:** official WhatsApp Business Platform (Cloud API) or authorized BSPs only. Business account connection, approved template management, opt-in records, campaigns, conversations, shared inbox, agent assignment, quick replies, chatbot flows, automated follow-up, support conversations, delivery/read status where available, analytics, usage and cost tracking.

Enforce provider rules, rate limits, the 24-hour customer service window, and marketing opt-in requirements.

All messages appear in the unified customer communication timeline.

---

## 13. CENTRAL WORKFLOW AUTOMATION ENGINE

One of the most important modules: a reusable, cross-module automation engine conceptually similar to Zapier, Make, and n8n.

### Architecture

- **Trigger registry, condition operators, and action registry** — each module registers its own triggers and actions through a typed contract (name, label, input schema, output schema, required permission, required entitlement).
- Workflows are stored as versioned JSON graphs. Only published versions execute.
- Execution runs in queued jobs, one step at a time, with persisted state so delays can last days and survive deploys.

### Visual builder (Vue Flow)

Node types: Trigger, Condition, Action, Delay, Schedule, Branch, Parallel, Approval, Webhook, AI action, Data transformation (declarative mapping only), Loop (strict limits), Error handler, End.

### Triggers

Record created, record updated, status changed, time-based, scheduled, webhook received, form submitted, CRM lead created, deal won, task completed, employee joined, leave approved, project overdue, campaign completed, payment received, invoice paid, subscription expiring, manual.

### Conditions

IF/ELSE, AND/OR, nested groups, field/date/numeric/string comparisons, record relationships, dynamic variables.

### Actions

Create/update CRM records, assign sales agents, create projects and tasks, send notifications, email, SMS, permitted WhatsApp messages, change status, update custom fields, create reminders, request approval, call authorized webhooks, execute approved AI actions.

### Advanced

Templates, versioning, draft/published states, validation, test execution, monitoring, step-level logs and debugging, retries, failure alerts, history, rate limiting, per-tenant usage quotas, idempotency, timeouts, loop prevention (max steps per execution, max recursion depth when workflows trigger other workflows), concurrency control, scheduled and manual execution, tenant-level workflow permissions.

### Example workflow

When a Facebook lead arrives: verify the webhook → resolve tenant → upsert contact with duplicate check → create lead → assign an available sales agent → send an approved WhatsApp welcome template (only if opted in) → create a follow-up task → wait N hours → re-check lead status → send reminder if needed → notify the manager if overdue → record the full execution log.

Never execute arbitrary tenant-supplied PHP, shell commands, or code. Data transformations use a safe, declarative expression language only.

---

## 14. AI ASSISTANT & AI AUTOMATION (optional paid app)

Writing assistant, social captions, email content, SMS suggestions, marketing ideas, lead and conversation summaries, CRM insights, project summaries, task descriptions, document summarization, support reply suggestions, workflow suggestions, campaign recommendations, lead categorization, AI-assisted segmentation.

**Integration:** provider-independent AI service interface; platform-managed credentials and, where permitted, tenant-owned keys (encrypted). Per-tenant usage tracking, AI credit allocation, usage-based billing.

**Safeguards:** human approval before sensitive actions, data access restricted to the requesting user's permissions, audit logging, prompt-injection protection (treat all record content as untrusted data), token and cost monitoring, configurable retention, request limits, failure handling.

Never expose one tenant's data to another tenant, including through shared prompts, caches, or embeddings.

---

## 15. BUILT-IN APP STORE

Sidebar menu name: **App Store**.

**App types:** free, paid, monthly, yearly, one-time, trial, usage-based, enterprise, add-on.

**Features:** catalog, categories, featured, popular, search, filters, detail pages, descriptions, screenshots, feature lists, pricing plans, dependencies, permissions, compatibility, version info, install, activate, deactivate, uninstall, purchase, upgrade, downgrade, renew, cancel, free trial, license verification, usage monitoring, My Apps, purchase history, billing history, reviews and ratings, update information.

**Categories:** HRM & Payroll, CRM & Sales, Project Management, Invoicing, Social Media, Email Marketing, SMS Marketing, WhatsApp Marketing, Workflow Automation, AI Tools, Customer Support, Accounting & Billing, Reporting, Productivity, Integrations, Utilities.

**Architecture rules:**
- Apps are first-party Laravel modules registered in an app registry (manifest: key, name, version, dependencies, permissions, routes, nav items, widgets, triggers, actions, scheduled jobs).
- Tenants cannot upload or execute third-party code.
- Installing a first-party app activates existing functionality for that tenant only.
- A paid app creates an entitlement only after server-side payment verification.
- Entitlements gate backend access, routes, navigation, APIs, scheduled jobs, integrations, widgets, and automation triggers/actions — checked server-side by middleware and policies.
- Activation validates dependencies; deactivation warns about dependents.
- Uninstalling never deletes business data automatically; data is retained per the retention policy and restored on reinstall.
- Lifecycle hooks: `installing`, `installed`, `activating`, `deactivated`, `uninstalling`, `upgraded`.
- Design (do not implement) future support for an approved third-party developer marketplace without remote plugin execution.

---

## 16. SUBSCRIPTION & BILLING SYSTEM (platform → tenants)

**Plans:** Starter, Business, Enterprise, Custom. Monthly and yearly billing, trials, renewal, upgrades, downgrades, proration policy, invoices, payment history, coupons, discounts, failed payments, grace periods, suspension, cancellation, refund records.

**App billing:** app subscriptions independent of the base plan where permitted, one-time purchases, licenses, per-seat pricing, feature-based pricing, renewal, usage tracking.

**Usage-based billing:** SMS, email, WhatsApp, AI credits, automation executions, storage, extra users, advanced features — through one central metering service with idempotent usage records (unique idempotency key per event) to prevent double counting.

**Payment gateways (provider abstraction):** SSLCommerz, bKash merchant, Nagad merchant, Stripe where supported, others.

Do not assume merchant credentials or gateway approvals exist. Payment success is confirmed only by backend verification through the gateway's official validation/IPN/webhook mechanism — never by a frontend redirect alone.

---

## 17. SUPER ADMIN PANEL

Separate interface on `admin.APP_DOMAIN`: global dashboard, tenant management, registration approval settings, active/suspended tenants, subscriptions, plans, App Store management, app creation and publication, app pricing and licensing, tenant feature restrictions and usage limits, revenue dashboard, invoices, payments, refunds, coupons, usage (email, SMS, WhatsApp, AI, automation, storage), queue and failed-job monitoring, system health, security and audit logs, integration provider settings, platform notifications and announcements, global configuration, support tools, reporting.

Protect with mandatory 2FA and explicit permissions. Impersonation / tenant access requires a recorded reason, is time-limited, is visible to the tenant in their audit log, and is fully audited.

---

## 18. TENANT DASHBOARD

Customizable, permission-aware widgets registered by installed apps: employees, attendance, active projects, pending tasks, deadlines, new leads, open deals, pipeline, revenue, outstanding invoices, active campaigns, email performance, SMS usage, social performance, automation activity, notifications, pending approvals, installed apps, subscription status.

Only show data from installed and authorized apps. Allow per-user layout configuration.

---

## 19. UNIFIED NOTIFICATION & COMMUNICATION CENTER

In-app, email, SMS, WhatsApp, and realtime (Reverb) notifications; templates; user preferences; scheduled notifications; delivery tracking; read/unread; history; event-driven messages. Reused across all modules.

---

## 20. CUSTOMER SUPPORT APPLICATION

Tickets, categories, priorities, assignment, status, SLA management, escalation, comments, internal notes, attachments, shared inbox, saved replies, customer portal, knowledge base, CSAT surveys, support automation, analytics. Tickets link to shared contacts and companies.

---

## 21. API & INTEGRATION CENTER

OAuth connections, encrypted credential storage, webhook registration, webhook signature verification, delivery logs, API access tokens (Sanctum, scoped abilities), token expiration, provider configuration, integration status, rate-limit monitoring, health reporting, reconnection workflows.

Reusable adapters handle expired credentials, API failures, network errors, revoked permissions, and duplicate webhooks (idempotency by provider event ID).

---

## 22. UI/UX REQUIREMENTS

Modern, premium, clean, enterprise-grade interface with its own visual identity.

**Layout:** left sidebar, top bar, global search (Ctrl/Cmd+K), notification center, workspace switcher (for users in multiple tenants), profile menu, breadcrumbs, contextual page actions, responsive content area.

**Design:** minimalist, consistent spacing scale, professional typography with a font that renders Bengali well (e.g., Hind Siliguri or Noto Sans Bengali alongside Inter), accessible contrast (WCAG AA), responsive grids, clean data tables (sorting, filtering, pagination, column visibility), modern forms, status badges, empty states, loading skeletons, error states, toasts, confirmation dialogs, light and dark mode, mobile responsive.

**Navigation rules:**
- Core menus always visible where permitted.
- Optional apps appear only when installed, entitled, and permitted.
- App Store visible to users allowed to browse or purchase.
- Never show inaccessible functionality as active.

Use one design system across Super Admin, Tenant workspace, App Store, and all apps.

---

## 23. DATABASE & DATA MANAGEMENT

Normalized structures, indexing (every `tenant_id` and common filter columns), tenant-aware composite unique constraints, foreign keys, safe reversible migrations, soft deletes where appropriate, audit trails, transactions, cursor pagination for large lists, query optimization, no N+1 queries (enable `Model::preventLazyLoading()` outside production), large dataset handling, archiving, retention, backups, restore procedures.

Provide structures for: tenants, tenant domains, platform users, users, tenant memberships, roles, permissions, branches, departments, contacts, companies, apps, app versions, app installations, app entitlements, plans, subscriptions, subscription items, payments, invoices, usage records, workflows, workflow versions, workflow executions, workflow steps, workflow logs, integrations, API credentials, webhooks, notifications, files, audit logs.

Each module owns its domain tables and reuses shared platform records. Avoid duplication across CRM, marketing, and support.

---

## 24. SECURITY REQUIREMENTS

Strict tenant isolation; server-side authorization; secure authentication; MFA; password hashing; CSRF; XSS prevention (no `v-html` with user content unless sanitized); SQL injection protection; Form Request validation; API authentication; rate limiting; credential encryption (Laravel encrypted casts); webhook signature verification; idempotency; secure file uploads (MIME validation, size limits, no execution); private file access via signed URLs; tenant-specific storage paths; security audit logs; session management; least privilege; secret management; WebSocket channel authorization; job tenant context validation; privacy and consent management; safe error handling.

Never trust tenant IDs, billing status, entitlements, or permissions sent from the frontend.
Never log passwords, tokens, or credentials.
Never hardcode production credentials.

---

## 25. PERFORMANCE & SCALABILITY

Redis caching with tenant-isolated keys, queue-based processing, Horizon monitoring, separate queues (`default`, `notifications`, `campaigns`, `automation`, `webhooks`, `billing`, `ai`), chunked processing, batches, indexing, efficient pagination, asset optimization (Vite code splitting per module), rate limiting, API throttling, per-tenant execution quotas so one tenant cannot starve others, retry and failure management, scheduled maintenance, observability, health check endpoint.

Large campaigns and automations must never block web requests. Do not introduce microservices prematurely.

---

## 26. REPORTS & ANALYTICS

Permission-aware reports: tenant subscriptions, platform revenue, attendance, payroll, project performance, timesheets, CRM leads, pipeline, conversion, customer activity, receivables, email campaigns, SMS delivery, WhatsApp campaigns, social engagement, automation executions, App Store sales, app usage, AI usage.

Support date and advanced filters, CSV/Excel export, PDF where relevant, summary cards, charts, comparisons, scheduled reports. Large exports run as queued jobs and are delivered via signed, expiring links. All exports respect tenant boundaries and permissions.

---

## 27. IMPLEMENTATION PHASES

Do not generate the platform as one giant unverified change. Each phase must produce working, tested software.

**Phase 1 — Architecture & Foundation (THIS SESSION)**
- Inspect repository and environment
- Verify Laravel 13 and package compatibility; write ADRs for tenancy and RBAC choices
- Create the Laravel 13 project with Sail, Vue 3, Inertia, TypeScript, Tailwind
- Configure MySQL and Redis
- Module folder structure and module service provider registration
- Tenancy layer: subdomain resolution, tenant context, `BelongsToTenant` scope, tenant-aware cache/storage/queue context
- Authentication (tenant guard and separate Super Admin guard) including 2FA scaffold
- Tenant memberships, user management basics, user invitations
- RBAC with tenant-scoped roles and module-registered permissions
- Audit logging foundation
- Design system: base Vue components (Button, Input, Select, Modal, Table, Badge, Toast, Card, EmptyState, Skeleton, Dropdown), light/dark mode, English/Bengali i18n setup
- Layouts: Auth layout, Tenant layout (sidebar, top bar), Super Admin layout
- Basic tenant dashboard and Super Admin dashboard pages (with real data where it exists, otherwise clear empty states)
- Seeders for local development (one Super Admin, two demo tenants with users) — development only
- Tests (see Section 29), including cross-tenant access tests
- GitHub Actions workflow running Pint, Larastan (level 5+), Pest, `vue-tsc`, and Vitest
- Documentation skeleton and CLAUDE.md / PROGRESS.md

**Phase 2 — Core SaaS & Billing:** tenant self-registration and onboarding, company settings, branches, departments, shared contacts/companies, plans, platform billing, payment gateway abstraction, entitlements, usage limits, metering service, Super Admin tenant and plan management.

**Phase 3 — App Store:** app registry and manifests, categories, details, free app install, paid checkout, licensing, activation, My Apps, app permissions, dependencies, entitlement-driven navigation.

**Phase 4 — CRM**
**Phase 5 — Project Management**
**Phase 6 — Invoicing**
**Phase 7 — HRM & Payroll**
**Phase 8 — Communication Platform** (notifications, email/SMS/WhatsApp providers, interaction timeline)
**Phase 9 — Marketing Apps** (email, SMS, WhatsApp, social, analytics)
**Phase 10 — Central Automation Engine**
**Phase 11 — AI Applications**
**Phase 12 — Support & Advanced Reporting**
**Phase 13 — Production Hardening** (security, isolation, performance, queue, billing verification, deployment, backup/restore verification, docs)

Note: the event and automation contracts (interfaces for triggers/actions) are defined in Phase 1 so every module built afterwards registers its triggers and actions from the start, even though the engine itself is built in Phase 10.

---

## 28. CODE QUALITY RULES

1. Clean, maintainable, production-quality code following Laravel conventions.
2. Use services/actions, policies, events, listeners, and jobs appropriately. Keep controllers thin.
3. No duplicated business logic.
4. Database transactions for critical operations.
5. Form Request validation; API Resources where appropriate.
6. Well-defined TypeScript types for all Inertia page props (generate or hand-maintain a shared `types/` folder).
7. Reusable Vue components; no copy-pasted UI.
8. Meaningful error handling; configuration over hardcoded values.
9. Tenant isolation at every layer; entitlement checks everywhere a module is touched.
10. Replaceable integrations behind interfaces.
11. Event-driven communication between modules.
12. Prefer the simplest solution that meets the requirement.
13. Record major decisions as ADRs in `docs/adr/NNNN-title.md`.
14. Code passes Pint, Larastan, `vue-tsc`, and all tests before a task is called done.

Never present placeholder implementations as completed features. No fake payment verification, simulated provider integrations, or mock API responses in production code paths. Fakes are allowed only in clearly separated test/dev code (e.g., a `FakeSmsProvider` bound only in the `testing`/`local` environment).

---

## 29. TESTING REQUIREMENTS

Write tests for critical processes before marking any feature complete. Cover as phases arrive:

Authentication; role authorization; tenant isolation; **cross-tenant access prevention for every new model and route** (a reusable test helper should make this easy); subscription activation and expiration; payment verification; app installation, licensing, dependencies; feature entitlements; workflow validation, execution, retry; duplicate webhook handling; usage metering; queue execution; integration failure handling; export access; sensitive file authorization.

---

## 30. DOCUMENTATION REQUIREMENTS

Maintain in `docs/` (and README.md at root): README.md, ARCHITECTURE.md, DATABASE.md, MODULES.md, APP_STORE.md, BILLING.md, AUTOMATION.md, INTEGRATIONS.md, SECURITY.md, DEPLOYMENT.md, TESTING.md, and `adr/`.

Document installation, configuration, development commands, deployment, queue workers, scheduler, migrations, secrets, and backups. Keep docs synchronized with the code; do not write docs for features that do not exist yet beyond a short "planned" note.

### Session memory files (mandatory)

- **CLAUDE.md** (root) — short, stable project rules for any future AI session: stack, locked decisions from Section 0, folder structure, commands (test, lint, build), conventions, "never do" list. Keep under ~200 lines.
- **PROGRESS.md** (root) — living log: completed phases and tasks, current state, known issues, deferred items, decisions made with dates, and the exact next steps. Update it at the end of every session.

---

## 31. DEVELOPMENT RULES

You are the lead engineer. Work directly in the current workspace.

- Inspect the repository first. If empty, initialize a new Laravel 13 project.
- Do not overwrite unrelated files or destroy existing data.
- Do not run destructive database operations (`migrate:fresh`, dropping tables, truncating) on anything except a disposable local/test database without explicit authorization.
- Do not commit secrets. Keep `.env` out of git; maintain `.env.example`.
- Make small, logical git commits with clear messages.
- Do not introduce undocumented breaking changes.

**Before each major module:** define responsibilities, tables and relationships, authorization rules, services, events and integrations, frontend pages, automation triggers and actions, entitlement behavior, and tests — then implement.

**After each module:** run tests and static analysis, fix verified issues, update docs and PROGRESS.md, summarize completed and remaining work.

If an external service needs credentials or approval, build the secure integration interface, document the missing configuration, and show an honest "not configured" state in the UI.

Ask the project owner only when a missing business decision, credential, permission, or potentially destructive action genuinely requires it. Otherwise decide sensibly and record the decision.

---

## 32. INITIAL TASK — START HERE (PHASE 1 ONLY)

1. Inspect the environment (PHP version, Composer, Node, Docker availability) and the repository.
2. Verify Laravel 13 compatibility of every planned package. Write ADRs for the tenancy and RBAC choices.
3. Write `docs/ARCHITECTURE.md` (module boundaries, shared services, tenancy design, future dedicated-DB strategy) and `docs/DATABASE.md` (Phase 1 tables in full; outline only for Core SaaS, App Store, Billing, and Automation tables). Keep these concise — then move on to code.
4. Create the Laravel 13 + Sail + Vue 3 + Inertia + TypeScript + Tailwind application.
5. Implement everything listed under Phase 1 in Section 27.
6. Define (interfaces/contracts only) the module manifest, entitlement check, trigger/action registry, and domain event conventions, so later phases plug in cleanly.
7. Run Pint, Larastan, `vue-tsc`, Pest, and Vitest. Fix failures.
8. Create CLAUDE.md and PROGRESS.md.
9. **Stop.** Give a clear summary: what was built, how to run it locally (exact commands and demo logins), test results, decisions made, anything that needs the owner's input, and the recommended scope for Phase 2.

Do not start Phase 2 in this session.

---

## FINAL GOAL

A production-ready, scalable, secure, premium multi-tenant SaaS platform where businesses manage employees, projects, customers, sales, invoicing, marketing campaigns, communication, support, and automation in one workspace — expandable through a built-in App Store with paid apps and usage-based services — running on one shared MySQL database initially, with a clear path to dedicated tenant databases.

Prioritize maintainability, performance, reliability, security, excellent UI/UX, and long-term commercial scalability.

**Begin Phase 1 now.**
