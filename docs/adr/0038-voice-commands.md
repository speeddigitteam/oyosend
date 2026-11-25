# ADR 0038 — Voice commands: speech becomes text the member sees, never an action on its own

- **Status:** Accepted and built (2026-10-15). The owner decided: transcription only through the operator's own
  provider (no browser speech service); voice is part of the paid AI app; "run without Enter" is the member's own
  preference with no workspace override. Not reviewed; never run against a live transcription server or in a real
  browser with a microphone.
- **Date:** 2026-10-15
- **Changes:** `Permissions-Policy` sends `microphone=(self)` on workspace hosts; a new migration adds
  `ai_settings.voice_enabled` and `ai_provider_accounts.transcription_model` / `is_transcription_default`; a new
  permission `ai.voice.use`, a new limit `ai.voice_clips`, a new route `ai.voice.transcribe`, a new core contract
  `VoiceInput`, a user preference `voice_auto_run`; the `ai` module version is now 1.1.0. Changes no locked decision.
  Builds on ADR 0018 (AI never changes data on its own) and ADR 0013/0028 (operator-managed credentials).

## Context

The owner wants to work in the workspace by speaking: "open invoices", "কন্টাক্ট খোলো", "find Rahim's last invoice".
Two existing pieces already do the work once speech is text:

- The **command palette** (Ctrl/Cmd+K) lists only the pages and quick actions the member's sidebar offers, so it is
  already filtered by permission and entitlement, in the member's language.
- The **AI assistant** answers from records the member may see and changes data only through a proposal the member
  confirms (ADR 0018).

So the new part is speech recognition and where its text goes. The risks: a misheard sentence doing something; the
member's voice going to a company nobody chose; the microphone reachable from pages that have no use for it; a
control shown where it cannot work.

Browser speech recognition (the Web Speech API) was considered and **rejected by the owner**: Chrome, Edge and Safari
send the audio to Google, Microsoft or Apple, outside the operator's control, and Firefox has none.

## Decision

### 1. Voice is an input method, not an actor

Speech produces a **transcript** that goes to one of two places, where the member sees it:

| Where | What happens |
|---|---|
| Command palette (microphone in the search row) | The transcript becomes the search text and is matched by the page names it contains ("open contacts" finds Contacts; the longest name wins, so "AI settings" beats "Settings"). The member picks the result with Enter or a click |
| AI assistant message box (microphone beside Send, on the assistant pages) | The transcript is added to the box. Whatever the assistant proposes still needs the confirmation card |

**Push to talk only.** No wake word, no listening in the background. While recording the control shows a stop button,
a cancel button and the seconds left (read out through `aria-live`); recording ends on the stop button, after
1.5 seconds of quiet following speech, or at the length limit (30 seconds). Voice is not offered in the Super Admin
panel, on the central site, in the live-chat frame, or to a support session (the operator is not the member).

### 2. "Press Enter" is a member preference

The profile page has **"Run voice commands without pressing Enter"**, **off by default**, stored in
`users.preferences.voice_auto_run` beside the numerals preference, so it follows the person into every workspace. The
workspace cannot override it.

- Off: the transcript waits in the palette or the box; the member presses Enter and can correct it first.
- On: in the palette, if exactly one **page** matches, it opens. An **action** (sign out, switch workspace, theme)
  never runs without Enter, whatever the preference. In the assistant, the message is sent when the transcript
  arrives; a change the assistant proposes still needs a click.

Neither target can change data from one sentence, so this is safe to leave to the member.

### 3. Transcription through the operator's provider

The browser records one clip (`MediaRecorder`: WebM/Ogg Opus, MP4 on Safari) and posts it to
`POST /ai/voice/transcriptions` (`ai.voice.transcribe`). The AI module transcribes it through `AiGateway`:

- **Contract.** `App\Modules\Ai\Contracts\Transcriber`, separate from the chat `AiProvider`, because the operator may
  use a different service for speech. One adapter: `OpenAiTranscriber`, for any server with the OpenAI transcription
  API (`POST {base_url}/audio/transcriptions`, multipart, `response_format=json`): OpenAI (`whisper-1`,
  `gpt-4o-transcribe`) and self-hosted Whisper servers that copy it, so audio need not leave servers the operator
  chose. The Anthropic API has no transcription endpoint that this adapter knows of; Anthropic accounts cannot
  transcribe.
