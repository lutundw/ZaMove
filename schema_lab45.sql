-- ZaMove Labs 4 and 5: two extra tables (MySQL / MariaDB on XAMPP).
-- Run AFTER schema.sql:  phpMyAdmin -> database zamumove -> Import, or
--   C:\xampp\mysql\bin\mysql -u root zamumove < schema_lab45.sql

USE zamumove;

-- Server-side session store (Lab 5).
-- The cookie holds a random 64-hex id; only its SHA-256 is stored here.
CREATE TABLE IF NOT EXISTS sessions (
  session_id_hash CHAR(64)     NOT NULL,
  user_id         INT          NOT NULL,
  created_at      DATETIME     NOT NULL,          -- all times are UTC
  last_seen_at    DATETIME     NOT NULL,          -- idle timeout: 30 minutes
  expires_at      DATETIME     NOT NULL,          -- absolute timeout: 8 hours
  ip_address      VARCHAR(45)  NULL,
  user_agent      VARCHAR(255) NULL,
  PRIMARY KEY (session_id_hash),
  KEY idx_sessions_user    (user_id),
  KEY idx_sessions_expires (expires_at),
  CONSTRAINT fk_sessions_user
    FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Fixed-window rate-limit counters (Lab 4).
CREATE TABLE IF NOT EXISTS rate_limits (
  rl_key       VARCHAR(100) NOT NULL,             -- operationId + ':' + SHA-256(client IP)
  window_start INT          NOT NULL,             -- Unix time the current window began
  hits         INT          NOT NULL,
  PRIMARY KEY (rl_key)
) ENGINE=InnoDB;
