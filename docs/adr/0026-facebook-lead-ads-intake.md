# ADR 0026 - Facebook Lead Ads: signed workspace webhook, Page claim registry, CRM only through `LeadIntake`

- **Status:** Accepted for implementation (built, unreviewed; not exercised against Meta's live API).
- **Date:** 2026-10-13
- **Builds on:** ADR 0014 (honest availability), ADR 0016 (claim registry pattern), ADR 0023 (`LeadIntake`).

## Context

People who fill in a Facebook lead form should become CRM leads. Meta announces a lead with a `leadgen` webhook
(ids only) and serves the answers from `GET /{leadgen-id}` with a Page token. The Social app owns the Page connection;
the CRM is a separate optional app that Social must not import (CLAUDE.md), and a workspace may not have it.

## Decision

1. **Receiver.** A stateless route on the workspace host (`tenant.callback`, `entitled:social`). Order: body cap, HMAC
   `X-Hub-Signature-256` with the Meta app secret over the raw body, parse, then per change look up the Page in the
   claim registry and read its setup row through the tenant scope; one row per `leadgen_id`; queue the job. Only ids are
   taken from the payload. A backlog cap answers 503 so Meta redelivers.
2. **Page claim registry.** `social_lead_ad_page_claims` (no `tenant_id`, `UNIQUE(page_id)`, owned through a tenant-owned
   row, cascade) so one Page feeds one workspace, atomically, as ADR 0016 does for WhatsApp numbers. It is claimed when
   lead capture is switched on and released when it is switched off.
3. **Processing.** A queued job fetches the lead with the Page token (Authorization header only), maps it with the
   form's mapping and calls `LeadIntake::capture` (source `facebook_lead_ads`, key `fb-lead:{leadgen_id}`). Every outcome
   is a visible status with a reason: captured, duplicate, failed, waiting for the CRM, waiting for the Page,
   skipped. The CRM is never reached any other way; `unavailable` keeps the lead (answers encrypted, 14 days) and a
   sweep sends it when the CRM is entitled.
4. **Consent.** `consentEvidence` is true only when the person mapped a form question as consent and the lead answered
   it affirmatively. Nothing is inferred from the existence of the form.
5. **Honest availability.** The lead permissions are requested at connect only when the operator sets
   `SOCIAL_FACEBOOK_LEAD_ADS`; the page reports `not_enabled`, `not_configured`, `webhook_not_configured`, missing
   permissions, or a Page that must be connected again.
6. **Permissions.** `social.leadads.view`, `social.leadads.manage` (sensitive: switching a form on makes outside
   enquiries enter the CRM unreviewed).

## Consequences

- **One callback URL per Meta app, one workspace host per URL.** Meta registers a single webhook URL per app, while this
  platform receives on `{workspace}.APP_DOMAIN`. Several workspaces behind one operator Meta app need a central relay that
  verifies the signature, finds the workspace from the claim, and forwards. It is **not built**; the claim registry would
  need the owning tenant id for it. Until then lead ads work for a deployment whose Meta app points at one workspace host.
- Pages claimed by a workspace stay claimed until it releases them or is deleted; there is no operator release screen.
- Lead answers are personal data held encrypted for as long as needed (and at most 14 days if never handed over);
  processing history (no answers) is kept 180 days.
- The per-account allow-list of Page access does not apply to lead ads.

## Alternatives considered

- **Poll `GET /{form-id}/leads` only.** Rejected as the primary path (latency, rate limits); kept as the bounded manual
  "fetch recent leads".
- **A central webhook endpoint now.** Rejected for this change: a cross-tenant lookup and a forwarding hop, not asked for.
- **Create CRM rows from Social.** Rejected: breaks the module boundary (ADR 0023).
