<?php

namespace Config;

use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\FrameworkException;
use CodeIgniter\HotReloader\HotReloader;

/*
 * --------------------------------------------------------------------
 * Application Events
 * --------------------------------------------------------------------
 * Events allow you to tap into the execution of the program without
 * modifying or extending core files. This file provides a central
 * location to define your events, though they can always be added
 * at run-time, also, if needed.
 *
 * You create code that can execute by subscribing to events with
 * the 'on()' method. This accepts any form of callable, including
 * Closures, that will be executed when the event is triggered.
 *
 * Example:
 *      Events::on('create', [$myInstance, 'myMethod']);
 */

Events::on('pre_system', static function (): void {
    if (ENVIRONMENT !== 'testing') {
        $value = ini_get('zlib.output_compression');

        if (filter_var($value, FILTER_VALIDATE_BOOLEAN) || (int) $value > 0) {
            throw FrameworkException::forEnabledZlibOutputCompression();
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        ob_start(static fn ($buffer) => $buffer);
    }

    /*
     * --------------------------------------------------------------------
     * Debug Toolbar Listeners.
     * --------------------------------------------------------------------
     * If you delete, they will no longer be collected.
     */
    if (CI_DEBUG && ! is_cli()) {
        Events::on('DBQuery', 'CodeIgniter\Debug\Toolbar\Collectors\Database::collect');
        service('toolbar')->respond();
        // Hot Reload route - for framework use on the hot reloader.
        if (ENVIRONMENT === 'development') {
            service('routes')->get('__hot-reload', static function (): void {
                (new HotReloader())->run();
            });
        }
    }
});

/*
 * --------------------------------------------------------------------
 * Audit outbox auto-drain.
 * --------------------------------------------------------------------
 * In dev/demo there is no cron worker, so after each web response we
 * opportunistically drain pending `audit_outbox` rows into the
 * append-only `audit_events` chain (cooldown-gated, never CLI/tests).
 * This keeps the Audit reader near-real-time without a scheduler.
 */
Events::on('post_system', static function (): void {
    if (! is_cli()) {
        \Config\Services::auditAutoDrain()->maybeDrain();
        // Inventory audit fix: sweep for low-stock reorders so stock-outs
        // are caught even when nobody clicks "Run auto-check". Cooldown-
        // gated (30 min) so it is not a write per request.
        \Config\Services::reorderAutoCheck()->maybeRun();
        // Notification audit fix (2026-08-05): without this the outbox
        // rows written in-transaction (appointment.assigned, no_show, …)
        // pile up forever and the header bell stays empty. Cooldown-
        // gated (10s) so it is not a write per request.
        \Config\Services::notificationAutoDrain()->maybeDrain();
    }
});

