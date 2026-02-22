# Multiplayer API Contract and Build Specification (v1)

Status: Approved for implementation
Audience: AI coding agents implementing both client and server
Primary objective: deliver a consistent, testable multiplayer API and client integration without contract drift.

## 1. Scope

### In scope (v1)
- Create a session and issue GM + join tokens.
- Player self-registration via join link.
- Polling event stream using `since_id` (no WebSockets/SSE).
- Submit `roll` and `push` events.
- Scene strain updates from push events and GM reset.
- GM controls for joining toggle, join-link rotation, list players, revoke player.

### Out of scope (v1)
- Manual GM "set scene strain to arbitrary value" endpoint.
- Presence timeout-based `leave` events.
- WebSockets/SSE transport.

## 2. Conventions

- Base path: `/api`.
- Request/response format: `application/json; charset=utf-8`.
- Timestamps: RFC3339 UTC strings.
- IDs: unsigned integers.
- Authorization: `Authorization: Bearer <token>`.
- Success responses return JSON objects unless explicit `204 No Content`.
- Error responses always use the error envelope in Section 5.

## 3. Token and Role Model

- Join token:
  - role: `join`
  - used only for `POST /api/join`
  - can be revoked by join-link rotation
- Session token:
  - role: `gm` or `player`
  - used for authenticated session APIs

Token requirements:
- Generate 32 random bytes.
- Encode as base64url for transport.
- Persist SHA-256 hash only.
- Persist token prefix for operational debugging.

## 4. Security Invariants

- Actor identity is server-authoritative.
- `POST /api/events` MUST derive actor from authenticated token.
- Client-provided `actor_id` or equivalent identity fields are forbidden in event submit payloads.
- GM endpoints require GM token bound to the requested `session_id`.
- Revoked tokens are never authorized.

## 5. Error Envelope and Codes

All non-2xx responses MUST return:

```json
{
  "error": {
    "code": "TOKEN_INVALID",
    "message": "Authorization token is invalid.",
    "details": {}
  }
}
```

`details` is optional and may be omitted.

Common codes:
- `TOKEN_MISSING` (401)
- `TOKEN_INVALID` (401)
- `TOKEN_REVOKED` (403)
- `ROLE_FORBIDDEN` (403)
- `SESSION_NOT_FOUND` (404)
- `JOIN_DISABLED` (403)
- `JOIN_TOKEN_REVOKED` (403)
- `VALIDATION_ERROR` (422)
- `EVENT_TYPE_UNSUPPORTED` (422)
- `RATE_LIMITED` (429)
- `CONFLICT` (409)

Status code policy:
- 401: missing/invalid bearer credentials.
- 403: authenticated but not permitted (wrong role, revoked, disabled join).
- 404: target session/token resource does not exist.
- 409: conflicting state transition.
- 422: syntactically valid JSON but semantically invalid payload.

## 6. Data Contract (Server Persistence)

### `sessions`
- `session_id` BIGINT UNSIGNED PK
- `session_name` VARCHAR(128) NOT NULL
- `session_created` DATETIME(6) NOT NULL
- `session_updated` DATETIME(6) NOT NULL

### `session_join_tokens`
- `join_token_id` BIGINT UNSIGNED PK
- `join_token_session_id` BIGINT UNSIGNED NOT NULL
- `join_token_hash` BINARY(32) NOT NULL UNIQUE
- `join_token_prefix` CHAR(12) NOT NULL
- `join_token_revoked` DATETIME(6) NULL
- `join_token_created` DATETIME(6) NOT NULL
- `join_token_updated` DATETIME(6) NOT NULL
- `join_token_last_used` DATETIME(6) NULL

### `session_tokens`
- `token_id` BIGINT UNSIGNED PK
- `token_session_id` BIGINT UNSIGNED NOT NULL
- `token_role` ENUM('gm', 'player') NOT NULL
- `token_display_name` VARCHAR(64) NULL
- `token_hash` BINARY(32) NOT NULL UNIQUE
- `token_prefix` CHAR(12) NOT NULL
- `token_revoked` DATETIME(6) NULL
- `token_created` DATETIME(6) NOT NULL
- `token_updated` DATETIME(6) NOT NULL
- `token_last_seen` DATETIME(6) NULL

