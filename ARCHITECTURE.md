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
4. Listener applies synchronous gating:
   - ignore fake-user self messages
   - room scope allowlist
   - strict mention policy (without mention no forward)
5. Listener builds signed Nextcloud-compatible webhook payload.
6. Listener sends directly to Ironclaw with a single low-timeout request.
7. Ironclaw handles idempotency using `eventId`.

## Contract Surface

Stable contract (app -> Ironclaw):
- HTTP endpoint path and auth headers.
- Signed payload fields (`type`, `actor`, `object`, `target`, `eventId`).

Internal coupling explicitly isolated:
- Mention parsing and room scope logic in dedicated services.
- Talk-specific event class usage confined to one listener.

Current limitation:
- Room type can be unknown on some Talk versions. Unknown is treated fail-closed (mention required).

## Upgrade Risk Notes

Low/medium risk:
- `ChatMessageSentEvent` lifecycle changes in future Talk versions.
- Message serialization changes (raw comment payload format).

Mitigations:
- Keep parser defensive (supports plain and JSON message forms).
- Keep integration tests around mention detection and payload mapping.

## Rollback Strategy

- Disable app (`enabled=0`) in app config.
- Existing Ironclaw outbound path remains untouched.
- Optional fallback to legacy webhook ingress path while investigating.
