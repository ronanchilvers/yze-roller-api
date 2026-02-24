# Decisions

## 2026-02-22 - Initial schema follows the API outline's four-table model

### Context
The project needed an initial importable MariaDB schema based on the server-side implementation outline.

### Decision
Create `schema/001_initial_schema.sql` with:
- `sessions`
- `session_join_tokens`
- `session_tokens`
- `events`

Use InnoDB foreign keys, unique token-hash constraints, and the polling index `(session_id, id)` on `events`.

### Consequences
- Aligns initial persistence model with the documented API contracts.
- Supports token revocation and event polling requirements from day one.
- Leaves room for later schema evolution if state snapshots need dedicated storage.

### Alternatives considered
- Adding a dedicated `session_state` table immediately was deferred because it is not part of the current explicit data model section in the outline.

## 2026-02-22 - Apply prefixed column naming convention and standardized datetime names

### Context
The schema needed a consistent naming convention by table type and explicit `created`/`updated` datetime columns in every table.

### Decision
Rename columns in `schema/001_initial_schema.sql` as follows:
- `sessions.*` prefixed with `session_`
- `session_join_tokens.*` prefixed with `join_token_`
- `session_tokens.*` prefixed with `token_`
- `events.*` prefixed with `event_`

Rename datetime fields to remove `_at` and ensure each table has `<prefix>created` and `<prefix>updated`.

### Consequences
- Column naming is uniform and unambiguous across all tables.
- SQL consumers must use the new prefixed column names in queries and model mappings.

## 2026-02-22 - Server-side implementation will be driven by the canonical API contract

### Context
Implementation planning docs include client and server runbooks, but the current task scope is server code only.

### Decision
For server implementation, treat `docs/plans/multiplayer-api-contract-and-build-spec.md` as normative and `docs/plans/server-side-implementation-outline.md` as the execution checklist. Use client spec only when needed to avoid response-shape drift.

### Consequences
- Backend route behavior, validation, error envelope, and transaction semantics should be implemented against contract Sections 5-12.
- Client-side implementation details are out of scope unless they reveal server contract mismatches.

## 2026-02-22 - Resolve schema blockers by aligning SQL with contract state model and timestamp precision

### Context
Pre-implementation review identified blocking drift between SQL and contract for `session_state`, inconsistent datetime precision, and a syntax error risk (trailing comma).

### Decision
Update `schema/001_initial_schema.sql` to:
- make `session_state` match contract (`state_name VARCHAR(64)`, `state_value VARCHAR(256)`, UNIQUE (`state_session_id`, `state_name`))
- normalize schema datetime fields to `DATETIME(6)`
- remove the trailing comma in `session_state` index definitions

### Consequences
- Schema is contract-aligned for state handling and ready for server implementation work.
- MariaDB import failure risk from the `session_state` trailing comma is removed.

## 2026-02-24 - Normalize configured CORS origins before allowlist matching

### Context
CORS headers were not being emitted for local development requests because configured origins can include a trailing slash (for example `http://localhost:5173/`), while browser `Origin` headers are sent without a trailing slash (`http://localhost:5173`).

### Decision
Normalize both configured allowlist origins and incoming request origins in `CorsPolicy` before comparison:
- canonicalize scheme/host/port using `parse_url` when possible
- ignore trailing slash/path artifacts in configured origins
- continue to support wildcard (`*`) reflection and explicit `null` origin matching

### Consequences
- Common origin formatting mistakes no longer silently disable CORS.
- Runtime behavior is more tolerant while still enforcing allowlist checks.
