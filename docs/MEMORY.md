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

- What: Token lookup service now resolves join/session tokens via `SimplePdo` and derives revoked state for guard usage.
  Where: `src/Auth/TokenLookup.php`.
  Evidence: `findJoinTokenByOpaqueToken()` and `findSessionTokenByOpaqueToken()` query token tables by binary SHA-256 hash and add `is_revoked`.

- What: Service container now exposes token lookup helper for dependency injection.
  Where: `config/services.php`.
  Evidence: `TokenLookup::class` is registered and constructed from `SimplePdo::class`.

- What: Unit tests cover token lookup query behavior and binary-hash parameter expectations.
  Where: `tests/TokenLookupTest.php`.
  Evidence: PHPUnit suite verifies null/result paths, revoked derivation, and exact 32-byte hash parameter use.

- What: Auth guard service now provides reusable join/session token enforcement and GM-role checks with contract-aligned error responses.
  Where: `src/Auth/AuthGuard.php`.
  Evidence: `requireJoinToken()`, `requireSessionToken()`, and `requireGmRole()` return token rows on success or `Response` errors (`TOKEN_MISSING`, `TOKEN_INVALID`, `JOIN_TOKEN_REVOKED`, `TOKEN_REVOKED`, `ROLE_FORBIDDEN`).

- What: Service container now exposes `AuthGuard` for route/controller use.
  Where: `config/services.php`.
  Evidence: `AuthGuard::class` is registered using `TokenLookup::class`.

- What: Unit tests cover auth guard success and failure paths for join/session/GM role checks.
  Where: `tests/AuthGuardTest.php`.
  Evidence: Suite verifies missing/malformed/invalid/revoked token paths and role gating behavior against response codes and envelope fields.

- What: Request validation service now enforces contract rules for `since_id`, `limit`, `display_name`, and `POST /api/events` payloads.
  Where: `src/Validation/RequestValidator.php`.
  Evidence: Validator returns normalized values on success or contract-aligned `Response` errors (`VALIDATION_ERROR`, `EVENT_TYPE_UNSUPPORTED`) with optional `details`.

- What: Service container now exposes request validator for route/controller reuse.
  Where: `config/services.php`.
  Evidence: `RequestValidator::class` is registered as a container service.

- What: Unit tests cover validator success and failure paths for query params, display-name validation, and roll/push payload contracts.
  Where: `tests/RequestValidatorTest.php`.
  Evidence: Test suite verifies allowed values, unsupported event types, unknown fields rejection, integer range checks, and boolean `strain` enforcement.

- What: `POST /api/sessions` is now wired and backed by a transactional session bootstrap service.
  Where: `web/index.php`, `src/Controller/SessionsController.php`, `src/Service/SessionBootstrapService.php`.
  Evidence: Route registration calls controller, which invokes service and returns contract-shaped `Response`.

- What: Session bootstrap implementation now creates session + GM token + join token + required `session_state` defaults in one transaction.
  Where: `src/Service/SessionBootstrapService.php`.
  Evidence: Service inserts `sessions`, `session_tokens` (gm), `session_join_tokens`, and `session_state` (`scene_strain=0`, `joining_enabled=true`) and returns `201` payload with `join_link`.

- What: Validator now includes `session_name` checks for create-session requests.
  Where: `src/Validation/RequestValidator.php`.
  Evidence: `validateSessionName()` enforces trimmed length 1..128 and returns `VALIDATION_ERROR` response when invalid.

- What: Response mapping now treats `INTERNAL_ERROR` as HTTP `500`.
  Where: `src/Response.php`.
  Evidence: `defaultStatusForErrorCode()` maps `ERROR_INTERNAL` to `STATUS_INTERNAL_SERVER_ERROR`.

- What: Unit tests cover session bootstrap behavior (validation failure, success payload + inserts, transaction error path).
  Where: `tests/SessionBootstrapServiceTest.php`.
  Evidence: Tests assert insert/write flow, token hash/prefix behavior, RFC3339 `created_at` formatting, and internal-error fallback.

- What: `POST /api/join` is now wired and implemented via service + controller.
  Where: `web/index.php`, `src/Controller/JoinController.php`, `src/Service/JoinService.php`.
  Evidence: Route registration calls `JoinController::create`, which delegates to `JoinService` and emits contract-style `Response`.

- What: Join flow now uses auth guard + display-name validation + joining-enabled state check + transactional player token mint/event write.
  Where: `src/Service/JoinService.php`.
  Evidence: Service enforces join token auth, validates `display_name`, checks `session_state.joining_enabled`, then transactionally inserts `session_tokens` (player), updates `session_join_tokens.last_used`, and inserts `join` event.

- What: Controller response handling is now centralized in a shared base controller helper.
  Where: `src/Controller/Base.php`, `src/Controller/SessionsController.php`, `src/Controller/JoinController.php`.
  Evidence: Controllers now reuse `Base::sendResponse()` for consistent status/body handling.

