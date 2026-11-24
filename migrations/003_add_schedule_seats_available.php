<?php
declare(strict_types=1);
/**
 * Migration 003 - a running seat counter on every schedule.
 *
 * Why: counting booked seats on every booking attempt (SUM over passenger_booking_details) is slow and
 * cannot be locked safely. A counter column can be changed in ONE atomic statement:
 *     UPDATE schedules SET seats_available = seats_available - ? WHERE schedule_id = ? AND seats_available >= ?
 * The CHECK constraint is the database's own last line of defence: it can never go below 0.
 * The back-fill gives existing rows the right value (total seats minus seats on live bookings).
 */
return [
    'up' => function (Schema $s): void {
        $s->addColumn('schedules', fn(Blueprint $t) => $t->integer('seats_available')->default(0)->check('seats_available >= 0'));
        $s->statement(
            "UPDATE schedules SET seats_available = total_seats - COALESCE((
                SELECT SUM(d.number_of_seats)
                  FROM bookings b JOIN passenger_booking_details d ON d.booking_id = b.booking_id
                 WHERE b.schedule_id = schedules.schedule_id AND b.booking_status NOT IN ('cancelled','rejected')), 0)"
        );
    },
    'down' => function (Schema $s): void {
        $s->dropColumn('schedules', 'seats_available');
    },
];
