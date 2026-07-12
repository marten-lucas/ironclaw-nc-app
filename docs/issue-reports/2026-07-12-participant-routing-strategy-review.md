# Issue Report: Nextcloud Talk Participant Routing Strategy Review

## Context

The Ironclaw Talk Bridge currently decides whether a Talk message should be forwarded to Ironclaw based on room composition and mention gating.

The intended behavior is:

- If the room contains only the configured fake user plus one other participant, forward without requiring a mention.
- If the room contains the fake user plus two or more other participants, require a mention.
- The configured fake user must be read from app config.

## What changed recently

We moved away from trying to derive the participant list directly during the chat request path.

The earlier approach failed because Talk/Nextcloud raised `dirty table reads` when the bridge tried to read Talk data during the same request in which Talk was also mutating its tables. That made the request path fragile and noisy.

To avoid that, we introduced an asynchronous participant snapshot cache:

- a background job refreshes cached room participant snapshots
- the listener reads the snapshot cache during message handling
- the listener no longer needs to query Talk tables directly in the hot path

This works, but it introduces a new tradeoff: cache freshness.

## Current concern

The current strategy is correct only if the snapshot cache is reasonably up to date.

That creates two concerns:

1. If the fake user is added to or removed from a room, the cache may lag behind until the next refresh.
2. The solution can appear to work only because the cache was manually warmed up before testing.

So the core question is whether the cache-based design is good enough for the intended semantics, or whether we need a more authoritative source of room membership.

## Why the previous variant did not work

The previous variant failed for two independent reasons:

1. It relied on reading Talk participant data directly inside the Talk message request.
2. Those reads triggered Nextcloud's dirty-table protection because the same request was already inside Talk's own DB transaction flow.

In practice that meant:

- the bridge received the event
- the bridge attempted to inspect Talk tables immediately
- Nextcloud reported `dirty table reads`
- the bridge path became fragile or noisy

So the issue was not only the routing rule itself, but the timing and transaction context of the lookup.

## Review of the current strategy

### Strengths

- Avoids direct Talk DB reads in the hot path.
- Preserves the desired 2-vs-3 routing rule.
- Keeps the listener responsive and less coupled to Talk internals.
- Uses the configured fake user from app config.

### Weaknesses

- Snapshot freshness is eventual, not immediate.
- Membership changes may be missed for a short period.
- Manual warmup can mask stale-cache problems during validation.
- The cache becomes another state source that can drift from Talk.

### Key question for review

Is eventual consistency acceptable for this routing rule, or do we need a stronger guarantee that the participant state is current at message time?

## Open questions

- What is the acceptable staleness window for routing decisions?
- Should stale snapshots fail closed, fail open, or use a different fallback?
- Can Talk expose a stable event or API that reports room membership changes without dirty-table risk?
- Is it acceptable to refresh snapshots on a short schedule, or should the cache be updated on room changes only?

## Recommendation for second opinion

Please review whether the current snapshot-cache design is the right tradeoff for this integration.

The main decision is between:

- strict correctness with more coupling to Talk internals
- or eventual consistency with a safer request path

## Alternatives considered

See also:

- direct event-time lookup in the request path
- background snapshot cache
- background-only lookup with deferred routing decision
- Talk-native membership event integration
- explicit room-change sync / polling model

## Success criteria

Any final solution should:

- use the configured fake user from app config
- distinguish exactly two participants from three-or-more participants
- not rely on online/presence status
- avoid dirty-table failures in the Talk request path
- remain understandable and maintainable for future changes