- What: Unit tests cover join service success and contract-relevant failure paths.
  Where: `tests/JoinServiceTest.php`.
  Evidence: Tests validate missing token, invalid display name, join disabled, successful join payload/event insertion, and transaction failure returning `INTERNAL_ERROR`.

- What: `GET /api/session` snapshot endpoint is now wired through a dedicated snapshot service.
  Where: `web/index.php`, `src/Controller/SessionController.php`, `src/Service/SessionSnapshotService.php`.
  Evidence: Route registration calls `SessionController::show`, which delegates to `SessionSnapshotService` and emits contract-style `Response`.

- What: Snapshot service behavior includes auth guard enforcement, session existence check, state parsing with safe fallbacks, latest-event cursor defaulting, and active player projection.
  Where: `src/Service/SessionSnapshotService.php`.
  Evidence: Service returns `SESSION_NOT_FOUND` (404) if missing; parses `joining_enabled` from `session_state` (`true` only), parses `scene_strain` non-negative integer string fallback `0`, and returns `latest_event_id=0` when no events exist.

- What: Unit tests cover snapshot success and key contract fallback/error rules.
  Where: `tests/SessionSnapshotServiceTest.php`.
  Evidence: Tests verify missing token, session-not-found, full success payload shape, and invalid-state/no-event fallback behavior.

- What: Flight route registration for GET session snapshot must use `Flight::route('/api/session', ...)` (not `Flight::get(...)`), and path must be singular `/api/session`.
  Where: `web/index.php`.
  Evidence: `Flight::get()` in this stack is not a GET-route helper and led to missing-route behavior; corrected to route registration.

- What: `GET /api/events` polling endpoint is now wired through a dedicated poll service and method-specific route registration.
  Where: `web/index.php`, `src/Controller/EventsController.php`, `src/Service/EventsPollService.php`.
  Evidence: Route registration uses `Flight::route('GET /api/events', ...)`; controller delegates to service and returns contract-style `Response`.

- What: Events poll service now enforces auth + query validation and implements contract polling semantics (`since_id` exclusive, ascending order, `limit` bounds, `204` for no events).
  Where: `src/Service/EventsPollService.php`.
  Evidence: Service uses `AuthGuard` + `RequestValidator`, queries by `event_id > since_id`, and returns either `withNoContent()` or `{ events, next_since_id }`.

- What: Event serialization for polling now includes contract fields with RFC3339 timestamps and decoded payload JSON, including nullable actor mapping.
  Where: `src/Service/EventsPollService.php`.
  Evidence: Output maps to `id`, `type`, `session_id`, `occurred_at`, `actor`, `payload`; actor is resolved from joined `session_tokens` data.

- What: Unit tests cover events polling auth/validation failures, empty `204`, success mapping, and internal-error fallback.
  Where: `tests/EventsPollServiceTest.php`.
  Evidence: Tests verify default `limit=10`, `next_since_id` from last event, actor/payload mapping, and `INTERNAL_ERROR` on query failure.

- What: `POST /api/events` submit endpoint is now wired through `EventsController::create` to `EventsSubmitService`.
  Where: `web/index.php`, `src/Controller/EventsController.php`, `src/Service/EventsSubmitService.php`, `config/services.php`.
  Evidence: Route registration includes `POST /api/events`; service is DI-registered and invoked by controller method.

- What: Event submit flow now enforces session-token auth + payload validation, supports `roll` and `push`, and uses a DB transaction with `FOR UPDATE` for `push` when `strain=true`.
  Where: `src/Service/EventsSubmitService.php`.
  Evidence: `submit()` branches by event type; `push` + `strain=true` executes `SimplePdo::transaction()`, locks `scene_strain` row, updates `session_state`, then inserts event.

- What: `POST /api/events` unit tests now cover auth failure, validation failure, roll success, push success (strain and non-strain), transactional state update behavior, and internal-error fallback.
  Where: `tests/EventsSubmitServiceTest.php`.
  Evidence: PHPUnit cases assert response envelope, payload shape, query/insert/update interactions, and error-code mapping.

- What: GM joining toggle endpoint is now implemented at `POST /api/gm/sessions/:session_id/joining`.
  Where: `web/index.php`, `src/Controller/GmSessionsController.php`, `src/Service/GmSessionJoiningService.php`, `config/services.php`.
  Evidence: Route wiring invokes service through controller; service is registered in DI and enforces GM/session authorization.

- What: Joining toggle writes `session_state.joining_enabled` transactionally with row-level lock semantics.
  Where: `src/Service/GmSessionJoiningService.php`.
  Evidence: Service executes `SELECT ... FOR UPDATE` on `session_state` and then updates/inserts canonical string state values (`"true"`/`"false"`).

- What: Validator now includes reusable positive-ID validation and strict payload checks for joining toggle requests.
  Where: `src/Validation/RequestValidator.php`, `tests/RequestValidatorTest.php`.
  Evidence: `validatePositiveId()` and `validateJoiningTogglePayload()` added; tests verify accepted and rejected inputs.

