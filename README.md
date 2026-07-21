# Ironclaw Talk Bridge (Nextcloud App)

This app forwards Nextcloud Talk messages to Ironclaw through a synchronous, server-side listener.

## Scope

- Integration points:
  - `OCA\\Talk\\Events\\ChatMessageSentEvent`
  - `OCA\\Talk\\Events\\ReactionAddedEvent`
  - `OCA\\Talk\\Events\\ReactionRemovedEvent`
- No outbox, no background job, no direct reads on Talk participant tables
- Routing rule:
  - all room types: forward only on exact mention

## App Config

Values are read from app config (`app=ironclaw_talk_bridge`):

- `bridge_enabled` (`0|1`)
- `ironclaw_inbound_url` (required)
- `ironclaw_shared_secret` (required)
- `mention_display_name` (required, without `@`)
- `fake_user_id` (required for self-loop prevention)
- `room_allowlist_tokens` (optional CSV)
- `signature_tolerance_seconds` (default `300`)
- `reaction_approval_user_ids` (optional CSV; authorizes HITL reactions `✅` and `❌`)
- `attachment_allowed_mime_patterns` (CSV allowlist, default: `text/*,application/pdf,image/*,application/json,application/xml,text/markdown,text/csv`)
- `attachment_max_file_size_bytes` (default `5242880`)
- `attachment_max_total_size_bytes` (default `20971520`)
- `attachment_max_extract_chars` (default `12000`)
- `attachment_enable_ocr` (`0|1`, default `0`)
- `attachment_ocr_languages` (tesseract languages, default `deu+eng`)

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
    "content": "@Ironclaw summarize this",
    "attachments": [],
    "attachmentErrors": []
  },
  "target": {
    "id": "room-token"
  },
  "eventId": "nc-talk:room-token:456"
}
```

When attachments are present, the bridge resolves them server-side and enriches `object.attachments[*].extract` with extracted text metadata where possible.
If extraction fails or policy blocks an attachment (MIME/size), the bridge emits structured entries in `object.attachmentErrors`.

## Logging and Counters

- Synchronous decisions are always logged at debug (`loglevel 0`) with `decision=allow|deny` and a `reason`.
- Success delivery is logged as `Ironclaw event delivered (sync)`.
- Delivery failure is logged as `Ironclaw event delivery failed (sync)`.
- Metrics command: `ironclaw-talk-bridge:metrics`.

## Validation Checklist

1. Any room without mention is denied.
2. Group/public room with mention is forwarded.
3. DM with mention is forwarded.
4. Fake-user self messages are denied.
