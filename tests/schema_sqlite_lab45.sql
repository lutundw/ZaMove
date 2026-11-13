-- SQLite copy of the tables the Lab 4/5 tests need (tests only; XAMPP uses schema.sql + schema_lab45.sql).
CREATE TABLE users (
  user_id         INTEGER PRIMARY KEY AUTOINCREMENT,
  full_name       VARCHAR(120) NOT NULL,
  email           VARCHAR(150) NOT NULL,
  phone           VARCHAR(25)  NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  role            TEXT NOT NULL DEFAULT 'customer' CHECK (role IN ('customer','operator','admin')),
  account_status  TEXT NOT NULL DEFAULT 'active'   CHECK (account_status IN ('pending','active','suspended')),
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_users_email UNIQUE (email)
);
CREATE TABLE sessions (
  session_id_hash CHAR(64)     NOT NULL PRIMARY KEY,
  user_id         INTEGER      NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
  created_at      DATETIME     NOT NULL,
  last_seen_at    DATETIME     NOT NULL,
  expires_at      DATETIME     NOT NULL,
  ip_address      VARCHAR(45),
  user_agent      VARCHAR(255)
);
CREATE TABLE rate_limits (
  rl_key       VARCHAR(100) NOT NULL PRIMARY KEY,
  window_start INTEGER      NOT NULL,
  hits         INTEGER      NOT NULL
);
-- Reduced bookings table (columns the API reads) so GET /bookings has something to return.
CREATE TABLE bookings (
  booking_id        INTEGER PRIMARY KEY AUTOINCREMENT,
  booking_reference VARCHAR(30)  NOT NULL UNIQUE,
  customer_id       INTEGER      NOT NULL,
  operator_id       INTEGER      NOT NULL DEFAULT 1,
  booking_type      TEXT         NOT NULL,
  schedule_id       INTEGER,
  quotation_id      INTEGER,
  booking_status    TEXT         NOT NULL DEFAULT 'pending',
  total_amount      DECIMAL(10,2) NOT NULL,
  currency          CHAR(3)      NOT NULL DEFAULT 'ZMW',
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