- What: Unit tests cover the new GM joining toggle service for auth, role checks, session binding, session existence, validation, transactional update/insert, and internal error fallback.
  Where: `tests/GmSessionJoiningServiceTest.php`.
  Evidence: PHPUnit suite asserts response codes/envelopes and expected DB interaction patterns across all major paths.

- What: GM join-link rotation endpoint is now implemented at `POST /api/sessions/:session_id/join-link/rotate`.
  Where: `web/index.php`, `src/Controller/GmSessionsController.php`, `src/Service/GmJoinLinkRotateService.php`, `config/services.php`.
  Evidence: Route wiring invokes `rotateJoinLink()`; service is DI-registered and enforces session token auth + GM role + same-session binding.

- What: Join-link rotation executes as a single transaction that revokes all active join tokens for the session and mints a new hashed join token.
  Where: `src/Service/GmJoinLinkRotateService.php`.
  Evidence: `transaction()` updates `session_join_tokens` (`join_token_revoked`, `join_token_updated`) and inserts one new active `session_join_tokens` row with SHA-256 hash + prefix.

- What: Unit tests cover rotate join-link endpoint service behaviors including auth failures, role/session checks, session-not-found, successful revoke+mint, and transaction failure fallback.
  Where: `tests/GmJoinLinkRotateServiceTest.php`.
  Evidence: Tests assert response envelopes and DB interaction shape for `update()` + `insert()` inside transaction.

- What: GM players list endpoint is now implemented at `GET /api/gm/sessions/:session_id/players`.
  Where: `web/index.php`, `src/Controller/GmSessionsController.php`, `src/Service/GmPlayersListService.php`, `config/services.php`.
  Evidence: Route wiring invokes `listPlayers()`; service is DI-registered and enforces session token auth + GM role + same-session binding.

- What: Player-list response now maps player token rows to contract shape with revoke status and RFC3339 timestamps.
  Where: `src/Service/GmPlayersListService.php`.
  Evidence: Service returns `players[]` entries containing `token_id`, `display_name`, `role`, `revoked`, `created_at`, `last_seen_at`, and `revoked_at`.

- What: Unit tests cover GM players list service for auth/role/session checks, session-not-found handling, payload mapping, and internal error fallback.
  Where: `tests/GmPlayersListServiceTest.php`.
  Evidence: Tests assert response envelopes and mapped output for active/revoked players including timestamp conversions and nullable fields.

- What: GM player revoke endpoint is now implemented at `POST /api/gm/sessions/:session_id/players/:token_id/revoke` with idempotent semantics.
  Where: `web/index.php`, `src/Controller/GmSessionsController.php`, `src/Service/GmPlayerRevokeService.php`, `config/services.php`.
  Evidence: Route wiring invokes `revokePlayer()`; service is DI-registered and enforces session token auth + GM role + same-session binding.

- What: Revoke flow is transactional and emits exactly one `leave` event on first revoke; repeat revoke returns success with `event_emitted=false`.
  Where: `src/Service/GmPlayerRevokeService.php`.
  Evidence: Service uses `transaction()` with `SELECT ... FOR UPDATE` on target player token, updates revoke fields when first revoked, and inserts `leave` event payload `{token_id, display_name, reason:\"revoked\"}` only once.

- What: Request validator now has a reusable strict empty-object payload guard used for no-body mutation endpoints.
  Where: `src/Validation/RequestValidator.php`.
  Evidence: `validateEmptyObjectPayload()` accepts only empty object payload and returns `VALIDATION_ERROR` for non-empty bodies.

- What: Unit tests cover GM revoke service for auth/role/session checks, path/body validation, missing resources, idempotency, first-revoke leave-event emission, and internal error fallback.
  Where: `tests/GmPlayerRevokeServiceTest.php`, `tests/RequestValidatorTest.php`.
  Evidence: PHPUnit cases assert response envelopes and DB interaction patterns for both first and repeated revoke flows.

- What: GM scene strain reset endpoint is now implemented at `POST /api/gm/sessions/:session_id/reset_scene_strain`.
  Where: `web/index.php`, `src/Controller/GmSessionsController.php`, `src/Service/GmSceneStrainResetService.php`, `config/services.php`.
  Evidence: Route wiring invokes `resetSceneStrain()`; service is DI-registered and enforces session token auth + GM role + same-session binding.

- What: Scene strain reset executes transactionally with row lock semantics and emits `strain_reset` event payload containing previous and new strain values.
  Where: `src/Service/GmSceneStrainResetService.php`.
  Evidence: Service uses `transaction()` with `SELECT ... FOR UPDATE` on `session_state(scene_strain)`, writes state to `'0'`, and inserts `events` row with payload `{previous_scene_strain, scene_strain:0}`.

- What: Unit tests cover GM scene strain reset service for auth/role/session checks, path/body validation, missing resources, state-row update/insert branches, invalid stored-value fallback, and internal error fallback.
  Where: `tests/GmSceneStrainResetServiceTest.php`.
  Evidence: PHPUnit cases assert response envelopes, payload shape (`session_id`, `scene_strain`, `event_id`), and DB interaction patterns for each branch.
