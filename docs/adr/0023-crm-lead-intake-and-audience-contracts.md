# ADR 0023 — Two core contracts for the CRM: `LeadIntake` and `CrmAudience`

- **Status:** Accepted for implementation (built, unreviewed).
- **Date:** 2026-10-13
- **Phase:** not assigned. Builds on ADR 0010, 0014, 0017 and 0020. (ADR 0022 is held by the AI text-assistant work.)

## Context

WhatsApp needs to turn a first conversation into a lead, Facebook Lead Ads needs to sync form answers into the CRM,
and campaign apps need the CRM's customer groups as an audience. CLAUDE.md forbids a module from importing another
module's models, and CRM customer data lives on the shared contact. Until now the only door was the
`crm.lead.create` automation action, which needs an existing contact id and a signed workflow author, and there was
no way to read a CRM group from outside CRM.

## Decision

Two contracts in `App\Core\Crm`, with core null objects (`bindIf` in `CoreServiceProvider`) and the real bindings in
`CRMServiceProvider`:

1. **`LeadIntake::capture(LeadCapture): LeadCaptureResult`.** A system integration hands over one validated
   enquiry (source key, idempotency key, name, email, E.164 phone, optional company, bounded plain-text answers,
   campaign and ad references, a consent-evidence flag, time). CRM finds or creates the shared contact through
   `ContactDirectory` (dedupe by email, then canonical phone `phone_e164`, with a fallback for rows saved without
   it), reuses an open lead of that contact and source or creates one, records a timeline entry, a plain note of the
   answers and an audit entry, and registers the source as a `LeadSource` once. The outcome is stored per
   (workspace, `crm.lead_intake.{source}`, idempotency key) in the existing `crm_automation_action_receipts` table:
   a retry returns the first ids with status `replayed` and fires no event. The existing `crm.lead.created` event is
   the trigger; its payload now also carries `source_id` and `source_key`.
2. **`CrmAudience::segments()` / `contactIds()`.** Lead-status, deal-stage, tag, saved-segment and customer-lifecycle
   groups as cursor pages (at most 1000) of shared contact ids. Saved segments run through `SegmentQuery`, which
   accepts only declared filters. An optional `TenantMembership $viewer` narrows every source with `RecordAccess`
   by the permission that governs the record it comes from.

Rules that follow:

- The caller is the system, not a person: no signed-in user's permission is read, nothing is stamped with the
  current session user, and the tenant comes only from `TenantContext`. A new lead is unassigned: CRM has no
  default-owner or round-robin setting today; a workflow (`crm.lead.created` then `crm.lead.assign`) assigns it.
- Without the CRM entitlement `LeadIntake` returns `unavailable` and writes nothing, `CrmAudience` returns nothing.
- CRM never writes platform consent: `consentEvidence` is only recorded on the timeline. Consent and suppression
  stay with `MarketingAudience` / `ConsentRecorder`, checked again at queue and send time.
- Free text from the integration is stripped of control characters and tags, bounded, stored as text and never
  evaluated.
- `viewer = null` means the whole workspace and is for trusted system callers; a campaign launched by a person
  passes that person's membership.

## Consequences

- WhatsApp, Facebook Lead Ads and web forms integrate without importing CRM classes; faking them in tests is one
  trait (`InteractsWithCrmContracts`).
- Receipts are shared with the automation actions, so the idempotency key space is namespaced by the action string.
- Segment sizes are exact counts at call time and can be slow on very large workspaces; callers should treat them
  as approximate and cache.
- No schema change, no new permission, no UI.

## Alternatives considered

- **Reuse `crm.lead.create` through the workflow engine.** Rejected: it needs a published workflow and a member to
  run as, and cannot find or create the contact.
- **Let integrations write `Lead` rows.** Rejected: breaks the module boundary and duplicates dedupe rules.
- **A new receipts table.** Rejected: the existing one already has the right unique key.