### `session_state`
- `state_id` BIGINT UNSIGNED PK
- `state_session_id` BIGINT UNSIGNED NOT NULL
- `state_name` VARCHAR(64) NOT NULL
- `state_value` VARCHAR(256) NOT NULL
- `state_created` DATETIME(6) NOT NULL
- `state_updated` DATETIME(6) NOT NULL

Constraints:
- UNIQUE (`state_session_id`, `state_name`)
- Required keys per session:
  - `scene_strain` where `state_value` is a base-10 integer string (`"0"`, `"1"`, ...)
  - `joining_enabled` where `state_value` is boolean string (`"true"` or `"false"`)

Typed state conversion rules:
- When writing `scene_strain`, persist numeric values as canonical base-10 strings.
- When reading `scene_strain`, parse as integer and validate `>= 0`.
- When writing `joining_enabled`, persist exactly `"true"` or `"false"`.
- When reading `joining_enabled`, convert `"true"` to `true` and `"false"` to `false`.
- Invalid stored values MUST fail safely:
  - `scene_strain` fallback to `0`
  - `joining_enabled` fallback to `false`

### `events`
- `event_id` BIGINT UNSIGNED PK
- `event_session_id` BIGINT UNSIGNED NOT NULL
- `event_actor_token_id` BIGINT UNSIGNED NULL
- `event_type` VARCHAR(64) NOT NULL
- `event_payload_json` JSON NOT NULL
- `event_created` DATETIME(6) NOT NULL
- `event_updated` DATETIME(6) NOT NULL

Indexes:
- `events(event_session_id, event_id)`
- `session_tokens(token_session_id, token_role, token_revoked)`
- `session_join_tokens(join_token_session_id, join_token_revoked)`

Foreign key policy:
- Do not add database foreign key constraints in v1.
- Enforce relationships at application/service level.

## 7. Event Schema

Event object returned from `GET /api/events`:

```json
{
  "id": 124,
  "type": "push",
  "session_id": 7,
  "occurred_at": "2026-02-22T20:30:01.123Z",
  "actor": {
    "token_id": 31,
    "display_name": "Alice",
    "role": "player"
  },
  "payload": {
    "successes": 2,
    "banes": 1,
    "strain": true,
    "scene_strain": 4
  }
}
```

Supported types and payload contract:
- `roll`:
  - `successes` integer 0..99
  - `banes` integer 0..99
- `push`:
  - `successes` integer 0..99
  - `banes` integer 0..99
  - `strain` boolean
  - `scene_strain` integer >= 0 (server-populated in emitted event)
- `strain_reset`:
  - `previous_scene_strain` integer >= 0
  - `scene_strain` integer (`0`)
- `join`:
  - `token_id` integer
  - `display_name` string
- `leave`:
  - `token_id` integer
  - `display_name` string
  - `reason` string enum: `"revoked"`

## 8. Endpoint Contract

### 8.1 `POST /api/sessions`
Create a new session and initial tokens.

Request:

```json
{
  "session_name": "Streetwise Night"
}
```

Validation:
- `session_name` trimmed length 1..128.

State initialization on create:
- Insert `session_state` row `scene_strain = "0"`.
- Insert `session_state` row `joining_enabled = "true"`.

Response: `201 Created`

```json
{
  "session_id": 7,
  "session_name": "Streetwise Night",
  "joining_enabled": true,
  "gm_token": "<opaque>",
  "join_link": "https://example.com/join#join=<opaque>",
  "created_at": "2026-02-22T20:30:00.000Z"
}
```

### 8.2 `POST /api/sessions/:session_id/join-link/rotate`
GM-only. Revoke all active join tokens and mint a new join token.

Auth: GM token.

Response: `200 OK`

```json
{
  "session_id": 7,
  "join_link": "https://example.com/join#join=<opaque>",
  "rotated_at": "2026-02-22T20:31:00.000Z"
}
```

