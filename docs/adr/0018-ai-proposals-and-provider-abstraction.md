# ADR 0018 — AI never changes data on its own; providers sit behind one contract and one gateway

- **Status:** Accepted, with one open item recorded under "Open decision: operator AI provider accounts"
- **Date:** 2026-10-13
- **Phase:** 11 (AI Assistant)

## Context

The AI Assistant answers from workspace records and drafts text. Three things can go wrong in a multi-tenant product:
the model reads or changes what the member is not allowed to, text inside a record (a contact's notes, an email
body) steers the model, and a provider outage or a long tool loop runs up cost. The provider credential is a
platform secret that no tenant should ever hold or see. Providers differ (Anthropic Messages API, OpenAI-compatible
chat-completions servers) and will change.

## Decision

### Access and change

1. **Tools run as the member.** Data reaches the model only through `App\Core\Ai\Contracts\AiTool`
   implementations that modules tag (`AiTool::TAG`). Each tool is checked by `AccessChecker` (entitlement included)
   and narrowed by `RecordAccess::constrain()`; a record outside the member's scope is "not found". A tool is
   offered only when the workspace enabled its data scope (new scopes are opt-in).

2. **The model cannot change data. A person confirms a proposal.** A tool that would change data is a
   `ProposalTool`: `execute()` validates and describes, `apply()` runs only after confirmation, as that member,
   through the module's own action (for contacts, `ContactDirectory`) inside a transaction with an audit entry and a
   domain event. A proposal (`ai_proposals`) holds an encrypted payload, belongs to one membership, expires after 24
   hours and is `pending`, `confirmed`, `rejected`, `failed` or `expired`. The confirmation card lists each field
   with the value that would be written and, for an update, the current value. Confirming needs the member's own
   request and `ai.proposals.confirm`; the underlying permission and record scope are re-checked when the action
   runs. The proposal is re-read under a row lock, so a double click or two tabs apply it once. Only the member the
   assistant worked for can decide it (404 for anyone else).

3. **No money, no access control.** `ToolRegistry` throws when a tool's name or permission contains a payment,
   payout, refund, payroll, billing, role, permission, invitation, member, transfer, credit-note or salary segment.

4. **Records are data, not instructions.** Tool results and drafting inputs are JSON inside data blocks (tag
   characters hex-escaped); the versioned system prompt lives in code and ranks above data; drafting and workflow
   calls have **no tools at all**; whatever tool the model asks for is re-checked against the member's available
   tools. Output is capped per tool result and per field. Optional PII redaction (off by default) masks email, phone
   and card-like numbers in everything sent to the provider, as a safeguard, not a guarantee.

### Provider abstraction

5. **One contract, one gateway.** `App\Modules\Ai\Contracts\AiProvider` (`name`, `model`, `isConfigured`,
   `complete`) has adapters for the Anthropic Messages API (over the Laravel HTTP client; no SDK dependency was
   added) and for OpenAI-compatible chat-completions servers. Every call goes through `AiGateway`, which checks the
   feature switches, the credential, the circuit breaker, per-workspace and per-member requests per minute, and the
   plan limit `ai.tokens`, bounds each call (`max_output_tokens`, `max_turn_tokens`, `max_tool_rounds`) and meters
   tokens afterwards with an idempotency key per call, so a retried job counts nothing twice.

6. **Honest state.** `AiState` is `ready`, `not_configured`, `unavailable` (breaker open), `disabled`,
   `limit_reached`, `rate_limited` or `too_long`, returned to pages and JSON with a matching status. No credential
   means `not_configured`; nothing is called and nothing is simulated. The scripted `FakeAiProvider` is bound only in
   `local` and `testing` and refuses to load elsewhere. Provider failures are logged by HTTP status and error type
   only and surface as a generic message.

7. **Resolution.** `ProviderManager`: the operator's enabled default account if one exists; else, if accounts exist
   but none is an enabled default, `not_configured` (never a silent fall back to the environment); else the
   environment (`config/ai.php`, `ANTHROPIC_API_KEY`). The credential is platform-level: never from a tenant, the
   request or a header, and write-only in the panel.

8. **Auditing without content.** The audit trail records tool names, statuses, record ids and proposal events
   (`ai.tool.executed`, `ai.proposal.created|confirmed|rejected|failed`, `ai.conversation.deleted`,
   `ai.conversations.cleared`, `ai.retention.applied`), never message or record content. A conversation is private
   to its membership. Retention is a workspace setting of 1 to 365 days (default 90) enforced by the daily
   `ai.retention` job.

### Open decision: operator AI provider accounts

The environment key was the original mechanism. The code now also contains operator AI provider accounts
(Super Admin > AI providers, `platform.ai.*`, table `ai_provider_accounts`, `AiProviderAccounts`,
`OpenAiCompatibleProvider`) whose key is stored in the database with the `encrypted:array` cast, hidden and
write-only, mirroring ADR 0013. **ADR 0013, which the owner approved for messaging accounts, states that AI provider
keys stay in the environment and that it does not relax the rule for them.** The AI accounts feature therefore
extends a locked rule (CLAUDE.md: never store gateway credentials in the database) beyond what was approved. It is
recorded here so the decision is made explicitly: the owner should either approve it by amending ADR 0013 and the
CLAUDE.md exception, or have it removed in favour of environment configuration. Until then the feature is to be
treated as unapproved. Its adapters (especially the OpenAI-compatible one, which sends `max_tokens`) have been
exercised only against `Http::fake`.

## Consequences

- The assistant can see exactly what the member can see and change nothing without a human click; a prompt injection
  in record text can at worst produce a proposal that the member sees spelled out and may reject.
- Confirmation is per proposal and personal. There is no auto-approve and no approver other than the requester.
- Because every call passes the gateway, cost and rate guardrails hold for any provider. The call that crosses the
  token cap is allowed (its cost is unknown beforehand); the next is refused.
- Adding a provider is one adapter plus resolution; adding a data source is a tool in the owning module that wraps
  that module's own contracts. Today only contacts and companies are exposed.
- Not built: streaming, tenant-owned (BYO) keys, embeddings or vector search, tools over other optional modules,
  AI-assisted segmentation, and charging usage on an invoice.
- Neither real adapter has run against a live provider. Providers differ in token-parameter names and tool-call
  support; "test connection" and a real turn are needed before relying on an account.

## Alternatives considered

- **Let the model call write tools directly with member permissions.** Rejected: one injected sentence would be a
  data change; a confirmation that shows the value is the control.
- **A system identity for AI tools.** Rejected for the same reason as ADR 0017: it bypasses scopes.
- **Tenant-owned provider keys.** Not built: needs encrypted per-workspace storage and an operator policy; the spec
  allows it only "where permitted".
- **The vendor SDK.** Not installed: a new dependency would need the licence and Laravel 13 check; the HTTP API
  surface used is small.
- **Fall back silently to the environment key when accounts exist but none is default.** Rejected: an operator who
  disabled the accounts would not expect traffic to continue on a hidden credential.
