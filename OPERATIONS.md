# Operations Runbook

## Setup

1. Deploy app code into Nextcloud apps directory.
2. Enable app in Nextcloud.
3. Open Nextcloud Administration Settings -> Basic settings (Server section) and configure "Ironclaw Talk Bridge".
4. Configure app values:
   - `enabled=1`
   - `ironclaw_inbound_url`
   - `ironclaw_shared_secret`
   - `mention_display_name`
   - `fake_user_id` (recommended)
   - `signature_tolerance_seconds=300`
5. Verify metrics command is available: `ironclaw-talk-bridge:metrics`.

## Health Checks

- New inbound messages should produce synchronous allow/deny decision logs immediately.
- Repeated non-2xx to Ironclaw should produce structured warning logs.
- Validate signatures on Ironclaw endpoint and monitor reject rate.

## Monitoring Signals

Log events:
- Event detected
- Synchronous routing decision with `decision=allow|deny` and reason
- Delivery success
- Delivery failure after micro-retry

Operational counters to derive from logs:
- received_events
- allowed_events
- denied_events
- delivered_events
- delivery_failures

## Failure Modes

Ironclaw down:
- Delivery fails in request path after micro-retry.
- Failure is logged immediately.

Bad secret/config:
- Signature validation fails on Ironclaw.
- Events fail immediately with clear log reason.
- Rotate secret and retry by sending a new message.

## Rollback

1. Set `enabled=0` in app config to stop new ingestion.
2. Re-enable legacy ingress path if needed.
