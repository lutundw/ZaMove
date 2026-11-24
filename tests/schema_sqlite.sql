-- Minimal SQLite copy of the `users` table, used ONLY by tests/run_tests.sh
-- so the API can be tested without a MySQL server.
-- The real schema for XAMPP is schema.sql (MySQL / MariaDB).
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
