# AI Assistant (module `ai`)

State: Phase 11, backend and bilingual pages (`resources/js/Modules/Ai`: Assistant, Settings, Usage; the operator
panel is `resources/js/Platform/Pages/Ai/Providers.vue`). Not independently reviewed. Category AI Tools, a **paid**
optional app that depends on `core` only. Spec: `MASTER_PROMPT.md` section 14. Design record: ADR 0018.

What it is: a private assistant chat that answers from the records the signed-in member may see, text-only drafting
(summarise a record, draft a reply, rewrite), a workflow action that generates text, and voice input for the command
palette and the assistant (section 11). It **cannot change data on its own**: a change is a proposal that a person
confirms. It cannot move money or change permissions.

What it is not (yet): streaming answers, tenant-supplied provider keys, embeddings / vector search, tools over other
optional modules (the contract is built, see "Adding a tool"), AI-assisted segmentation or campaign features.

## 1. Provider

| Piece | Where |
|---|---|
| Contract | `App\Modules\Ai\Contracts\AiProvider`: `name()`, `model()`, `isConfigured()`, `complete(CompletionRequest): CompletionResponse` (tool use included, no streaming) |
| Real adapters (see also section 9) | `Providers\AnthropicProvider`: Anthropic Messages API (`POST {base_url}/v1/messages`, `x-api-key`, `anthropic-version: 2023-06-01`) over the Laravel HTTP client. The official PHP SDK is **not** installed (new dependency: licence and Laravel 13 check not done); this is a thin adapter over the documented HTTP API |
| Fake | `Providers\FakeAiProvider`: scripted answers. Registered only when the environment is `local` or `testing`, selected only by `AI_PROVIDER=fake`; `ProviderManager` resolves anything else to Anthropic, which reports `not_configured` without a key |
| Config | `config/ai.php`; env `ANTHROPIC_API_KEY`, `ANTHROPIC_BASE_URL`, `AI_ANTHROPIC_MODEL` (default `claude-sonnet-5-5`), `AI_PROVIDER`, `AI_TIMEOUT_SECONDS`, `AI_MAX_OUTPUT_TOKENS`, `AI_MAX_TURN_TOKENS`, `AI_TENANT_RPM`, `AI_MEMBER_RPM`, `AI_REDACTION_DEFAULT` |

The credential is **platform-level**: held by the operator, either as an account in the Super Admin panel
(section 9: stored encrypted, write-only) or, when no account exists, as one key in the environment. It is never
taken from a tenant, the request or a header, and never shown or logged. It exists only in the provider request
header (`x-api-key`, or a bearer token for OpenAI-compatible accounts). A provider failure is logged by HTTP status and error type only (never request or
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
| Confirmation shows values | The confirmation card lists each field with the value that would be written and, for an update, the current value (`ProposalTool::changes()`, plain text, cut at 200 characters), not only field names. A confirm re-reads the proposal under a row lock, so a double click or two tabs apply it once (one audit entry, one event) |
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
- Limit `ai.voice_clips` (metered): voice clips transcribed per billing period (section 11). One per clip, key
  `ai:voice:{ulid}` generated by the server. The Usage page and widget show tokens only.
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
`drafting_enabled`, `automation_enabled` (default off), `voice_enabled` (default off), `data_scopes`, `retention_days`,
`redact_pii`),
`ai_conversations` (+ `membership_id`), `ai_messages` (text, tool trace of names/ids, tokens, prompt version,
provider, model), `ai_proposals` (encrypted payload, status, 24 h expiry), `ai_automation_action_receipts`.

Permissions: `ai.assistant.use`, `ai.drafts.generate`, `ai.proposals.confirm` (sensitive), `ai.usage.view`,
`ai.settings.manage` (sensitive), `ai.voice.use`. None is granted to a system role; the owner holds all.

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
| `ai.voice.transcribe` | POST `/ai/voice/transcriptions` (multipart `audio`, optional `language`; JSON `{text}`) | `ai.voice.use` |

