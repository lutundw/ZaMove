<?php
declare(strict_types=1);
/**
 * Migration 001 - the core Zamove tables (same design as schema.sql).
 * Parents are created before children; `down` drops children first.
 * Performance indexes are NOT created here: migration 004 adds them, so the effect of an index
 * can be measured before and after (Lab 7, Task 3). Foreign keys and unique keys stay here
 * because they are integrity rules, not tuning.
 */
$statuses = ['pending', 'accepted', 'rejected', 'confirmed', 'in_transit', 'completed', 'cancelled'];

return [
    'up' => function (Schema $s) use ($statuses): void {
        $s->createTable('users', function (Blueprint $t) {
            $t->id('user_id');
            $t->string('full_name', 120);
            $t->string('email', 150);
            $t->string('phone', 25);
            $t->string('password_hash', 255);
            $t->enum('role', ['customer', 'operator', 'admin'])->default('customer');
            $t->enum('account_status', ['pending', 'active', 'suspended'])->default('active');
            $t->datetime('created_at')->defaultNow();
            $t->unique(['email'], 'uq_users_email');
        });
        $s->createTable('operators', function (Blueprint $t) {
            $t->id('operator_id');
            $t->integer('user_id');
            $t->string('business_name', 150);
            $t->enum('business_type', ['bus', 'cargo', 'both']);
            $t->string('business_address', 255);
            $t->enum('verification_status', ['pending', 'approved', 'rejected'])->default('pending');
            $t->integer('approved_by')->nullable();
            $t->unique(['user_id'], 'uq_operators_user');
            $t->foreign('user_id')->references('users', 'user_id');
            $t->foreign('approved_by')->references('users', 'user_id')->onDelete('SET NULL');
        });
        $s->createTable('vehicles', function (Blueprint $t) {
            $t->id('vehicle_id');
            $t->integer('operator_id');
            $t->string('registration_number', 30);
            $t->string('vehicle_type', 50);
            $t->integer('seat_capacity')->nullable();
            $t->decimal('cargo_capacity_kg', 10, 2)->nullable();
            $t->enum('vehicle_status', ['active', 'inactive', 'maintenance'])->default('active');
            $t->unique(['registration_number'], 'uq_vehicles_registration');
            $t->foreign('operator_id')->references('operators', 'operator_id');
            $t->check('chk_vehicles_seats', 'seat_capacity IS NULL OR seat_capacity > 0');
            $t->check('chk_vehicles_cargo', 'cargo_capacity_kg IS NULL OR cargo_capacity_kg > 0');
        });
        $s->createTable('routes', function (Blueprint $t) {
            $t->id('route_id');
            $t->integer('operator_id');
            $t->string('origin', 120);
            $t->string('destination', 120);
            $t->decimal('distance_km', 8, 2)->nullable();
            $t->decimal('base_fare', 10, 2);
            $t->enum('route_status', ['active', 'inactive'])->default('active');
            $t->foreign('operator_id')->references('operators', 'operator_id');
            $t->check('chk_routes_places', 'origin <> destination');
            $t->check('chk_routes_fare', 'base_fare >= 0');
        });
        $s->createTable('schedules', function (Blueprint $t) {
            $t->id('schedule_id');
            $t->integer('route_id');
            $t->integer('vehicle_id');
            $t->datetime('departure_at');
            $t->datetime('arrival_at')->nullable();
            $t->decimal('fare_per_seat', 10, 2);
            $t->integer('total_seats');
            $t->enum('schedule_status', ['scheduled', 'cancelled', 'completed'])->default('scheduled');
            $t->foreign('route_id')->references('routes', 'route_id');
            $t->foreign('vehicle_id')->references('vehicles', 'vehicle_id');
            $t->check('chk_schedules_seats', 'total_seats > 0');
            $t->check('chk_schedules_fare', 'fare_per_seat >= 0');
            $t->check('chk_schedules_times', 'arrival_at IS NULL OR arrival_at > departure_at');
        });
        $s->createTable('cargo_requests', function (Blueprint $t) {
            $t->id('cargo_request_id');
            $t->integer('customer_id');
            $t->string('pickup_location', 255);
            $t->string('delivery_location', 255);
            $t->text('cargo_description');
            $t->decimal('weight_kg', 10, 2)->nullable();
            $t->datetime('requested_pickup_at')->nullable();
            $t->enum('request_status', ['open', 'quoted', 'accepted', 'closed', 'cancelled'])->default('open');
            $t->datetime('created_at')->defaultNow();
            $t->foreign('customer_id')->references('users', 'user_id');
            $t->check('chk_cargo_weight', 'weight_kg IS NULL OR weight_kg > 0');
        });
        $s->createTable('quotations', function (Blueprint $t) {
            $t->id('quotation_id');
            $t->integer('cargo_request_id');
            $t->integer('operator_id');
            $t->decimal('quoted_amount', 10, 2);
            $t->datetime('valid_until')->nullable();
            $t->enum('quotation_status', ['pending', 'accepted', 'rejected', 'expired'])->default('pending');
            $t->datetime('created_at')->defaultNow();
            $t->unique(['cargo_request_id', 'operator_id'], 'uq_quotation_request_operator');
            $t->foreign('cargo_request_id')->references('cargo_requests', 'cargo_request_id');
            $t->foreign('operator_id')->references('operators', 'operator_id');
            $t->check('chk_quotations_amount', 'quoted_amount > 0');
        });
        $s->createTable('bookings', function (Blueprint $t) use ($statuses) {
            $t->id('booking_id');
            $t->string('booking_reference', 30);
            $t->integer('customer_id');
            $t->integer('operator_id');
            $t->enum('booking_type', ['passenger', 'cargo']);
            $t->integer('schedule_id')->nullable();
            $t->integer('quotation_id')->nullable();
            $t->enum('booking_status', $statuses)->default('pending');
            $t->decimal('total_amount', 10, 2);
            $t->char('currency', 3)->default('ZMW');
            $t->datetime('hold_expires_at')->nullable();
            $t->datetime('created_at')->defaultNow();
            $t->unique(['booking_reference'], 'uq_bookings_reference');
            $t->unique(['quotation_id'], 'uq_bookings_quotation');
            $t->foreign('customer_id')->references('users', 'user_id');
            $t->foreign('operator_id')->references('operators', 'operator_id');
            $t->foreign('schedule_id')->references('schedules', 'schedule_id');
            $t->foreign('quotation_id')->references('quotations', 'quotation_id');
            $t->check('chk_bookings_type', "(booking_type = 'passenger' AND schedule_id IS NOT NULL AND quotation_id IS NULL) OR (booking_type = 'cargo' AND quotation_id IS NOT NULL AND schedule_id IS NULL)");
            $t->check('chk_bookings_amount', 'total_amount >= 0');
        });
        $s->createTable('passenger_booking_details', function (Blueprint $t) {
            $t->integer('booking_id');
            $t->integer('number_of_seats');
            $t->string('passenger_name', 120)->nullable();
            $t->string('special_request', 255)->nullable();
            $t->primary(['booking_id']);
            $t->foreign('booking_id')->references('bookings', 'booking_id')->onDelete('CASCADE');
            $t->check('chk_pbd_seats', 'number_of_seats > 0');
        });
        $s->createTable('payments', function (Blueprint $t) {
            $t->id('payment_id');
            $t->integer('booking_id');
            $t->string('provider', 50);
            $t->string('provider_reference', 150);
            $t->decimal('amount', 10, 2);
            $t->char('currency', 3)->default('ZMW');
            $t->enum('payment_status', ['pending', 'successful', 'failed', 'cancelled', 'refunded'])->default('pending');
            $t->datetime('created_at')->defaultNow();
            $t->datetime('paid_at')->nullable();
            $t->unique(['provider_reference'], 'uq_payments_provider_ref');
            $t->foreign('booking_id')->references('bookings', 'booking_id');
            $t->check('chk_payments_amount', 'amount > 0');
        });
        $s->createTable('payment_webhook_events', function (Blueprint $t) {
            $t->id('event_id');
            $t->string('provider', 50);
            $t->string('provider_event_id', 150);
            $t->integer('payment_id')->nullable();
            $t->datetime('received_at')->defaultNow();
            $t->unique(['provider', 'provider_event_id'], 'uq_webhook_provider_event');
            $t->foreign('payment_id')->references('payments', 'payment_id')->onDelete('SET NULL');
        });
        $s->createTable('booking_status_history', function (Blueprint $t) use ($statuses) {
            $t->id('history_id');
            $t->integer('booking_id');
            $t->enum('old_status', $statuses)->nullable();
            $t->enum('new_status', $statuses);
            $t->integer('changed_by')->nullable();
            $t->datetime('changed_at')->defaultNow();
            $t->foreign('booking_id')->references('bookings', 'booking_id');
            $t->foreign('changed_by')->references('users', 'user_id')->onDelete('SET NULL');
        });
        $s->createTable('complaints', function (Blueprint $t) {
            $t->id('complaint_id');
            $t->integer('user_id');
            $t->integer('booking_id')->nullable();
            $t->integer('operator_id')->nullable();
            $t->string('subject', 150);
            $t->text('message');
            $t->enum('complaint_status', ['open', 'under_review', 'resolved', 'dismissed'])->default('open');
            $t->datetime('created_at')->defaultNow();
            $t->foreign('user_id')->references('users', 'user_id');
            $t->foreign('booking_id')->references('bookings', 'booking_id')->onDelete('SET NULL');
            $t->foreign('operator_id')->references('operators', 'operator_id')->onDelete('SET NULL');
        });
    },

    'down' => function (Schema $s): void {
        foreach (['complaints', 'booking_status_history', 'payment_webhook_events', 'payments', 'passenger_booking_details',
                  'bookings', 'quotations', 'cargo_requests', 'schedules', 'routes', 'vehicles', 'operators', 'users'] as $t) {
            $s->dropTable($t);
        }
    },
];
