<?php
/**
 * Dox Feedback Cache — keep reviewer-facing responses out of page caches.
 *
 * Review links are dynamic, per-session surfaces: the reviewer landing routes,
 * and any page being actively reviewed (which renders the comment overlay only
 * when a valid session cookie is present). A full-page cache (WP Rocket,
 * LiteSpeed, W3 Total Cache, WP Super Cache, Cache Enabler, SiteGround,
 * Cloudflare/edge, server FastCGI, …) that stores or serves these responses
 * breaks reviews in two ways:
 *
 *   1. A reviewer opens a share link in a fresh browser and the cache serves a
 *      stale copy of the page WITHOUT the overlay — "the link does nothing".
 *   2. The agency deactivates a review but the cached page keeps showing it.
 *
 * This class closes both holes with a layered, plugin-agnostic strategy:
 *
 *   - Storage bypass: define DONOTCACHEPAGE (+ object/db/minify) and send
 *     no-store headers on every reviewer-context request, so caches never
 *     STORE these responses. Honoured by nearly every WP cache plugin.
 *   - Serve bypass: register the reviewer URL prefix + the reviewer session
 *     cookies with each major plugin's documented exclusion filter, so caches
 *     don't SERVE a stored copy when a reviewer session is in play. (add_filter
 *     on a hook a plugin doesn't define is a harmless no-op, so registering for
 *     all of them is safe regardless of which one is installed.)
 *   - Purge on change: when a review is activated / deactivated / reopened /
 *     deleted / expired, flush the cached copies of its pages and reviewer URLs.
 *   - One-shot full flush on activate/upgrade, so any copy cached before this
 *     code shipped is cleared once.
 *
 * @since 1.0.7
 */

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

// Read-only cache-control logic. Every superglobal access below is a routing
// signal (does this request belong to a reviewer?), never a state mutation, so
// nonce verification does not apply; all values are sanitised on read.
// phpcs:disable WordPress.Security.NonceVerification.Recommended

final class DXF_Cache {

    /** Leading path segment of the reviewer landing/activation/item routes. */
    private const REVIEW_URI_SEGMENT = 'dox-feedback';

    /** Option flag gating the one-shot third-party config refresh + flush. */
    private const CONFIG_OPTION = 'dxf_cache_integration_v';
    private const CONFIG_VERSION = 1;

    public function __construct() {
        // --- Storage-side bypass for the current request -------------------
        if ( self::is_reviewer_context() ) {
            self::declare_uncacheable();
            // Re-assert once WordPress is fully loaded: some plugins (LiteSpeed)
            // only have their runtime no-cache hook ready by template_redirect.
            add_action('template_redirect', [self::class, 'declare_uncacheable'], 0);
            add_action('send_headers',      [self::class, 'send_nostore_headers']);
        }

        // --- Serve-side exclusion registered with each major cache plugin ---
        $this->register_plugin_exclusions();

        // --- Purge on review lifecycle changes -----------------------------
        add_action('dxf_review_cache_dirty', [self::class, 'on_review_dirty']);

        // --- One-shot config refresh + flush after install/upgrade ---------
        add_action('admin_init', [self::class, 'maybe_refresh_integrations']);
    }

    // ---------------------------------------------------------------------
    // Context detection
    // ---------------------------------------------------------------------

    /**
     * Is the current request part of a reviewer's journey? True when a reviewer
     * session cookie is present, the URL is a /dox-feedback/* or token route,
     * or a bridge/cache-bust query arg is set.
     */
    public static function is_reviewer_context(): bool {
        foreach ( self::cookie_names() as $cookie ) {
            if ( ! empty($_COOKIE[$cookie]) ) {
                return true;
            }
        }

        $uri  = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $path = $uri !== '' ? (string) wp_parse_url($uri, PHP_URL_PATH) : '';
        if ( $path !== '' ) {
            if ( strpos($path, '/' . self::REVIEW_URI_SEGMENT . '/') !== false
                || rtrim($path, '/') === '/' . self::REVIEW_URI_SEGMENT ) {
                return true;
            }
            if ( class_exists('DXF_Review_Mode')
                && strpos($path, '/' . DXF_Review_Mode::TOKEN_QUERY_VAR . '/') !== false ) {
                return true;
            }
        }

        if ( ! empty($_GET['dxf_review']) || ! empty($_GET['dxf_session']) || ! empty($_GET['dxf_token']) ) {
            return true;
        }

        return false;
    }

