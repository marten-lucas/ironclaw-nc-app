# Phased Plan

## Phase A (Inbound replacement, this repository scope)

1. Implement Nextcloud app bootstrap and Talk listener.
2. Implement exact mention gating and self-loop prevention.
3. Implement signed outbound contract to Ironclaw.
4. Implement strict mention-only policy for all room types.
5. Provide operational docs and validation checklist.
6. Add unit tests for mention matcher and signature logic.

Exit criteria:
- Mention-only triggering works.
- One-to-one/direct rooms require mention.
- No per-room bot activation required for trigger path.
- Delivery failures are explicit and observable in request-path logs.

## Phase A.1 (Hardening)

1. Add explicit replay-protection checks on Ironclaw side (timestamp/nonce window).
2. Improve room-type resolution compatibility across Talk versions.
3. Add metrics endpoint or structured counters for synchronous allow/deny/failure signals.
4. Add integration test harness with mocked Ironclaw endpoint.

## Phase B (Optional)

1. Move outbound posting into this app only if operationally justified.
2. Introduce optional reply rendering/pinning rules in Nextcloud app layer.
3. Keep Ironclaw as orchestrator, but centralize Talk IO in one boundary.
