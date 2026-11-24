<?php
declare(strict_types=1);
/**
 * Migration 004 - B-Tree indexes chosen from EXPLAIN output (Lab 7, Task 3).
 *
 * Column order rule for a composite index: equality columns first, then the range column, then the
 * ORDER BY column. The first columns narrow the search; the last ones return rows already sorted.
 *
 *   idx_routes_search            (origin, destination, route_status)   route search: WHERE origin=? AND destination=?
 *   idx_schedules_route_departure (route_id, departure_at)             join route -> schedules in date order
 *   idx_schedules_search         (route_id, schedule_status, departure_at, seats_available)  covering index for the search
 *   idx_bookings_customer_status  (customer_id, booking_status, created_at)  "my bookings", filtered and newest first
 *   idx_bookings_schedule_status  (schedule_id, booking_status)        seats sold / FK join schedule -> bookings
 *   idx_payments_booking          (booking_id)                         FK join booking -> payments
 *   idx_history_booking           (booking_id)                         FK join booking -> history
 * bookings.booking_reference already has a UNIQUE index (uq_bookings_reference) from migration 001.
 */
return [
    'up' => function (Schema $s): void {
        $s->createIndex('routes',    'idx_routes_search',             ['origin', 'destination', 'route_status']);
        $s->createIndex('schedules', 'idx_schedules_route_departure', ['route_id', 'departure_at']);
        $s->createIndex('schedules', 'idx_schedules_search',          ['route_id', 'schedule_status', 'departure_at', 'seats_available']);
        $s->createIndex('bookings',  'idx_bookings_customer_status',  ['customer_id', 'booking_status', 'created_at']);
        $s->createIndex('bookings',  'idx_bookings_schedule_status',  ['schedule_id', 'booking_status']);
        $s->createIndex('payments',  'idx_payments_booking',          ['booking_id']);
        $s->createIndex('booking_status_history', 'idx_history_booking', ['booking_id']);
    },
    'down' => function (Schema $s): void {
        $s->dropIndex('booking_status_history', 'idx_history_booking');
        $s->dropIndex('payments',  'idx_payments_booking');
        $s->dropIndex('bookings',  'idx_bookings_schedule_status');
        $s->dropIndex('bookings',  'idx_bookings_customer_status');
        $s->dropIndex('schedules', 'idx_schedules_search');
        $s->dropIndex('schedules', 'idx_schedules_route_departure');
        $s->dropIndex('routes',    'idx_routes_search');
    },
];
