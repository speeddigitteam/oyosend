# AI Assistant (module `ai`)

State: Phase 11 backend. Category AI Tools, a **paid** optional app that depends on `core` only. The frontend pages
are built separately (`resources/js/Modules/Ai`). Spec: `MASTER_PROMPT.md` section 14.

What it is: a private assistant chat that answers from the records the signed-in member may see, text-only drafting
(summarise a record, draft a reply, rewrite), and a workflow action that generates text. It **cannot change data on
its own**: a change is a proposal that a person confirms. It cannot move money or change permissions.

What it is not (yet): streaming answers, tenant-supplied provider keys, embeddings / vector search, tools over other
optional modules (the contract is built, see "Adding a tool"), AI-assisted segmentation or campaign features.

## 1. Provider

| Piece | Where |
|---|---|
| Contract | `App\Modules\Ai\Contracts\AiProvider`: `name()`, `model()`, `isConfigured()`, `complete(CompletionRequest): CompletionResponse` (tool use included, no streaming) |
| Real adapter | `Providers\AnthropicProvider`: Anthropic Messages API (`POST {base_url}/v1/messages`, `x-api-key`, `anthropic-version: 2023-06-01`) over the Laravel HTTP client. The official PHP SDK is **not** installed (new dependency: licence and Laravel 13 check not done); this is a thin adapter over the documented HTTP API |
| Fake | `Providers\FakeAiProvider`: scripted answers. Registered only when the environment is `local` or `testing`, selected only by `AI_PROVIDER=fake`; `ProviderManager` resolves anything else to Anthropic, which reports `not_configured` without a key |
| Config | `config/ai.php`; env `ANTHROPIC_API_KEY`, `ANTHROPIC_BASE_URL`, `AI_ANTHROPIC_MODEL` (default `claude-sonnet-5-5`), `AI_PROVIDER`, `AI_TIMEOUT_SECONDS`, `AI_MAX_OUTPUT_TOKENS`, `AI_MAX_TURN_TOKENS`, `AI_TENANT_RPM`, `AI_MEMBER_RPM`, `AI_REDACTION_DEFAULT` |

The credential is **platform-level**: one key held by the operator in the environment. It is never stored in the
database, never taken from a tenant, the request or a header, and never shown or logged. It exists only in the
`x-api-key` request header. A provider failure is logged by HTTP status and error type only (never request or
response body) and surfaces as a generic message. Tenant-owned (BYO) keys were considered and **not built**: they
would need encrypted per-workspace storage and a documented operator decision (spec: "where permitted").

No credential means `not_configured`: the pages and the JSON endpoints say so (`{state, message}`), nothing is
called and nothing is simulated. The honest states (`Enums\AiState`) are `ready`, `not_configured`, `unavailable`
(circuit breaker open), `disabled`, `limit_reached`, `rate_limited`, `too_long`. They are returned as props
(`availability`) and as the JSON body of a refused API call with a matching HTTP status (503 / 503 / 403 / 403 / 429 / 422).

## 2. Safeguards