- **Resolution** (`TranscriberManager`, the ADR 0018 rules): the enabled account marked as the voice provider; when
  accounts with a transcription model exist but none is that, `not_configured` (never a silent fall back); otherwise
  the environment (`AI_TRANSCRIPTION_BASE_URL`, `AI_TRANSCRIPTION_MODEL`, `AI_TRANSCRIPTION_API_KEY`). An
  OpenAI-compatible account can have a transcription model, a chat model or both; an account without a chat model
  never becomes the chat default. The first enabled account given a transcription model becomes the voice provider.
- **Gateway.** `AiGateway::transcriptionState()` / `transcribe()`: the workspace switch, a configured provider, **its
  own circuit breaker** (a failing speech server does not switch off the assistant), the plan limit
  `ai.voice_clips`, and the same per-workspace and per-member requests per minute as chat.
- **Metering per clip.** One `ai.voice_clips` usage record per clip with a server-generated idempotency key. Seconds
  were considered, but the `json` response carries no duration and the browser's figure cannot be trusted; a clip is
  at most 30 seconds, so counting clips is honest and cannot be under-reported.
- **Nothing kept.** The clip is read into memory for the request and dropped. No file, row, queue payload, log line or
  audit entry holds the audio or the transcript; the usage record holds the provider, model and byte size only. A
  provider failure is logged by HTTP status alone.
- **Input checks.** The type is sniffed from the content (`mimetypes`, not the name or the browser's claim), the size is
  capped (`ai.transcription.max_kilobytes`, 2048), the language is one of the platform locales (default: the interface
  language). The returned text is reduced to one plain line and capped like a typed message.

### 4. Settings and permissions

- Workspace switch `ai_settings.voice_enabled`, **off by default**, on the AI Settings page (`ai.settings.manage`),
  audited with the other `ai.settings.*` changes. The page says that each recording goes to the platform's
  transcription provider and is not kept, and shows whether speech to text is set up.
- Permission `ai.voice.use` (group `ai_assistant`, not granted to any system role; the owner holds it). The microphone
  in the assistant box also needs that page's own permission.
- The workspace shell asks the core contract `App\Core\Ai\Contracts\VoiceInput` whether to offer the microphone; the
  AI module answers yes only when the workspace is entitled to `ai`, voice is switched on, the member holds
  `ai.voice.use` and a transcription provider is configured. Without the app the core `NullVoiceInput` offers nothing.
  The shared prop `voice` (`{maxSeconds, maxBytes, autoRun}` or null) only hides or shows the control: the route checks
  `entitled:ai`, `can:ai.voice.use` and the gateway state again.
- A full allowance or an open breaker does not hide the control; the member is told why when they use it.

### 5. Browser policy

`SecurityHeaders` sends `microphone=(self)` on workspace hosts and `microphone=()` on the central site and the Super
Admin host. The live-chat frame is a stateless callback page with its own headers and keeps `microphone=()`. Before
this change the workspace also sent `microphone=()`, which blocked the Telephony softphone's `getUserMedia` (ADR 0036)
too; the same change unblocks it. Microphone access also needs HTTPS (`*.localhost` counts as secure).

## Consequences

- A member can move around the workspace and talk to the assistant by voice in Bengali or English, and voice can never
  do more than the keyboard can.
- Voice costs the operator a transcription call per clip and is sold with the AI app; without a configured
  transcription server no microphone appears anywhere.
- Recognition quality for mixed Bengali and English ("invoice খোলো") and for Bengali suffixes ("কন্টাক্টগুলো") is
  unknown until tried with a real server; matching needs the whole page name as spoken words.
- Not verified: the adapter has only run against `Http::fake`; recording, pause detection and the microphone prompt
  have only run against test doubles in Vitest, not in a real browser.

## Not built

Dictation into every text field, voice replies (text to speech), wake words, voice in the Super Admin panel, any voice
path to money, payroll, roles or permissions, storing recordings, voice usage on the AI Usage page (the clips are in
the usage records under `ai.voice_clips`).

## Alternatives considered

- **The browser's Web Speech API.** Rejected by the owner: audio to Google, Microsoft or Apple; no Firefox.
- **Voice that runs any command directly.** Rejected: one misheard word would act. The palette and proposals are the
  control, and actions always wait for Enter.
- **Always listening.** Rejected: privacy, and accidental triggering in an office.
- **A speech library running in the browser.** Not chosen: Bengali models are large downloads and each library needs
  the licence and maintenance check; a self-hosted Whisper server gives the same "audio stays with us" property.
- **Metering seconds of audio.** Rejected for now (see 3).
