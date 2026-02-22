# Server-Side Implementation Outline

## Stack: FlightPHP + MariaDB + Nginx + PHP-FPM

## Goals

-   Multiplayer session support (GM + multiple players)
-   Self-registering players via rotatable join link
-   Per-player bearer tokens
-   Polling via since_id (no WebSockets/SSE)
-   Individual player revocation

------------------------------------------------------------------------

## Data Model

### sessions

-   id (BIGINT PK AUTO_INCREMENT)
-   name (VARCHAR 128)
-   joining_enabled (BOOLEAN DEFAULT 1)
-   created_at (DATETIME)
-   updated_at (DATETIME)

### session_join_tokens

-   id (BIGINT PK AUTO_INCREMENT)
-   session_id (FK → sessions.id)
-   token_hash (BINARY(32) UNIQUE)
-   token_prefix (CHAR(12))
-   revoked_at (DATETIME NULL)
-   created_at (DATETIME)
-   last_used_at (DATETIME NULL)

### session_tokens

-   id (BIGINT PK AUTO_INCREMENT)
-   session_id (FK → sessions.id)
-   role (ENUM 'gm','player')
-   display_name (VARCHAR 64 NULL)
-   token_hash (BINARY(32) UNIQUE)
-   token_prefix (CHAR(12))
-   revoked_at (DATETIME NULL)
-   created_at (DATETIME)
-   last_seen_at (DATETIME NULL)

### events

-   id (BIGINT PK AUTO_INCREMENT)
-   session_id (FK → sessions.id)
-   actor_token_id (FK → session_tokens.id NULL)
-   type (VARCHAR 64)
-   payload_json (JSON)
-   created_at (DATETIME(6))

Indexes: - events(session_id, id) - session_tokens(token_hash) -
session_join_tokens(token_hash)

------------------------------------------------------------------------

## Authentication Model

Tokens: 
  - GM token (role=gm) 
  - Join token (mints player tokens)
  - Player token (role=player)

All tokens: 
  - 32 random bytes
  - base64url encoded 
  - Store SHA-256 hash in DB

------------------------------------------------------------------------

## Routes

### Create Session (GM)

POST /api/gm/sessions

Creates: 
  - session row 
  - GM token 
  - join token

Returns: 
  - session_id 
  - gm_token 
  - join_links
------------------------------------------------------------------------

### Rotate Join Link (GM)

POST /api/gm/sessions/:id/join-link/rotate

Revokes old join tokens and issues new one.

------------------------------------------------------------------------

### Enable/Disable Joining (GM)

POST /api/gm/sessions/:id/joining

Body: { "joining_enabled": true\|false }

------------------------------------------------------------------------

### Self-Register Player

POST /api/join Authorization: Bearer `<join_token>`{=html}

Body: { "display_name": "Alice" }

Returns: { "player_token": "`<opaque>`{=html}", "display_name": "Alice"
}

------------------------------------------------------------------------

### Fetch Session Snapshot

GET /api/session Authorization: Bearer `<player_or_gm_token>`{=html}

Returns session metadata and current state.

------------------------------------------------------------------------

### Poll Events

GET /api/events?since_id=123&limit=100 Authorization: Bearer
`<player_or_gm_token>`{=html}

Returns: - 200 + events if new - 204 if none

------------------------------------------------------------------------

### Submit Roll

POST /api/rolls Authorization: Bearer `<player_token>`{=html}

Inserts event with type 'roll'.

------------------------------------------------------------------------

### Set Scene Strain (GM)

POST /api/state/scene-strain Authorization: Bearer `<gm_token>`{=html}

Updates state and inserts event 'scene_strain_set'.

------------------------------------------------------------------------

## Error Codes

-   401 Unauthorized
-   403 Forbidden
-   404 Not Found
-   409 Conflict
-   429 Too Many Requests

------------------------------------------------------------------------

## Nginx / PHP-FPM Notes

-   Polling only (no long-lived connections required)
-   Ensure HTTPS
-   Do not log Authorization headers
-   Rate-limit /api/join
