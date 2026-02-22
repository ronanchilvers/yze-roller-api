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
