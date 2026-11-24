-- =====================================================================
-- ZamuMove - Transportation and Logistics Booking Platform
-- Database schema for XAMPP (MariaDB / MySQL, InnoDB)
--
-- HOW TO RUN (phpMyAdmin):
--   1. Start Apache and MySQL in the XAMPP Control Panel.
--   2. Open http://localhost/phpmyadmin
--   3. Click the "Import" tab, choose this file, click "Import".
--      (Or open the "SQL" tab, paste the whole file and click "Go".)
--
-- TABLE ORDER MATTERS: a table can only reference (FOREIGN KEY) a table
-- that already exists, so parents are created before children:
--   users -> operators -> vehicles, routes -> schedules
--   users -> cargo_requests -> quotations -> bookings
--   bookings -> passenger_booking_details, payments, complaints,
--               booking_status_history; payments -> payment_webhook_events
--
-- Conventions used everywhere:
--   * Every primary key is INT AUTO_INCREMENT, and every foreign key
--     column uses exactly the same type (INT). A type mismatch gives
--     error #1215 "Cannot add foreign key constraint".
--   * Money is DECIMAL(10,2), never FLOAT. Currency defaults to 'ZMW'.
--   * Default delete rule is RESTRICT: a parent row cannot be deleted
--     while child rows exist (protects bookings and payment records).
--   * Statuses are ENUMs so the database rejects invalid values.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS zamumove
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE zamumove;

