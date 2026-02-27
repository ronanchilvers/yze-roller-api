-- Initial database schema for YZE-Roller API (MariaDB)
-- Based on docs/plans/server-side-implementation-outline.md

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
    session_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_name VARCHAR(128) NOT NULL,
    session_joining_enabled TINYINT(1) NOT NULL DEFAULT 1,
    session_created DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    session_updated DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS session_join_tokens (
    join_token_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    join_token_session_id BIGINT UNSIGNED NOT NULL,
    join_token_hash BINARY(32) NOT NULL,
    join_token_prefix CHAR(12) NOT NULL,
    join_token_revoked DATETIME(6) NULL,
    join_token_created DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    join_token_updated DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    join_token_last_used DATETIME(6) NULL,
    PRIMARY KEY (join_token_id),
    UNIQUE KEY uq_session_join_tokens_join_token_hash (join_token_hash),
    KEY idx_session_join_tokens_join_token_session_id (join_token_session_id),
    KEY idx_session_join_tokens_join_token_session_id_revoked (join_token_session_id, join_token_revoked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS session_tokens (
    token_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_session_id BIGINT UNSIGNED NOT NULL,
    token_role ENUM('gm', 'player') NOT NULL,
    token_display_name VARCHAR(64) NULL,
    token_hash BINARY(32) NOT NULL,
    token_prefix CHAR(12) NOT NULL,
    token_revoked DATETIME(6) NULL,
    token_created DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    token_updated DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    token_last_seen DATETIME(6) NULL,
    PRIMARY KEY (token_id),
    UNIQUE KEY uq_session_tokens_token_hash (token_hash),
    KEY idx_session_tokens_token_session_id (token_session_id),
    KEY idx_session_tokens_token_session_id_role_revoked (token_session_id, token_role, token_revoked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS session_state (
    state_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    state_session_id BIGINT UNSIGNED NOT NULL,
    state_name VARCHAR(64) NOT NULL,
    state_value VARCHAR(256) NOT NULL,
    state_created DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    state_updated DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (state_id),
    UNIQUE KEY uq_session_state_state_session_id_state_name (state_session_id, state_name),
    KEY idx_states_state_session_id_state_id (state_session_id, state_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_session_id BIGINT UNSIGNED NOT NULL,
    event_actor_token_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(64) NOT NULL,
    event_payload_json JSON NOT NULL,
    event_created DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    event_updated DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (event_id),
    KEY idx_events_event_session_id_event_id (event_session_id, event_id),
    KEY idx_events_event_actor_token_id (event_actor_token_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
