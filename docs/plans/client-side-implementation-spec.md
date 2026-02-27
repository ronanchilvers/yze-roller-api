# Client-Side Implementation Specification (Contract-Aligned)

Primary contract: `/Users/ronan/Personal/experiments/yze-roller/docs/plans/multiplayer-api-contract-and-build-spec.md`

This document is an implementation runbook for agents building the multiplayer client against the canonical API contract.

## 1. Client Goals

- Join session via fragment token link.
- Store session token in memory (not persisted in localStorage by default).
- Initialize from `GET /api/session`.
- Poll `GET /api/events` using cursor (`since_id`) semantics.
- Submit gameplay events (`roll`, `push`) with contract-valid payloads.
- Expose GM controls when role is `gm`.

## 2. Required Client State

```text
joinToken: string | null
sessionToken: string | null
sessionId: number | null
role: "gm" | "player" | null
self: { tokenId, displayName, role } | null
sceneStrain: number
sinceId: number
players: PlayerSummary[]
events: Event[]
pollIntervalMs: number
pollingStatus: "idle" | "running" | "backoff" | "stopped"
```

Rules:
- `sinceId` initializes from `/api/session.latest_event_id`.
- If snapshot has no events, `sinceId=0`.
- On auth failure (401/403), clear `sessionToken` and stop polling.

## 3. API Client Layer

Implement a single `apiFetch` helper:
- attaches `Authorization: Bearer <sessionToken>` when provided
- parses JSON success bodies
- parses error envelope (`error.code`, `error.message`)
- surfaces `status`, `code`, `message` to callers

Join call uses join token (not session token):
- `POST /api/join` with `Authorization: Bearer <joinToken>`

## 4. Join Flow

1. Load `/join` route.
2. Parse `#join=<token>` from URL fragment.
3. Validate presence of token; if absent show blocking error state.
4. Collect `display_name` and submit `POST /api/join`.
5. On success:
   - set `sessionToken` from response `player_token`
   - clear URL fragment
   - navigate to session route
6. On failure:
   - display user-friendly message mapped from `error.code`
   - allow retry

## 5. Session Bootstrap Flow

After session token is available:
1. `GET /api/session`
2. Persist in memory:
   - `sessionId`, `role`, `self`, `sceneStrain`, `players`, `sinceId`
3. Start polling loop.

## 6. Polling Loop (Normative)

Endpoint: `GET /api/events?since_id=<sinceId>&limit=10`

Algorithm:
- Start at `1000ms`.
- On `200`:
  - append events in response order
  - apply reducer updates for each event
  - set `sinceId = next_since_id`
  - set interval to `1000ms`
- On `204`:
  - increase interval x1.5 up to `8000ms`
- On network/server errors:
  - exponential backoff up to `30000ms`
  - jitter +/-20%
- On `401` or `403`:
  - stop polling
  - clear session token
  - show "session ended / rejoin" view

Stop polling on unmount/navigation away.

## 7. Event Reducer Matrix

Handle event types from API:
- `roll`: append event/log and update UI history.
- `push`: append event/log and set `sceneStrain` from payload if provided.
- `strain_reset`: set `sceneStrain=0` (or payload value if contract evolves).
- `join`: add/update player in local player list.
- `leave`: mark player inactive/remove from active list.

Reducer rules:
- preserve event ordering from server.
- dedupe by `event.id` if polling overlap/retry occurs.

## 8. Submit Actions

### Roll submit
`POST /api/events`

```json
{
  "type": "roll",
  "payload": {
    "successes": 1,
    "banes": 0
  }
}
```

### Push submit
`POST /api/events`

```json
{
  "type": "push",
  "payload": {
    "successes": 2,
    "banes": 1,
    "strain": true
  }
}
```

Client requirements:
- do not send actor identity fields
- validate local payload shape before submit
- treat server response as authoritative event/state source

## 9. GM-Only UI/API

Show only for role `gm`:
- Rotate join link:
  - `POST /api/sessions/:session_id/join-link/rotate`
- Enable/disable joining:
  - `POST /api/gm/sessions/:session_id/joining`
- Reset scene strain:
  - `POST /api/gm/sessions/:session_id/reset_scene_strain`
- List players:
  - `GET /api/gm/sessions/:session_id/players`
- Revoke player:
  - `POST /api/gm/sessions/:session_id/players/:token_id/revoke`

## 10. Error Handling Contract (Client)

Map error codes to UX states:
- `TOKEN_MISSING`, `TOKEN_INVALID`, `TOKEN_REVOKED`:
  - clear token, stop polling, show rejoin/session-ended state
- `JOIN_DISABLED`, `JOIN_TOKEN_REVOKED`:
  - join view message + retry/close
- `ROLE_FORBIDDEN`:
  - hide GM controls and show permission error
- `VALIDATION_ERROR`, `EVENT_TYPE_UNSUPPORTED`:
  - show non-fatal action error and keep session active
- `RATE_LIMITED`:
  - transient warning, retry after delay

## 11. Client Build Steps (Testable)

### Step C1: API layer and error parser
- Implement `apiFetch` and typed response helpers.
- Tests: success parse + error envelope parse.

### Step C2: Join page + token handoff
- Implement fragment parsing and `/api/join` flow.
- Tests: missing token, join success, join failure.

### Step C3: Snapshot bootstrap
- Implement `/api/session` initialization.
- Tests: empty snapshot (`latest_event_id=0`) and populated snapshot.

### Step C4: Polling engine
- Implement interval/backoff/jitter and cursor update logic.
- Tests: 200 path, 204 path, error path, auth-failure shutdown.

### Step C5: Event reducer and action submit
- Implement reducer for all event types and roll/push submit calls.
- Tests: per-event reducer coverage + dedupe by `event.id`.

### Step C6: GM controls
- Implement list/revoke/toggle/rotate/reset interactions.
- Tests: role-gated rendering and happy/error paths.

## 12. Definition of Done (Client)

- Client behavior matches endpoint contracts and polling semantics.
- All required error codes produce deterministic UX behavior.
- Event handling is idempotent and order-preserving.
- Integration tests pass against contract-compliant server.
