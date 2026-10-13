# ADR 0024 — A core `TextAssistant` contract: other apps get text from AI without touching the AI module

- **Status:** Accepted (implemented, not independently reviewed)
- **Date:** 2026-10-13
- **Builds on:** ADR 0014 (core contracts, honest `not_configured`), ADR 0018 (AI never acts; one gateway)

## Context

WhatsApp Business (reply suggestions) and Social (captions, hashtags, sentiment) want short generated text.
Optional apps may not import another app's classes, and `DraftService` / `GenerateTextAction` are shaped for
members and workflows (records, receipts, HTTP refusals). Each consumer calling the gateway itself would
duplicate prompting, parsing and the safeguards, and would be one forgotten check away from an unmetered or
injectable call.

## Decision

1. **One contract in core.** `App\Core\Ai\Contracts\TextAssistant::generate(TextTask): TextResult`, with value
   objects in `App\Core\Ai\Text` (`TextTask`, `ContextBlock`, `TextResult`, enums `TextKind`, `TextTone`,
   `TextState`, `Sentiment`). Core binds `NullTextAssistant` with `bindIf` (state `not_configured`); the `ai`
   module binds `GatewayTextAssistant`. Consumers depend on the interface only.
2. **Text only.** The contract has no tools and no side effects. It returns text, a normalised hashtag list or a
   sentiment triple; the caller shows it to a person. This is ADR 0018's rule applied to a new entry point.
3. **Everything through `AiGateway`.** Feature switches, credential, circuit breaker, plan limit
   (`ai.tokens`), per-workspace and per-member rate limits, optional PII redaction and idempotent metering are
   inherited, not reimplemented. The feature switch is the existing `drafting` setting (no new column); the
   workspace must also be entitled to the `ai` app, otherwise the state is `disabled`.
4. **Tenant text is data.** The task sentence is built only from closed values (kind, tone enum, locale code,
   platform key, numbers). Input and context travel as encoded `<text_data>` / `<record_data>` blocks (the
   existing `DataBlock`), so they cannot close a block or pose as an instruction; the versioned system prompt
   (`TextPrompts::VERSION`) ranks above data. Input and context are size-capped (cut, `truncated` flag).
5. **Output is untrusted.** Plain text is stripped of tags and control characters and cut to the requested
   length. Hashtags must start with `#`, are lowercased, de-duplicated and capped. Sentiment must be a strict JSON
   object with a known label and a score in -1..1 that does not contradict the label; anything else is
   `unknown`. Score is stored as signed basis points.
6. **Idempotency by purpose.** Metering key = `ai:text:` + sha256(purpose + task fingerprint). A retry is counted
   once (meter) and, for one hour, replayed from the tenant cache (`replayed = true`) instead of calling the
   provider. Replay is best effort; the meter is the guarantee. A caller wanting a fresh alternative changes the
   purpose.
7. **Honest states.** `ok`, `not_configured`, `disabled`, `limit_reached`, `refused` (rate limited, retryable),
   `failed` (provider down or unusable output, retryable). Messages are human-safe; provider errors, bodies and
   keys never reach a result.
8. **Authorization stays with the caller.** The assistant takes an optional actor membership id only for the
   per-member rate limit. The consuming module checks its own permission and decides what to do with the text.

## Consequences

- Consumers need no AI knowledge and can be tested with `Tests\Support\FakeTextAssistant` (`docs/AI.md`
  section 10).
- Usage appears on the existing AI usage page under feature `drafting`.
- Adding a kind means a case in `TextKind`, a sentence in `TextPrompts`, a parser branch and a test.
- The retry replay stores generated text in the tenant cache for an hour; nothing else is persisted.

## Not decided

- Streaming, multiple alternatives per call and image captioning are not part of this contract.
- A per-feature switch separate from `drafting` (for example "social AI off, inbox AI on") would need a settings
  column; deferred until an owner asks.
