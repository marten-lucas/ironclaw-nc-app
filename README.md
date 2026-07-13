# Ironclaw Talk Bridge (Nextcloud App)

This app implements a bot-free inbound trigger path from Nextcloud Talk to Ironclaw.

## Technical Recommendation

Best integration point: `OCA\\Talk\\Events\\ChatMessageSentEvent` (official Talk PHP event, since Talk 18).

Why this point is preferred:
- It is an official server-side event and does not require browser clients.
- It avoids per-room webhook endpoint registration.
- It is less coupled than direct DB triggers and less operationally heavy than polling.
- It supports app-internal processing and queueing before forwarding to Ironclaw.

Alternatives and why not primary:
- `BotInvokeEvent`: official, but still tied to bot installation lifecycle.
- DB polling: high coupling, latency, and upgrade fragility.
- Frontend hook/browser extension: violates non-goals.

## Current Scope (Phase A)

- Inbound only in Nextcloud app:
  - Listen to `ChatMessageSentEvent`.
  - Queue-first routing:
    - the listener enqueues raw Talk events without DB lookups on `talk_attendees`.
    - the background dispatcher resolves room metadata later, outside the Talk request transaction.
  - Room-aware gating in the dispatcher:
    - rooms resolved to `one_to_one` => forward without mention.
    - rooms resolved to `group` or `public` => require mention.
  - Mention matching supports exact `@<fake_user_id>` mentions from Talk UI.
  - Ignore self messages of configured fake user.
  - Build signed event payload and enqueue in durable outbox.
  - Background dispatch resolves room metadata from Talk DB attendees and then either filters or delivers the event.
  - Retry delivery to Ironclaw on transient failures.
- Outbound remains in Ironclaw via fake-user REST path (unchanged).

Note on room relevance in Phase A:
- The app enforces room scope through configurable allowlist tokens (or all rooms when empty).
- Self-loop prevention is enforced by `fake_user_id` check.
- The current resolver verifies fake-user room membership from Talk attendee records and caches the result in Nextcloud memory cache.
- This DB-backed lookup is intentionally deferred to the background dispatcher to avoid dirty-read stack traces in the Talk message request.

## App Config (set via Nextcloud app config)

All values are read from Nextcloud app config (`app=ironclaw_talk_bridge`):

- `enabled` (`0|1`)
- `ironclaw_inbound_url` (required)
- `ironclaw_shared_secret` (required, secret)
- `mention_display_name` (required, exact display name without `@`)
- `fake_user_id` (optional but recommended)
- `signature_tolerance_seconds` (default `300`)
- `dispatch_batch_size` (default `50`)
- `room_allowlist_tokens` (optional CSV; empty means all rooms)
- `strict_membership_resolver` (`0|1`, default `1`, fail-closed membership check)

The same key fields are available in Nextcloud Admin Settings (Server section):
- Ironclaw URL (`ironclaw_inbound_url`)
- Fake user name for exact mention matching (`mention_display_name`)
- Shared secret for Ironclaw authentication (`ironclaw_shared_secret`)
- Plus operational fields used by the bridge (`fake_user_id`, room allowlist, batch size, enabled flag)
- Hardening fields (`signature_tolerance_seconds`, `strict_membership_resolver`)

## Event Contract to Ironclaw

`POST {ironclaw_inbound_url}`

Headers:
- `Content-Type: application/json`
- `X-Ironclaw-Signature: <hex-hmac-sha256>`
- `X-Ironclaw-Timestamp: <unix-seconds>`
- `X-Ironclaw-Nonce: <random-hex>`
- `X-Ironclaw-Key-Id: nextcloud-talk-bridge-v1`

Signature base string:
- `"{timestamp}\\n{nonce}\\n{raw_body}"`

Body shape:

```json
{
  "type": "Create",
  "actor": {
    "type": "users",
    "id": "alice",
    "name": "Alice"
  },
  "object": {
    "id": "456",
    "content": "@Ironclaw please summarize"
  },
  "target": {
    "id": "abc123"
  },
  "eventId": "nc-talk:abc123:456",
  "mention": {
    "displayName": "Ironclaw"
  },
  "bridgeMessage": {
    "raw": "@Ironclaw please summarize",
    "stripped": "please summarize"
  },
  "occurredAt": "2026-07-09T10:00:00+00:00"
}
```

Notes:
- The bridge stores an internal payload in the outbox, then renders a Nextcloud Talk-compatible webhook body (`type/actor/object/target`) at dispatch time.
- For one-to-one rooms that are allowed without explicit mention, the dispatcher prefixes the configured mention token so Ironclaw can still accept the event on `/webhooks/nextcloud/talk`.

## Delivery Behavior

- Durable outbox table stores pending payloads.
- Unique `eventId` enforces dedupe.
- Listener only enqueues.
- Background dispatch performs room lookup, mention gating, and final forwarding.
- Events that do not satisfy routing rules are marked as `filtered` in the outbox.
- Failures are retried with exponential backoff.
- No event loss on short Ironclaw outages (until max retries policy, configurable in code).

## Commands and Jobs

- OCC command: `ironclaw-talk-bridge:dispatch` (run deferred routing and flush due outbox events)
- OCC command: `ironclaw-talk-bridge:metrics` (structured counters + outbox state, including `filtered`)
- OCC command: `ironclaw-talk-bridge:diagnose-room` (inspect DB-backed room metadata resolution for a Talk room token)
- Background job: `RetryQueuedEventsJob` (periodic retry)

## Integration Harness (Mock Ironclaw)

For Phase A.1 contract checks, use the mock inbound verifier:

```bash
cd ironclaw-nc-app
IRONCLAW_SHARED_SECRET=dev-secret node tests/integration/mock-ironclaw-server.mjs
```

It validates:
- `X-Ironclaw-Timestamp`
- `X-Ironclaw-Nonce` (replay rejection)
- `X-Ironclaw-Signature` over `timestamp + "\\n" + nonce + "\\n" + body`

## Validation Checklist (target)

1. Fake user is normal room participant.
2. No per-room Talk webhook bot needed.
3. Exact mention triggers Ironclaw.
4. No mention triggers only when the deferred room resolution yields `one_to_one`.
5. Self messages are ignored.
6. Events that fail routing rules are marked `filtered` instead of being delivered.
7. Reply posted in same room by existing Ironclaw outbound path.
8. Multiple rooms work in parallel.
9. Restart of app/Ironclaw does not silently drop queued events.