Drafting request fields: `kind` (`summarize_record`, `draft_reply`, `rewrite`), `record_type` (`contact`,
`company`, `whatsapp_conversation`) and `record_id` (required unless `rewrite`), `instruction` (<= 1000), `tone` (`neutral`, `friendly`,
`formal`), `text` (required for `rewrite`). Response: `{text, ai_generated, label, prompt_version, usage}`; the
record is loaded through the same tool layer as the member, 404 when not visible. Nothing is saved or sent.

## 6. Tools

Built in (all in the shared contacts directory, `core.contacts.*` permissions, record scope applied):

| Tool | Kind | Data scope | Permission |
|---|---|---|---|
| `contacts_search`, `contacts_get` | read | `contacts` | `core.contacts.view` |
| `companies_search`, `companies_get` | read | `companies` | `core.contacts.view` |
| `contacts_propose_create`, `contacts_propose_update` | proposal | `contacts` | `core.contacts.manage` |

From other apps:

| Tool | App | Kind | Data scope | Permission |
|---|---|---|---|---|
| `whatsapp_conversation_get` | WhatsApp Business (`ConversationReadTool`) | read | `whatsapp_conversations` | `whatsapp_marketing.conversations.view` (conversation scope applied) |

`whatsapp_conversation_get` returns the customer name, status, tags and the last 20 messages (no internal notes). It
backs the inbox's "Draft with AI": `draft_reply` with record type `whatsapp_conversation` asks for the next reply to
the customer's latest message in their language; the agent reviews and sends it. Nothing is sent by the AI.

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

- Add a provider in the Super Admin panel (AI providers, section 9), or set `ANTHROPIC_API_KEY` (and optionally
  `AI_ANTHROPIC_MODEL`) in the platform environment. Without either the app installs and works as far as settings
  and history go, and says `not_configured` for generation.
- Queue `ai` is worked by Horizon (existing supervisor). Run `php artisan permissions:sync` and
  `php artisan apps:sync --prune` after deploy.
- Fake provider for local development: `AI_PROVIDER=fake` (the scripted provider answers "Fake reply."; it refuses
  to load in production).
- The model is a platform decision; Sonnet class is the default for cost. Prompts are versioned in
  `Support\SystemPrompt`.

## 9. Operator provider accounts (Super Admin > AI providers)

Extends ADR 0013 (platform provider accounts, written for messaging): the operator manages AI provider accounts
in the panel instead of only in the environment. The panel is `platform.ai.*` routes
(`GET/POST /ai/providers`, `PUT/DELETE /ai/providers/{account}`, `POST .../test`, `POST .../default`) on
`admin.APP_DOMAIN`, behind `can:platform.settings.manage`; the page is `Platform/Pages/Ai/Providers`.

| Piece | Where |
|---|---|
| Table / model | `ai_provider_accounts` / `Models\AiProviderAccount` (central connection, no `tenant_id`). Columns: `driver`, `name` (unique), `model`, `base_url`, `is_enabled`, `is_default`, `secrets` (`encrypted:array`, hidden), last test status/reason/time |
| Drivers | `Enums\AiDriver`: `anthropic` (key required, endpoint optional) and `openai_compatible` (chat-completions API: OpenAI, Azure-style gateways, OpenRouter, Ollama-like servers; endpoint required and includes the version prefix such as `https://api.openai.com/v1`, key optional, sent as a bearer token) |
| Manager | `Actions\AiProviderAccounts`: create / update / makeDefault / delete / test, all audited (`platform.ai_provider.*`, no key, only `key_set` / `key_replaced` flags) |
| Resolution | `Providers\ProviderManager`: the enabled default account; else, if accounts exist but none is an enabled default, `not_configured` (never a silent fall back to the environment); else, with no account at all, the environment exactly as before |
| Adapters | `AnthropicProvider` (settings from the account or from config) and `OpenAiCompatibleProvider` (neutral `tool_use` / `tool_result` blocks translated to `tool_calls` / `tool` messages and back) |

