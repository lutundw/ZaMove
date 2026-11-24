<?php
declare(strict_types=1);
/**
 * Migration 005 - durable store for real-time events (Lab 8, Task 2).
 *
 * One row per RECIPIENT of an event. Writing the row is what makes an event "real": the WebSocket daemon
 * pushes it to the recipient if they are connected, and a user who was offline fetches it on reconnect
 * (hello {last_event_id}) because event_id only ever grows.
 *
 *   event_id    AUTO_INCREMENT: the cursor clients remember ("last_event_id")
 *   user_id     the recipient (FK users, rows vanish with the user)
 *   booking_id  the booking the event is about (FK bookings), NULL for system events
 *   event_type  booking.created | booking.status | booking.message
 *   payload     JSON text (max 4 KB, validated before it is stored)
 *   idx_events_user_cursor (user_id, event_id) answers "everything for me after N" with one index range scan.
 */
return [
    'up' => function (Schema $s): void {
        $s->createTable('realtime_events', function (Blueprint $t) {
            $t->id('event_id');
            $t->integer('user_id');
            $t->integer('booking_id')->nullable();
            $t->string('event_type', 40);
            $t->text('payload');
            $t->datetime('created_at')->defaultNow();
            $t->foreign('user_id')->references('users', 'user_id')->onDelete('cascade');
            $t->foreign('booking_id')->references('bookings', 'booking_id')->onDelete('cascade');
            $t->index(['user_id', 'event_id'], 'idx_events_user_cursor');
        });
    },
    'down' => function (Schema $s): void {
        $s->dropTable('realtime_events');
    },
];
