-- Lab 8 test tables (SQLite). XAMPP gets the same tables from migrations/005 + schema.sql.
CREATE TABLE booking_status_history (
  history_id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER NOT NULL REFERENCES bookings(booking_id),
  old_status TEXT, new_status TEXT NOT NULL, changed_by INTEGER, changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE realtime_events (
  event_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
  booking_id INTEGER REFERENCES bookings(booking_id) ON DELETE CASCADE, event_type VARCHAR(40) NOT NULL,
  payload TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE INDEX idx_events_user_cursor ON realtime_events (user_id, event_id);