### 8.3 `POST /api/gm/sessions/:session_id/joining`
GM-only. Toggle whether join tokens can mint players.

Request:

```json
{
  "joining_enabled": false
}
```

Response: `200 OK`

```json
{
  "session_id": 7,
  "joining_enabled": false,
  "updated_at": "2026-02-22T20:31:30.000Z"
}
```

State write behavior:
- Update `session_state` where `state_name='joining_enabled'` to `"true"` or `"false"`.

### 8.4 `POST /api/gm/sessions/:session_id/reset_scene_strain`
GM-only. Reset scene strain and emit `strain_reset` event.

Request:

```json
{}
```

Response: `200 OK`

```json
{
  "session_id": 7,
  "scene_strain": 0,
  "event_id": 129
}
```

### 8.5 `GET /api/gm/sessions/:session_id/players`
GM-only player list.

Response: `200 OK`

```json
{
  "session_id": 7,
  "players": [
    {
      "token_id": 31,
      "display_name": "Alice",
      "role": "player",
      "revoked": false,
      "created_at": "2026-02-22T20:30:10.000Z",
      "last_seen_at": "2026-02-22T20:31:20.000Z",
      "revoked_at": null
    }
  ]
}
```

### 8.6 `POST /api/gm/sessions/:session_id/players/:token_id/revoke`
GM-only. Revoke one player token.

Idempotency:
- First revoke emits one `leave` event.
- Repeating revoke on already-revoked token returns `200` with `event_emitted=false`.

Request:

```json
{}
```

Response: `200 OK`

```json
{
  "session_id": 7,
  "token_id": 31,
  "revoked": true,
  "event_emitted": true,
  "event_id": 130
}
```

### 8.7 `POST /api/join`
Join-token authenticated. Mint player token and emit `join` event.

Auth: join token.

Request:

```json
{
  "display_name": "Alice"
}
```

Validation:
- trimmed `display_name` length 1..64.
- reject control characters.
- reject join attempts when `session_state.joining_enabled` resolves to `false` (`403 JOIN_DISABLED`).

Response: `201 Created`

```json
{
  "session_id": 7,
  "player_token": "<opaque>",
  "player": {
    "token_id": 31,
    "display_name": "Alice",
    "role": "player"
  }
}
```

### 8.8 `GET /api/session`
Session snapshot for GM or player token.

Response: `200 OK`

```json
{
  "session_id": 7,
  "session_name": "Streetwise Night",
  "joining_enabled": true,
  "role": "player",
  "self": {
    "token_id": 31,
    "display_name": "Alice",
    "role": "player"
  },
  "scene_strain": 3,
  "latest_event_id": 130,
  "players": [
    {
      "token_id": 31,
      "display_name": "Alice",
      "role": "player"
    }
  ]
}
```

Rules:
- If no events exist, `latest_event_id` MUST be `0`.
- `joining_enabled` MUST be parsed from `session_state` key `joining_enabled`.

### 8.9 `GET /api/events?since_id=<int>&limit=<int>`
Poll for new events.

Query rules:
- `since_id` default: `0`.
- `since_id` semantics: exclusive (`event_id > since_id`).
- `limit` default: `10`, min `1`, max `100`.
- Events sorted by ascending `id`.

Response when events exist: `200 OK`

```json
{
  "events": [
    {
      "id": 131,
      "type": "roll",
      "session_id": 7,
      "occurred_at": "2026-02-22T20:32:10.000Z",
      "actor": {
        "token_id": 31,
        "display_name": "Alice",
        "role": "player"
      },
      "payload": {
        "successes": 1,
        "banes": 0
      }
    }
  ],
  "next_since_id": 131
}
```

Response when none: `204 No Content` with empty body.

### 8.10 `POST /api/events`
Submit gameplay events (`roll` or `push` only).

Auth: GM or player token.

Request (`roll`):

```json
{
  "type": "roll",
  "payload": {
    "successes": 1,
    "banes": 0
  }
}
```

Request (`push`):

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

Validation:
- `type` must be `roll` or `push`.
- Unknown top-level fields rejected with `422 VALIDATION_ERROR`.
- For `roll`, `payload.successes` and `payload.banes` required integers 0..99.
- For `push`, `payload.successes`, `payload.banes`, `payload.strain` required.

