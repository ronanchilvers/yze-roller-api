# Server-Side Implementation Outline (Contract-Aligned)

Primary contract: `/Users/ronan/Personal/experiments/yze-roller/docs/plans/multiplayer-api-contract-and-build-spec.md`

Use this document as an execution runbook for implementing the server in consistent, testable steps.

## 1. Stack and Constraints

- Runtime: FlightPHP + MariaDB + Nginx + PHP-FPM
- Transport: HTTP polling only (no WebSockets/SSE)
- Security:
  - HTTPS required except in development environments
  - never log `Authorization` header values
  - rate-limit `/api/join`

## 2. v1 Server Deliverables

- Tokenized session lifecycle: create session, rotate join link, join toggle.
- Player lifecycle: self-join, list players (GM), revoke player (GM).
- Event ingestion and polling: `roll`, `push`, `join`, `leave`, `strain_reset`.
- Scene strain state management: push-based increment + GM reset.
- Deterministic error envelope and status-code semantics.

## 3. Data Model Implementation Checklist

Implement tables and constraints exactly per canonical contract Section 6:

- `sessions`
- `session_join_tokens`
- `session_tokens`
- `session_state`
- `events`

Additional requirements:
- UNIQUE (`state_session_id`, `state_name`) in `session_state`.
- Ensure required keys exist for each new session:
  - `state_name='scene_strain'` with string integer value (for example `"0"`).
  - `state_name='joining_enabled'` with boolean string value (`"true"`/`"false"`).
- Index `events(event_session_id, event_id)` for polling.
- Do not add database foreign key constraints; enforce relationships in application logic.

## 4. Middleware and Shared Utilities

Implement before route handlers:

1. Bearer token parser and hash lookup utility.
2. Auth guards:
   - join token guard
   - gm/player session token guard
   - GM role guard
3. Error responder that always emits:

```json
{
  "error": {
    "code": "...",
    "message": "..."
  }
}
```

4. Validation helper returning `422 VALIDATION_ERROR`.
5. Event writer helper returning normalized event objects.

## 5. Endpoint Build Order (Server)

### Step S1: Session bootstrap routes
- `POST /api/sessions`
- `POST /api/join`
- `GET /api/session`

Must satisfy:
- join link format: `https://<host>/join#join=<token>`
- `latest_event_id=0` when no events exist
- `join` event emitted on successful join
- initialize `session_state` defaults (`scene_strain=\"0\"`, `joining_enabled=\"true\"`)

### Step S2: Event stream routes
- `GET /api/events?since_id=<int>&limit=<int>`
- `POST /api/events`

Must satisfy:
- `since_id` is exclusive (`event_id > since_id`)
- ascending event order
- default `limit=10`, min `1`, max `100`
- `204` with no body when no events
- reject event types other than `roll`/`push`
- actor derived from auth token, never from request body

### Step S3: GM management routes
- `POST /api/sessions/:session_id/join-link/rotate`
- `POST /api/gm/sessions/:session_id/joining`
- `POST /api/gm/sessions/:session_id/reset_scene_strain`
- `GET /api/gm/sessions/:session_id/players`
- `POST /api/gm/sessions/:session_id/players/:token_id/revoke`

Must satisfy:
- GM token bound to same session
- revoke is idempotent
- first revoke emits exactly one `leave` event
- reset emits `strain_reset`
- join toggle reads/writes `session_state.joining_enabled` as boolean string values

### Step S4: Transaction hardening
Add explicit DB transactions for:
- join token mint + `join` event write
- push with strain increment + event write
- joining toggle state write
- revoke state change + `leave` event write
- reset strain + `strain_reset` event write

Concurrency requirements:
- lock `scene_strain` row on updates (`SELECT ... FOR UPDATE` or equivalent)
- lock/serialize `joining_enabled` state row updates
- no partial write state

## 6. Validation Rules

### `POST /api/join`
- `display_name` required, trimmed length 1..64
- reject control characters
- reject if parsed `joining_enabled` is false (`403 JOIN_DISABLED`)

### `POST /api/events`
- body must contain only `type` and `payload`
- `type` in `{"roll","push"}`
- `roll.payload`: `successes` and `banes` integers 0..99
- `push.payload`: `successes`, `banes` integers 0..99 and `strain` boolean

### Query params
- `since_id`: integer >= 0
- `limit`: integer 1..100 (default 10)

## 7. Error Code Mapping

Use canonical codes from contract Section 5, including:
- `TOKEN_MISSING` (401)
- `TOKEN_INVALID` (401)
- `TOKEN_REVOKED` (403)
- `ROLE_FORBIDDEN` (403)
- `JOIN_DISABLED` (403)
- `VALIDATION_ERROR` (422)
- `EVENT_TYPE_UNSUPPORTED` (422)
- `RATE_LIMITED` (429)

## 8. Server Test Plan (Required)

### Unit/service tests
- token hash lookup and role guard behavior
- payload validator coverage for all invalid permutations
- event serializer output shape

### API contract tests
- each endpoint: success + auth failure + validation failure
- polling behavior: exclusivity, ordering, 200 vs 204
- revoke idempotency (`event_emitted` true first time, false repeat)

### Concurrency tests
- concurrent `push` with `strain=true` preserves exact strain total
- concurrent revoke attempts produce one `leave` event
- reset strain transaction does not lose concurrent event writes

## 9. Definition of Done (Server)

- All routes in Section 5 implemented and documented responses match contract.
- All non-2xx responses use the standard error envelope.
- Transaction and idempotency guarantees are verified in tests.
- No undocumented response fields remain.

## 10. Explicit v1 Deferral

- GM "set scene strain to arbitrary value" endpoint is intentionally deferred.
- `leave` events are emitted only on explicit revoke in v1.
