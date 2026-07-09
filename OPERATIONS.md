# Operations Runbook

## Setup

1. Deploy app code into Nextcloud apps directory.
2. Enable app in Nextcloud.
3. Open Nextcloud Administration Settings -> Basic settings (Server section) and configure "Ironclaw Talk Bridge".
4. Configure app values:
   - `enabled=1`
   - `strict_membership_resolver=1`
   - `ironclaw_inbound_url`
   - `ironclaw_shared_secret`
   - `mention_display_name`
   - `fake_user_id` (recommended)
   - `signature_tolerance_seconds=300`
5. Verify OCC command is available: `ironclaw-talk-bridge:dispatch`.
6. Verify metrics command is available: `ironclaw-talk-bridge:metrics`.

## Health Checks

- Outbox backlog should remain near zero under normal operation.
- Repeated non-2xx to Ironclaw should produce structured warning logs.
- Validate signatures on Ironclaw endpoint and monitor reject rate.

## Monitoring Signals

Log events:
- Event detected
- Mention match or no-match
- Self-loop discarded
- Delivery success
- Delivery failure and retry schedule

Operational counters to derive from logs:
- queued_events
- delivered_events
- retry_events
- dead_letter_events

## Failure Modes

Ironclaw down:
- Events remain queued.
- Retry job continues until successful delivery.

Bad secret/config:
- Signature validation fails on Ironclaw.
- Events retry and fail consistently.
- Rotate secret and re-dispatch queue.

## Rollback

1. Set `enabled=0` in app config to stop new ingestion.
2. Keep queue table for forensic analysis.
3. Re-enable legacy ingress path if needed.
4. After fix, run dispatch command to replay queued events.