    /** Cookie names that mark an active reviewer session. */
    private static function cookie_names(): array {
        return [ 'dxf_review_session', 'dxf_reviewing' ];
    }

    // ---------------------------------------------------------------------
    // Storage-side bypass
    // ---------------------------------------------------------------------

    /**
     * Tell every cache layer not to store this response. The DONOTCACHE*
     * constants are honoured by WP Super Cache, W3TC, WP Rocket, Comet Cache,
     * SG Optimizer, Surge and others; w3tc_can_cache + the LiteSpeed action
     * cover the two big plugins that also want a runtime signal.
     */
    public static function declare_uncacheable(): void {
        foreach ( [ 'DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB', 'DONOTMINIFY' ] as $const ) {
            if ( ! defined($const) ) {
                define($const, true);
            }
        }
        add_filter('w3tc_can_cache', '__return_false', 99);
        // LiteSpeed Cache runtime "do not cache this request".
        do_action('litespeed_control_set_nocache', 'dxf reviewer session');
    }

    /** Strong no-store headers (also respected by Cloudflare/edge + proxies). */
    public static function send_nostore_headers(): void {
        if ( headers_sent() ) {
            return;
        }
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
        header('Pragma: no-cache', true);
    }

    // ---------------------------------------------------------------------
    // Serve-side exclusion (per-plugin)
    // ---------------------------------------------------------------------

    /**
     * Register the reviewer URL prefix + session cookies with the documented
     * cache-exclusion filters of the major plugins. Each add_filter is inert
     * if its plugin isn't installed, so registering for all of them is safe.
     */
    private function register_plugin_exclusions(): void {
        $cookies   = self::cookie_names();
        $uri_regex = '/' . self::REVIEW_URI_SEGMENT . '/(.*)';

        // WP Rocket — reject by URI and by cookie. (Persisted to Rocket's
        // config file by maybe_refresh_integrations(), since Rocket reads these
        // at config-generation time, not per request.)
        add_filter('rocket_cache_reject_uri', static function ( $uris ) use ( $uri_regex ) {
            $uris   = is_array($uris) ? $uris : [];
            $uris[] = $uri_regex;
            return $uris;
        });
        add_filter('rocket_cache_reject_cookies', static function ( $list ) use ( $cookies ) {
            return array_merge( is_array($list) ? $list : [], $cookies );
        });

        // WP Super Cache — add the reviewer cookies to its cache-busting list.
        add_filter('wpsc_cookies', static function ( $list ) use ( $cookies ) {
            return array_merge( is_array($list) ? $list : [], $cookies );
        });

        // LiteSpeed Cache — vary the cache on the reviewer cookies so a reviewer
        // is never served the anonymous (overlay-less) copy.
        add_filter('litespeed_vary_cookies', static function ( $list ) use ( $cookies ) {
            return array_merge( is_array($list) ? $list : [], $cookies );
        });

        // Cache Enabler — bypass when this is a reviewer request.
        add_filter('cache_enabler_bypass_cache', static function ( $bypass ) {
            return self::is_reviewer_context() ? true : $bypass;
        });

        // SiteGround Optimizer (SuperCacher) — same bypass.
        add_filter('sgo_bypass_cache', static function ( $bypass ) {
            return self::is_reviewer_context() ? true : $bypass;
        });
    }

    // ---------------------------------------------------------------------
    // Purge on review change
    // ---------------------------------------------------------------------

