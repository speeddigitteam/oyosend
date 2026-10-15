# ADR 0038 — Voice commands: speech becomes text the member sees, never an action on its own

- **Status:** Proposed, **not built**. The owner decided on 2026-10-15 that voice belongs to the paid AI app and
  that the member's own "run without Enter" preference is enough (no workspace override). Open: question 1 at the end.
- **Date:** 2026-10-15
- **Changes (when built):** relaxes `Permissions-Policy` to `microphone=(self)` on the workspace host; adds one AI
  setting, one AI permission and one member preference; stage 2 adds a transcription capability to the AI provider contract
  and a metered limit. Changes no locked decision. Builds on ADR 0018 (AI never changes data on its own).

## Context

The owner wants to work in the workspace by speaking: "open invoices", "নতুন কন্ট্যাক্ট", "find Rahim's last invoice".
Nothing voice-related exists today. Two existing pieces already do the work once speech is text:

- The **command palette** (Ctrl/Cmd+K, `Core/Components/Shell/CommandPalette.vue`) lists only the pages and quick
  actions the member's sidebar offers, so it is already filtered by permission and entitlement, in the member's
  language.
- The **AI assistant** (ADR 0018) answers from records the member may see and changes data only through a proposal
  the member confirms.

So the new part is speech recognition and where its text goes. The risks: a misheard sentence doing something; audio
of the member leaving the platform without anyone deciding that; the microphone being reachable from pages that
have no business using it; a control shown in a browser where it cannot work.

## Decision (proposed)

### 1. Voice is an input method, not an actor

Speech produces a **transcript**, shown to the member before and while it is used. The transcript goes to exactly
one of two places:

| Where | What happens |
|---|---|
| Command palette (mic button inside it, or a shortcut) | The transcript becomes the palette's search text. The member picks the result, or (see 2) the single exact match opens. Navigation and the palette's existing quick actions only: nothing that writes data runs from the palette without its own form or confirmation, exactly as today |
| AI assistant message box (mic button next to Send) | The transcript fills the box. Sending follows the member's preference (see 2). Whatever the assistant proposes still needs the confirmation card (ADR 0018); voice adds no path around it |

There is no always-on listening and no wake word: **push to talk**, a visible "listening" state with a stop button,
an automatic stop after silence or 15 seconds, and an `aria-live` region that reads the transcript. Voice is not
offered in the Super Admin panel, on the central site or in the live-chat frame.

### 2. "Press Enter" is a member preference (owner's request, 2026-10-15)

A member preference **"Run voice commands without pressing Enter"**, **off by default**:

- Off: the transcript waits in the palette or the message box; the member presses Enter (or clicks) to go on, and
  can correct the text first.
- On: in the palette, if exactly one entry matches, it opens; with zero or several matches the list is shown and
  nothing runs. In the assistant, the message is sent when speech ends.

This is safe to leave to the member because neither target can change data from one sentence: the palette only
navigates or opens a form, and an assistant change is still a proposal that needs a click. The workspace can switch
voice off entirely (section 4) but does not decide this preference for its members.

### 3. Recognition engine, in two stages

**Stage 1: the browser's own recognition (Web Speech API).** No dependency, no server cost, no audio reaches our
servers. Language follows the member's locale (`bn-BD` or `en-US`/`en-GB`). The button exists only when the browser
has the API (Chrome, Edge, Safari; **not Firefox**) and the workspace switched voice on: no dead control.
The privacy cost must be said plainly: **in most browsers the audio is processed by the browser vendor's cloud
service** (Google for Chrome, Microsoft for Edge, Apple for Safari), not by the operator. The workspace switch
carries that sentence, and the setting is **off by default**. No provider is called, so stage 1 uses no
`ai.tokens`.

**Stage 2 (later, optional): transcription through the platform.** The browser records a short clip
(`MediaRecorder`, at most 30 seconds, size-capped) and posts it to a workspace route; the AI module transcribes it
through `AiGateway` with the operator's provider, so the operator chooses who hears the audio (including a
self-hosted, Whisper-compatible server). Rules: a new `transcribe()` capability on the provider contract, offered
only by drivers that have it (OpenAI-compatible `/audio/transcriptions`; whether the Anthropic API offers
transcription must be checked when this is built, not assumed); the clip is held in memory only, **never stored,
logged or audited** (the audit records "transcribed, N seconds"); metered as a new limit `ai.audio_seconds` with an
idempotency key per clip; honest `not_configured` when the active provider cannot transcribe.

**Both stages belong to the paid AI app (owner decision, 2026-10-15).** A workspace without the `ai` entitlement sees
no voice control anywhere, the palette included.

### 4. Settings and permissions

- AI setting `voice_enabled` on `ai_settings` (default `false`), on the AI Settings page under `ai.settings.manage`,
  audited like the other `ai.settings.*` changes.
- New permission `ai.voice.use` in the AI manifest (not granted to any system role; the owner holds it), so voice can
  be given without the assistant. The mic in the assistant box needs it and `ai.assistant.use`; stage 2's
  transcription route checks it on the server with `can:` and `entitled:ai`.
- Stage 1 makes no server call, so the permission and the switch decide only whether the control is shown; voice
  reaches nothing the keyboard does not already reach.
- Member preference `voice_auto_run` (default `false`), on the member's own profile like the numerals preference.
  **The workspace cannot override it (owner decision, 2026-10-15)**: it decides only whether voice exists at all.

### 5. Browser policy

`SecurityHeaders` sends `microphone=()` on every response today, which blocks the microphone outright. The
workspace host gets `microphone=(self)`; the central site, the Super Admin host and the live-chat frame keep
`microphone=()`. Microphone access also needs HTTPS (`*.localhost` counts as secure for local development).

**Side finding:** the Telephony softphone (ADR 0036) calls `getUserMedia` on the workspace host, so the current
header blocks it too; its "microphone refused" message would appear even when the member allowed the microphone.
The same header change fixes it. It should be checked in a browser either way.

## Consequences

- Voice is sold with the AI app; workspaces without it see nothing.
- A member can move around the workspace and talk to the assistant by voice in Bengali or English, and voice can
  never do more than the keyboard can.
- Firefox users see no voice control in stage 1.
- Stage 1 sends audio to the browser vendor; the workspace owner decides that knowingly. Stage 2 removes that cost
  at the price of AI usage.
- Recognition quality for mixed Bengali and English speech ("invoice খোলো") is unknown until tried; the palette's
  `keywords` may need English and Bengali synonyms per page.

## Not built (and not planned here)

Dictation into every text field, voice replies (text to speech), wake words, voice in the Super Admin panel, any
voice path to money, payroll, roles or permissions, storing recordings.

## Alternatives considered

- **Voice that runs any command directly.** Rejected: one misheard word would act. The palette and proposals are the
  control.
- **Always listening.** Rejected: privacy and battery cost, accidental triggering in an office.
- **A JavaScript speech library running in the browser.** Not chosen now: models for Bengali are large downloads,
  and each library needs the licence and maintenance check. Stage 2's self-hosted option covers "audio never leaves
  our servers".

## Open questions for the owner

1. **Open.** Is stage 1 (browser recognition, audio processed by Google/Microsoft/Apple, off by default) acceptable,
   or should only stage 2 be built?
2. Decided 2026-10-15: part of the paid AI app (both stages).
3. Decided 2026-10-15: the member's own preference is enough; no workspace override.