| Safeguard | How |
|---|---|
| Sees no more than the person | Every tool runs as the member (`ToolContext`): `AccessChecker` for the permission (entitlement included) and `RecordAccess::constrain()` for the record scope. A record outside the scope is "not found" |
| Read-only tools | `AiTool::execute()` returns data. A tool that would change data is a `ProposalTool`: `execute()` validates and describes, `apply()` runs only after a human confirms, as that human, through the module action (`ContactDirectory`), inside a transaction with audit and a domain event |
| No money, no permissions | `ToolRegistry` refuses (LogicException) any tool whose name or permission has a payment, payout, refund, payroll, billing, role, permission, invitation, member, transfer, credit-note or salary segment |
| Prompt injection | Record content reaches the model only as data: tool results are `{"untrusted_data": ...}` JSON (tag characters hex-escaped), drafting wraps records in `<record_data>` / `<text_data>` JSON blocks; the system prompt (versioned `SystemPrompt::VERSION`, in code only) ranks above data and says data is never an instruction. Drafting and automation calls have **no tools at all**. A tool the model asks for is checked against the member's available tools regardless of what the model was told |
| Output size | A tool result is capped (`ai.limits.tool_result_chars`, fields `field_chars`), list entries are dropped and the result marked `truncated` |
| Cost | Per-call output cap (`max_output_tokens`), per-turn token cap (`max_turn_tokens`) and round cap (`max_tool_rounds`), per-workspace and per-member requests per minute, plan token limit, circuit breaker (platform-wide, central cache) |
| PII redaction | Off by default (`AI_REDACTION_DEFAULT=false`). A workspace can switch it on (`redact_pii`). Pattern based (`ai.redaction.patterns`: email, phone, card-like numbers) over **everything** sent to the provider. A safeguard, not a guarantee |
| Audit | `ai.tool.executed` (tool, status, record ids, conversation id), `ai.proposal.created|confirmed|rejected|failed`, `ai.conversation.deleted`, `ai.conversations.cleared`, `ai.retention.applied`, `ai.settings.*`. Never message or record content |
| Labelling | Assistant messages carry `aiGenerated`; draft responses `ai_generated: true` and a translated `label` |
| Privacy | A conversation is private to its membership: always looked up through the signed-in membership, 404 for anyone else (owner included) |
| Retention | Workspace `retention_days` (1-365, default 90); `ai.retention` runs daily through the scheduler and deletes idle conversations and expires stale proposals |
| Tenant isolation | Every table is tenant-owned; the rate limiter and cache are per tenant; the breaker holds platform state only |

## 3. Metering and limits

- Limit `ai.tokens` (metered, `LimitDefinition`): a plan caps total tokens (input + output) per billing period via
  `plans.limits['ai.tokens']`. No entry means unlimited.
- `AiGateway` is the only place a provider is called. Before a call it checks the feature switches, the credential,
  the breaker and `UsageLimits::allows('ai.tokens', 1)`. The call that crosses the cap is allowed (its cost is
  unknown beforehand; one turn is bounded by `max_turn_tokens`); the next one is refused with `limit_reached`.
- After each provider call `UsageMeter::record('ai.tokens', in + out, key, meta)`. The key identifies the call:
  `ai:{assistant message ulid}:{round}`, `ai:draft:{ulid}`, `ai:auto:{sha256(idempotency key)}`. A retried job counts
  nothing twice. Meta holds feature, provider, model, input/output tokens and prompt version (no content).
- Usage page and dashboard widget read `UsageReport` (allowance from `UsageLimits`, last 30 days by feature and day
  from the idempotent usage records).
- Charging for usage on an invoice is **not built** (`docs/BILLING.md`); the offers below are flat subscriptions.

## 4. Offers (owner decision: placeholders)

| Offer key | Type | Price | Trial |
|---|---|---|---|
| `monthly` | monthly | BDT 1,990.00 (`199000`) | none |
| `yearly` | yearly | BDT 19,900.00 (`1990000`) | none |
| `trial` | monthly | BDT 1,990.00 (`199000`) | 14 days |

These prices are **placeholders chosen by the implementer**, to be replaced by the product owner (change the offers
and bump the module version; the catalogue snapshot is immutable per version). The default token allowance is set
per plan by the operator; the demo seeder includes `ai` in the Enterprise plan only. Paid activation goes through
verified `InvoiceSettlement` like every app (`docs/BILLING.md`, `tests/Feature/Ai/AiAppStoreTest.php`).

## 5. Data, permissions, routes

Tables (`app/Modules/Ai/Database/Migrations`): `ai_settings` (one row per workspace: `enabled`, `chat_enabled`,
`drafting_enabled`, `automation_enabled` (default off), `data_scopes`, `retention_days`, `redact_pii`),
`ai_conversations` (+ `membership_id`), `ai_messages` (text, tool trace of names/ids, tokens, prompt version,
provider, model), `ai_proposals` (encrypted payload, status, 24 h expiry), `ai_automation_action_receipts`.

Permissions: `ai.assistant.use`, `ai.drafts.generate`, `ai.proposals.confirm` (sensitive), `ai.usage.view`,
`ai.settings.manage` (sensitive). None is granted to a system role; the owner holds all.