Side effects:
- `roll`: insert event only.
- `push` with `strain=true`: increment `scene_strain` by `banes`, then insert event containing resulting `scene_strain`.
- `push` with `strain=false`: insert event only.

Response: `201 Created`

```json
{
  "event": {
    "id": 132,
    "type": "push",
    "session_id": 7,
    "occurred_at": "2026-02-22T20:32:20.000Z",
    "actor": {
      "token_id": 31,
      "display_name": "Alice",
      "role": "player"
    },
    "payload": {
      "successes": 2,
      "banes": 1,
      "strain": true,
      "scene_strain": 4
    }
  },
  "scene_strain": 4
}
```

## 9. Transaction and Concurrency Rules

Atomic operations (single DB transaction each):
- `POST /api/join`: create player token + insert `join` event.
- `POST /api/events` (`push` with `strain=true`): lock `scene_strain` row, update strain, insert event.
- `POST /api/gm/sessions/:id/joining`: update `joining_enabled` state row.
- `POST /api/gm/sessions/:id/players/:token_id/revoke`: update token revoke state and, only on first revoke, insert `leave` event.
- `POST /api/gm/sessions/:id/reset_scene_strain`: lock `scene_strain` row, set to zero, insert `strain_reset` event.

Concurrency requirements:
- Use `SELECT ... FOR UPDATE` or equivalent for `scene_strain` updates.
- Revoke must be idempotent under concurrent requests.
- Event IDs are global monotonic in commit order.

## 10. Client Polling Contract (Normative)

Client loop:
- Start interval at 1000ms.
- Call `GET /api/events?since_id=<cursor>&limit=10`.
- On `200`:
  - append events in returned order,
  - set cursor to `next_since_id`,
  - reset interval to 1000ms.
- On `204`: multiply interval by 1.5 up to 8000ms.
- On request error/network failure: exponential backoff up to 30000ms with +/-20% jitter.
- On `401` or `403`: stop polling, clear in-memory token, show rejoin/session-ended state.

## 11. Implementation Plan for Agents

### Phase A: Server contract skeleton
1. Implement auth middleware and error envelope.
2. Implement `/api/sessions`, `/api/join`, `/api/session`.
3. Implement `/api/events` submit and poll routes with strict validation.
4. Implement GM routes: joining toggle, rotate join-link, reset strain, players list, revoke.

Definition of done:
- All endpoints return documented status/body/error shapes.

### Phase B: Server integrity hardening
1. Add transaction boundaries from Section 9.
2. Add idempotency behavior for revoke.
3. Add limit and schema validation guards.

Definition of done:
- Race/concurrency tests pass.

### Phase C: Client integration
1. Build shared `apiFetch` with bearer token and error parsing.
2. Implement join page flow with fragment parsing and token storage in memory.
3. Implement snapshot bootstrap and cursor initialization.
4. Implement polling loop and event reducer.
5. Implement roll/push posting and GM controls.

Definition of done:
- Client state transitions align with Section 10 and endpoint contracts.

### Phase D: End-to-end verification
1. Execute endpoint contract tests.
2. Execute polling behavior tests (200/204/error).
3. Execute auth-role tests and revoke flow tests.
4. Execute push strain concurrency tests.

Definition of done:
- All tests green and no undocumented response shapes remain.

## 12. Minimum Test Matrix

Server tests:
- Contract tests per endpoint: success, auth fail, validation fail.
- `since_id` exclusivity and ordering tests.
- `limit` clamp tests.
- Transactional strain increment under concurrent push requests.
- Revoke idempotency + single `leave` emission.

Client tests:
- Join flow: parse fragment, join success/failure.
- Polling loop: interval reset/backoff/jitter behavior.
- Event reducer: roll/push/strain_reset/join/leave handling.
- Token invalidation behavior on `401`/`403`.

Integration tests:
- Multi-client propagation within polling interval expectations.
- GM revoke disconnect path.
- Scene strain consistency after concurrent pushes and reset.
