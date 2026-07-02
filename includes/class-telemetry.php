<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Telemetry — intentionally disabled in Dox Feedback.
 *
 * The upstream project shipped an opt-in weekly "ping" to a vendor endpoint.
 * Dox Feedback removes that entirely: it never schedules a cron event, never
 * builds a payload, and never makes an outbound request. This stub only keeps
 * the public method names so the rest of the plugin needs no changes; every
 * method is a no-op and is_enabled() is always false.
 */
final class DXF_Telemetry {

    public const CRON_HOOK = 'dxf_telemetry_ping';

    public function __construct() {}

    public static function is_enabled(): bool {
        return false;
    }

    public static function enable(): void {}

    public static function disable(): void {}
}