Rules: the key is write-only and encrypted; exactly one enabled account is the default (the first enabled account
added becomes it; disabling the default clears the flag; a disabled account cannot be made default). Changing,
disabling, deleting or switching accounts resets the circuit breaker, because its failure history belonged to the
previous provider; the cost guardrails (output cap, turn cap, rate limits, plan limit) are unchanged and apply to
whichever account is active. Workspaces never see or choose an account: the default is platform-wide.
"Test connection" makes one call with an 8 token output cap and stores only `ok` or a reason code
(`not_configured`, `unreachable`, `unauthorized`, `rate_limited`, `http_error`, `bad_response`).

Not verified: both adapters, and especially the OpenAI-compatible one, have only been exercised against faked HTTP
responses (`Http::fake`, `tests/Feature/Ai/ProviderAccountsTest.php`); they have not been run against a live
Anthropic, OpenAI, Azure, OpenRouter or Ollama endpoint. Servers differ (for example in token parameter names such
as `max_tokens` vs `max_completion_tokens`, or in tool-call support), so run "test connection" and a real assistant
turn before relying on an account.

## 10. Text assistant contract (for other apps)

Design record: ADR 0024. Other apps get captions, hashtags, reply suggestions, sentiment, summaries and
translations through `App\Core\Ai\Contracts\TextAssistant` without importing this module. It returns text only;
it never sends, saves or changes anything.

```php
use App\Core\Ai\Contracts\TextAssistant;
use App\Core\Ai\Text\{TextTask, TextKind, TextTone, ContextBlock, TextState};

$result = app(TextAssistant::class)->generate(new TextTask(
    kind: TextKind::ReplySuggestion,
    input: $customerMessage,                 // bounded to 4000 chars (cut, `truncated`)
    purpose: 'whatsapp:reply:'.$messageId,   // metering / idempotency key, [A-Za-z0-9:._-]{1,100}
    locale: 'bn',                            // output language; null = language of the input; required for translate
    tone: TextTone::Friendly,
    maxLength: 600,                          // text kinds; maxItems (1..30) for hashtags
    platform: 'whatsapp',
    context: [new ContextBlock('product', $productDescription)],   // quoted data, max 5 blocks / 6000 chars
    actorMembershipId: $membership->id,      // rate limit only; null for system jobs
));

if ($result->ok()) {
    // $result->text; hashtags: $result->hashtags (no '#'); sentiment: ->sentiment, ->scoreBasisPoints, ->language
    // Show with an "AI-generated" label; a person decides what happens next.
} else {
    // $result->state: not_configured | disabled | limit_reached | refused | failed
    // $result->message is safe to show; $result->retryable says whether trying later may work.
}
```

| Kind | Result fields |
|---|---|
| `caption`, `reply_suggestion`, `summary`, `translate` | `text` (plain, tags stripped, cut to `maxLength`) |
| `hashtags` | `hashtags` (lowercase, de-duplicated, only tokens that start with `#`), `text` as `#a #b` |
| `sentiment` | `sentiment` (`positive`, `neutral`, `negative`, `mixed`, `unknown`), `scoreBasisPoints` (-10000..10000 or null), `language` (ISO 639 or null) |

Behaviour: the workspace must be entitled to `ai` and have AI and the `drafting` switch on (otherwise `disabled`);
the call goes through `AiGateway` (breaker, `ai.tokens` plan limit, per-workspace and per-member rate limits,
redaction, idempotent usage record). The same purpose with the same task is metered once and replayed from the
tenant cache for an hour. Tenant text is sent only as encoded data blocks; model JSON is never trusted (sentiment
falls back to `unknown`). A caller must check its own permission first: the contract authorizes nothing.

