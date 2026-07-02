<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

final class DXF_Plugin {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function __construct() {}

    private function init(): void {
        $this->maybe_migrate();
        // Translations: WordPress.org loads them automatically for wp.org-
        // hosted plugins since WP 4.6, so no manual load_plugin_textdomain.
        $this->load_modules();

        if ( is_admin() ) {
            new DXF_Admin();
            new DXF_Welcome();
            new DXF_Whats_New();
            new DXF_Pins_Dashboard();
        }
    }

    private function maybe_migrate(): void {
        $installed = get_option('dxf_db_version', '0.0.0');
        if ( version_compare($installed, DXF_DB_VERSION, '<') ) {
            DXF_Migrations::run($installed);
            update_option('dxf_db_version', DXF_DB_VERSION);
        }
    }

    private function load_modules(): void {
        // First, so DONOTCACHEPAGE is declared for reviewer requests as early
        // as possible (before any output-buffer cache decision is made).
        new DXF_Cache();
        new DXF_Comments();
        new DXF_Review_Mode();
        new DXF_Reviews();
        new DXF_Approvals();
        // All-in-one: wire on multi-page + email-reviewer features and register
        // the plugin as fully unlocked (no separate add-on, no licence server).
        new DXF_Features();

        // First client sign-off → flag the one-time wp.org review ask
        // (rendered by DXF_Admin). Registered here, not in DXF_Admin:
        // approvals arrive on PUBLIC (nopriv) AJAX requests where the admin
        // class is never constructed.
        add_action(DXF_Events::HOOK, static function ( string $event ): void {
            if ( $event === 'approval.created' && get_option('dxf_review_ask_state', '') === '' ) {
                update_option('dxf_review_ask_state', 'due', false);
            }
        });
    }

    // -------------------------------------------------------------------------
    // Activation / deactivation
    // -------------------------------------------------------------------------

    public static function activate(): void {
        DXF_Migrations::run('0.0.0');
        update_option('dxf_db_version', DXF_DB_VERSION);

        // Flush rewrite rules for review-mode pretty URLs.
        flush_rewrite_rules();

        // Land the user on the guided Getting Started page on first activation
        // (one-shot; the redirect itself guards against bulk/network activates).
        DXF_Welcome::arm_redirect();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
        // Clear scheduled cron hooks.
        wp_clear_scheduled_hook(DXF_Telemetry::CRON_HOOK);
        wp_clear_scheduled_hook(DXF_Reviews::CRON_HOOK);
        // Clear any reviewer pages a page-cache plugin stored while active, so
        // we don't leave stale overlay markup behind.
        if ( class_exists('DXF_Cache') ) {
            DXF_Cache::flush_all();
        }
    }
}
