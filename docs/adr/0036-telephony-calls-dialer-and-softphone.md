# ADR 0036 — Telephony: call log, click-to-call, dialer and softphone

Status: **Accepted** (2026-10-15). The owner chose: an own PBX with an IPTSP SIP trunk; each workspace configures
its own PBX and numbers; build everything now against fakes and verify on a real PBX later.

## Context

The owner wants a calling app: every call logged against the shared contact, a click-to-call button on a contact,
a dialer keypad for any number, and a browser softphone (talk through the computer's microphone and headset, and
receive calls in the browser). Call recording is not wanted.

Bangladesh constraints:

- Voice must be carried by a BTRC-licensed operator (IPTSP / IGW / ICX); bypassing them is illegal. Foreign CPaaS
  (Twilio, Plivo, Vonage) cannot give local numbers and their termination into Bangladesh is not dependable, so
  they are not the provider.
- Local IPTSPs typically sell a **SIP trunk** or SIP accounts (IP numbers). Few publish an HTTP API, and none we
  found offers WebRTC (SIP over WebSocket with DTLS-SRTP media), which a browser needs.

So no single local provider can serve all four features directly. A browser cannot speak plain SIP/RTP, and a SIP
trunk cannot place a "ring the agent, then the customer" call by itself.

## Decision (proposed)

### 1. A PBX between the app and the IPTSP

Each workspace connects to a **PBX** (Asterisk or FreeSWITCH) that holds the IPTSP's SIP trunk. The PBX is a
separate server the operator or the company runs; it is **infrastructure, not a code dependency** of this
repository (Asterisk is GPL-2.0, FreeSWITCH MPL-1.1; neither is linked into or shipped with Oyosend). The PBX
provides what the IPTSP cannot:

- **WebRTC for the softphone**: SIP over secure WebSocket (WSS), DTLS-SRTP media, ICE with STUN/TURN.
- **An HTTP control API** (Asterisk ARI, or FreeSWITCH's event socket behind a small HTTP shim) to originate calls
  and to report call events.

A hosted service that already offers WSS + an origination API can stand in for the PBX; the app only needs those
two interfaces.

### 2. Features on top of it

| Feature | How |
|---|---|
| Softphone | **JsSIP** (MIT, 3.13.x, maintained) in the browser registers the member's own SIP extension over WSS. Outgoing and incoming calls, mute, hold, transfer later. Ringing for incoming calls comes from the SIP registration itself, so no Reverb server is needed for it. |
| Dialer | A keypad that dials through the softphone; Bangladeshi numbers are normalised (`01712…` → `+8801712…`). A matching contact is shown and the call is filed under it; an unknown number offers "Save as contact" (through `ContactDirectory`). |
| Click-to-call | The same as the dialer with the number taken from the contact. For a member without a softphone open, a **bridge** fallback asks the PBX API to ring the member's own phone first, then the customer. |
| Call log | Tenant-owned `telephony_calls` (direction, status, member, contact/company id, numbers, started/answered/ended, duration in seconds, PBX call id). Written from **PBX events** received on a `tenant.callback` webhook (signature checked, idempotent by event id, payload never trusted beyond what it reports about its own call). What the browser reports is shown live but is never the record. No recording column. |

Published to other apps the standard way: a Customer 360 section (calls on the contact's timeline), a
`ReportProvider` for the call log, an automation trigger `telephony.call.ended`, a usage meter if a plan caps calls.

### 3. Credentials

- The workspace's PBX connection (WSS URL, SIP domain, API URL, API credentials) and each member's SIP extension
  password are stored **encrypted and write-only**, never shown again, never logged — the ADR 0028 pattern, with the
  same environment fallback for a single-company install.
- **A softphone must hand the member's own SIP password to that member's browser** to register. This is inherent to
  every browser softphone. Mitigation: one extension per member, delivered only to that member over HTTPS on request,
  never in page props shared with others, never logged; the PBX limits each extension to its own calls; rotating it is
  one action.

### 4. Module

A new optional app `telephony` (category Communication) following `docs/MODULES.md`: permissions
`telephony.calls.view` (scopable), `telephony.calls.make`, `telephony.settings.manage`; morph alias; entitlement-gated
routes; en + bn strings; tests for tenant isolation, cross-workspace 404, webhook idempotency and `not_configured`.
Without a configured PBX every screen says so plainly and nothing is simulated.

## Consequences

- Real calls need a PBX (or an equivalent hosted service) and an IPTSP trunk before anything rings. Until then the app
  is built and tested against fakes only, and is documented as never exercised against a live server, like the other
  provider adapters.
- New frontend dependency: `jssip` (MIT). New platform-wide or per-workspace encrypted credential storage, recorded in
  `docs/DATABASE.md`.
- Microphone permission and HTTPS are required in the browser; `*.localhost` counts as secure for local testing.

## Owner decisions (2026-10-15)

1. **Phone server**: an own PBX (Asterisk or FreeSWITCH) holding the IPTSP's SIP trunk.
2. **Who configures it**: each workspace, in its own Telephony settings (`telephony.settings.manage`); each company has
   its own numbers and PBX. The credentials are tenant-owned, encrypted and write-only.
3. **Order**: everything is built now and tested against fakes; nothing has rung a real phone until a PBX is connected.