Testing a consumer (`tests/Support`): `InteractsWithTextAssistant::fakeTextAssistant()` binds the scripted
`FakeTextAssistant` (queue `FakeTextAssistant::ok()`, `hashtags()`, `sentiment()`, `refused()`; read `->tasks`).
`realTextAssistant($tenant)` entitles the workspace and switches to the scripted provider so the real
gateway-backed assistant runs. In tests the array cache is rebuilt on each tenant-context entry: keep the calls of
one scenario inside one `inTenant()` block when asserting replay or rate limits.

## 11. Voice input

Design record: ADR 0038. Speech becomes text the member sees; it never runs anything on its own.

| Piece | Where |
|---|---|
| Where voice appears | The microphone in the command palette's search row (core `CommandPalette` + `VoiceButton`) and beside Send on the assistant pages. Push to talk: stop button, cancel, seconds left; ends after 1.5 s of quiet following speech or at 30 s |
| Offer | Core contract `App\Core\Ai\Contracts\VoiceInput`, bound by this module to `GatewayVoiceInput` (core binds `NullVoiceInput`). Offered only when the workspace is entitled to `ai`, `voice_enabled` is on, the member holds `ai.voice.use` and a transcription provider is configured. Shared as the workspace prop `voice` (`{maxSeconds, maxBytes, autoRun}` or null); not shared to a support session |
| Route | `POST /ai/voice/transcriptions` (`ai.voice.transcribe`, `can:ai.voice.use`, `throttle:30,1`): `audio` sniffed as audio (WebM, Ogg, MP4/M4A, MP3, WAV), at most `ai.transcription.max_kilobytes` (2048); `language` one of the platform locales, default the interface language. Answer `{text}` (one plain line), or `{state, message}` with the `AiState` status |
| Gateway | `AiGateway::transcriptionState()` / `transcribe()`: workspace switch, configured provider, a separate breaker (`CircuitBreaker::transcription()`), plan limit `ai.voice_clips`, the chat rate limits; one usage record per clip (provider, model, bytes; never the text) |
| Provider | `Contracts\Transcriber`, adapter `Providers\OpenAiTranscriber` (`POST {base_url}/audio/transcriptions`, multipart, `response_format=json`, bearer key when set). `Providers\FakeTranscriber` in `local`/`testing` only (`AI_TRANSCRIPTION_PROVIDER=fake`, hears `ai.transcription.fake_text`) |
| Resolution | `Providers\TranscriberManager`: the enabled account marked as the voice provider (Super Admin > AI providers, "Use for voice input"); when accounts with a transcription model exist but none is that, `not_configured`; otherwise the environment `AI_TRANSCRIPTION_BASE_URL`, `AI_TRANSCRIPTION_MODEL`, `AI_TRANSCRIPTION_API_KEY`, `AI_TRANSCRIPTION_TIMEOUT_SECONDS` |
| Accounts | OpenAI-compatible accounts take an optional transcription model; the chat model is then optional too (a Whisper-only server). An account without a chat model never becomes the chat default; the first enabled account given a transcription model becomes the voice provider. "Test connection" also sends half a second of generated silence to the transcription model |
| Member preference | "Run voice commands without pressing Enter" on the profile page (`users.preferences.voice_auto_run`, off by default, no workspace override). On: a single matching **page** in the palette opens and the assistant message is sent; actions (sign out, switch workspace, theme) always wait for Enter |
| Browser policy | `Permissions-Policy: microphone=(self)` on workspace hosts only |

Nothing heard is kept: no file, row, queue payload, log line or audit entry holds the audio or the transcript.

Not verified: the adapter only against `Http::fake` (`tests/Feature/Ai/VoiceTest.php`); recording and pause detection
only against test doubles (`tests/js/voice.test.ts`). Run "Test connection" and one spoken command in a real browser,
over HTTPS or `*.localhost`, before relying on it. Recognition of mixed Bengali and English is untested.
