-- Run ONCE in phpMyAdmin (database zamumove, tab SQL). Safe to run twice.
-- Your test booking ZM-TEST-001 has no passenger row, so the new pages would show "0 seats".
INSERT IGNORE INTO passenger_booking_details (booking_id, number_of_seats, passenger_name)
VALUES (1, 2, 'Emmanuel');
-- Make sure the existing bus has a vehicle type the new "Add vehicle" list knows (cosmetic).
UPDATE vehicles SET vehicle_type = 'Standard' WHERE vehicle_type = 'Bus';
