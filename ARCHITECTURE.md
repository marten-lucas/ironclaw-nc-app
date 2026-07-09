# Architecture Decision

## Recommendation

Use `OCA\\Talk\\Events\\ChatMessageSentEvent` as the primary inbound integration point.

## Why this is the best fit

- Official Talk PHP event documented as public event API.
- Server-side and synchronous to message creation, no browser dependency.
- No per-room webhook/bot callback endpoint needed for ingestion.
- Lower long-term coupling than DB-level polling or reverse-engineered hooks.

## Data Flow

1. Talk stores chat message.
2. Talk dispatches `ChatMessageSentEvent`.
3. App listener receives event.
4. Listener applies gating:
   - event type and payload sanity
   - ignore fake-user self messages
   - exact mention match
   - room scope check (allowlist, optional)
5. Listener builds minimal contract payload.
6. Payload is inserted into durable outbox (`oc_ic_talk_outbox`).
7. Dispatcher sends signed request to Ironclaw inbound endpoint.
8. On `2xx`, outbox row becomes `delivered`; otherwise retry is scheduled.
9. Ironclaw handles idempotency using `eventId`.
10. Ironclaw outbound (existing fake-user REST path) replies to same room.

## Contract Surface

Stable contract (app -> Ironclaw):
- HTTP endpoint path and auth headers.
- Signed payload fields (`eventId`, `roomToken`, `messageId`, `replyTo`, actor, stripped message).
- Retry semantics: at-least-once delivery with dedupe key.

Internal coupling explicitly isolated:
- Mention parsing and room scope logic in dedicated services.
- Persistence and retry in outbox repository + dispatcher.
- Talk-specific event class usage confined to one listener.

Current limitation:
- There is no fully stable public Talk API to ask "is fake user member of this room" from inside this app without extra coupling.
- Phase A therefore ships with explicit room scope controls (allowlist) and self-loop prevention.
- Phase A.1 should add a stricter membership resolver once a stable API path is validated.

## Upgrade Risk Notes

Low/medium risk:
- `ChatMessageSentEvent` lifecycle changes in future Talk versions.
- Message serialization changes (raw comment payload format).

Mitigations:
- Keep parser defensive (supports plain and JSON message forms).
- Keep integration tests around mention detection and payload mapping.
- Keep fallback operations command to replay outbox after incidents.

## Rollback Strategy

- Disable app (`enabled=0`) in app config.
- Existing Ironclaw outbound path remains untouched.
- Optional fallback to legacy webhook ingress path while investigating.
