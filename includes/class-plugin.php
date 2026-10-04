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

    // -------------------------------------------------------------------------
    // Client IP
    // -------------------------------------------------------------------------

    /** Cloudflare edge ranges (https://www.cloudflare.com/ips/). */
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * Visitor IP for rate limits and audit hashes. REMOTE_ADDR is the only
     * value nobody can forge, so it wins; CF-Connecting-IP is trusted only
     * when the request really comes from a Cloudflare edge. Otherwise a site
     * behind Cloudflare (without real-IP restore) would put every visitor in
     * the same bucket, and a site not behind it would let anyone dodge the
     * limit by sending the header.
     */
    public static function client_ip(): string {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if ( isset($_SERVER['HTTP_CF_CONNECTING_IP']) && self::in_ranges($ip, self::CLOUDFLARE_RANGES) ) {
            $cf = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
            if ( filter_var($cf, FILTER_VALIDATE_IP) ) {
                $ip = $cf;
            }
        }
        return $ip;
    }

    private static function in_ranges(string $ip, array $ranges): bool {
        $bin = @inet_pton($ip);
        if ( $bin === false ) {
            return false;
        }
        foreach ( $ranges as $range ) {
            [ $net, $bits ] = explode('/', $range);
            $net_bin = inet_pton($net);
            if ( strlen($net_bin) !== strlen($bin) ) {
                continue;
            }
            $bits  = (int) $bits;
            $bytes = intdiv($bits, 8);
            if ( substr($bin, 0, $bytes) !== substr($net_bin, 0, $bytes) ) {
                continue;
            }
            $rest = $bits % 8;
            if ( $rest === 0 ) {
                return true;
            }
            $mask = chr((0xFF << (8 - $rest)) & 0xFF);
            if ( ($bin[$bytes] & $mask) === ($net_bin[$bytes] & $mask) ) {
                return true;
            }
        }
        return false;
    }
}