    /**
     * Fired (via do_action('dxf_review_cache_dirty', $id)) whenever a review
     * is activated, deactivated, reopened, expired or deleted. Purges the cached
     * copies of every page in the review plus the reviewer landing URL, so the
     * next visit re-renders against current state.
     */
    public static function on_review_dirty( int $review_id ): void {
        if ( ! class_exists('DXF_Review') ) {
            return;
        }
        $review = DXF_Review::get($review_id);
        if ( ! $review ) {
            return;
        }

        $urls = [];
        foreach ( DXF_Review::resolve_post_ids($review) as $pid ) {
            $pid = (int) $pid;
            self::purge_post($pid);
            $url = get_permalink($pid);
            if ( $url ) {
                $urls[] = $url;
            }
        }
        if ( ! empty($review['slug']) ) {
            $urls[] = DXF_Review::landing_url((string) $review['slug']);
        }
        foreach ( array_unique($urls) as $url ) {
            self::purge_url($url);
        }
    }

    /** Purge a single post's cached page across whichever plugin is active. */
    private static function purge_post( int $post_id ): void {
        if ( $post_id <= 0 ) {
            return;
        }
        if ( function_exists('rocket_clean_post') )            { rocket_clean_post($post_id); }
        if ( function_exists('w3tc_flush_post') )              { w3tc_flush_post($post_id); }
        if ( function_exists('wp_cache_post_change') )         { wp_cache_post_change($post_id); } // WP Super Cache
        if ( function_exists('wpfc_clear_post_cache_by_id') )  { wpfc_clear_post_cache_by_id($post_id); } // WP Fastest Cache
        if ( is_callable([ 'Cache_Enabler', 'clear_page_cache_by_post' ]) ) {
            Cache_Enabler::clear_page_cache_by_post($post_id);
        }
        // LiteSpeed — runtime purge by post id.
        do_action('litespeed_purge_post', $post_id);
    }

    /** Purge a single URL's cached copy across whichever plugin is active. */
    private static function purge_url( string $url ): void {
        if ( $url === '' ) {
            return;
        }
        if ( function_exists('rocket_clean_files') ) { rocket_clean_files($url); }
        // LiteSpeed — runtime purge by URL.
        do_action('litespeed_purge_url', $url);
    }

    // ---------------------------------------------------------------------
    // One-shot integration refresh (activation / upgrade)
    // ---------------------------------------------------------------------

    /**
     * Run once per CONFIG_VERSION bump: persist our WP Rocket exclusions into
     * its config file (Rocket only reads the reject filters at generation time)
     * and clear every cache once, so any page cached before this layer shipped
     * is rebuilt. Cheap and idempotent — guarded by an option flag.
     */
    public static function maybe_refresh_integrations(): void {
        if ( (int) get_option(self::CONFIG_OPTION, 0) >= self::CONFIG_VERSION ) {
            return;
        }
        self::refresh_rocket_config();
        self::flush_all();
        update_option(self::CONFIG_OPTION, self::CONFIG_VERSION, false);
    }

    /** Force WP Rocket to rewrite its config so our reject rules take effect. */
    private static function refresh_rocket_config(): void {
        if ( function_exists('rocket_generate_config_file') ) { rocket_generate_config_file(); }
        if ( function_exists('flush_rocket_htaccess') )       { flush_rocket_htaccess(); }
    }

    /**
     * Clear every cache once. Used on activation/upgrade and exposed for the
     * deactivation hook. Calls each plugin's documented full-flush entry point;
     * all are guarded, so only the installed plugin(s) act.
     */
    public static function flush_all(): void {
        if ( function_exists('rocket_clean_domain') )   { rocket_clean_domain(); }
        if ( function_exists('w3tc_flush_all') )        { w3tc_flush_all(); }
        if ( function_exists('wp_cache_clear_cache') )  { wp_cache_clear_cache(); }      // WP Super Cache
        if ( function_exists('wpfc_clear_all_cache') )  { wpfc_clear_all_cache(true); }  // WP Fastest Cache
        if ( is_callable([ 'Cache_Enabler', 'clear_complete_cache' ]) ) {
            Cache_Enabler::clear_complete_cache();
        }
        if ( function_exists('sg_cachepress_purge_cache') ) { sg_cachepress_purge_cache(); }
        do_action('litespeed_purge_all'); // LiteSpeed
    }
}
