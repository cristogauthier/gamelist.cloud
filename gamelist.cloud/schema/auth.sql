-- Auth schema for gamelist.cloud.
-- Deploy manually against the 'game' database before enabling auth features.
-- There is no migration framework; apply this once and track it in version control.

-- [USERS] Core account table for username-only authentication.
-- NOTE: email is nullable and reserved for future email-verification and password-recovery flow.
--       Its UNIQUE constraint still applies to non-null values; MySQL permits multiple NULL rows.
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    username      VARCHAR(50)   NOT NULL,
    email         VARCHAR(255)  NULL     DEFAULT NULL,
    password_hash VARCHAR(255)  NOT NULL,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login    TIMESTAMP     NULL     DEFAULT NULL,
    PRIMARY KEY   (id),
    UNIQUE KEY    uq_username (username),
    UNIQUE KEY    uq_email    (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TODO: Add password_reset_tokens table when outbound email delivery is wired in.
--       Intended shape for that future release:
--
--   CREATE TABLE password_reset_tokens (
--       id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
--       user_id     INT UNSIGNED  NOT NULL,
--       selector    CHAR(32)      NOT NULL,          -- public URL segment; safe to expose
--       token_hash  CHAR(64)      NOT NULL,          -- SHA-256 of full token; raw token never stored
--       expires_at  TIMESTAMP     NOT NULL,
--       used_at     TIMESTAMP     NULL DEFAULT NULL,
--       created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
--       PRIMARY KEY (id),
--       UNIQUE KEY  uq_selector (selector),
--       FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
--   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
