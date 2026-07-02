<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

// Custom-table data layer for review-mode tokens + approvals. All direct
// queries target {$wpdb->prefix}dxf_review_tokens / dxf_approvals,
// which we own. Object caching is avoided because tokens mutate on every
// reviewer hit (used / revoked / activated). Nonce checks for this module
// live in WP's own template_redirect flow + per-AJAX check_ajax_referer.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.Security.NonceVerification.Recommended

class DXF_Review_Mode {

    public  const TOKEN_QUERY_VAR = 'dxf_token';
    private const NONCE_ACTION    = 'dxf_review';
    public  const SESSION_COOKIE  = 'dxf_review_session'; // value = current review slug

    // Post meta key linking a page to its admin-bar "quick share" Review.
    // Stored as a single int — the DXF_Review row id. This is how
    // generate / revoke / "is there an active share link?" map the legacy
    // per-page surface onto the v0.16 Review object model. The legacy
    // dxf_review_tokens table is no longer written to by new shares,
    // but old tokens still resolve via the existing rewrite for back-compat.
    public const META_QUICK_SHARE_REVIEW = '_dxf_quick_share_review_id';

    public function __construct() {
        add_action('init',                          [$this, 'add_rewrite_var']);
        add_action('pre_get_posts',                 [$this, 'override_query_for_token']);
        add_action('pre_get_posts',                 [$this, 'allow_reviewer_draft_access']);
        add_action('template_redirect',             [$this, 'maybe_continue_review_session'], 1);
        add_action('template_redirect',             [$this, 'handle_review_request']);
        // Maintenance / Coming-soon bypass for valid review links (see methods below).
        add_filter('bricks/maintenance/should_apply',          [$this, 'bricks_maintenance_should_apply'], 10, 2);
        add_action('wp',                                       [$this, 'maybe_bypass_legacy_bricks_maintenance'], 8);
        add_filter('elementor/maintenance_mode/is_login_page', [$this, 'elementor_maintenance_is_login_page']);
        add_action('add_meta_boxes',                [$this, 'register_meta_box']);
        add_action('admin_enqueue_scripts',         [$this, 'enqueue_admin_assets']);
        add_action('admin_bar_menu',                [$this, 'add_admin_bar_node'], 99);
        add_action('wp_ajax_dxf_generate_review_link',          [$this, 'ajax_generate_link']);
        add_action('wp_ajax_dxf_revoke_review_link',            [$this, 'ajax_revoke_link']);
        add_action('wp_ajax_dxf_mark_review_complete',          [$this, 'ajax_mark_review_complete']);
        add_action('wp_ajax_nopriv_dxf_mark_review_complete',   [$this, 'ajax_mark_review_complete']);
        add_action('wp_ajax_dxf_mark_reviewed',                 [$this, 'ajax_mark_reviewed']);
        add_action('wp_ajax_nopriv_dxf_mark_reviewed',          [$this, 'ajax_mark_reviewed']);
        add_action('wp_ajax_dxf_review_done',                   [$this, 'ajax_review_done']);
        add_action('wp_ajax_nopriv_dxf_review_done',            [$this, 'ajax_review_done']);
        add_action('wp_ajax_dxf_review_done_all',               [$this, 'ajax_review_done_all']);
        add_action('wp_ajax_nopriv_dxf_review_done_all',        [$this, 'ajax_review_done_all']);
        add_action('wp_ajax_dxf_review_exit',                   [self::class, 'ajax_review_exit']);
        add_action('wp_ajax_nopriv_dxf_review_exit',            [self::class, 'ajax_review_exit']);
    }

    // -------------------------------------------------------------------------
    // Admin bar
    // -------------------------------------------------------------------------