| Route name | Verb and URL | Permission |
|---|---|---|
| `ai.assistant.index` | GET `/ai` | `ai.assistant.use` |
| `ai.assistant.conversations.store` | POST `/ai/conversations` (`content`) | `ai.assistant.use` |
| `ai.assistant.conversations.clear` | DELETE `/ai/conversations` | `ai.assistant.use` |
| `ai.assistant.conversations.show` | GET `/ai/conversations/{conversation}` | `ai.assistant.use` |
| `ai.assistant.conversations.messages.store` | POST `/ai/conversations/{conversation}/messages` (`content`) | `ai.assistant.use` |
| `ai.assistant.conversations.destroy` | DELETE `/ai/conversations/{conversation}` | `ai.assistant.use` |
| `ai.proposals.confirm` | POST `/ai/proposals/{proposal}/confirm` | `ai.assistant.use` + `ai.proposals.confirm` |
| `ai.proposals.reject` | POST `/ai/proposals/{proposal}/reject` | `ai.assistant.use` |
| `ai.status` | GET `/ai/status` (JSON) | `ai.drafts.generate` |
| `ai.drafts.store` | POST `/ai/drafts` (JSON) | `ai.drafts.generate` |
| `ai.usage.index` | GET `/ai/usage` | `ai.usage.view` |
| `ai.settings.index` / `ai.settings.update` | GET / PUT `/ai/settings` | `ai.settings.manage` |

Drafting request fields: `kind` (`summarize_record`, `draft_reply`, `rewrite`), `record_type` (`contact`,
`company`) and `record_id` (required unless `rewrite`), `instruction` (<= 1000), `tone` (`neutral`, `friendly`,
`formal`), `text` (required for `rewrite`). Response: `{text, ai_generated, label, prompt_version, usage}`; the
record is loaded through the same tool layer as the member, 404 when not visible. Nothing is saved or sent.

## 6. Tools

Built in (all in the shared contacts directory, `core.contacts.*` permissions, record scope applied):

| Tool | Kind | Data scope | Permission |
|---|---|---|---|
| `contacts_search`, `contacts_get` | read | `contacts` | `core.contacts.view` |
| `companies_search`, `companies_get` | read | `companies` | `core.contacts.view` |
| `contacts_propose_create`, `contacts_propose_update` | proposal | `contacts` | `core.contacts.manage` |

### Adding a tool

Implement `App\Core\Ai\Contracts\AiTool` (or `ProposalTool`) in your module over a documented contract, tag it in
your provider (`$this->app->tag(MyTool::class, AiTool::TAG)`) and register a data scope name. The tool is offered
only to members who hold its permission in an entitled module and only when the workspace enabled its data scope
(new scopes are opt-in; the defaults are `contacts` and `companies`). A module must not import another module's
models: tools wrap that module's own services or documented contracts. Names are `[a-z][a-z0-9_]*`; changing tools
carry `propose` in the name.

## 7. Automation

- Action `ai.text.generate` (permission `ai.drafts.generate`): input `instruction` (<= 2000) and optional `context`
  (<= 6000, passed as data), output `{text, ai_generated, input_tokens, output_tokens, prompt_version}`. Text only,
  no tools. Needs the workspace's `automation_enabled` setting. A durable receipt per tenant / action / idempotency
  key returns the first text on retry; the usage key derives from the same idempotency key.
- Trigger `ai.proposal.confirmed` (payload: proposal id, tool, conversation id, membership id).
- Events: `ai.proposal.confirmed`, `ai.proposal.rejected`.

## 8. Operating

- Set `ANTHROPIC_API_KEY` (and optionally `AI_ANTHROPIC_MODEL`) in the platform environment. Without it the app
  installs and works as far as settings and history go, and says `not_configured` for generation.
- Queue `ai` is worked by Horizon (existing supervisor). Run `php artisan permissions:sync` and
  `php artisan apps:sync --prune` after deploy.
- Fake provider for local development: `AI_PROVIDER=fake` (the scripted provider answers "Fake reply."; it refuses
  to load in production).
- The model is a platform decision; Sonnet class is the default for cost. Prompts are versioned in
  `Support\SystemPrompt`.
