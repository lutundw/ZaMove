<?php
declare(strict_types=1);
/** Migration 002 - tables added in Labs 4 and 5 (server-side sessions and rate-limit counters). */
return [
    'up' => function (Schema $s): void {
        $s->createTable('sessions', function (Blueprint $t) {
            $t->char('session_id_hash', 64);                    // SHA-256 of the cookie value, never the value itself
            $t->integer('user_id');
            $t->datetime('created_at');
            $t->datetime('last_seen_at');
            $t->datetime('expires_at');
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->primary(['session_id_hash']);
            $t->foreign('user_id')->references('users', 'user_id')->onDelete('CASCADE');
            $t->index(['user_id'], 'idx_sessions_user');
            $t->index(['expires_at'], 'idx_sessions_expires');
        });
        $s->createTable('rate_limits', function (Blueprint $t) {
            $t->string('rl_key', 100);
            $t->integer('window_start');
            $t->integer('hits');
            $t->primary(['rl_key']);
        });
    },
    'down' => function (Schema $s): void {
        $s->dropTable('rate_limits');
        $s->dropTable('sessions');
    },
];
