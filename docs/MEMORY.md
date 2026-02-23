# Project Memory

- What: Current server schema file defines five tables: `sessions`, `session_join_tokens`, `session_tokens`, `session_state`, and `events`.
  Where: `schema/001_initial_schema.sql`.
  Evidence: DDL currently includes explicit `CREATE TABLE` statements for all five.

- What: API authentication tokens are intended to be stored as SHA-256 hashes using fixed-width binary columns.
  Where: `schema/001_initial_schema.sql` (`session_join_tokens.join_token_hash`, `session_tokens.token_hash`).
  Evidence: Contract Section 3 token requirements + schema columns.

- What: Event polling is optimized by indexing `(event_session_id, event_id)` on events.
  Where: `schema/001_initial_schema.sql` (`events.idx_events_event_session_id_event_id`).
  Evidence: Contract Sections 6 and 8.9 specify cursor polling semantics.

- What: Schema columns now follow table-specific prefixes and standardized datetime names without `_at`, with `created` and `updated` columns on every table.
  Where: `schema/001_initial_schema.sql`.
  Evidence: Naming migration applied across `sessions`, `session_join_tokens`, `session_tokens`, and `events`.

- What: Server implementation should follow the canonical contract first, then server runbook; client doc is reference-only for server work.
  Where: `docs/plans/multiplayer-api-contract-and-build-spec.md`, `docs/plans/server-side-implementation-outline.md`.
  Evidence: Readiness summary marks contract as normative and server outline references it directly.

- What: SQL `session_state` schema aligns with contract naming/types and uniqueness requirements.
  Where: `schema/001_initial_schema.sql`, `docs/plans/multiplayer-api-contract-and-build-spec.md` Section 6.
  Evidence: `state_name VARCHAR(64)`, `state_value VARCHAR(256)`, and UNIQUE (`state_session_id`, `state_name`) are present.

- What: Current SQL schema has no foreign keys by project decision; integrity must be enforced in application logic.
  Where: `schema/001_initial_schema.sql`, `docs/plans/server-side-implementation-outline.md`.
  Evidence: FK constraints removed from DDL; outline explicitly states none.

- What: Canonical contract and runbooks currently reference docs under sibling repo path `/Users/ronan/Personal/experiments/yze-roller/...`; local copies in this repo are being used for implementation.
  Where: `docs/plans/server-side-implementation-outline.md`.
  Evidence: Header links in docs point outside this workspace.

- What: Schema blocker fixes were applied: `session_state` now uses `state_name VARCHAR(64)`, `state_value VARCHAR(256)`, and UNIQUE (`state_session_id`, `state_name`); trailing comma removed; timestamp precision normalized to `DATETIME(6)` for schema datetime fields.
  Where: `schema/001_initial_schema.sql`.
  Evidence: Updated DDL definitions and keys in current schema file.

- What: Runtime bootstrap is present but API implementation is not started; only a placeholder `/api` route exists.
  Where: `web/index.php` (`Flight::route("/api", function () { /* placeholder */ });`).
  Evidence: No endpoint handlers are registered for contract routes yet.

- What: Database access is currently a single `flight\database\SimplePdo` service from settings and no repository/service layer exists yet.
  Where: `config/services.php` (`$container->set(SimplePdo::class, ...)`).
  Evidence: `src/` is empty and container wiring only registers `SimplePdo`.

- What: Environment configuration is loaded from `config/settings.php` with optional overrides from `.env.php`.
  Where: `config/settings.php`.
  Evidence: Base config array is merged with `.env.php` when present.

- What: There are no automated tests or test scaffolding committed for the server API yet.
  Where: `tests/`.
  Evidence: Directory is empty while contract/runbook requires unit, API, and concurrency test coverage.

- What: Canonical schema DDL is maintained in `schema/001_initial_schema.sql`.
  Where: `schema/001_initial_schema.sql`.
  Evidence: No duplicate schema file is present under `docs/`.

- What: Database configuration uses `database.adapter` key consistently.
  Where: `config/settings.php`, `config/services.php`, `.env.php.dist`.
  Evidence: DSN wiring and config templates all reference `adapter`.

- What: `flight\database\SimplePdo` supports high-level helpers (`fetchRow`, `fetchAll`, `fetchColumn`, `fetchPairs`, `insert`, `update`, `delete`, `runQuery`) and should be used as the primary DB abstraction for Task 1.
  Where: `docs/knowledge/SimplePdo.md`, `config/services.php`.
  Evidence: Service container registers `SimplePdo::class`; knowledge doc lists helper interface.

- What: Transaction handling for server write paths should prefer `SimplePdo::transaction(callable)` with callback-scoped writes and rollback on exception.
  Where: `docs/knowledge/SimplePdo.md` (Transactions), `docs/plans/server-side-implementation-outline.md` (Step S4), `docs/plans/multiplayer-api-contract-and-build-spec.md` Section 9.
  Evidence: `SimplePdo` provides transaction wrapper; contract requires transactional guarantees for join/push/toggle/revoke/reset flows.

- What: Query shape caveats for Task 1: `fetchRow` auto-applies `LIMIT 1`, and `runQuery` expands array parameters for `IN(?)` (empty array becomes `IN(NULL)`).
  Where: `docs/knowledge/SimplePdo.md`.
  Evidence: Documented runtime behavior affects auth lookups and list/revoke queries.

- What: `Response` now emits contract-compliant error envelopes and uses explicit `204` behavior (no implicit status switching).
  Where: `src/Response.php`.
  Evidence: Non-2xx `data()` returns `{ "error": { "code", "message", "details?" } }`; `withNoContent()` controls `204`; success responses default to JSON object payloads.

- What: PHPUnit harness and contract-focused unit tests exist for `Response`.
  Where: `phpunit.xml.dist`, `tests/bootstrap.php`, `tests/ResponseTest.php`.
  Evidence: `vendor/bin/phpunit --configuration phpunit.xml.dist` passes with 8 tests and 16 assertions.

- What: Shared bearer token utility now exists for auth middleware foundations.
  Where: `src/Auth/BearerToken.php`.
  Evidence: Provides `parseAuthorizationHeader()` and raw-binary SHA-256 `hashToken()` for DB `BINARY(32)` lookups.

- What: Unit tests cover bearer parsing and token hashing behavior.
  Where: `tests/BearerTokenTest.php`.
  Evidence: Valid/invalid header parsing, case-insensitive bearer scheme, binary hash length/format, and empty-token exception are verified.
