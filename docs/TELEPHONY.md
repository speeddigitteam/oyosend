# Telephony

Optional app `telephony` (category Customer Support, one free offer, depends on `core`). Decision record:
[ADR 0036](adr/0036-telephony-calls-dialer-and-softphone.md).

State of the build: **built, unreviewed, never run against a live PBX.** Everything below is tested against the fake
PBX and mocked browser APIs only. Nothing rings until a workspace connects a real PBX.

## What it does

| Feature | How |
|---|---|
| Browser softphone | JsSIP (MIT, `jssip` 3.13.8) registers the member's own SIP extension on the workspace's PBX over a secure WebSocket and carries the audio with WebRTC. Call, answer, decline, mute, keypad tones (DTMF), one call at a time (a second incoming call gets 486 Busy). |
| Dialer | A keypad and number box on `/telephony/dialer`. Bangladeshi numbers in any spelling (Bengali digits too) become E.164, like `PhoneNumber::normalize()` on the server; the server normalises again and decides. |
| Click-to-call | The "Call" action on a contact's Customer 360 opens the dialer on that contact's number. |
| Bridge call | "Call through my phone": the PBX rings the member's own phone (their `bridge_phone`, else their extension) and, once answered, the customer. For a member without a microphone or with the softphone closed. |
| Call log | `/telephony/calls`, narrowed by `telephony.calls.view` (scopable). Calls also show on the contact's Customer 360 and in the "Calls by member" report. |

No call is recorded: there is no recording column, field or setting.

## Why a PBX

A licensed Bangladeshi IPTSP carries the calls (BTRC rules). IPTSPs sell SIP trunks, and none we found offers WebRTC
or an HTTP origination API. So each workspace connects **its own PBX** (Asterisk or FreeSWITCH) that holds the trunk
and provides the two interfaces the app needs: a WSS endpoint for the softphone and the Asterisk REST Interface (ARI)
for bridge calls. The PBX is infrastructure the company runs; it is not a dependency of this repository.

## Settings (`/telephony/settings`, `telephony.settings.manage`)

Stored per workspace in `telephony_settings` (one row). Secrets are **encrypted and write-only**: the page learns only
whether one is stored, and an empty field on save keeps it.

| Field | Used for |
|---|---|
| `driver` | `asterisk`. `fake` (rings nobody) is offered only in `local` and `testing` |
| `wss_url`, `sip_domain` | The softphone. Both set = softphone ready |
| `stun_urls`, `turn_url`, `turn_username`, `turn_credential` | ICE servers handed to the softphone |
| `api_url`, `api_username`, `api_secret` | ARI, for bridge calls. All three set = bridge ready |
| `origination_context`, `caller_id` | The dialplan context and the caller id for bridge calls (default context `from-internal`) |
| `webhook_secret` | Signs PBX events. Generated on request and shown once |

The ARI URL must be `https`, carry no credentials, query or fragment, and resolve to public addresses only
(`PbxUrlGuard`, a server-side request forgery guard). The connection is pinned to the checked addresses. A
single-company install whose PBX sits on the app's private network sets `TELEPHONY_ALLOW_PRIVATE_PBX=true`.

### Extensions

`telephony_extensions`: one per member (`membership_id` unique per workspace), an `extension` unique per workspace, an
encrypted write-only `sip_password`, and an optional `bridge_phone` (E.164). The softphone needs the password.

**The SIP password reaches the member's browser.** A browser softphone cannot register without it. It is returned only
by `GET /telephony/softphone/credentials`, only to that member, as JSON with `Cache-Control: no-store`, never in page
props, never logged. The PBX should limit each extension to its own calls.

## The call log

`telephony_calls`: direction (`outbound`/`inbound`), mode (`softphone`/`bridge`), status (`dialing`, `ringing`,
`answered`, `completed`, `missed`, `busy`, `no_answer`, `failed`), the remote number (E.164), the extension, the
matched shared contact (through the indexed `contacts.phone_e164`), started/answered/ended times, the duration in
seconds and the record owner (`HasRecordOwner`: the member who made or took the call).

Two sources, ranked:

1. **The PBX's events** are the record. They set `confirmed_at`.
2. **The member's browser** reports its own call (`POST /telephony/calls`, `POST /telephony/calls/{call}/status`) so the
   log is live without a PBX webhook. Only the call's owner may report; once the PBX confirmed a call, browser reports
   change nothing. The call log marks unconfirmed calls.

A call that ends unanswered is `missed` (incoming) or `no_answer` (outgoing). `telephony.call.ended` is announced once,
when a call reaches a final state.

## PBX events (`POST {workspace}/telephony/events`)

Stateless (`tenant.callback`): no session, no cookies, no CSRF. Headers:

- `X-Oyosend-Timestamp`: Unix seconds, within 300 seconds of now.
- `X-Oyosend-Signature`: `sha256=` + HMAC-SHA256 of `"{timestamp}.{raw body}"` with the workspace's webhook secret.

Body (JSON, at most 16 KB):

```json
{
  "event_id": "unique per event (redelivery is a no-op)",
  "call_id": "the PBX's id, or ours (the ARI channel id of a bridge call)",
  "sip_call_id": "optional; the SIP Call-ID, to match a softphone call",
  "type": "ringing | answered | ended",
  "direction": "inbound | outbound",
  "from": "+8801711000001",
  "to": "+8801711000002",
  "extension": "101",
  "status": "completed | busy | no_answer | failed (with ended)",
  "at": "2026-10-15T10:00:00+06:00"
}
```

A call is found by our id, the PBX's id, or the SIP Call-ID. An unknown call becomes a new row owned by the member
whose extension it names (inside this workspace only). Answers: `accepted`, `ignored` (nothing usable), `duplicate`,
`401` (bad signature or stale), `404` (no secret set), `413`, `422`.

## Permissions

| Permission | Scopable | Gives |
|---|---|---|
| `telephony.calls.view` | yes | The call log, calls on a contact, the report |
| `telephony.calls.make` | no | The dialer, the softphone credentials, bridge calls |
| `telephony.settings.manage` | no | Settings, extensions, the webhook secret |

## Routes

All under `{tenant}.APP_DOMAIN/telephony`, behind `auth:web`, `auth.session`, `member`, `subscribed`,
`entitled:telephony` and a `can:` permission, except the PBX event route.

| Name | Verb and path |
|---|---|
| `telephony.dialer` | GET `/dialer` (`?number=`, `?contact=`) |
| `telephony.softphone.credentials` | GET `/softphone/credentials` |
| `telephony.calls.start` / `.report` | POST `/calls`, POST `/calls/{call}/status` |
| `telephony.bridge` | POST `/bridge` |
| `telephony.calls.index` | GET `/calls` |
| `telephony.settings.*` | GET/PUT `/settings`, POST `/settings/webhook-secret`, POST/PUT/DELETE `/settings/extensions[/{extension}]` |
| `telephony.events` | POST `/telephony/events` (stateless, HMAC) |

## Published to other apps

Customer 360 section `telephony.calls` (`CallsSection`, with a "Call" action), report `telephony.calls_by_member`,
automation trigger `telephony.call.ended`. Audit events: `telephony.settings.saved` (changed field names only),
`telephony.webhook_secret.rotated`, `telephony.extension.created|updated|deleted`. Morph aliases `telephony_call`,
`telephony_extension`, `telephony_settings`.

## Setting up a PBX (outline)

1. Run Asterisk 20+ (or FreeSWITCH) with the IPTSP's SIP trunk.
2. Enable WebRTC on a PJSIP transport (WSS on 8089, DTLS-SRTP, ICE), one endpoint per member extension.
3. Enable ARI over https with a user for Oyosend, and a dialplan context that reaches both extensions and the trunk.
4. Post call events to the workspace's event URL, signed as above (a small ARI or dialplan script).
5. Enter the settings and give members their extensions.

## Not done

- Never run against a live PBX, browser or IPTSP. The ARI adapter and the event body are a documented contract only.
- No hold or transfer, no call queues or IVR, no realtime push of the log (the dialer refreshes on its own calls only).
- No usage limit or metering.
