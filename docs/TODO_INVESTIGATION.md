# TODO Investigation

- Question: SQL vs contract drift for `session_state` needs resolution before implementing write/read paths. Which definition is authoritative for column names/types and constraints?
  Best leads: `schema/001_initial_schema.sql`; `docs/plans/multiplayer-api-contract-and-build-spec.md` Section 6; `docs/plans/server-side-implementation-outline.md` Section 3.
  Status: closed (schema updated to contract shape: `state_name VARCHAR(64)`, `state_value VARCHAR(256)`, UNIQUE (`state_session_id`, `state_name`))

- Question: `schema/001_initial_schema.sql` currently contains a trailing comma in `session_state` index list, which may break MariaDB import. Should this be fixed now before server implementation begins?
  Best leads: `schema/001_initial_schema.sql` (`session_state` table definition).
  Status: closed (trailing comma removed)

- Question: Contract references `DATETIME(6)` broadly while parts of SQL use `DATETIME` precision without fractional seconds. Confirm required precision policy for v1.
  Best leads: `docs/plans/multiplayer-api-contract-and-build-spec.md` Section 6; `schema/001_initial_schema.sql`.
  Status: closed (schema datetime fields normalized to `DATETIME(6)`)

- Question: Should `docs/001_initial_schema.sql` remain as a duplicate of `schema/001_initial_schema.sql`, or should one canonical schema file be retained to avoid drift?
  Best leads: `schema/001_initial_schema.sql`; `docs/001_initial_schema.sql`; team docs conventions.
  Status: closed (canonical schema retained at `schema/001_initial_schema.sql`; duplicate under `docs/` is no longer present)

- Question: Should the settings key `database.adaptor` be standardized to `database.adapter` before implementation, or kept as-is for backward compatibility with current config files?
  Best leads: `config/settings.php`; `config/services.php`; `.env.php.dist`.
  Status: closed (`database.adapter` is now used consistently in settings, service wiring, and `.env.php.dist`)
