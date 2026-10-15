# Documentation index

Rules for working in the repository are in [`../CLAUDE.md`](../CLAUDE.md); what is done and what is next is in
`../PROGRESS.md`; the product specification is `../MASTER_PROMPT.md`. The code is the source of truth: where a page
and the code disagree, fix the page.

## Platform

| Page | Read it for |
|---|---|
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Areas and hosts, request lifecycle, code layout, shared services, tenancy, authorization, events, queues |
| [`MODULES.md`](MODULES.md) | The 17 registered optional modules (permissions, limits, jobs), and how to add a module |
| [`DATABASE.md`](DATABASE.md) | Conventions, central versus tenant-owned tables, every module's tables, dedicated-database strategy |
| [`SECURITY.md`](SECURITY.md) | Tenant isolation layer by layer, per-module security rules, known limitations |
| [`TESTING.md`](TESTING.md) | Commands, the test database, helpers, conventions, CI |
| [`DEPLOYMENT.md`](DEPLOYMENT.md) | Environment, DNS/TLS, Nginx, workers, scheduler, deploy sequence, health endpoints, operator commands |
| [`BILLING.md`](BILLING.md) | Plans, subscriptions, invoices, payments, gateways, limits |
| [`APP_STORE.md`](APP_STORE.md) | Catalogue sync, licences, lifecycle, app billing and entitlement gates |
| [`INTEGRATIONS.md`](INTEGRATIONS.md) | Outside services and the rules integrations follow |
| [`AUTOMATION.md`](AUTOMATION.md) | Trigger and action contracts and every module's definitions |
| [`BACKUP_RESTORE.md`](BACKUP_RESTORE.md) | What a backup holds, `ops:verify-backup`, what was and was not exercised |
| [`PERFORMANCE.md`](PERFORMANCE.md) | `ops:benchmark` and the one-machine baseline |
| [`../resources/js/README.md`](../resources/js/README.md) | Frontend guide and design language |

## Apps

| Page | App (module key) |
|---|---|
| [`CRM.md`](CRM.md) | CRM & Sales (`crm`) |
| [`PROJECTS.md`](PROJECTS.md) | Project Management (`projects`) |
| [`INVOICING.md`](INVOICING.md) | Invoicing (`invoicing`) |
| [`HRM.md`](HRM.md) | HR Management (`hrm`) |
| [`PAYROLL.md`](PAYROLL.md) | Payroll (`payroll`) |
| [`TALENT.md`](TALENT.md) | Talent & Performance (`talent`) |
| [`WORKFLOWS.md`](WORKFLOWS.md) | Workflow Automation (`automation`) |
| [`AI.md`](AI.md) | AI Assistant (`ai`) |
| [`COMMUNICATION.md`](COMMUNICATION.md) | Communication (`communication`) |
| [`EMAIL_MARKETING.md`](EMAIL_MARKETING.md) | Email Marketing (`email_marketing`) |
| [`SMS_MARKETING.md`](SMS_MARKETING.md) | SMS Marketing (`sms_marketing`) |
| [`WHATSAPP.md`](WHATSAPP.md) | WhatsApp Business (`whatsapp_marketing`) |
| [`SOCIAL.md`](SOCIAL.md) | Social Media (`social`) |
| [`META_ADS.md`](META_ADS.md) | Meta Ads (`meta_ads`) |
| [`SUPPORT.md`](SUPPORT.md) | Customer Support (`support`) |
| [`REPORTS.md`](REPORTS.md) | Reports & Analytics (`reports`) |
| [`NOTES.md`](NOTES.md) | Notes (`notes`) |
| [`TELEPHONY.md`](TELEPHONY.md) | Telephony (`telephony`): softphone, dialer, click-to-call, call log |

## Decisions (`adr/`)