-- ---------------------------------------------------------------------
-- 1. users  (customers, operators and admins all log in from here)
-- ---------------------------------------------------------------------
CREATE TABLE users (
  user_id         INT           NOT NULL AUTO_INCREMENT,
  full_name       VARCHAR(120)  NOT NULL,
  email           VARCHAR(150)  NOT NULL,
  phone           VARCHAR(25)   NOT NULL,
  password_hash   VARCHAR(255)  NOT NULL,           -- store password_hash() output, never the password
  role            ENUM('customer','operator','admin') NOT NULL DEFAULT 'customer',
  account_status  ENUM('pending','active','suspended') NOT NULL DEFAULT 'active',
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. operators  (extra profile for users whose role = 'operator')
--    One user -> zero or one operator profile  (user_id is UNIQUE)
-- ---------------------------------------------------------------------
CREATE TABLE operators (
  operator_id          INT           NOT NULL AUTO_INCREMENT,
  user_id              INT           NOT NULL,
  business_name        VARCHAR(150)  NOT NULL,
  business_type        ENUM('bus','cargo','both') NOT NULL,
  business_address     VARCHAR(255)  NOT NULL,
  verification_status  ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  approved_by          INT           NULL,          -- admin who approved; NULL until reviewed
  PRIMARY KEY (operator_id),
  UNIQUE KEY uq_operators_user (user_id),
  CONSTRAINT fk_operators_user
    FOREIGN KEY (user_id)     REFERENCES users (user_id),
  CONSTRAINT fk_operators_approved_by
    FOREIGN KEY (approved_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. vehicles  (owned by an operator)
-- ---------------------------------------------------------------------
CREATE TABLE vehicles (
  vehicle_id          INT            NOT NULL AUTO_INCREMENT,
  operator_id         INT            NOT NULL,
  registration_number VARCHAR(30)    NOT NULL,
  vehicle_type        VARCHAR(50)    NOT NULL,      -- bus, van, truck ...
  seat_capacity       INT            NULL,          -- NULL for goods-only vehicles
  cargo_capacity_kg   DECIMAL(10,2)  NULL,          -- NULL for passenger-only vehicles
  vehicle_status      ENUM('active','inactive','maintenance') NOT NULL DEFAULT 'active',
  PRIMARY KEY (vehicle_id),
  UNIQUE KEY uq_vehicles_registration (registration_number),
  CONSTRAINT fk_vehicles_operator
    FOREIGN KEY (operator_id) REFERENCES operators (operator_id),
  CONSTRAINT chk_vehicles_seats  CHECK (seat_capacity IS NULL OR seat_capacity > 0),
  CONSTRAINT chk_vehicles_cargo  CHECK (cargo_capacity_kg IS NULL OR cargo_capacity_kg > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. routes  (published by an operator)
-- ---------------------------------------------------------------------
CREATE TABLE routes (
  route_id      INT            NOT NULL AUTO_INCREMENT,
  operator_id   INT            NOT NULL,
  origin        VARCHAR(120)   NOT NULL,
  destination   VARCHAR(120)   NOT NULL,
  distance_km   DECIMAL(8,2)   NULL,
  base_fare     DECIMAL(10,2)  NOT NULL,            -- ZMW
  route_status  ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (route_id),
  KEY idx_routes_search (origin, destination),      -- speeds up the Lusaka -> Ndola style search
  CONSTRAINT fk_routes_operator
    FOREIGN KEY (operator_id) REFERENCES operators (operator_id),
  CONSTRAINT chk_routes_places CHECK (origin <> destination),
  CONSTRAINT chk_routes_fare   CHECK (base_fare >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. schedules  (one journey = a route + a vehicle + a departure time)
--    Seats left is NOT stored; it is computed from bookings (see guide).
-- ---------------------------------------------------------------------
CREATE TABLE schedules (
  schedule_id      INT            NOT NULL AUTO_INCREMENT,
  route_id         INT            NOT NULL,
  vehicle_id       INT            NOT NULL,
  departure_at     DATETIME       NOT NULL,
  arrival_at       DATETIME       NULL,
  fare_per_seat    DECIMAL(10,2)  NOT NULL,
  total_seats      INT            NOT NULL,
  schedule_status  ENUM('scheduled','cancelled','completed') NOT NULL DEFAULT 'scheduled',
  PRIMARY KEY (schedule_id),
  KEY idx_schedules_route_departure (route_id, departure_at),
  KEY idx_schedules_vehicle (vehicle_id),
  CONSTRAINT fk_schedules_route
    FOREIGN KEY (route_id)   REFERENCES routes (route_id),
  CONSTRAINT fk_schedules_vehicle
    FOREIGN KEY (vehicle_id) REFERENCES vehicles (vehicle_id),
  CONSTRAINT chk_schedules_seats CHECK (total_seats > 0),
  CONSTRAINT chk_schedules_fare  CHECK (fare_per_seat >= 0),
  CONSTRAINT chk_schedules_times CHECK (arrival_at IS NULL OR arrival_at > departure_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. cargo_requests  (a customer asks for goods transport)
-- ---------------------------------------------------------------------
CREATE TABLE cargo_requests (
  cargo_request_id    INT            NOT NULL AUTO_INCREMENT,
  customer_id         INT            NOT NULL,
  pickup_location     VARCHAR(255)   NOT NULL,
  delivery_location   VARCHAR(255)   NOT NULL,
  cargo_description   TEXT           NOT NULL,
  weight_kg           DECIMAL(10,2)  NULL,
  requested_pickup_at DATETIME       NULL,
  request_status      ENUM('open','quoted','accepted','closed','cancelled') NOT NULL DEFAULT 'open',
  created_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (cargo_request_id),
  KEY idx_cargo_status (request_status),
  CONSTRAINT fk_cargo_customer
    FOREIGN KEY (customer_id) REFERENCES users (user_id),
  CONSTRAINT chk_cargo_weight CHECK (weight_kg IS NULL OR weight_kg > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. quotations  (an operator's price offer for a cargo request)
--    One request -> many quotations, but one per operator (revise by UPDATE)
-- ---------------------------------------------------------------------
CREATE TABLE quotations (
  quotation_id      INT            NOT NULL AUTO_INCREMENT,
  cargo_request_id  INT            NOT NULL,
  operator_id       INT            NOT NULL,
  quoted_amount     DECIMAL(10,2)  NOT NULL,
  valid_until       DATETIME       NULL,
  quotation_status  ENUM('pending','accepted','rejected','expired') NOT NULL DEFAULT 'pending',
  created_at        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (quotation_id),
  UNIQUE KEY uq_quotation_request_operator (cargo_request_id, operator_id),
  KEY idx_quotations_operator (operator_id),
  CONSTRAINT fk_quotations_request
    FOREIGN KEY (cargo_request_id) REFERENCES cargo_requests (cargo_request_id),
  CONSTRAINT fk_quotations_operator
    FOREIGN KEY (operator_id)      REFERENCES operators (operator_id),
  CONSTRAINT chk_quotations_amount CHECK (quoted_amount > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. bookings  (shared by passenger AND cargo bookings)
--    passenger booking -> schedule_id is set, quotation_id is NULL
--    cargo booking     -> quotation_id is set, schedule_id is NULL
--    The CHECK below makes the database enforce that rule.
-- ---------------------------------------------------------------------
CREATE TABLE bookings (
  booking_id        INT            NOT NULL AUTO_INCREMENT,
  booking_reference VARCHAR(30)    NOT NULL,        -- customer-facing, e.g. ZM-20261022-0001
  customer_id       INT            NOT NULL,
  operator_id       INT            NOT NULL,
  booking_type      ENUM('passenger','cargo') NOT NULL,
  schedule_id       INT            NULL,
  quotation_id      INT            NULL,
  booking_status    ENUM('pending','accepted','rejected','confirmed','in_transit','completed','cancelled')
                    NOT NULL DEFAULT 'pending',
  total_amount      DECIMAL(10,2)  NOT NULL,        -- price agreed at booking time; never recalculated
  currency          CHAR(3)        NOT NULL DEFAULT 'ZMW',
  hold_expires_at   DATETIME       NULL,            -- short seat hold while the customer pays
  created_at        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (booking_id),
  UNIQUE KEY uq_bookings_reference (booking_reference),
  UNIQUE KEY uq_bookings_quotation (quotation_id),  -- one accepted quotation -> one booking
  KEY idx_bookings_customer (customer_id),
  KEY idx_bookings_operator (operator_id),
  KEY idx_bookings_schedule_status (schedule_id, booking_status),
  CONSTRAINT fk_bookings_customer
    FOREIGN KEY (customer_id)  REFERENCES users (user_id),
  CONSTRAINT fk_bookings_operator
    FOREIGN KEY (operator_id)  REFERENCES operators (operator_id),
  CONSTRAINT fk_bookings_schedule
    FOREIGN KEY (schedule_id)  REFERENCES schedules (schedule_id),
  CONSTRAINT fk_bookings_quotation
    FOREIGN KEY (quotation_id) REFERENCES quotations (quotation_id),
  CONSTRAINT chk_bookings_type CHECK (
       (booking_type = 'passenger' AND schedule_id  IS NOT NULL AND quotation_id IS NULL)
    OR (booking_type = 'cargo'     AND quotation_id IS NOT NULL AND schedule_id  IS NULL)
  ),
  CONSTRAINT chk_bookings_amount CHECK (total_amount >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 9. passenger_booking_details  (1-to-1 extension of bookings)
--    booking_id is BOTH the primary key and the foreign key.
--    ON DELETE CASCADE: details have no meaning without their booking.
-- ---------------------------------------------------------------------
CREATE TABLE passenger_booking_details (
  booking_id       INT           NOT NULL,
  number_of_seats  INT           NOT NULL,
  passenger_name   VARCHAR(120)  NULL,              -- only if different from the account holder
  special_request  VARCHAR(255)  NULL,
  PRIMARY KEY (booking_id),
  CONSTRAINT fk_pbd_booking
    FOREIGN KEY (booking_id) REFERENCES bookings (booking_id) ON DELETE CASCADE,
  CONSTRAINT chk_pbd_seats CHECK (number_of_seats > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 10. payments  (one booking can have several payment attempts)
-- ---------------------------------------------------------------------
CREATE TABLE payments (
  payment_id          INT            NOT NULL AUTO_INCREMENT,
  booking_id          INT            NOT NULL,
  provider            VARCHAR(50)    NOT NULL,      -- e.g. flutterwave, dpo, pesapal
  provider_reference  VARCHAR(150)   NOT NULL,      -- gateway transaction reference
  amount              DECIMAL(10,2)  NOT NULL,
  currency            CHAR(3)        NOT NULL DEFAULT 'ZMW',
  payment_status      ENUM('pending','successful','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  created_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at             DATETIME       NULL,          -- set ONLY after the gateway result is verified
  PRIMARY KEY (payment_id),
  UNIQUE KEY uq_payments_provider_ref (provider_reference),
  KEY idx_payments_booking (booking_id),
  CONSTRAINT fk_payments_booking
    FOREIGN KEY (booking_id) REFERENCES bookings (booking_id),
  CONSTRAINT chk_payments_amount CHECK (amount > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 11. payment_webhook_events  (stops the same gateway notification
--     from being processed twice = idempotency)
-- ---------------------------------------------------------------------
CREATE TABLE payment_webhook_events (
  event_id           INT           NOT NULL AUTO_INCREMENT,
  provider           VARCHAR(50)   NOT NULL,
  provider_event_id  VARCHAR(150)  NOT NULL,
  payment_id         INT           NULL,
  received_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id),
  UNIQUE KEY uq_webhook_provider_event (provider, provider_event_id),
  CONSTRAINT fk_webhook_payment
    FOREIGN KEY (payment_id) REFERENCES payments (payment_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 12. booking_status_history  (audit trail of every status change)
-- ---------------------------------------------------------------------
CREATE TABLE booking_status_history (
  history_id   INT  NOT NULL AUTO_INCREMENT,
  booking_id   INT  NOT NULL,
  old_status   ENUM('pending','accepted','rejected','confirmed','in_transit','completed','cancelled') NULL,
  new_status   ENUM('pending','accepted','rejected','confirmed','in_transit','completed','cancelled') NOT NULL,
  changed_by   INT  NULL,                           -- user who made the change
  changed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (history_id),
  KEY idx_history_booking (booking_id),
  CONSTRAINT fk_history_booking
    FOREIGN KEY (booking_id) REFERENCES bookings (booking_id),
  CONSTRAINT fk_history_user
    FOREIGN KEY (changed_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 13. complaints  (FR18 - complaints and feedback, reviewed by admin)
-- ---------------------------------------------------------------------
CREATE TABLE complaints (
  complaint_id      INT           NOT NULL AUTO_INCREMENT,
  user_id           INT           NOT NULL,         -- who complained
  booking_id        INT           NULL,             -- optional: about a booking
  operator_id       INT           NULL,             -- optional: about an operator
  subject           VARCHAR(150)  NOT NULL,
  message           TEXT          NOT NULL,
  complaint_status  ENUM('open','under_review','resolved','dismissed') NOT NULL DEFAULT 'open',
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (complaint_id),
  KEY idx_complaints_status (complaint_status),
  CONSTRAINT fk_complaints_user
    FOREIGN KEY (user_id)     REFERENCES users (user_id),
  CONSTRAINT fk_complaints_booking
    FOREIGN KEY (booking_id)  REFERENCES bookings (booking_id)   ON DELETE SET NULL,
  CONSTRAINT fk_complaints_operator
    FOREIGN KEY (operator_id) REFERENCES operators (operator_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- OPTIONAL (run separately, once, after the tables exist):
-- a limited MySQL account for your PHP code, instead of using root.
-- Change the password before running.
--
-- CREATE USER 'zamumove_app'@'localhost' IDENTIFIED BY 'ChangeThisPassword!';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON zamumove.* TO 'zamumove_app'@'localhost';
-- FLUSH PRIVILEGES;
-- =====================================================================