    /**
     * Register the Dox Feedback root admin-bar node. Everything inside the popout
     * (link state, quick-review buttons, round info, settings link) is
     * rendered by quick-review.js into a single content node that's added
     * by DXF_Reviews::add_admin_bar_quick_review_node() at priority 100.
     */
    public function add_admin_bar_node( \WP_Admin_Bar $bar ): void {
        if ( ! current_user_can('edit_posts') ) {
            return;
        }
        // Front-end only — hide from the WP admin backend screens.
        if ( is_admin() ) {
            return;
        }
        $open  = $this->get_total_open_comments();
        $badge = $open
            ? ' <span class="dxf-ab-badge">' . $open . '</span>'
            : '';

        // Dox Studio brand mark — the diamond uses currentColor so it inherits
        // the admin-bar text colour (reads white on the default dark bar), and
        // the swoosh stays Dox orange, so the bar matches the rest of the
        // Dox Studio plugins.
        $icon = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 523.34 517.95" '
              . 'style="vertical-align:-3px;margin-right:6px;">'
              . '<polygon fill="currentColor" points="299.08 0 74.75 129.45 299.08 258.97 299.08 517.95 523.34 388.5 523.34 129.45 299.08 0"/>'
              . '<path fill="#ff8d27" d="M56.1,464.02h0c74.77,43.15,168.22-10.81,168.22-97.14h0c0-40.07-21.38-77.1-56.08-97.13h0C93.47,226.57,0,280.53,0,366.88h0c0,40.08,21.39,77.11,56.1,97.15Z"/>'
              . '</svg>';

        $bar->add_node([
            'id'    => 'dxf',
            'title' => $icon . 'Dox Feedback' . $badge,
            'href'  => admin_url('admin.php?page=dox-feedback'),
            'meta'  => ['class' => 'dxf-ab-root'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Asset enqueue — admin screens + front-end admin bar
    // -------------------------------------------------------------------------

    /**
     * Enqueue review-admin assets on the post-edit screen (for the side meta
     * box). The front-end admin-bar popout is owned by quick-review.js now —
     * it pulls its link-state from a separate localised object.
     */
    public function enqueue_admin_assets( string $hook ): void {
        if ( ! current_user_can('edit_posts') ) {
            return;
        }
        if ( ! in_array($hook, ['post.php', 'post-new.php'], true) ) {
            return;
        }

        wp_enqueue_style(
            'dxf-review-admin',
            DXF_URL . 'assets/admin/review-admin.css',
            [],
            DXF_VERSION
        );
        wp_enqueue_script(
            'dxf-review-admin',
            DXF_URL . 'assets/admin/review-admin.js',
            ['jquery'],
            DXF_VERSION,
            true
        );

        global $post;
        $post_id = isset($post->ID) ? (int) $post->ID : 0;
        $share   = $post_id ? self::get_active_share_for_post($post_id) : null;

        wp_localize_script('dxf-review-admin', 'dxfReviewAdmin', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce(self::NONCE_ACTION),
            'postId'      => $post_id,
            'reviewUrl'   => $share ? (string) $share['url'] : '',
            'token'       => $share ? (string) ($share['review_id'] ?? '') : '',
            'i18n'        => [
                'generating'    => __('Generating…',  'dox-feedback'),
                'revoking'      => __('Revoking…',    'dox-feedback'),
                'copied'        => __('Copied!',      'dox-feedback'),
                'noLink'        => __('No active review link.', 'dox-feedback'),
                'activeLink'    => __('Active review link:', 'dox-feedback'),
                'noPage'        => __('Navigate to a page to manage its review link.', 'dox-feedback'),
                'error'         => __('Something went wrong.', 'dox-feedback'),
                'revokeConfirm' => __('Revoke this review link? Clients with the current URL will lose access.', 'dox-feedback'),
            ],
        ]);
    }


    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Viewport-emulation sub-frame detection. The reviewer-facing iframe
     * (created by review.js when the reviewer picks tablet/mobile) appends
     * ?dxf_no_chrome=1 to the page URL. We use that signal to skip
     * enqueueing the overlay assets so the iframe renders the plain page
     * instead of stacking another full overlay inside itself.
     *
     * Only ever set on the iframe URL — never on the top-level reviewer
     * URL — so it's safe to read as authoritative for "am I a sub-frame?".
     */
    public static function is_no_chrome_subframe(): bool {
        return ! empty( $_GET['dxf_no_chrome'] );
    }

    /** Returns true if this post has any token row — active or revoked. */
    public static function post_has_any_token( int $post_id ): bool {
        if ( ! $post_id ) {
            return false;
        }
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM %i WHERE post_id = %d LIMIT 1",
                $wpdb->prefix . 'dxf_review_tokens', $post_id
            )
        );
    }

    private function get_total_open_comments(): int {
        global $wpdb;
        // Custom-table COUNT(*) with a constant WHERE — no user input; the table
        // name derives from $wpdb->prefix. (Mirrors DXF_Reviews' open-count.)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM %i
              WHERE status = 'open' AND (parent_id IS NULL OR parent_id = 0)",
            $wpdb->prefix . 'dxf_comments'
        ) );
    }

    public function add_rewrite_var(): void {
        // Use a 'top' rewrite rule so WP recognises the token URL before any
        // built-in rules run, preventing it from falling back to the blog archive.
        add_rewrite_rule(
            '^' . preg_quote(self::TOKEN_QUERY_VAR, '/') . '/([^/]+)/?$',
            'index.php?' . self::TOKEN_QUERY_VAR . '=$matches[1]',
            'top'
        );

        // Register the query var so WP passes it to get_query_var().
        add_filter('query_vars', function ( array $vars ): array {
            $vars[] = self::TOKEN_QUERY_VAR;
            return $vars;
        });

        // Auto-flush once whenever our rule version changes.
        $flush_key = 'dxf_rewrite_flushed_v3';
        if ( ! get_option($flush_key) ) {
            flush_rewrite_rules(false);
            update_option($flush_key, '1', false);
        }
    }

    /**
     * Force the main query to load the correct post for a token URL.
     * Runs before WP executes its SQL so we never hit the blog archive.
     */
    public function override_query_for_token( \WP_Query $query ): void {
        if ( ! $query->is_main_query() || is_admin() ) {
            return;
        }

        $token = get_query_var(self::TOKEN_QUERY_VAR);
        if ( ! $token ) {
            return;
        }

        $token_data = $this->get_token($token);
        if ( ! $token_data ) {
            return;
        }

        $post_id = (int) $token_data['post_id'];
        $post    = get_post($post_id);
        if ( ! $post ) {
            return;
        }

        // Prevent WordPress from redirecting /dxf_token/TOKEN/ to the
        // canonical post URL (e.g. /my-page/). Without this, WP sees page_id=X
        // on a non-canonical URL and issues a 301 that strips our token.
        add_filter('redirect_canonical', '__return_false');

        // Reset any archive/category flags that might have been set.
        $query->is_home     = false;
        $query->is_archive  = false;
        $query->is_category = false;
        $query->is_singular = true;

        if ( $post->post_type === 'page' ) {
            $query->set('page_id',   $post_id);
            $query->set('post_type', 'page');
            $query->is_page = true;
        } else {
            $query->set('p',         $post_id);
            $query->set('post_type', $post->post_type);
        }

        // A valid token may point at a page that isn't published yet (the
        // agency wants the client to sign off on a draft before it goes live).
        // The token itself is the access proof, so widen the status filter for
        // this one query. Hardening (noindex/no-store) still applies, so the
        // draft is never exposed to search engines or random visitors.
        if ( $post->post_status !== 'publish' ) {
            $query->set('post_status', self::reviewable_post_statuses());
        }
    }

    /**
     * Non-public post statuses we'll surface to a verified reviewer. Excludes
     * 'trash'/'auto-draft' — a deleted or never-saved post has nothing to
     * review. Filterable so a site can opt a custom status in/out.
     *
     * @return string[]
     */
    public static function reviewable_post_statuses(): array {
        return (array) apply_filters('dxf_reviewable_post_statuses', [
            'publish', 'draft', 'pending', 'private', 'future',
        ]);
    }

    /**
     * Let a verified reviewer load an unpublished (draft / pending / private /
     * future) post they have a valid session for, read-only. Runs on the main
     * query for the page permalink the reviewer was handed off to. Published
     * posts are left completely untouched so normal pages still cache normally.
     */
    public function allow_reviewer_draft_access( \WP_Query $query ): void {
        if ( is_admin() || ! $query->is_main_query() ) {
            return;
        }

        // Unpublished posts always resolve to an id-based query (?p= / ?page_id=)
        // because their pretty permalink isn't minted until publish — so that's
        // the only shape we need to inspect.
        $post_id = (int) ( $query->get('page_id') ?: $query->get('p') );
        if ( $post_id <= 0 ) {
            return;
        }

        $post = get_post($post_id);
        if ( ! $post || $post->post_status === 'publish' ) {
            return; // published pages need no intervention
        }

        if ( ! $this->reviewer_can_access_post($post_id) ) {
            return;
        }

        $query->set('post_status', self::reviewable_post_statuses());
        // The non-canonical id URL would otherwise 301 toward a pretty permalink
        // that doesn't exist for a draft, stripping our session in the process.
        add_filter('redirect_canonical', '__return_false');
    }

    /**
     * Whether the current visitor holds a valid, in-scope reviewer session for
     * a given post — the gate for {@see allow_reviewer_draft_access()}. Accepts
     * any of the three session signals: the legacy per-page token cookie, a
     * direct token URL, or the v0.16 Review session cookie / bridge arg.
     */
    private function reviewer_can_access_post( int $post_id ): bool {
        // Editors can always preview their own drafts.
        if ( current_user_can('edit_post', $post_id) ) {
            return true;
        }

        // 1 + 2: legacy per-page token, via cookie or direct token URL.
        $tokens = [
            sanitize_text_field(wp_unslash($_COOKIE['dxf_reviewing'] ?? '')),
            (string) get_query_var(self::TOKEN_QUERY_VAR),
        ];
        foreach ($tokens as $token) {
            if ( $token === '' ) {
                continue;
            }
            $td = $this->get_token($token);
            if (
                $td &&
                (int) $td['post_id'] === $post_id &&
                empty($td['revoked_at']) &&
                ( ! $td['expires_at'] || strtotime($td['expires_at']) >= time() )
            ) {
                return true;
            }
        }

        // 3: v0.16 Review session — slug from the session cookie or bridge arg.
        if ( class_exists('DXF_Review') ) {
            $slug = sanitize_text_field(wp_unslash($_COOKIE[self::SESSION_COOKIE] ?? ''));
            if ( $slug === '' && ! empty($_GET['dxf_review']) ) {
                $slug = sanitize_text_field(wp_unslash((string) $_GET['dxf_review']));
            }
            if ( $slug !== '' && preg_match('/^[a-f0-9]{16,64}$/', $slug) ) {
                $review = DXF_Review::get_by_slug($slug);
                if ( $review && DXF_Review::is_open($review) ) {
                    if ( $review['mode'] === DXF_Review::MODE_EMAIL ) {
                        // Email-mode access needs a verified member; the auth
                        // layer lives in Pro, so bail neutrally when absent.
                        if ( ! DXF_Review::email_features_available() ) {
                            return false;
                        }
                        if ( ! DXF_Review_Auth::current_member($review) ) {
                            return false;
                        }
                    }
                    if ( in_array($post_id, DXF_Review::resolve_post_ids($review), true) ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Priority-1 template_redirect: continue a review session that was started
     * via a token URL and persisted in a session cookie. This lets reviewers
     * navigate around the site and keep the overlay active on every page.
     */
    public function maybe_continue_review_session(): void {
        if ( is_admin() ) {
            return;
        }

        // v0.16 Reviews bridge — when the new Reviews landing redirects the
        // reviewer to a permalink with ?dxf_review=<slug>, we mint (or
        // reuse) a legacy per-page token for the current post so the
        // existing cookie + AJAX session machinery picks up unchanged.
        $this->maybe_bootstrap_from_reviews_bridge();

        $token = sanitize_text_field(wp_unslash($_COOKIE['dxf_reviewing'] ?? ''));
        if ( ! $token ) {
            return;
        }

        // Already being handled by a direct token URL — don't double-init.
        if ( get_query_var(self::TOKEN_QUERY_VAR) ) {
            return;
        }

        $token_data = $this->get_token($token);

        // Clear bad/expired cookies.
        if ( ! $token_data || ! empty($token_data['revoked_at']) ) {
            setcookie('dxf_reviewing', '', time() - 3600, '/');
            return;
        }
        if ( $token_data['expires_at'] && strtotime($token_data['expires_at']) < time() ) {
            setcookie('dxf_reviewing', '', time() - 3600, '/');
            return;
        }

        $current_post_id = (int) get_queried_object_id();
        $token_post_id   = (int) $token_data['post_id'];

        // Bricks builder context (?bricks=run) — the review overlay is not needed
        // there; editors use the built-in Builder comment mode instead.
        if ( ! empty($_GET['bricks']) ) {
            return;
        }

        // Viewport-emulation sub-frame (rendered inside the parent reviewer's
        // <iframe>) — skip the overlay so we don't pile FAB + sidebar + nav
        // panel on top of themselves. Hardening headers still apply (the
        // sub-frame is still a token-bearing private URL).
        if ( self::is_no_chrome_subframe() ) {
            $this->harden_review_response();
            return;
        }

        // Token matches the current page → enqueue the full overlay.
        if ( $current_post_id === $token_post_id ) {
            $this->enqueue_review_assets_for($token_post_id, $token);
            $this->harden_review_response();
            if ( ! current_user_can('edit_posts') ) {
                add_filter('show_admin_bar', '__return_false');
            }
            return;
        }

        // Reviewer has navigated off the page their per-page token covers.
        // If the parallel session cookie tells us which Review they're in,
        // we can serve a contextual UX: in-scope-but-not-bridged → kick
        // them through the bridge so a fresh token mints; off-scope →
        // banner only (no overlay; the per-page token can't read or write
        // here, so we don't enqueue the engine).
        $this->maybe_handle_off_token_navigation($current_post_id);
    }

    /**
     * Handle a request where the reviewer has the per-page token cookie but
     * is currently on a DIFFERENT page than the one the token covers.
     * Branches via the {@see SESSION_COOKIE} session cookie:
     *   - in-scope    → redirect to bridge so a fresh per-page token mints
     *   - off-scope   → enqueue the off-scope banner + nav panel only
     *   - no session  → return silently (legacy single-page flow)
     */
    private function maybe_handle_off_token_navigation( int $current_post_id ): void {
        if ( ! class_exists('DXF_Review') ) return;

        // The new Reviews module owns /dox-feedback/<slug>/* URLs (landing,
        // activation, page-handoff). Don't enqueue our chrome on top of its
        // own rendered templates — otherwise the off-scope banner + nav
        // panel pile onto the dashboard, which already has its own page list.
        if ( class_exists('DXF_Reviews') && get_query_var(DXF_Reviews::QUERY_VAR) ) {
            return;
        }
        // No post context (e.g. archive pages, 404s) — nothing meaningful
        // for the chrome to point at.
        if ( $current_post_id <= 0 ) return;

        $slug = sanitize_text_field(wp_unslash($_COOKIE[self::SESSION_COOKIE] ?? ''));
        if ( $slug === '' || ! preg_match('/^[a-f0-9]{16,64}$/', $slug) ) return;

        $review = DXF_Review::get_by_slug($slug);
        if ( ! $review || ! DXF_Review::is_open($review) ) return;

        // Email-mode reviews require a verified member session — if the
        // cookie is missing/expired, we DON'T leak the page nav panel.
        if ( $review['mode'] === DXF_Review::MODE_EMAIL ) {
            // If the email auth layer isn't loaded, treat the session as
            // unverified (don't leak the page-nav panel) rather than calling a
            // class that no longer exists.
            if ( ! DXF_Review::email_features_available() ) return;
            $member = DXF_Review_Auth::current_member($review);
            if ( ! $member ) return;
        }

        $allowed     = DXF_Review::resolve_post_ids($review);
        $is_in_scope = $current_post_id > 0 && in_array($current_post_id, $allowed, true);

        if ( $is_in_scope ) {
            // In-scope but no fresh token yet — bounce through the bridge.
            // The bridge mints a per-page token, sets cookies, then strips
            // ?dxf_review and redirects back to the clean URL, so the
            // address bar stays tidy.
            $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
            $url = add_query_arg('dxf_review', $slug, $request_uri);
            wp_safe_redirect(home_url($url));
            exit;
        }

        // Off-scope page — the reviewer wandered onto a URL not in this
        // Review's bundle (or a public page they reached via a site link).
        //
        // This "your feedback won't reach the team" banner is for actual
        // external reviewers. A logged-in team member (editor/admin) who opened
        // a review link and then browsed their own site is not the audience —
        // showing it to them is just noise — so skip the off-scope chrome for
        // them entirely. (edit_posts is the same reviewer-vs-team predicate used
        // throughout this module.)
        if ( current_user_can('edit_posts') ) {
            return;
        }

        // Enqueue the slim chrome (banner + nav panel) so the guest reviewer can
        // hop back into the dashboard, but skip the engine entirely.
        $this->enqueue_review_chrome_for($review, /* is_off_scope */ true);
        add_filter('show_admin_bar', '__return_false');
    }

    public function handle_review_request(): void {
        $token = get_query_var(self::TOKEN_QUERY_VAR);
        if ( ! $token ) {
            return;
        }

        $token_data = $this->get_token($token);

        if ( ! $token_data || $token_data['revoked_at'] ) {
            wp_die(esc_html__('This review link is no longer active.', 'dox-feedback'), 410);
        }

        if ( $token_data['expires_at'] && strtotime($token_data['expires_at']) < time() ) {
            wp_die(esc_html__('This review link has expired.', 'dox-feedback'), 410);
        }

        // Viewport-emulation sub-frame — render the page without our overlay,
        // and don't reset the parent's session cookie. Iframe is loaded by
        // the parent reviewer for media-query emulation only.
        if ( self::is_no_chrome_subframe() ) {
            $this->harden_review_response();
            return;
        }

        // Set a session cookie so the reviewer can navigate across pages.
        setcookie(
            'dxf_reviewing',
            $token,
            [
                'expires'  => 0,           // session cookie — clears when browser closes
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => false,        // keep readable by JS if needed
                'samesite' => 'Lax',
            ]
        );

        $post_id = (int) $token_data['post_id'];
        $this->enqueue_review_assets_for($post_id, $token);
        $this->harden_review_response();

        // Keep the admin bar visible for logged-in editors so they can navigate
        // away.  Only suppress it for guest reviewers.
        if ( ! current_user_can('edit_posts') ) {
            add_filter('show_admin_bar', '__return_false');
        }
    }

    // -------------------------------------------------------------------------
    // Maintenance / Coming-soon bypass
    //
    // A reviewer arriving on a link should see the actual page they were asked
    // to review, NOT the site's maintenance / coming-soon screen — even though
    // they are logged out and the site is otherwise walled off. We do this WITHOUT
    // disabling maintenance mode globally: the wall stays up for the public, and
    // only requests carrying a valid, non-revoked, in-scope review token/session
    // are let through (plus Dox Feedback's own review URLs, e.g. the landing/email gate).
    // -------------------------------------------------------------------------

    /**
     * Should THIS request slip past a maintenance / coming-soon wall?
     *
     * True only for: (a) Dox Feedback's own review routes (landing, email gate, page
     * handoff) so the reviewer can reach them at all, or (b) a request whose
     * token / session cookie is a valid reviewer for the page being requested.
     * Reuses {@see reviewer_can_access_post()}, which already enforces revoked /
     * expired / open-review / email-member / in-scope checks — so the bypass can
     * never be wider than the reviewer's existing read access.
     */
    private function request_is_reviewer_bypass(): bool {
        // Let integrators turn the whole behaviour off (e.g. an agency that
        // genuinely wants reviewers to see "coming soon").
        if ( ! apply_filters('dxf_bypass_maintenance_mode', true) ) {
            return false;
        }

        // Dox Feedback's own virtual URLs (/dox-feedback/<slug>/…) must render their
        // own templates, never the site maintenance page.
        if ( class_exists('DXF_Reviews') && get_query_var(DXF_Reviews::QUERY_VAR) ) {
            return true;
        }

        $post_id = (int) get_queried_object_id();
        return $post_id > 0 && $this->reviewer_can_access_post($post_id);
    }

    /**
     * Bricks 2.0+ maintenance bypass via the theme's official decision filter.
     * Fires inside Bricks\Maintenance::apply_maintenance_mode() on `wp` (prio 9),
     * AFTER its own built-in checks have decided the wall should apply. Returning
     * false here lets the requested page render for a verified reviewer.
     *
     * @param bool   $apply Whether Bricks intends to show the maintenance page.
     * @param string $mode  'maintenance' | 'coming_soon' (unused; kept for the API).
     */
    public function bricks_maintenance_should_apply( $apply, $mode = '' ) {
        if ( ! $apply ) {
            return $apply; // already decided to let this request through.
        }
        return $this->request_is_reviewer_bypass() ? false : $apply;
    }

    /**
     * Fallback for Bricks < 2.0, which predates the `bricks/maintenance/should_apply`
     * filter. There the wall is applied unconditionally on `wp` (prio 9), so we
     * run just ahead of it (prio 8) and, for a verified reviewer, unhook that
     * callback for this request only. No-op on Bricks 2.0+ (the filter handles it)
     * and on any non-Bricks site.
     */
    public function maybe_bypass_legacy_bricks_maintenance(): void {
        if ( ! class_exists('\\Bricks\\Maintenance') ) {
            return;
        }
        // 2.0+ exposes the filter above — don't double-handle.
        if ( defined('BRICKS_VERSION') && version_compare(BRICKS_VERSION, '2.0', '>=') ) {
            return;
        }
        // Maintenance / coming-soon not actually switched on → nothing to bypass.
        if ( ! \Bricks\Maintenance::get_mode() ) {
            return;
        }
        if ( ! $this->request_is_reviewer_bypass() ) {
            return;
        }
        remove_action('wp', [ \Bricks\Maintenance::get_instance(), 'apply_maintenance_mode' ], 9);
    }

    /**
     * Elementor maintenance / coming-soon bypass.
     *
     * Elementor exposes no "should this apply?" decision filter, but its
     * Maintenance_Mode::template_redirect() (hooked at template_redirect prio 11)
     * bails early when `elementor/maintenance_mode/is_login_page` is true. That
     * is its only public bypass lever and has existed since Elementor 1.0.4, so
     * we reuse it: for a verified reviewer we report "true" so Elementor steps
     * aside and the requested page renders. Non-reviewer traffic keeps whatever
     * value the filter already had, so the wall is untouched for the public.
     *
     * @param bool $is_login_page Elementor's running value (true = skip the wall).
     */
    public function elementor_maintenance_is_login_page( $is_login_page ) {
        if ( $is_login_page ) {
            return $is_login_page; // already skipping for this request.
        }
        return $this->request_is_reviewer_bypass() ? true : $is_login_page;
    }

    /**
     * Send the "this is a private review link, don't index, don't cache,
     * don't leak referrers" hardening on the response.
     *
     * Why: review links carry a high-entropy token in the URL. We block the
     * obvious paths a token could escape — search-engine indexing, CDN/proxy
     * caching, the Referer header on outbound clicks, and snippet/archive
     * scraping. The token's own entropy (192 bits) makes guessing infeasible;
     * these headers just close the channels where it might leak.
     */
    private function harden_review_response(): void {
        if ( ! headers_sent() ) {
            // Search engines + scrapers
            header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex', true);
            // Don't leak the review URL (which contains the token) in the
            // Referer header when the reviewer clicks an outbound link.
            header('Referrer-Policy: no-referrer', true);
            // No intermediary caching of token-bearing responses.
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
            header('Pragma: no-cache', true);
        }
        add_action('wp_head', function (): void {
            echo "\n<!-- Dox Feedback review session — keep this page out of search indexes -->\n";
            echo '<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">' . "\n";
            echo '<meta name="referrer" content="no-referrer">' . "\n";
        }, 1);
    }

    /**
     * Enqueue the review-mode frontend assets for a specific post + token.
     * Safe to call from both handle_review_request() and maybe_continue_review_session().
     */
    private function enqueue_review_assets_for( int $post_id, string $token ): void {
        add_action('wp_enqueue_scripts', function () use ( $post_id, $token ): void {
            // Reuse the builder's overlay styles so the reviewer UI matches the
            // in-builder comment experience exactly (sidebar, pins, cards, form).
            wp_enqueue_style(
                'dxf-builder',
                DXF_URL . 'assets/builder/builder.css',
                [],
                DXF_Comments::asset_ver('assets/builder/builder.css')
            );
            wp_enqueue_style(
                'dxf-frontend',
                DXF_URL . 'assets/frontend/review.css',
                ['dxf-builder'],
                DXF_Comments::asset_ver('assets/frontend/review.css')
            );

            // snapDOM powers the per-comment screenshot capture (1.0.7+).
            wp_enqueue_script(
                'dxf-snapdom',
                DXF_URL . 'assets/vendor/snapdom.min.js',
                [],
                DXF_Comments::asset_ver('assets/vendor/snapdom.min.js'),
                true
            );
            // Builder anchor adapters — must load before the engine (it routes
            // all element anchoring through window.DxfAnchors).
            wp_enqueue_script(
                'dxf-anchors',
                DXF_URL . 'assets/comment-engine/adapters.js',
                [],
                DXF_Comments::asset_ver('assets/comment-engine/adapters.js'),
                true
            );
            // Shared comment engine — must load before the front-end adapter.
            wp_enqueue_script(
                'dxf-comment-engine',
                DXF_URL . 'assets/comment-engine/engine.js',
                ['dxf-snapdom', 'dxf-anchors'],
                DXF_Comments::asset_ver('assets/comment-engine/engine.js'),
                true
            );
            wp_enqueue_script(
                'dxf-frontend',
                DXF_URL . 'assets/frontend/review.js',
                ['dxf-comment-engine'],
                DXF_Comments::asset_ver('assets/frontend/review.js'),
                true
            );

            // Client-portal branding. Defaults to the site's own name and the
            // default accent; a custom name / logo / colours can be supplied
            // through the `dxf_review_brand` filter.
            $brand = (array) apply_filters('dxf_review_brand', [
                'enabled'   => false,
                'name'      => '',
                'logo'      => '',
                'logoDark'  => '',
                'logoLight' => '',
                'color'     => '',
                'textColor' => '',
            ]);

            // Sidebar label: the reviewing site's own name, unless Pro white-
            // label supplied a custom agency name.
            $agency_name = ( ! empty($brand['enabled']) && ! empty($brand['name']) )
                ? (string) $brand['name']
                : get_bloginfo('name');

            // Multi-page Review context — when the reviewer arrived via
            // the new Reviews flow (DXF_Review_Session_Bridge), surface
            // the page list + member identity so the JS can render the
            // bottom-left nav panel and pre-fill the identity gate.
            // Falls back to empty `review` for legacy single-page tokens.
            $review_payload = self::build_active_review_payload($post_id);

            wp_localize_script('dxf-frontend', 'dxfReview', [
                'nonce'          => wp_create_nonce(DXF_Comments::NONCE_ACTION),
                'ajaxUrl'        => admin_url('admin-ajax.php'),
                'postId'         => $post_id,
                'token'          => $token,
                'pageTitle'      => get_the_title($post_id),
                'agencyName'     => $agency_name,
                'brand'          => $brand,
                'accent'         => DXF_Comments::accent_color(),
                'modalTheme'     => (string) DXF_Settings::get('comment_modal_theme', 'follow_bricks'),
                'fabPosition'    => DXF_Settings::fab_position(),
                'completed'      => (bool) get_post_meta($post_id, '_dxf_complete_' . $token, true),
                'captureLibUrl'  => DXF_URL . 'assets/vendor/snapdom.min.js',
                'currentUserId'    => get_current_user_id(), // 0 for guest reviewers
                'currentUser'      => is_user_logged_in() ? ( wp_get_current_user()->display_name ?: wp_get_current_user()->user_login ) : '',
                'currentUserEmail' => is_user_logged_in() ? (string) wp_get_current_user()->user_email : '',
                'canImportMedia' => current_user_can('upload_files'),
                'allowUploads'   => (bool) apply_filters('dxf_allow_comment_uploads', true, $post_id, 0),
                'review'         => $review_payload,
                'memberEmail'    => $review_payload['memberEmail'] ?? '',
                'memberName'     => $review_payload['memberName']  ?? '',
                'i18n'           => [
                    'orphaned'      => __('Element removed', 'dox-feedback'),
                    'navTitle'      => __('Pages in this review', 'dox-feedback'),
                    'navAllPages'   => __('All pages', 'dox-feedback'),
                    'navPrev'       => __('Previous', 'dox-feedback'),
                    'navNext'       => __('Next', 'dox-feedback'),
                    'navCollapse'   => __('Collapse', 'dox-feedback'),
                    'navExpand'     => __('Expand', 'dox-feedback'),
                    'statusTodo'    => __('To do', 'dox-feedback'),
                    'statusReview'  => __('In review', 'dox-feedback'),
                    'statusDone'    => __('Approved', 'dox-feedback'),
                    'offScopeMsg'   => __('This page isn\'t part of the review — feedback you leave here won\'t reach the team.', 'dox-feedback'),
                    'offScopeBack'  => __('Back to review dashboard', 'dox-feedback'),
                    'offScopeExit'  => __('Exit review mode', 'dox-feedback'),
                    'readOnlyMsg'   => __('This review is read-only — you can still read all the feedback here.', 'dox-feedback'),
                    'fabTip'        => __('Click the Feedback button to pin comments anywhere on this page.', 'dox-feedback'),
                    'fabTipClose'   => __('Dismiss', 'dox-feedback'),
                    'switchReview'  => __('Switch review', 'dox-feedback'),
                ],
            ]);
        });
    }

    /**
     * Build the cfg.review payload from the SESSION_COOKIE — page list,
     * statuses, current-page-in-scope flag, dashboard URL, member identity
     * (for email-mode pre-fill). Returns an empty array when there's no
     * active Review session (legacy single-page tokens).
     */
    public static function build_active_review_payload(int $current_post_id): array {
        if ( ! class_exists('DXF_Review') ) return [];

        $slug = sanitize_text_field(wp_unslash($_COOKIE[self::SESSION_COOKIE] ?? ''));
        if ( $slug === '' || ! preg_match('/^[a-f0-9]{16,64}$/', $slug) ) return [];

        $review = DXF_Review::get_by_slug($slug);
        if ( ! $review || ! DXF_Review::is_open($review) ) return [];

        $allowed = DXF_Review::resolve_post_ids($review);
        $states  = DXF_Review::get_post_states((int) $review['id']);

        $pages = [];
        foreach ($allowed as $pid) {
            $st = $states[$pid] ?? ['status' => DXF_Review::PAGE_STATUS_TODO];
            $pages[] = [
                'id'     => (int) $pid,
                'title'  => (string) (get_the_title($pid) ?: ('#' . $pid)),
                // Route through the /dox-feedback/<slug>/item/<id>/ handoff
                // rather than the bare permalink. That guarantees
                // DXF_Reviews::handle_page_open() runs for every
                // page-to-page navigation, which (a) refreshes the per-page
                // token cookie, (b) marks the new page as in_review,
                // (c) appends the cache-buster query arg so page-cache
                // layers can't serve a stale overlay-less response.
                'url'    => DXF_Review::item_url((string) $review['slug'], (int) $pid),
                'status' => (string) $st['status'],
                // Per-page "Reviewed" marker (reviewer finished their pass).
                'reviewed' => ! empty($st['reviewed_at']),
            ];
        }

        // Email-mode member identity (used by review.js to pre-fill the
        // identity gate with the invitee's known email + email-prefix as
        // a name suggestion). Role is also exposed so the front-end can
        // gate UI affordances (e.g. only Approver + Lead see the "Mark
        // page approved" button). The server still re-checks the role in
        // ajax_mark_review_complete — JS gating is UX, not security.
        // Link-mode (public) reviews carry no member identity — capabilities are
        // decided by mode, not role (anyone with the link may comment and approve,
        // matching the server's exemption of link reviews from the role gate).
        // Email-mode reviews resolve the visitor's identity and role from the
        // signed auth cookie, but ONLY when email features are available
        // (email_features_available). If they're absent, an existing email review
        // degrades to read-only: member_role stays '' so every capability below
        // resolves false.
        $member_email = '';
        $member_name  = '';
        $member_role  = '';
        $is_email     = ( $review['mode'] === DXF_Review::MODE_EMAIL );
        if ( $is_email && DXF_Review::email_features_available() ) {
            $member = DXF_Review_Auth::current_member($review);
            if ( $member ) {
                $member_email = (string) ($member['email'] ?? '');
                $member_name  = (string) ($member['name']  ?? '');
                $member_role  = (string) ($member['role']  ?? '');
            }
        }
        $read_only = DXF_Review::is_read_only($review);
        // role_can() lives in DXF_Review_Member; it is only reached when
        // $member_role !== '', which requires email_features_available()
        // (class_exists('DXF_Review_Member')) — so this stays fatal-safe when
        // that class isn't loaded.
        $can_approve = $is_email
            ? ( $member_role !== '' && DXF_Review_Member::role_can($member_role, 'approve') )
            : true;
        $can_invite  = $is_email && $member_role !== '' && DXF_Review_Member::role_can($member_role, 'invite');
        $can_comment = $is_email
            ? ( ! $read_only && $member_role !== '' && DXF_Review_Member::role_can($member_role, 'comment') )
            : ! $read_only;

        return [
            'slug'         => (string) $review['slug'],
            'name'         => (string) ($review['name'] ?? ''),
            'mode'         => (string) $review['mode'],
            'landingUrl'   => DXF_Review::landing_url((string) $review['slug']),
            // Other reviews this viewer can hop to (staff: all active; email
            // reviewers: their own). Empty unless there's more than one.
            'switchable'   => self::accessible_reviews_for_viewer($review, $member_email),
            'pages'        => $pages,
            'isOffScope'   => $current_post_id > 0 && ! in_array($current_post_id, $allowed, true),
            'memberEmail'  => $member_email,
            'memberName'   => $member_name,
            'memberRole'   => $member_role,
            // Email-mode reviews gate approval to Approver/Lead (auditable
            // sign-off). Public link-mode reviews — the free single-page share —
            // let anyone with the link approve, matching the server (which
            // exempts link reviews from the role gate) and the free feature set.
            'canApprove'   => $can_approve,
            'canInvite'    => $can_invite,
            'canComment'   => $can_comment,
            // A read-only review (e.g. an add-on paused it) keeps read access
            // but disables the composer. Enforced server-side in
            // DXF_Comments::ajax_add_comment(); this flag drives the UX.
            'readOnly'     => $read_only,
            'reviewId'     => (int) $review['id'],
            // Whether the FE should auto-open the sidebar on page load.
            // Default is FALSE for everyone (since 1.0.7): first-time
            // reviewers get a dismissible tooltip pointing at the Feedback
            // FAB instead of a modal covering the page they came to review.
            // Site-specific extensions can restore the old force-open via
            // this filter — the JS only auto-opens on an explicit `true`.
            'autoOpenSidebar' => (bool) apply_filters('dxf_review_auto_open_sidebar', false, $review),
        ];
    }

    /**
     * Reviews the current front-end viewer can switch between. Staff (anyone who
     * can edit_posts) can hop across every active review; email reviewers get the
     * reviews their address belongs to (contributed by Pro via the filter). Each
     * entry carries its landing URL — switching is just a navigation that
     * re-bootstraps the session, so there's no extra endpoint. Returns [] unless
     * there's genuinely more than one choice.
     *
     * @param array<string,mixed> $review        the current review row
     * @param string              $member_email  the viewer's email (email-mode)
     * @return array<int,array<string,mixed>>
     */
    public static function accessible_reviews_for_viewer(array $review, string $member_email = ''): array {
        if ( ! class_exists('DXF_Review') ) {
            return [];
        }
        $current_slug = (string) ( $review['slug'] ?? '' );
        $list         = [];

        if ( current_user_can('edit_posts') ) {
            $rows = DXF_Review::find(['status' => DXF_Review::STATUS_ACTIVE, 'per_page' => 25]);
            foreach ( $rows as $r ) {
                $list[] = [
                    'slug'      => (string) $r['slug'],
                    'name'      => (string) ( $r['name'] ?? '' ),
                    'scopeType' => (string) ( $r['scope_type'] ?? DXF_Review::SCOPE_SINGLE ),
                ];
            }
        }

        // Pro contributes the reviews tied to an email reviewer's address. Free
        // adds nothing here (it has no email-restricted reviews).
        $list = apply_filters('dxf_viewer_accessible_reviews', $list, $member_email, $review);

        $seen = [];
        $out  = [];
        foreach ( (array) $list as $r ) {
            $slug = (string) ( $r['slug'] ?? '' );
            if ( $slug === '' || isset($seen[$slug]) ) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = [
                'slug'       => $slug,
                'name'       => (string) ( $r['name'] ?? '' ),
                'scopeType'  => (string) ( $r['scopeType'] ?? DXF_Review::SCOPE_SINGLE ),
                'landingUrl' => DXF_Review::landing_url($slug),
                'current'    => ( $slug === $current_slug ),
            ];
        }
        return count($out) > 1 ? $out : [];
    }

    /**
     * Off-scope enqueue — slim chrome only (nav panel + banner), no
     * comment engine. Reviewer has the session cookie + (for email-mode)
     * verified member auth, but is currently on a URL outside the Review's
     * bundled pages.
     */
    private function enqueue_review_chrome_for(array $review, bool $is_off_scope): void {
        add_action('wp_enqueue_scripts', function () use ( $review, $is_off_scope ): void {
            wp_enqueue_style(
                'dxf-builder',
                DXF_URL . 'assets/builder/builder.css',
                [],
                DXF_VERSION
            );
            wp_enqueue_style(
                'dxf-frontend',
                DXF_URL . 'assets/frontend/review.css',
                ['dxf-builder'],
                DXF_VERSION
            );
            wp_enqueue_script(
                'dxf-frontend',
                DXF_URL . 'assets/frontend/review.js',
                [], // engine deliberately omitted — chrome-only path
                DXF_VERSION,
                true
            );

            $payload                = self::build_active_review_payload((int) get_queried_object_id());
            $payload['isOffScope']  = $is_off_scope; // authoritative for off-scope path

            wp_localize_script('dxf-frontend', 'dxfReview', [
                'ajaxUrl'      => admin_url('admin-ajax.php'),
                // Comment nonce — the chrome-only path doesn't post comments,
                // but the off-scope banner's Exit-review-mode button uses
                // this same nonce action against ajax_review_exit().
                'nonce'        => wp_create_nonce(DXF_Comments::NONCE_ACTION),
                'postId'       => (int) get_queried_object_id(),
                'token'        => '', // no token on off-scope pages — engine won't boot
                'pageTitle'    => (string) (get_the_title() ?: ''),
                'accent'       => DXF_Comments::accent_color(),
                'review'       => $payload,
                'memberEmail'  => $payload['memberEmail'] ?? '',
                'memberName'   => $payload['memberName']  ?? '',
                'i18n'         => [
                    'offScopeMsg'  => __('This page isn\'t part of the review — feedback you leave here won\'t reach the team.', 'dox-feedback'),
                    'offScopeBack' => __('Back to review dashboard', 'dox-feedback'),
                    'offScopeExit' => __('Exit review mode', 'dox-feedback'),
                    'navTitle'     => __('Pages in this review', 'dox-feedback'),
                    'navAllPages'  => __('All pages', 'dox-feedback'),
                    'navCollapse'  => __('Collapse', 'dox-feedback'),
                    'navExpand'    => __('Expand', 'dox-feedback'),
                    'statusTodo'   => __('To do', 'dox-feedback'),
                    'statusReview' => __('In review', 'dox-feedback'),
                    'statusDone'   => __('Approved', 'dox-feedback'),
                ],
            ]);
        });
    }

    public function register_meta_box(): void {
        $post_types = get_post_types(['public' => true]);
        add_meta_box(
            'dxf-review-mode',
            __('Client Review Link', 'dox-feedback'),
            [$this, 'render_meta_box'],
            array_keys($post_types),
            'side',
            'default'
        );
    }

    public function render_meta_box(\WP_Post $post): void {
        $share = self::get_active_share_for_post($post->ID);
        wp_nonce_field(self::NONCE_ACTION, 'dxf_review_nonce');
        ?>
        <div id="dxf-review-meta-box" data-post-id="<?php echo esc_attr($post->ID); ?>">
            <?php if ($share) : ?>
                <p>
                    <strong><?php esc_html_e('Active review link:', 'dox-feedback'); ?></strong><br>
                    <input type="text" readonly class="widefat"
                           value="<?php echo esc_url($share['url']); ?>" />
                </p>
                <p class="description">
                    <?php
                    if ( ! empty($share['expires_at']) ) {
                        printf(
                            /* translators: %s = expiry date */
                            esc_html__('Expires: %s', 'dox-feedback'),
                            esc_html(wp_date(get_option('date_format'), strtotime($share['expires_at'])))
                        );
                    }
                    ?>
                </p>
                <button type="button" class="button button-small" id="dxf-revoke-link"
                        data-token="<?php echo esc_attr((string) ($share['review_id'] ?? '')); ?>">
                    <?php esc_html_e('Revoke link', 'dox-feedback'); ?>
                </button>
            <?php else : ?>
                <p><?php esc_html_e('No active review link.', 'dox-feedback'); ?></p>
                <button type="button" class="button" id="dxf-generate-link">
                    <?php esc_html_e('Generate review link', 'dox-feedback'); ?>
                </button>
            <?php endif; ?>
        </div>
        <?php
    }

    public function ajax_generate_link(): void {
        check_ajax_referer(self::NONCE_ACTION);

        $post_id = absint($_POST['post_id'] ?? 0);
        if ( ! $post_id || ! current_user_can('edit_post', $post_id) ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
        }

        $share = self::ensure_quick_share_review($post_id);
        if ( is_wp_error($share) ) {
            wp_send_json_error([
                'message' => $share->get_error_message(),
            ], 400);
        }

        // Surface enough Review metadata for quick-review.js to prepend the
        // new row into the Active Reviews list without a page reload. The
        // admin-bar popout treats every share link as a real Review now.
        $review_id = (int) $share['review_id'];
        $review    = $review_id ? DXF_Review::get($review_id) : null;
        $name      = $review ? (string) ($review['name'] ?? '') : '';

        wp_send_json_success([
            'url'         => (string) $share['url'],
            'review_id'   => $review_id,
            // Kept for back-compat with quick-review.js, which still sends
            // a "token" param on revoke. We no longer use it server-side.
            'token'       => (string) $review_id,
            'review'      => $review_id ? [
                'id'        => $review_id,
                'name'      => $name,
                'manageUrl' => admin_url('admin.php?page=dxf-reviews&action=edit&id=' . $review_id),
            ] : null,
        ]);
    }

    public function ajax_revoke_link(): void {
        check_ajax_referer(self::NONCE_ACTION);

        $post_id = absint($_POST['post_id'] ?? 0);

        if ( ! $post_id || ! current_user_can('edit_post', $post_id) ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
        }

        // Try the new Review-backed share first.
        self::revoke_quick_share_review($post_id);

        // Legacy fallback — if the page only has an old dxf_review_tokens
        // row (no Review yet), honour the original behaviour and mark the
        // token revoked. Lets pre-v0.21 shares still be revoked from the UI.
        $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        // Only run the token UPDATE when the supplied value looks like a real
        // token (hex string, not the numeric review_id we now also send under
        // the same key for back-compat). Avoids touching rows we don't own.
        if ( $token !== '' && preg_match('/^[a-f0-9]{8,}$/i', $token) ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'dxf_review_tokens',
                ['revoked_at' => current_time('mysql')],
                ['token'      => $token, 'post_id' => $post_id],
                ['%s'],
                ['%s', '%d']
            );
        }

        // Revoke is idempotent — repeated calls (e.g. a flaky network on the
        // first attempt) just confirm the link is gone. Always return success
        // so the JS can clear its state and re-render the Generate UI.
        wp_send_json_success();
    }

    /**
     * Find or create the admin-bar quick-share Review for a post. Each post
     * has at most one — a SCOPE_SINGLE / MODE_LINK Review that surfaces in
     * both the admin-bar Active Reviews list and the page-edit meta box.
     * Free-tier safe (single-page link mode is the free maximum).
     *
     * @return array{review_id:int,url:string,expires_at:?string}|\WP_Error
     */
    public static function ensure_quick_share_review(int $post_id) {
        $existing = self::get_active_share_for_post($post_id);
        if ( $existing && empty($existing['legacy']) ) {
            return [
                'review_id'  => (int) $existing['review_id'],
                'url'        => (string) $existing['url'],
                'expires_at' => $existing['expires_at'] ?? null,
            ];
        }

        $expiry_days = (int) DXF_Settings::get('review_link_expiry_days', 30);
        $expires_at  = $expiry_days > 0
            ? gmdate('Y-m-d H:i:s', time() + ($expiry_days * DAY_IN_SECONDS))
            : null;

        $name = sprintf(
            /* translators: %s = page title */
            __('Quick share — %s', 'dox-feedback'),
            get_the_title($post_id) ?: ('#' . $post_id)
        );

        $review = DXF_Review::create([
            'name'       => $name,
            'scope_type' => DXF_Review::SCOPE_SINGLE,
            'mode'       => DXF_Review::MODE_LINK,
            'expires_at' => $expires_at,
            'no_expiry'  => $expiry_days <= 0,
            'post_ids'   => [ $post_id ],
        ]);

        if ( is_wp_error($review) ) {
            return $review;
        }

        // Reviews start as DRAFT; promote to ACTIVE so the share link is
        // immediately usable and appears in "active reviews" lists.
        DXF_Review::update((int) $review['id'], ['status' => DXF_Review::STATUS_ACTIVE]);
        update_post_meta($post_id, self::META_QUICK_SHARE_REVIEW, (int) $review['id']);

        return [
            'review_id'  => (int) $review['id'],
            'url'        => DXF_Review::landing_url((string) $review['slug']),
            'expires_at' => $expires_at,
        ];
    }

    /**
     * Close the quick-share Review attached to a post, if any. Sets status
     * to CLOSED (soft delete — comment history is preserved) and detaches
     * the post-meta pointer. Returns false if there's no Review-backed
     * share for this post (caller can then fall back to legacy token).
     */
    public static function revoke_quick_share_review(int $post_id): bool {
        $id = (int) get_post_meta($post_id, self::META_QUICK_SHARE_REVIEW, true);
        if ( ! $id ) return false;

        $review = DXF_Review::get($id);
        if ( $review ) {
            DXF_Review::update($id, [
                'status'    => DXF_Review::STATUS_CLOSED,
                'closed_at' => current_time('mysql', true),
            ]);
            DXF_Review_Audit::log($id, null, 'closed', [ 'source' => 'quick_share_revoke' ]);
        }
        delete_post_meta($post_id, self::META_QUICK_SHARE_REVIEW);

        // The reviews→review-mode bridge mints a legacy per-page token the
        // first time a reviewer opens the link. Closing the Review alone
        // leaves that token alive — get_active_share_for_post()'s legacy
        // fallback would then "resurrect" the share in the admin bar on the
        // next page load, and the token URL itself kept working. Revoke every
        // active token on this post; bridge tokens belonging to OTHER, still-
        // active Reviews re-mint automatically on the reviewer's next visit.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET revoked_at = %s WHERE post_id = %d AND revoked_at IS NULL",
            $wpdb->prefix . 'dxf_review_tokens',
            current_time('mysql'),
            $post_id
        ));
        return true;
    }

    /**
     * Resolve "is there an active share link for this post?" — preferring the
     * new Review-backed share, falling back to the legacy token row so
     * pre-v0.21 shares still appear in the UI long enough to be revoked.
     *
     * Returns a uniform shape so callers (admin bar, meta box) don't need to
     * branch on which backend produced the link.
     *
     * @return array{review_id:int,url:string,expires_at:?string,legacy?:bool}|null
     */
    public static function get_active_share_for_post(int $post_id): ?array {
        $id = (int) get_post_meta($post_id, self::META_QUICK_SHARE_REVIEW, true);
        if ( $id ) {
            $review = DXF_Review::get($id);
            $active = [ DXF_Review::STATUS_ACTIVE, DXF_Review::STATUS_DRAFT ];
            if ( $review && in_array($review['status'], $active, true) ) {
                return [
                    'review_id'  => (int) $review['id'],
                    'url'        => DXF_Review::landing_url((string) $review['slug']),
                    'expires_at' => $review['expires_at'] ?? null,
                ];
            }
            // Stale pointer — review was deleted or closed elsewhere.
            delete_post_meta($post_id, self::META_QUICK_SHARE_REVIEW);
        }

        $token = self::get_active_token_for_post($post_id);
        if ( $token ) {
            return [
                'review_id'  => 0,
                'url'        => self::build_review_url((string) $token['token']),
                'expires_at' => $token['expires_at'] ?? null,
                'legacy'     => true,
            ];
        }
        return null;
    }

    /**
     * Clear the reviewer's session cookies and respond with success. Used by
     * the off-scope banner's "Exit review mode" button — lets a reviewer who
     * wandered onto a marketing page (and now wants to use the live site
     * normally) drop the per-page token + session cookie without having to
     * close the browser or clear cookies manually.
     *
     * Nopriv-friendly (reviewers aren't WP-logged-in). The nonce is the
     * standard Dox Feedback comment nonce, which is also localised into the
     * frontend dxfReview payload — same surface that renders the banner.
     */
    public static function ajax_review_exit(): void {
        check_ajax_referer(DXF_Comments::NONCE_ACTION);

        $secure = is_ssl();
        // Mirror the path/secure/samesite attributes used when the cookies
        // were set (see bootstrap_session_for) so the browser matches and
        // actually deletes them.
        setcookie('dxf_reviewing', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        setcookie(self::SESSION_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE['dxf_reviewing'], $_COOKIE[self::SESSION_COOKIE]);

        wp_send_json_success();
    }

    public function ajax_mark_review_complete(): void {
        check_ajax_referer(DXF_Comments::NONCE_ACTION);

        $token   = sanitize_text_field(wp_unslash($_POST['token']   ?? ''));
        $post_id = absint($_POST['post_id'] ?? 0);

        if ( ! $token || ! $post_id ) {
            wp_send_json_error(['message' => __('Missing data.', 'dox-feedback')], 400);
        }

        // Validate token is active and belongs to this post.
        $token_data = $this->get_token($token);
        if (
            ! $token_data ||
            (int) $token_data['post_id'] !== $post_id ||
            ! empty($token_data['revoked_at']) ||
            ( $token_data['expires_at'] && strtotime($token_data['expires_at']) < time() )
        ) {
            wp_send_json_error(['message' => __('Invalid review link.', 'dox-feedback')], 403);
        }

        // Role gate — a sign-off on a page that belongs to an email-restricted
        // Review may ONLY be made by a verified Approver or Lead of that Review.
        //
        // Crucially the gate must NOT hinge on the session cookie alone: that
        // cookie (SESSION_COOKIE) is client-controlled and non-httponly, so a
        // Viewer/Reviewer could previously escape the check simply by NOT
        // sending it ($slug === '' skipped the whole block). We therefore derive
        // the requirement from the page's own Reviews (server side) and prove
        // membership through the unforgeable, HMAC-signed `dxf_member` cookie
        // (DXF_Review_Auth::current_member), which is independent of the session
        // cookie. Pages covered only by public link-mode Reviews stay
        // intentionally exempt — any holder of a public link may approve.
        $active_review_id = 0;
        $proven_for_page  = false; // holds a valid session for a Review that scopes this page

        if ( class_exists('DXF_Review') ) {
            $slug = sanitize_text_field(wp_unslash($_COOKIE[self::SESSION_COOKIE] ?? ''));
            if ( $slug !== '' && preg_match('/^[a-f0-9]{16,64}$/', $slug) ) {
                $review = DXF_Review::get_by_slug($slug);
                if ( $review && DXF_Review::is_open($review)
                    && in_array($post_id, DXF_Review::resolve_post_ids($review), true) ) {
                    $active_review_id = (int) $review['id'];
                    if ( $review['mode'] === DXF_Review::MODE_EMAIL
                        && class_exists('DXF_Review_Member') && class_exists('DXF_Review_Auth') ) {
                        // Email Review the reviewer is actively in — Approver/Lead only.
                        $member = DXF_Review_Auth::current_member($review);
                        if ( ! $member ) {
                            wp_send_json_error(['message' => __('Your reviewer session has expired. Please open the magic link from your email again.', 'dox-feedback')], 403);
                        }
                        if ( ! DXF_Review_Member::role_can((string) $member['role'], 'approve') ) {
                            wp_send_json_error(['message' => __('Only Approvers and Leads can mark a page as approved.', 'dox-feedback'), 'code' => 'role_required'], 403);
                        }
                    }
                    // Valid session for THIS page (email Approver/Lead above, or a
                    // public link that scopes the page) — entitled to sign off.
                    $proven_for_page = true;
                }
            }

            // No trusted session for this page (cookie absent, invalid, or for a
            // Review that doesn't contain the page). If ANY open email-mode
            // Review scopes this page, the sign-off must come from a verified
            // Approver/Lead of that Review — proven via the session-independent
            // `dxf_member` cookie. This is what closes the "drop the session
            // cookie to skip the role gate" bypass.
            if ( ! $proven_for_page
                && DXF_Review::email_features_available()
                && class_exists('DXF_Review_Member') && class_exists('DXF_Review_Auth') ) {

                $email_reviews = [];
                foreach ( DXF_Review::find(['status' => DXF_Review::STATUS_ACTIVE, 'per_page' => 500]) as $rev ) {
                    if ( $rev['mode'] === DXF_Review::MODE_EMAIL
                        && DXF_Review::is_open($rev)
                        && in_array($post_id, DXF_Review::resolve_post_ids($rev), true) ) {
                        $email_reviews[] = $rev;
                    }
                }

                if ( $email_reviews ) {
                    $approver_review = null;
                    foreach ( $email_reviews as $rev ) {
                        $member = DXF_Review_Auth::current_member($rev);
                        if ( $member && DXF_Review_Member::role_can((string) $member['role'], 'approve') ) {
                            $approver_review = $rev;
                            break;
                        }
                    }
                    if ( ! $approver_review ) {
                        wp_send_json_error(['message' => __('Only Approvers and Leads can mark a page as approved. Please open the magic link from your email again.', 'dox-feedback'), 'code' => 'role_required'], 403);
                    }
                    // Attribute the per-page status to the Review the approver
                    // actually belongs to (the session cookie may have been absent).
                    if ( 0 === $active_review_id ) {
                        $active_review_id = (int) $approver_review['id'];
                    }
                }
            }
        }

        $meta_key = '_dxf_complete_' . $token;
        $already  = (bool) get_post_meta($post_id, $meta_key, true);

        if ( ! $already ) {
            update_post_meta($post_id, $meta_key, current_time('mysql'));

            // Store an immutable approval record (who/when/where) for the
            // sign-off certificate. Name/email come from the reviewer's identity.
            $name  = sanitize_text_field(wp_unslash($_POST['author_name']  ?? ''));
            $email = sanitize_email(wp_unslash($_POST['author_email'] ?? ''));
            // Safety net: when a logged-in reviewer approves, always fall back to
            // their WP account so the certificate never records as anonymous —
            // even if the client-side identity seed didn't reach this request.
            if ( is_user_logged_in() ) {
                $u = wp_get_current_user();
                if ( $name === '' )  { $name  = (string) ( $u->display_name ?: $u->user_login ); }
                if ( $email === '' ) { $email = (string) $u->user_email; }
            }
            DXF_Approvals::record($post_id, $token, $name, $email);

            // Reflect the approval in the reviewer's OWN Review's per-page
            // status. A sign-off belongs to the one review the reviewer is
            // working in — NOT to every review that happens to include this
            // page. The token is per-page, so blanket-approving every review
            // containing the page let one client's approval surface as another
            // client's sign-off whenever two reviews shared a page, silently
            // contaminating the sign-off record. reviews_to_approve_for()
            // resolves the correct single review (or none, when it can't be
            // told apart) — see that method for the exact rules. The immutable
            // DXF_Approvals row + `_dxf_complete_<token>` post-meta remain the
            // authoritative record regardless.
            if ( class_exists('DXF_Review') ) {
                foreach ( $this->reviews_to_approve_for($post_id, $active_review_id) as $rev_id ) {
                    DXF_Review::set_post_status(
                        $rev_id,
                        $post_id,
                        DXF_Review::PAGE_STATUS_APPROVED,
                        $email
                    );
                }
            }

            $this->notify_review_complete($post_id, $token);

            DXF_Events::emit('approval.created', [
                'post_id'    => $post_id,
                'page_title' => get_the_title($post_id),
                'page_url'   => get_permalink($post_id) ?: '',
                'name'       => $name,
                'email'      => $email,
            ]);
        }

        wp_send_json_success(['already' => $already]);
    }

    /**
     * Which Review(s) a page sign-off should mark approved.
     *
     * A sign-off belongs to the ONE review the reviewer is working in — never
     * to every review that happens to include the page. The review token is
     * per-page, not per-review, so "approve every review containing this page"
     * let client A's approval surface as client B's sign-off whenever the two
     * reviews shared a page, silently contaminating the sign-off record that is
     * this plugin's core promise.
     *
     * Resolution (safe by construction):
     *   - Known active review (from the review-session cookie) that still
     *     contains the page → just that review.
     *   - No readable session (a legacy public single-page link, or the
     *     session cookie wasn't sent on this AJAX call): fall back only when
     *     EXACTLY ONE open review contains the page — then there is no other
     *     tenant to contaminate. Zero or two-plus → approve none; the
     *     immutable DXF_Approvals row + `_dxf_complete_<token>` post-meta
     *     remain the authoritative record of the sign-off either way.
     *
     * @return int[] review ids to mark approved (0 or 1 element)
     */
    private function reviews_to_approve_for( int $post_id, int $active_review_id ): array {
        if ( ! class_exists('DXF_Review') ) {
            return [];
        }

        if ( $active_review_id > 0 ) {
            $review = DXF_Review::get($active_review_id);
            if ( $review && DXF_Review::is_open($review)
                && in_array($post_id, DXF_Review::resolve_post_ids($review), true) ) {
                return [ $active_review_id ];
            }
            return [];
        }

        // No active-review signal — only safe to propagate when a single open
        // review owns the page.
        $containing = [];
        foreach ( DXF_Review::find(['status' => DXF_Review::STATUS_ACTIVE, 'per_page' => 500]) as $rev ) {
            if ( ! DXF_Review::is_open($rev) ) {
                continue;
            }
            if ( in_array($post_id, DXF_Review::resolve_post_ids($rev), true) ) {
                $containing[] = (int) $rev['id'];
            }
        }
        return count($containing) === 1 ? $containing : [];
    }

    /**
     * Reviewer signals "I'm done reviewing this page" — notifies the agency that
     * it's ready for their review (distinct from approving it). Throttled per
     * token+post so a double-tap can't spam the inbox.
     */
    /**
     * Per-page "Mark as reviewed" (multi-page reviews) — sets/clears the
     * page's Reviewed marker. No email; the reviewer notifies once from the
     * dashboard. Returns the dashboard URL so the JS can send them back.
     */
    public function ajax_mark_reviewed(): void {
        check_ajax_referer(DXF_Comments::NONCE_ACTION);

        $token    = sanitize_text_field(wp_unslash($_POST['token'] ?? ( $_POST['review_token'] ?? '' )));
        $post_id  = absint($_POST['post_id'] ?? 0);
        $reviewed = ! empty($_POST['reviewed']);
        if ( ! $token || ! $post_id ) {
            wp_send_json_error(['message' => __('Missing data.', 'dox-feedback')], 400);
        }

        $token_data = $this->get_token($token);
        if (
            ! $token_data ||
            (int) $token_data['post_id'] !== $post_id ||
            ! empty($token_data['revoked_at']) ||
            ( $token_data['expires_at'] && strtotime($token_data['expires_at']) < time() )
        ) {
            wp_send_json_error(['message' => __('Invalid review link.', 'dox-feedback')], 403);
        }

        if ( ! class_exists('DXF_Review') ) {
            wp_send_json_error(['message' => __('Unavailable.', 'dox-feedback')], 400);
        }
        $slug   = sanitize_text_field(wp_unslash($_COOKIE[self::SESSION_COOKIE] ?? ''));
        $review = ( $slug !== '' && preg_match('/^[a-f0-9]{16,64}$/', $slug) ) ? DXF_Review::get_by_slug($slug) : null;
        if ( ! $review || ! DXF_Review::is_open($review) ) {
            wp_send_json_error(['message' => __('Review not found.', 'dox-feedback')], 404);
        }

        $name = sanitize_text_field(wp_unslash($_POST['author_name'] ?? ''));
        if ( $name === '' && is_user_logged_in() ) {
            $u = wp_get_current_user();
            $name = (string) ( $u->display_name ?: $u->user_login );
        }

        DXF_Review::set_post_reviewed((int) $review['id'], $post_id, $reviewed, $name);

        if ( class_exists('DXF_Review_Audit') ) {
            DXF_Review_Audit::log((int) $review['id'], null, $reviewed ? 'page_reviewed' : 'page_unreviewed', [
                'post_id' => $post_id,
                'name'    => $name,
            ]);
        }

        wp_send_json_success([
            'reviewed'     => $reviewed,
            'dashboardUrl' => DXF_Review::landing_url((string) $review['slug']),
        ]);
    }

    public function ajax_review_done(): void {
        check_ajax_referer(DXF_Comments::NONCE_ACTION);

        $token   = sanitize_text_field(wp_unslash($_POST['token'] ?? ( $_POST['review_token'] ?? '' )));
        $post_id = absint($_POST['post_id'] ?? 0);
        if ( ! $token || ! $post_id ) {
            wp_send_json_error(['message' => __('Missing data.', 'dox-feedback')], 400);
        }

        // Same token validation as approval: active, unrevoked, this post.
        $token_data = $this->get_token($token);
        if (
            ! $token_data ||
            (int) $token_data['post_id'] !== $post_id ||
            ! empty($token_data['revoked_at']) ||
            ( $token_data['expires_at'] && strtotime($token_data['expires_at']) < time() )
        ) {
            wp_send_json_error(['message' => __('Invalid review link.', 'dox-feedback')], 403);
        }

        $name  = sanitize_text_field(wp_unslash($_POST['author_name']  ?? ''));
        $email = sanitize_email(wp_unslash($_POST['author_email'] ?? ''));
        if ( is_user_logged_in() ) {
            $u = wp_get_current_user();
            if ( $name === '' )  { $name  = (string) ( $u->display_name ?: $u->user_login ); }
            if ( $email === '' ) { $email = (string) $u->user_email; }
        }

        // Throttle: one "ready for review" ping per token+post per 10 minutes.
        $throttle_key = 'dxf_done_ping_' . md5($token . '|' . $post_id);
        if ( get_transient($throttle_key) ) {
            wp_send_json_success(['throttled' => true]);
        }
        set_transient($throttle_key, 1, 10 * MINUTE_IN_SECONDS);

        // Audit against the active review when the session cookie resolves one.
        if ( class_exists('DXF_Review_Audit') && class_exists('DXF_Review') ) {
            $slug = sanitize_text_field(wp_unslash($_COOKIE[self::SESSION_COOKIE] ?? ''));
            if ( $slug !== '' && preg_match('/^[a-f0-9]{16,64}$/', $slug) ) {
                $review = DXF_Review::get_by_slug($slug);
                if ( $review ) {
                    DXF_Review_Audit::log((int) $review['id'], null, 'review_done', [
                        'post_id' => $post_id,
                        'name'    => $name,
                        'email'   => $email,
                    ]);
                }
            }
        }

        $note = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));
        $this->notify_review_done($post_id, $token, $name, $email, $note);

        wp_send_json_success(['ok' => true]);
    }

    /** Email the agency that a reviewer has finished a page (ready-for-review). */
    private function notify_review_done(int $post_id, string $token, string $name, string $email, string $note = ''): void {
        // Piggy-backs on the comment-event toggle — no separate on/off setting.
        if ( ! DXF_Settings::notify_event_enabled('comment') ) {
            return;
        }
        $recipients = DXF_Settings::notify_recipients();
        if ( empty($recipients) ) {
            return;
        }
        $post = get_post($post_id);
        if ( ! $post ) {
            return;
        }
        $site_name  = get_bloginfo('name');
        $post_title = $post->post_title;
        $page_url   = get_permalink($post_id) ?: '';
        $edit_url   = DXF_Comments::builder_url($post_id);
        $who        = $name !== '' ? $name : __('A reviewer', 'dox-feedback');

        $subject = sprintf(
            /* translators: 1: site name, 2: page title */
            __('[%1$s] "%2$s" is ready for your review', 'dox-feedback'),
            $site_name, $post_title
        );

        $plain = sprintf(
            /* translators: 1: reviewer, 2: page title */
            __('%1$s has finished reviewing "%2$s" — it\'s ready for your review.', 'dox-feedback'),
            $who, $post_title
        ) . "\n\n"
          . ( $note !== '' ? __('Note from reviewer:', 'dox-feedback') . ' ' . $note . "\n\n" : '' )
          . __('View in builder:', 'dox-feedback') . ' ' . $edit_url
          . ( $page_url ? "\n" . __('Page URL:', 'dox-feedback') . ' ' . $page_url : '' );

        $body_html =
            '<p>' . sprintf(
                /* translators: 1: reviewer name, 2: page title */
                __('<strong>%1$s</strong> has finished reviewing <strong>%2$s</strong> and it\'s ready for your review.', 'dox-feedback'),
                esc_html($who), esc_html($post_title)
            ) . '</p>'
            . ( $email ? '<p style="color:#888;font-size:13px;margin-top:4px;">' . esc_html($email) . '</p>' : '' )
            . ( $note !== '' ? '<div style="margin-top:12px;padding:10px 14px;background:#f8f8ff;border-left:3px solid #ff8d27;border-radius:4px;"><strong style="display:block;font-size:12px;color:#666;margin-bottom:4px;">' . esc_html__('Note from reviewer', 'dox-feedback') . '</strong><div style="font-size:14px;color:#38385a;line-height:1.55;">' . nl2br(esc_html($note)) . '</div></div>' : '' );

        $html = DXF_Mailer::build_html(
            sprintf(
                /* translators: %s = page title */
                __('"%s" is ready for your review', 'dox-feedback'),
                $post_title
            ),
            $body_html,
            [ ['url' => $edit_url, 'label' => __('View in builder →', 'dox-feedback')] ]
        );

        DXF_Mailer::send(
            $recipients,
            $subject,
            $plain,
            $html,
            DXF_Settings::notify_opts($email, ['event' => 'review_done', 'post_id' => $post_id])
        );
    }

    /**
     * Review-level "I'm done reviewing" from the multi-page dashboard — one ping
     * that the whole review is ready, validated via the reviewer's session
     * cookie (and member auth for email-mode). Throttled per review.
     */
    public function ajax_review_done_all(): void {
        check_ajax_referer(DXF_Comments::NONCE_ACTION);
        if ( ! class_exists('DXF_Review') ) {
            wp_send_json_error(['message' => __('Unavailable.', 'dox-feedback')], 400);
        }
        $slug = sanitize_text_field(wp_unslash($_COOKIE[self::SESSION_COOKIE] ?? ''));
        if ( $slug === '' || ! preg_match('/^[a-f0-9]{16,64}$/', $slug) ) {
            wp_send_json_error(['message' => __('No active review session.', 'dox-feedback')], 403);
        }
        $review = DXF_Review::get_by_slug($slug);
        if ( ! $review || ! DXF_Review::is_open($review) ) {
            wp_send_json_error(['message' => __('Review not found.', 'dox-feedback')], 404);
        }

        $name = '';
        $email = '';
        if ( $review['mode'] === DXF_Review::MODE_EMAIL && class_exists('DXF_Review_Auth') ) {
            $member = DXF_Review_Auth::current_member($review);
            if ( ! $member ) {
                wp_send_json_error(['message' => __('Your reviewer session has expired. Please reopen your magic link.', 'dox-feedback')], 403);
            }
            $name  = (string) ( $member['name']  ?? '' );
            $email = (string) ( $member['email'] ?? '' );
        }
        if ( $name === '' )  { $name  = sanitize_text_field(wp_unslash($_POST['author_name']  ?? '')); }
        if ( $email === '' ) { $email = sanitize_email(wp_unslash($_POST['author_email'] ?? '')); }
        if ( is_user_logged_in() ) {
            $u = wp_get_current_user();
            if ( $name === '' )  { $name  = (string) ( $u->display_name ?: $u->user_login ); }
            if ( $email === '' ) { $email = (string) $u->user_email; }
        }

        $throttle_key = 'dxf_done_rev_' . md5($slug);
        if ( get_transient($throttle_key) ) {
            wp_send_json_success(['throttled' => true]);
        }
        set_transient($throttle_key, 1, 10 * MINUTE_IN_SECONDS);

        if ( class_exists('DXF_Review_Audit') ) {
            DXF_Review_Audit::log((int) $review['id'], null, 'review_done', [
                'name'  => $name,
                'email' => $email,
                'scope' => 'review',
            ]);
        }

        $note = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));
        $this->notify_review_done_all($review, $name, $email, $note);
        wp_send_json_success(['ok' => true]);
    }

    /** Email the agency that a reviewer finished the whole multi-page review. */
    private function notify_review_done_all(array $review, string $name, string $email, string $note = ''): void {
        if ( ! DXF_Settings::notify_event_enabled('comment') ) {
            return;
        }
        $recipients = DXF_Settings::notify_recipients();
        if ( empty($recipients) ) {
            return;
        }
        $site_name  = get_bloginfo('name');
        $project    = ( (string) ( $review['name'] ?? '' ) ) !== '' ? (string) $review['name'] : __('your review', 'dox-feedback');
        $post_ids   = DXF_Review::resolve_post_ids($review);
        $count      = count($post_ids);
        $states     = DXF_Review::get_post_states((int) $review['id']);
        $reviewed   = 0;
        foreach ( $post_ids as $pid ) {
            if ( ! empty($states[$pid]['reviewed_at']) ) {
                $reviewed++;
            }
        }
        $who        = $name !== '' ? $name : __('A reviewer', 'dox-feedback');
        $manage_url = admin_url('admin.php?page=dxf-reviews&action=edit&id=' . (int) $review['id']);

        /* translators: 1: reviewed page count, 2: total page count */
        $progress = sprintf(__('%1$d of %2$d pages marked reviewed.', 'dox-feedback'), $reviewed, $count);

        $subject = sprintf(
            /* translators: 1: site name, 2: review/project name */
            __('[%1$s] %2$s — review finished, ready for you', 'dox-feedback'),
            $site_name, $project
        );

        $plain = sprintf(
            /* translators: 1: reviewer, 2: project */
            __('%1$s has finished reviewing "%2$s" — it\'s ready for you.', 'dox-feedback'),
            $who, $project
        ) . "\n" . $progress . "\n\n"
          . ( $note !== '' ? __('Note from reviewer:', 'dox-feedback') . ' ' . $note . "\n\n" : '' )
          . __('Open the review:', 'dox-feedback') . ' ' . $manage_url;

        $body_html = '<p>' . sprintf(
            /* translators: 1: reviewer, 2: project */
            __('<strong>%1$s</strong> has finished reviewing <strong>%2$s</strong> and it\'s ready for you.', 'dox-feedback'),
            esc_html($who), esc_html($project)
        ) . '</p>'
        . '<p style="color:#666;font-size:13px;margin-top:2px;">' . esc_html($progress) . '</p>'
        . ( $email ? '<p style="color:#888;font-size:13px;margin-top:4px;">' . esc_html($email) . '</p>' : '' )
        . ( $note !== '' ? '<div style="margin-top:12px;padding:10px 14px;background:#f8f8ff;border-left:3px solid #ff8d27;border-radius:4px;"><strong style="display:block;font-size:12px;color:#666;margin-bottom:4px;">' . esc_html__('Note from reviewer', 'dox-feedback') . '</strong><div style="font-size:14px;color:#38385a;line-height:1.55;">' . nl2br(esc_html($note)) . '</div></div>' : '' );

        $html = DXF_Mailer::build_html(
            sprintf(
                /* translators: %s = project name */
                __('%s — review finished', 'dox-feedback'),
                $project
            ),
            $body_html,
            [ ['url' => $manage_url, 'label' => __('Open the review →', 'dox-feedback')] ]
        );

        DXF_Mailer::send(
            $recipients,
            $subject,
            $plain,
            $html,
            DXF_Settings::notify_opts($email, ['event' => 'review_done', 'review_id' => (int) $review['id']])
        );
    }

    private function notify_review_complete(int $post_id, string $token): void {
        if ( ! DXF_Settings::notify_event_enabled('approval') ) {
            return;
        }
        $recipients = DXF_Settings::notify_recipients();
        if ( empty($recipients) ) {
            return;
        }
        $post = get_post($post_id);
        if ( ! $post ) {
            return;
        }
        $site_name   = get_bloginfo('name');
        $post_title  = $post->post_title;
        $review_url  = self::build_review_url($token);
        $page_url    = get_permalink($post_id);

        // Look up the just-recorded approval row so we can name the approver
        // and attach the printable certificate to the email.
        $approval = DXF_Approvals::latest_for_post($post_id);
        $approver_name  = $approval['name']  ?? '';
        $approver_email = $approval['email'] ?? '';

        $subject = sprintf(
            /* translators: 1: site name, 2: page title */
            __('[%1$s] "%2$s" has been approved', 'dox-feedback'),
            $site_name, $post_title
        );

        $plain = sprintf(
            /* translators: 1: page title, 2: reviewer name */
            __('"%1$s" has been marked as approved%2$s.', 'dox-feedback'),
            $post_title,
            $approver_name ? ' ' . sprintf(
                /* translators: %s = reviewer name */
                __('by %s', 'dox-feedback'),
                $approver_name
            ) : ''
        ) . "\n\n"
            . __('Review link:', 'dox-feedback') . ' ' . $review_url . "\n"
            . __('Page URL:', 'dox-feedback')    . ' ' . $page_url;

        $by_html = $approver_name
            ? ' ' . sprintf(
                /* translators: 1: reviewer name, 2: reviewer email */
                __('by <strong>%1$s</strong>%2$s', 'dox-feedback'),
                esc_html($approver_name),
                $approver_email ? ' <span style="color:#888;">(' . esc_html($approver_email) . ')</span>' : ''
            )
            : '';

        $body_html =
            '<p>'
            . sprintf(
                /* translators: %s = page title */
                __('A reviewer has marked <strong>%s</strong> as approved.', 'dox-feedback'),
                esc_html($post_title)
            )
            . $by_html . '</p>'
            . '<p style="margin-top:8px;">'
            . '<span style="display:inline-block;background:#f0fdf4;border:1px solid #bbf7d0;'
            . 'color:#15803d;border-radius:6px;padding:8px 14px;font-weight:600;">&#10003; ' . esc_html__('Page approved', 'dox-feedback') . '</span>'
            . '</p>';

        $html = DXF_Mailer::build_html(
            sprintf(
                /* translators: %s = page title */
                __('"%s" has been approved', 'dox-feedback'),
                $post_title
            ),
            $body_html,
            [
                ['url' => $review_url, 'label' => __('View review link →', 'dox-feedback')],
                ['url' => $page_url,   'label' => __('View page →',        'dox-feedback')],
            ]
        );

        // Custom From / Reply-To and a printable approval-certificate attachment
        // can be added by a listener filtering the mailer opts for the 'approval'
        // event. By default this sends a plain approval notification.
        $opts = DXF_Settings::notify_opts($approver_email, [
            'event'       => 'approval',
            'post_id'     => $post_id,
            'approval_id' => (int) ( $approval['id'] ?? 0 ),
        ]);

        DXF_Mailer::send($recipients, $subject, $plain, $html, $opts);

        // Let a listener (e.g. a certificate generator) clean up any temporary
        // attachment it created for this send.
        do_action('dxf_notify_sent', 'approval', $opts);
    }

    // -------------------------------------------------------------------------
    // v0.16 Reviews bridge — bootstrap the legacy per-page session from a
    // Reviews redirect (?dxf_review=<slug>). Mints/reuses a per-page token
    // so the rest of this controller (cookie + AJAX) works unchanged.
    // -------------------------------------------------------------------------

    private function maybe_bootstrap_from_reviews_bridge(): void {
        if ( empty($_GET['dxf_review']) ) return;
        if ( ! class_exists('DXF_Review') ) return;

        $slug = sanitize_text_field((string) wp_unslash($_GET['dxf_review']));
        if ( ! preg_match('/^[a-f0-9]{16,64}$/', $slug) ) return;

        $review = DXF_Review::get_by_slug($slug);
        if ( ! $review || ! DXF_Review::is_open($review) ) return;

        // Prefer the explicit hint from handle_page_open() — that's the only
        // reliable identifier when the page being reviewed is the WordPress
        // front page set to "Latest posts" (get_queried_object_id() returns
        // 0 in that case, and on archive/404 URLs more generally). Fall back
        // to the queried object for clean permalinks. Final fallback: the
        // session-bridge transient set by DXF_Reviews::handle_page_open().
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $hinted_post_id = isset($_GET['dxf_review_post']) ? (int) $_GET['dxf_review_post'] : 0;
        $post_id = $hinted_post_id > 0 ? $hinted_post_id : (int) get_queried_object_id();
        if ( $post_id <= 0 && class_exists('DXF_Review_Session_Bridge') ) {
            $ctx = DXF_Review_Session_Bridge::get_context();
            if ( is_array($ctx) && (int) ($ctx['review_id'] ?? 0) === (int) $review['id'] ) {
                $post_id = (int) ($ctx['post_id'] ?? 0);
            }
        }
        if ( $post_id <= 0 ) return;

        if ( ! $this->bootstrap_session_for($review, $post_id) ) return;

        // Strip our bridge query args so refresh doesn't re-trigger this.
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $clean       = remove_query_arg(['dxf_review', 'dxf_review_post']);
        if ( $clean !== $request_uri ) {
            wp_safe_redirect($clean);
            exit;
        }
    }

    /**
     * Set up the reviewer's per-page review session for a given Review +
     * post_id pair. Validates scope + role, marks the page as in_review,
     * mints (or reuses) the legacy per-page token cookie, and writes the
     * parallel session cookie used by the cross-page chrome.
     *
     * Returns true if the session was established (or was already valid),
     * false if scope/role gating refused the bootstrap. Callers should send
     * their own redirect/response — this method does not exit.
     *
     * Called from two places:
     *   1. maybe_bootstrap_from_reviews_bridge() — when a permalink load
     *      carries ?dxf_review=<slug>, after we've resolved the post.
     *   2. DXF_Reviews::handle_page_open() — directly on the
     *      /dox-feedback/<slug>/page/<id>/ hit, so the cookies are set
     *      before the redirect to the page permalink. That's the resilient
     *      path: even if a query-arg or caching plugin strips
     *      ?dxf_review= on the permalink, the cookies are already on
     *      the response and maybe_continue_review_session() picks them up
     *      from the cookie jar alone.
     */
    public function bootstrap_session_for( array $review, int $post_id ): bool {
        if ( $post_id <= 0 ) return false;

        // Page must be in the review's scope.
        $allowed = DXF_Review::resolve_post_ids($review);
        if ( ! in_array($post_id, $allowed, true) ) return false;

        // Email-mode reviews require a verified cookie session.
        if ( $review['mode'] === DXF_Review::MODE_EMAIL ) {
            // If the email auth + role classes aren't loaded, this email review
            // can't be entered (don't fatal on the missing classes).
            if ( ! DXF_Review::email_features_available() ) return false;
            $member = DXF_Review_Auth::current_member($review);
            if ( ! $member ) return false;
            if ( ! DXF_Review_Member::role_can((string) $member['role'], 'comment') ) {
                // Viewer-only roles can browse pages but not enter review mode.
                return false;
            }
        }

        // Mark page as 'in_review' (idempotent — only writes if currently 'todo').
        $states = DXF_Review::get_post_states((int) $review['id']);
        $cur    = $states[$post_id]['status'] ?? DXF_Review::PAGE_STATUS_TODO;
        if ( $cur === DXF_Review::PAGE_STATUS_TODO ) {
            DXF_Review::set_post_status((int) $review['id'], $post_id, DXF_Review::PAGE_STATUS_IN_REVIEW);
        }

        // Mint or reuse a per-page legacy token, then set the session cookie.
        // Token lifetime FOLLOWS THE REVIEW — never outlive its expiry. The
        // revoke path also revokes tokens explicitly, but expiry alignment
        // makes every closure path (cron expiry, demo cleanup, admin close)
        // self-healing: a flat 30 days here used to leave bridge tokens alive
        // for a month after short-lived reviews ended.
        $existing = self::get_active_token_for_post($post_id);
        $days     = 30;
        if ( ! empty($review['expires_at']) ) {
            $remaining = strtotime((string) $review['expires_at']) - time();
            if ( $remaining > 0 ) {
                $days = max(1, min(30, (int) ceil($remaining / DAY_IN_SECONDS)));
            }
        }
        $token = $existing['token'] ?? $this->create_token($post_id, $days);

        $secure = is_ssl();
        setcookie('dxf_reviewing', $token, [
            'expires'  => time() + (14 * DAY_IN_SECONDS),
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['dxf_reviewing'] = $token;

        // Parallel session cookie that survives navigation off the bridged
        // page. Used by enqueue_review_assets_for() to render the always-on
        // nav panel + off-scope banner on any URL while the reviewer is
        // actively reviewing this Review. Slug is a 48-hex string (random,
        // not enumerable) so it's safe to expose to JS — no PII.
        setcookie(self::SESSION_COOKIE, (string) $review['slug'], [
            'expires'  => time() + (14 * DAY_IN_SECONDS),
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => false, // readable by JS so the banner can fade in pre-render
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::SESSION_COOKIE] = (string) $review['slug'];

        return true;
    }

    // -------------------------------------------------------------------------
    // Token helpers
    // -------------------------------------------------------------------------

    private function create_token(int $post_id, int $expiry_days): string {
        global $wpdb;

        $token = bin2hex(random_bytes(24));

        $wpdb->insert(
            $wpdb->prefix . 'dxf_review_tokens',
            [
                'post_id'    => $post_id,
                'token'      => $token,
                'expires_at' => $expiry_days > 0
                    ? gmdate('Y-m-d H:i:s', time() + ($expiry_days * DAY_IN_SECONDS))
                    : null,
                'created_by' => get_current_user_id(),
            ],
            ['%d', '%s', '%s', '%d']
        );

        return $token;
    }

    private function get_token(string $token): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE token = %s LIMIT 1",
                $wpdb->prefix . 'dxf_review_tokens', $token
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_active_token_for_post(int $post_id): ?array {
        global $wpdb;
        // Must exclude EXPIRED tokens, not just revoked ones — otherwise
        // bootstrap_session_for() reuses a stale token that validate_review_token()
        // then rejects with "Invalid review link". This bit the demo hard: its
        // pages are reused across many short-lived sessions, so an expired token
        // from a prior session would be handed back instead of minting a fresh
        // one. Mirrors the expiry check in DXF_Comments::validate_review_token().
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM %i
                  WHERE post_id = %d AND revoked_at IS NULL
                    AND (expires_at IS NULL OR expires_at > NOW())
                  ORDER BY created_at DESC LIMIT 1",
                $wpdb->prefix . 'dxf_review_tokens', $post_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function build_review_url(string $token): string {
        return home_url('/' . self::TOKEN_QUERY_VAR . '/' . $token . '/');
    }
}