| ADR | Decision |
|---|---|
| [0001](adr/0001-tenancy.md) | Single-database tenancy with our own fail-closed scope |
| [0002](adr/0002-rbac.md) | Roles and permissions: package for schema, our `AccessChecker` for checks |
| [0003](adr/0003-user-identity-and-sessions.md) | Platform-wide users, tenant memberships, host-only sessions |
| [0004](adr/0004-local-environment.md) | Local environment (native Laragon verified, Sail unverified) |
| [0005](adr/0005-design-system.md) | Design system and fonts |
| [0006](adr/0006-platform-billing.md) | Invoice-driven platform billing |
| [0007](adr/0007-permission-scopes.md) | Own / department / branch / all permission scopes |
| [0008](adr/0008-sign-up-and-support-access.md) | Sign-up and audited support access |
| [0009](adr/0009-first-party-app-store.md) | First-party compiled App Store and verified licences |
| [0010](adr/0010-crm-sales-domain-boundaries.md) | CRM customer identity and sales hand-offs |
| [0011](adr/0011-project-boundaries-and-shared-identity.md) | Project boundaries and shared identities |
| [0012](adr/0012-vue-flow-for-workflow-editor.md) | Vue Flow for the workflow editor |
| [0013](adr/0013-platform-provider-accounts.md) | Operator messaging provider accounts (encrypted, write-only) |
| [0014](adr/0014-marketing-contracts-and-honest-channel-availability.md) | Marketing apps send only through core communication contracts; honest channel availability |
| [0015](adr/0015-reporting-provider-contract.md) | Reports are tagged providers; the Reports app owns no data |
| [0016](adr/0016-communication-account-claims.md) | Platform-wide provider account claims without `tenant_id` |
| [0017](adr/0017-workflow-engine-authority-model.md) | Workflows are declarative and run as the member who published them |
| [0018](adr/0018-ai-proposals-and-provider-abstraction.md) | AI proposals need human confirmation; provider contract and gateway; open decision on AI accounts |
| [0019](adr/0019-central-health-and-ops-commands.md) | Health probes and `ops:` commands outside tenant context |
| [0020](adr/0020-customer-360-unified-inbox-and-messenger.md) | Customer 360 and unified inbox as tagged read providers; Messenger as a separate conversational app (four owner decisions open) |
| [0021](adr/0021-whatsapp-business-feature-gaps.md) | WhatsApp Business 1.1 (interactive messages, notes, ratings, rule lists, AI drafts, lead and order workflows); six larger gaps proposed for owner decision |
| [0022](adr/0022-whatsapp-numbers-rich-messages-media-and-template-catalogue.md) | Several WhatsApp numbers per workspace, rich WhatsApp payloads, media gateway and the provider template catalogue as core communication contracts |
| [0023](adr/0023-crm-lead-intake-and-audience-contracts.md) | CRM `LeadIntake` and `CrmAudience` core contracts for lead capture and campaign audiences |
| [0024](adr/0024-text-assistant-contract.md) | Core `TextAssistant` contract: captions, hashtags, reply suggestions and sentiment for other apps, text only, through `AiGateway` |
| [0025](adr/0025-social-audience-comments.md) | Social audience comments: signed webhook plus polling, one Page one workspace, keyword rules as data that run as their last editor |
| [0026](adr/0026-facebook-lead-ads-intake.md) | Facebook Lead Ads: signed workspace webhook, Page claim registry, CRM only through `LeadIntake` |
| [0027](adr/0027-meta-ads-marketing-api-app.md) | Meta Ads: its own per-workspace encrypted token, a read-only `ConnectedPages` Social contract, safe pause / budget changes under a ceiling |
| [0028](adr/0028-operator-managed-integration-credentials.md) | Operator-managed integration credentials in the panel: payment gateways and invoice issuer, encrypted and env-fallback |
| [0029](adr/0029-social-and-meta-ads-panel-credentials.md) | Social and Meta Ads app credentials in the panel: config-override overlay, write-only secrets, shown-back public ids |
| [0036](adr/0036-telephony-calls-dialer-and-softphone.md) | Telephony: call log, click-to-call, dialer and a JsSIP browser softphone through a PBX (Asterisk/FreeSWITCH) that holds a licensed Bangladeshi IPTSP's SIP trunk; PBX events are the call record; encrypted write-only credentials; each workspace configures its own PBX |
