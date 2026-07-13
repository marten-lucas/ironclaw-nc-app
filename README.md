# Ironclaw Talk Bridge (Nextcloud App)

This app forwards Nextcloud Talk messages to Ironclaw through a synchronous, server-side listener.

## Scope

- Integration point: `OCA\\Talk\\Events\\ChatMessageSentEvent`
- No outbox, no background job, no direct reads on Talk participant tables
- Routing rule:
  - one-to-one/direct room type: forward without mention
  - group/public/unknown room type: forward only on exact mention

## App Config

Values are read from app config (`app=ironclaw_talk_bridge`):

- `bridge_enabled` (`0|1`)
- `ironclaw_inbound_url` (required)
- `ironclaw_shared_secret` (required)
- `mention_display_name` (required, without `@`)
- `fake_user_id` (required for self-loop prevention)
- `room_allowlist_tokens` (optional CSV)
- `signature_tolerance_seconds` (default `300`)

## Event Contract to Ironclaw

`POST {ironclaw_inbound_url}`

Headers:

- `Content-Type: application/json`
- `X-Ironclaw-Signature: <hex-hmac-sha256>`
- `X-Ironclaw-Timestamp: <unix-seconds>`
- `X-Ironclaw-Nonce: <random-hex>`
- `X-Ironclaw-Key-Id: nextcloud-talk-bridge-v1`

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
    "content": "@Ironclaw summarize this"
  },
  "target": {
    "id": "room-token"
  },
  "eventId": "nc-talk:room-token:456"
}
```

## Logging and Counters

- Synchronous decisions are always logged at debug (`loglevel 0`) with `decision=allow|deny` and a `reason`.
- Success delivery is logged as `Ironclaw event delivered (sync)`.
- Delivery failure is logged as `Ironclaw event delivery failed (sync)`.
- Metrics command: `ironclaw-talk-bridge:metrics`.

## Validation Checklist

1. DM without mention is forwarded.
2. Group/public room without mention is denied.
3. Group/public room with mention is forwarded.
4. Fake-user self messages are denied.
