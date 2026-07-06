<?php
/**
 * Dox Feedback Reviews — orchestrator for the v0.16 Review feature.
 *
 * Wires:
 *   - Rewrite rules:
 *       /dox-feedback/<slug>/                  → reviewer landing (page checklist)
 *       /dox-feedback/<slug>/activate/<token>/ → magic-link activation
 *       /dox-feedback/<slug>/item/<post_id>/   → drop into per-page review mode
 *   - WP-cron sweep for expired reviews
 *   - Admin AJAX endpoints (create review, invite reviewers, revoke, close)
 *   - Magic-link emails (uses DXF_Mailer for From/Reply-To consistency)
 *
 * Per-page review mode itself still uses the existing DXF_Review_Mode
 * controller; this class just hands off into it with a verified review context.
 *
 * @since 0.16.0
 */

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

final class DXF_Reviews {

    public const CRON_HOOK   = 'dxf_reviews_sweep';
    public const QUERY_VAR   = 'dxf_review_slug';
    public const QV_ACTIVATE = 'dxf_review_activate';
    public const QV_PAGE     = 'dxf_review_page';

    // Bump when the rewrite rule set in add_rewrite_rules() changes shape.
    // A mismatch with the stored dxf_reviews_rewrites_v option triggers a
    // one-shot flush on init — so installs that picked up the Reviews module
    // via an update (rather than activation) get their /dox-feedback/*
    // routes registered without needing the admin to deactivate/reactivate.
    //
    // v3: page-handoff segment renamed `page` → `item`. The `page` segment
    //     collides with WP's pagination convention (`/page/N/`) — even with
    //     our 'top'-priority rewrite, downstream layers (redirect_canonical,
    //     Rank Math's canonical handler, some hosts' URL normalisers)
    //     interpret `…/page/<id>/` as pagination of the parent path and
    //     silently 30x-redirect back to the dashboard URL. Symptom: the
    //     reviewer clicks a page card and the URL bar never changes from
    //     /dox-feedback/<slug>/. Switching to `item` sidesteps the whole
    //     mechanism.
    public const REWRITES_VERSION = 4;

    // The landing rewrite rule's regex key, used by maybe_flush_rewrites() to
    // detect when our rules have fallen out of WP's persisted rewrite cache
    // (permalink-structure switch, folder rename, another plugin rebuilding the
    // cache) so we can self-heal without waiting for a REWRITES_VERSION bump.
    private const LANDING_RULE = '^dox-feedback/([a-f0-9]{16,64})/?$';

    public function __construct() {
        add_action('init',                    [$this, 'add_rewrite_rules']);
        add_action('init',                    [$this, 'maybe_flush_rewrites'], 99);
        add_filter('query_vars',              [$this, 'add_query_vars']);
        add_action('template_redirect',       [$this, 'maybe_handle_request']);
        add_action(self::CRON_HOOK,           [self::class, 'cron_sweep']);
        add_action('admin_init',              [self::class, 'maybe_schedule_cron']);

        // Admin-bar popout — runs after the Dox Feedback root node (priority 99)
        // so our sub-menu nests under it. This is the *only* admin-bar node
        // for the popout now; legacy review-link nodes were retired in the
        // popout redesign and quick-review.js renders the whole panel.
        add_action('admin_bar_menu',          [$this, 'add_admin_bar_quick_review_node'], 100);
        add_action('wp_enqueue_scripts',      [$this, 'enqueue_quick_review_assets']);
        add_action('admin_enqueue_scripts',   [$this, 'enqueue_quick_review_assets']);

        if ( is_admin() ) {
            new DXF_Reviews_Admin();
        }

        // Admin AJAX (capability + nonce checked inside each handler). The
        // email-restricted invite/revoke/role/resend endpoints — and the
        // reviewer-facing Lead-invite endpoint — are registered by the email
        // module (DXF_Email_Reviews) when it loads.
        add_action('wp_ajax_dxf_review_create',  [self::class, 'ajax_create']);
        add_action('wp_ajax_dxf_review_close',   [self::class, 'ajax_close']);
        add_action('wp_ajax_dxf_review_reopen',  [self::class, 'ajax_reopen']);
        add_action('wp_ajax_dxf_review_delete',  [self::class, 'ajax_delete']);
        add_action('wp_ajax_dxf_review_publish', [self::class, 'ajax_publish']);
        add_action('wp_ajax_dxf_quick_review',   [self::class, 'ajax_quick_review']);

        // Read-receipt beacon — review.js fires this once the reviewer overlay
        // boots in a real browser. Both priv + nopriv: a logged-in agency user
        // opening their own link is filtered out inside the handler so it never
        // counts as a "client viewed".
        add_action('wp_ajax_nopriv_dxf_review_seen', [self::class, 'ajax_review_seen']);
        add_action('wp_ajax_dxf_review_seen',        [self::class, 'ajax_review_seen']);
        // Fired when a guest completes the identity gate (name, optional email).
        // Lets the "client opened" receipt name the reviewer even when the very
        // first open was anonymous (the gate appears later, when they comment).
        add_action('wp_ajax_nopriv_dxf_review_identified', [self::class, 'ajax_review_identified']);
        add_action('wp_ajax_dxf_review_identified',        [self::class, 'ajax_review_identified']);
    }

    /**
     * Read-receipt beacon. Auth model: the review-session cookie (same trust
     * boundary as the public comment endpoints — no nonce for guests). We log
     * the view client-side via JS rather than server-side on the link hit so
     * email link-scanners (SafeLinks, Mimecast, Proofpoint) — which fetch the
     * URL but never execute JS — don't fabricate a "client opened it" event.
     */
    public static function ajax_review_seen(): void {
        // The agency's own opens (any user who can edit) are not "client" views.
        if ( current_user_can('edit_posts') ) {
            wp_send_json_success(['skipped' => 'staff']);
        }

        $review_id = DXF_Comments::resolve_active_review_id();
        if ( ! $review_id ) {
            wp_send_json_success(['skipped' => 'no_review']);
        }

        // Identity is optional here — usually the reviewer hasn't completed the
        // gate yet at first open, so the receipt is anonymous until they do.
        list( $name, $email ) = self::read_identity();
        self::record_client_view($review_id, $name, $email);
        wp_send_json_success(['ok' => true]);
    }

    /**
     * Fired when a guest completes the identity gate. Records who they are and,
     * if the "opened" receipt already went out anonymously, sends a short
     * follow-up naming them. Staff are ignored (they don't see the gate).
     */
    public static function ajax_review_identified(): void {
        if ( current_user_can('edit_posts') ) {
            wp_send_json_success(['skipped' => 'staff']);
        }
        $review_id = DXF_Comments::resolve_active_review_id();
        if ( ! $review_id ) {
            wp_send_json_success(['skipped' => 'no_review']);
        }
        list( $name, $email ) = self::read_identity();
        if ( $name === '' ) {
            wp_send_json_success(['skipped' => 'no_name']);
        }
        self::record_client_identity($review_id, $name, $email);
        wp_send_json_success(['ok' => true]);
    }

    /** Sanitised [name, email] from the request (both optional). */
    private static function read_identity(): array {
        // Read from public nopriv read-receipt beacons; the request is secured by
        // token-scoped resolve_active_review_id() in the callers, not a form nonce.
        // Both values are fully sanitised here and nothing privileged is performed.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $name  = sanitize_text_field(wp_unslash($_POST['author_name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['author_email'] ?? ''));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        return [ $name, $email ];
    }

    private static function viewer_ip_hash(): string {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return $ip !== '' ? substr(hash('sha256', $ip . wp_salt('auth')), 0, 16) : 'none';
    }

    private static function identity_meta( string $name, string $email ): array {
        $meta = [];
        if ( $name !== '' )  { $meta['name']  = $name; }
        if ( $email !== '' ) { $meta['email'] = $email; }
        return $meta;
    }

    /**
     * Log a throttled "viewed" audit event for a review and, on the FIRST ever
     * view, notify the agency. Throttle: one logged view per viewer (ip_hash)
     * per review every 30 min, so a reviewer clicking between pages doesn't
     * spam the audit log or trigger repeat emails.
     */
    private static function record_client_view(int $review_id, string $name = '', string $email = ''): void {
        $key = 'dxf_seen_' . $review_id . '_' . self::viewer_ip_hash();
        if ( get_transient($key) ) {
            return;
        }
        set_transient($key, 1, 30 * MINUTE_IN_SECONDS);

        // Detect first view BEFORE logging the new one.
        $first = ! DXF_Review_Audit::has_event($review_id, 'viewed');
        DXF_Review_Audit::log($review_id, null, 'viewed', self::identity_meta($name, $email));

        if ( $first ) {
            self::notify_open($review_id, $name, $email);
        }
    }

    /**
     * Record the reviewer's self-identification (name + optional email). Logged
     * once per viewer per review; triggers notify_open() so an anonymous
     * "opened" receipt gets a naming follow-up.
     */
    private static function record_client_identity(int $review_id, string $name, string $email): void {
        $key = 'dxf_ident_' . $review_id . '_' . self::viewer_ip_hash();
        if ( get_transient($key) ) {
            return;
        }
        set_transient($key, 1, 30 * MINUTE_IN_SECONDS);

        DXF_Review_Audit::log($review_id, null, 'identified', self::identity_meta($name, $email));
        self::notify_open($review_id, $name, $email);

        /**
         * Fires once (per reviewer, per 30-min window) when a reviewer gives
         * their identity on a review. Lets add-ons capture the email — e.g. the
         * doxstudio.com demo launcher pipes demo emails to a MailerLite group.
         * No-op in the shipped plugin (nothing hooks it).
         *
         * @since 1.0.9
         * @param string $name       Reviewer name (may be empty).
         * @param string $email      Reviewer email (may be empty).
         * @param int    $review_id  The review they're on.
         */
        do_action('dxf_reviewer_identified', $name, $email, $review_id);
    }

    /**
     * Email the agency about a client opening the review. Sends at most one
     * "opened" email and, if that one was anonymous, at most one follow-up once
     * the reviewer identifies themselves. State is tracked in a per-review
     * day-long transient: '' (none) → 'anon' → 'named'.
     *
     * @param string $name  Reviewer name, or '' if not yet known.
     * @param string $email Reviewer email, or '' if not provided.
     */
    private static function notify_open(int $review_id, string $name = '', string $email = ''): void {
        if ( ! DXF_Settings::notify_event_enabled('viewed') ) {
            return;
        }
        $state     = (string) get_transient('dxf_open_notified_' . $review_id );
        $have_name = $name !== '';
        if ( $state === 'named' ) {
            return; // already named them — nothing more to say
        }
        if ( $state === 'anon' && ! $have_name ) {
            return; // already sent the anonymous receipt, no new info
        }

        $review = DXF_Review::get($review_id);
        if ( ! $review ) {
            return;
        }
        $recipients = DXF_Settings::notify_recipients();
        if ( empty($recipients) ) {
            return;
        }

        $site   = get_bloginfo('name');
        $name_r = (string) ($review['name'] ?? '') ?: __('your review', 'dox-feedback');
        $manage = admin_url('admin.php?page=dxf-reviews&action=edit&id=' . $review_id);
        $who    = $have_name
            ? $name . ( $email !== '' ? ' (' . $email . ')' : '' )
            : __('A client', 'dox-feedback');
        $who_h  = $have_name
            ? '<strong>' . esc_html($name) . '</strong>' . ( $email !== '' ? ' (' . esc_html($email) . ')' : '' )
            : __('A client', 'dox-feedback');

        $is_followup = ( $state === 'anon' );
        if ( $is_followup ) {
            /* translators: 1: site name, 2: reviewer name, 3: review name */
            $subject   = sprintf(__('[%1$s] %2$s is reviewing "%3$s"', 'dox-feedback'), $site, $name, $name_r);
            /* translators: 1: reviewer (name + email), 2: review name */
            $body_html = '<p>' . sprintf(__('The client who opened <strong>%2$s</strong> just identified themselves as %1$s.', 'dox-feedback'), $who_h, esc_html($name_r)) . '</p>';
            /* translators: 1: reviewer (name + email), 2: review name */
            $plain     = sprintf(__('The client who opened "%2$s" just identified themselves as %1$s.', 'dox-feedback'), $who, $name_r);
            $heading   = __('We know who\'s reviewing', 'dox-feedback');
        } else {
            if ( $have_name ) {
                /* translators: 1: site name, 2: reviewer name, 3: review name */
                $subject = sprintf(__('[%1$s] %2$s just opened "%3$s"', 'dox-feedback'), $site, $name, $name_r);
            } else {
                /* translators: 1: site name, 2: review name */
                $subject = sprintf(__('[%1$s] A client just opened "%2$s"', 'dox-feedback'), $site, $name_r);
            }
            /* translators: 1: reviewer (name + email) or "A client", 2: review name */
            $body_html = '<p>' . sprintf(__('%1$s just opened the review link for <strong>%2$s</strong> for the first time.', 'dox-feedback'), $who_h, esc_html($name_r)) . '</p>';
            /* translators: 1: reviewer (name + email) or "A client", 2: review name */
            $plain     = sprintf(__('%1$s just opened the review link for "%2$s" for the first time.', 'dox-feedback'), $who, $name_r);
            $heading   = __('A client opened your review', 'dox-feedback');
        }
        $plain .= "\n\n" . __('Manage review:', 'dox-feedback') . ' ' . $manage;

        $html = DXF_Mailer::build_html(
            $heading,
            $body_html,
            [['url' => $manage, 'label' => __('Open review →', 'dox-feedback')]]
        );

        DXF_Mailer::send($recipients, $subject, $plain, $html, DXF_Settings::notify_opts('', ['event' => 'viewed', 'review_id' => $review_id]));

        set_transient('dxf_open_notified_' . $review_id, $have_name ? 'named' : 'anon', DAY_IN_SECONDS);
    }

    // ---------------------------------------------------------------------
    // Rewrites
    // ---------------------------------------------------------------------

    public function add_rewrite_rules(): void {
        // /dox-feedback/<slug>/activate/<token>/
        add_rewrite_rule(
            '^dox-feedback/([a-f0-9]{16,64})/activate/([a-f0-9]{16,64})/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]&' . self::QV_ACTIVATE . '=$matches[2]',
            'top'
        );
        // /dox-feedback/<slug>/item/<post_id>/
        // Note: deliberately NOT `page/<id>/` — that path segment collides
        // with WordPress's built-in pagination, and downstream canonical-
        // redirect handlers (core's redirect_canonical, Rank Math, etc.)
        // can strip it and bounce back to the dashboard URL. See
        // REWRITES_VERSION comment above for the full incident note.
        add_rewrite_rule(
            '^dox-feedback/([a-f0-9]{16,64})/item/(\d+)/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]&' . self::QV_PAGE . '=$matches[2]',
            'top'
        );
        // /dox-feedback/<slug>/
        add_rewrite_rule(
            '^dox-feedback/([a-f0-9]{16,64})/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]',
            'top'
        );
    }

    public function add_query_vars(array $vars): array {
        $vars[] = self::QUERY_VAR;
        $vars[] = self::QV_ACTIVATE;
        $vars[] = self::QV_PAGE;
        return $vars;
    }

    /**
     * Keep the reviewer-link rewrite rules registered in WP's persisted rewrite
     * cache. Solves the "I created a Review and the share/activation link 404s"
     * symptom, which happens when add_rewrite_rule() registrations aren't
     * persisted yet — register_activation_hook only fires on activate, so an
     * in-place upgrade, a plugin-folder rename, a permalink-structure switch, or
     * another plugin rebuilding the cache can all leave our rules missing.
     *
     * Two triggers:
     *   1. REWRITES_VERSION changed since we last flushed (the rule set's shape
     *      changed, or we want to force a one-time re-flush on upgrade).
     *   2. Self-heal — the version matches but our landing rule is no longer in
     *      the persisted rewrite_rules option. Re-flush once (rate-limited so a
     *      hostile environment can't trigger a flush storm).
     */
    public function maybe_flush_rewrites(): void {
        $stored = (int) get_option('dxf_reviews_rewrites_v', 0);
        if ( $stored !== self::REWRITES_VERSION ) {
            flush_rewrite_rules(false);
            update_option('dxf_reviews_rewrites_v', self::REWRITES_VERSION, false);
            return;
        }

        // On "Plain" permalinks no rewrite rule is needed — reviewer URLs fall
        // back to query-var form (see DXF_Review::review_url()), so don't flush.
        if ( ! get_option('permalink_structure') ) {
            return;
        }

        // add_rewrite_rules() (init prio 10) has already registered our rule in
        // the live $wp_rewrite object; here we check the PERSISTED cache. If our
        // landing rule isn't there, a flush regenerates it from the registered
        // rules — self-healing the 404 without any manual permalink re-save.
        $rules = get_option('rewrite_rules');
        if ( is_array($rules) && ! isset($rules[ self::LANDING_RULE ]) ) {
            if ( get_transient('dxf_reviews_rewrite_heal') ) {
                return;
            }
            set_transient('dxf_reviews_rewrite_heal', 1, HOUR_IN_SECONDS);
            flush_rewrite_rules(false);
        }
    }

    // ---------------------------------------------------------------------
    // Request handling
    // ---------------------------------------------------------------------

    public function maybe_handle_request(): void {
        $slug = get_query_var(self::QUERY_VAR);
        if ( ! $slug ) return;

        self::debug_log('maybe_handle_request: enter', [
            'slug'       => $slug,
            'activation' => (string) get_query_var(self::QV_ACTIVATE),
            'page'       => (int) get_query_var(self::QV_PAGE),
            'request'    => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '',
        ]);

        $review = DXF_Review::get_by_slug((string) $slug);
        if ( ! $review ) {
            self::debug_log('maybe_handle_request: slug not found → 404');
            status_header(404);
            $this->render_error(__('Review not found.', 'dox-feedback'));
            exit;
        }

        // SEO hardening — identical to per-page hardening from 0.14.1.
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        header('Referrer-Policy: no-referrer', true);
        add_action('wp_head', function (): void {
            echo "\n<meta name=\"robots\" content=\"noindex, nofollow, noarchive\" />\n";
            echo "<meta name=\"referrer\" content=\"no-referrer\" />\n";
        }, 1);

        // Activation route
        $activation = (string) get_query_var(self::QV_ACTIVATE);
        if ( $activation !== '' ) {
            $this->handle_activation($review, $activation);
            exit;
        }

        // Per-page review-mode handoff
        $page_id = (int) get_query_var(self::QV_PAGE);
        if ( $page_id > 0 ) {
            $this->handle_page_open($review, $page_id);
            exit;
        }

        // Landing
        $this->render_landing($review);
        exit;
    }

    private function handle_activation(array $review, string $token): void {
        // Magic-link activation is part of the email-restricted feature (Dox Feedback
        // Pro). If Pro is no longer installed, the link can't be honoured —
        // show a neutral notice instead of calling the absent auth layer.
        if ( ! DXF_Review::email_features_available() ) {
            $this->render_landing_error($review, __('This review is no longer available.', 'dox-feedback'));
            return;
        }
        $result = DXF_Review_Auth::activate_token($token, (string) $review['slug']);
        if ( is_wp_error($result) ) {
            $data = $result->get_error_data();
            if ( is_array($data) && ! empty($data['status']) ) {
                status_header((int) $data['status']);
            }
            $this->render_landing_error($review, $result->get_error_message());
            return;
        }
        // Successful activation → bounce to landing as the logged-in member.
        wp_safe_redirect(DXF_Review::landing_url((string) $review['slug']));
    }

    private function handle_page_open(array $review, int $page_id): void {
        self::debug_log('handle_page_open: enter', ['review_id' => $review['id'] ?? 0, 'slug' => $review['slug'] ?? '', 'page_id' => $page_id, 'status' => $review['status'] ?? '']);

        if ( ! DXF_Review::is_open($review) ) {
            self::debug_log('handle_page_open: review not open → render landing-error', ['status' => $review['status'] ?? '']);
            $this->render_landing_error($review, __('This review is closed.', 'dox-feedback'));
            return;
        }

        // Email-mode reviews require an active cookie session.
        if ( $review['mode'] === DXF_Review::MODE_EMAIL ) {
            // If the email auth layer isn't loaded, an email-mode review can no
            // longer be opened — surface a neutral notice rather than fatal.
            if ( ! DXF_Review::email_features_available() ) {
                self::debug_log('handle_page_open: email-mode but Pro auth absent → unavailable');
                $this->render_landing_error($review, __('This review is no longer available.', 'dox-feedback'));
                return;
            }
            $member = DXF_Review_Auth::current_member($review);
            if ( ! $member ) {
                self::debug_log('handle_page_open: email-mode + no member → redirect to landing');
                wp_safe_redirect(DXF_Review::landing_url((string) $review['slug']));
                return;
            }
            if ( ! DXF_Review_Member::role_can((string) $member['role'], 'view') ) {
                self::debug_log('handle_page_open: member role cannot view → render_error', ['role' => $member['role']]);
                $this->render_error(__('You do not have access to this review.', 'dox-feedback'));
                return;
            }
        }

        // Page must be in scope.
        $allowed = DXF_Review::resolve_post_ids($review);
        if ( ! in_array($page_id, $allowed, true) ) {
            self::debug_log('handle_page_open: page not in scope → render_error', ['page_id' => $page_id, 'allowed' => $allowed]);
            $this->render_error(__('That page is not part of this review.', 'dox-feedback'));
            return;
        }

        // Resolve the permalink before we mutate response state — if the post
        // is gone (trashed/deleted), surface a clean error rather than redirect
        // into the void.
        $url = get_permalink($page_id);
        if ( ! $url ) {
            self::debug_log('handle_page_open: get_permalink returned falsy → render_error', ['page_id' => $page_id]);
            $this->render_error(__('Page is no longer available.', 'dox-feedback'));
            return;
        }
        self::debug_log('handle_page_open: resolved permalink', ['url' => $url]);

        // Set the session cookies + per-page token RIGHT NOW, on this response.
        // Doing it here (rather than relying on the permalink hit to pick up
        // ?dxf_review=<slug> and bootstrap from there) means the redirect
        // chain is exactly one hop: /dox-feedback/<slug>/page/<id>/ →
        // /<post-permalink>/, with no query args needed. That's resilient
        // against caching plugins, security plugins, CDN edge rules, and any
        // other layer that might strip our query args before maybe_continue_
        // review_session() gets a chance to bootstrap. DXF_Review_Mode is
        // always available alongside DXF_Reviews — both ship in Free.
        if ( class_exists('DXF_Review_Mode') ) {
            $mode = new DXF_Review_Mode();
            $bootstrapped = $mode->bootstrap_session_for($review, $page_id);
            self::debug_log('handle_page_open: bootstrap_session_for', ['ok' => $bootstrapped]);
        } else {
            self::debug_log('handle_page_open: DXF_Review_Mode class missing!');
        }

        // Also stash a transient as a belt-and-braces fallback for the
        // existing ?dxf_review path (covers any caller that arrives at the
        // permalink WITHOUT having gone through this handler).
        DXF_Review_Session_Bridge::set_context((int) $review['id'], $page_id);

        // Cache buster — page-cache plugins (WP Rocket, W3 Total Cache,
        // LiteSpeed, Cloudflare APO, server-level Nginx fastcgi_cache, etc.)
        // can serve a stale response that was generated before the reviewer
        // had cookies. Without the overlay enqueue conditions firing, the
        // page renders bare and the reviewer thinks the demo is broken.
        // Appending a unique-per-session token forces the cache to miss on
        // this first hit. The token is short and tied to the review slug so
        // it's stable across the session's lifetime (so the reviewer can
        // hit Back/Forward without re-busting). review.js strips the param
        // from the URL bar via history.replaceState() after render so the
        // address bar stays clean.
        $cache_bust  = substr((string) $review['slug'], 0, 8);
        $redirect_to = add_query_arg(['dxf_session' => $cache_bust], $url);

        self::debug_log('handle_page_open: issuing wp_safe_redirect', ['to' => $redirect_to]);
        wp_safe_redirect($redirect_to);
    }

    /**
     * True when the current frontend request is rendering a /dox-feedback/<slug>/
     * surface (dashboard, activation, or per-item handoff). We use this to
     * skip enqueuing the admin-bar quick-review panel — its JS depends on a
     * normal post context which doesn't exist on these custom-template URLs.
     *
     * Read the query var rather than parsing REQUEST_URI ourselves: by the
     * time admin_bar_menu/wp_enqueue_scripts fire (post-parse_request), WP
     * has populated query vars from the rewrite match.
     */
    private function is_on_reviewer_dashboard(): bool {
        if ( is_admin() ) return false;
        return (string) get_query_var(self::QUERY_VAR) !== '';
    }

    /**
     * Lightweight debug logger — OFF by default, opt-in via the dedicated
     * DXF_DEBUG constant (add `define('DXF_DEBUG', true);` to wp-config).
     *
     * Deliberately NOT gated on plain WP_DEBUG: lots of production sites run
     * WP_DEBUG / WP_DEBUG_LOG on for unrelated reasons, and tracing every
     * /dox-feedback/* hit (three lines per page open, plus the review slug)
     * into their debug.log is pure noise + needless disk I/O. On a site whose
     * review links are being repeatedly prefetched by an email link-scanner
     * (SafeLinks, Mimecast, Proofpoint, …) that adds up fast. Never logs PII —
     * only IDs, statuses, URL strings.
     */
    private static function debug_log(string $msg, array $ctx = []): void {
        if ( ! defined('DXF_DEBUG') || ! DXF_DEBUG ) return;
        $ctx_str = empty($ctx) ? '' : ' | ' . wp_json_encode($ctx);
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('[Dox Feedback/Reviews] ' . $msg . $ctx_str);
    }

    // ---------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------

    private function render_landing(array $review): void {
        // If an email-restricted review's auth layer isn't loaded, it can no
        // longer be opened — show a neutral notice rather than the magic-link
        // gate (whose auth layer would also be absent).
        if ( $review['mode'] === DXF_Review::MODE_EMAIL && ! DXF_Review::email_features_available() ) {
            $this->render_landing_error($review, __('This review is no longer available.', 'dox-feedback'));
            return;
        }

        $member = $review['mode'] === DXF_Review::MODE_EMAIL
            ? DXF_Review_Auth::current_member($review)
            : null;

        // Email mode + no session → gated landing ("Resend my link")
        if ( $review['mode'] === DXF_Review::MODE_EMAIL && ! $member ) {
            $this->render_email_gate($review);
            return;
        }

        $post_ids = DXF_Review::resolve_post_ids($review);

        // Single-page review → skip the landing checklist entirely and drop
        // straight into per-page review mode for the only page.
        if ( count($post_ids) === 1 ) {
            $only = (int) $post_ids[0];
            if ( $only > 0 && get_post_status($only) ) {
                wp_safe_redirect(
                    DXF_Review::landing_url((string) $review['slug']) . 'item/' . $only . '/'
                );
                return;
            }
        }

        // The multi-page landing (a checklist of the pages in a review) is
        // rendered by the multi-page module, which hooks this action and echoes
        // its own complete, internally-escaped document (it calls
        // wp_head()/wp_footer() itself). We let it print DIRECTLY rather than
        // buffering + re-echoing — a buffered echo of a full HTML document can't
        // be run through a context escaper, and "escape late" means the template
        // that builds the markup owns its escaping. With no listener or no pages
        // in scope, fall back to the neutral notice instead.
        if ( empty($post_ids) || ! has_action('dxf_review_render_landing') ) {
            $this->render_landing_error($review, __('This review is no longer available.', 'dox-feedback'));
            return;
        }
        do_action('dxf_review_render_landing', $review, $post_ids, $member);
    }

    private function render_email_gate(array $review): void {
        // The magic-link gate markup ("use your private link") ships with the
        // email-restricted module, which hooks this action to render its
        // template. The shared gate styling lives here (it also dresses
        // render_landing_error). render_landing() bails to render_landing_error()
        // earlier when email features are absent, so no listener means a blank
        // gate, never a fatal.
        $this->enqueue_gate_style();
        do_action('dxf_review_render_email_gate', $review);
    }

    private function render_landing_error(array $review, string $message): void {
        $this->enqueue_gate_style();
        $error = $message;
        include __DIR__ . '/templates/landing-error.php';
    }

    /**
     * Enqueue the shared gate/soft-error stylesheet at a late priority so it
     * lands after the active theme's styles in the cascade — these templates
     * call wp_head()/wp_footer(), so theme CSS is present and the reviewer
     * surface must win. Registered here (template_redirect) before
     * wp_enqueue_scripts fires inside wp_head().
     */
    private function enqueue_gate_style(): void {
        add_action('wp_enqueue_scripts', static function (): void {
            wp_enqueue_style(
                'dxf-gate',
                DXF_URL . 'assets/frontend/gate.css',
                [],
                DXF_VERSION
            );
        }, 99);
    }

    private function render_error(string $message): void {
        wp_die(esc_html($message), esc_html__('Dox Feedback review', 'dox-feedback'), ['response' => 200]);
    }

    // ---------------------------------------------------------------------
    // Admin-bar quick-review
    // ---------------------------------------------------------------------

    /**
     * Add a "Quick review" sub-menu under the existing Dox Feedback admin-bar root.
     * Buttons let an editor spin up a new Review for the current page, all
     * items of the current post type, or the whole site without leaving the
     * page they're on.
     *
     * Rounds compatibility: Review objects (this class) are orthogonal to
     * per-post `_dxf_round` meta — creating a Review does NOT reset or
     * touch round counters. The panel surfaces the current round + a
     * shortcut to start a new one for transparency, but both delegate to
     * the existing handlers in DXF_Comments.
     */
    public function add_admin_bar_quick_review_node( \WP_Admin_Bar $bar ): void {
        if ( ! current_user_can('edit_posts') ) return;
        if ( ! $bar->get_node('dxf') )       return; // legacy root not registered yet
        // The reviewer dashboard renders its own minimal template via
        // template_redirect; the quick-review JS that hydrates this node
        // can hang on that surface (no $post context to localise against,
        // and the dashboard exits before some hooks run). The user-visible
        // symptom was the panel sticking on "Loading quick review…". Skip
        // the popout node here — the Dox Feedback root admin-bar item stays in
        // place and still navigates to Settings on click.
        if ( $this->is_on_reviewer_dashboard() ) return;

        $bar->add_group([
            'parent' => 'dxf',
            'id'     => 'dxf-quick-group',
            'meta'   => ['class' => 'ab-sub-secondary'],
        ]);

        $bar->add_node([
            'parent' => 'dxf-quick-group',
            'id'     => 'dxf-ab-quick',
            'title'  => '<div id="dxf-ab-quick-inner" class="dxf-ab-quick-inner">'
                      . esc_html__('Loading quick review…', 'dox-feedback')
                      . '</div>',
            'href'   => false,
            'meta'   => ['class' => 'dxf-ab-quick-item'],
        ]);
    }

    /**
     * Front-end + back-end enqueue for the quick-review panel. Same gating
     * as the legacy admin-bar panel (admin bar showing + edit_posts cap).
     * Bricks builder is excluded to avoid clashing with builder scripts.
     */
    public function enqueue_quick_review_assets(): void {
        if ( ! is_admin_bar_showing() || ! current_user_can('edit_posts') ) return;
        // Read-only check for the Bricks-builder ?bricks=run query var so we
        // don't enqueue our quick-review CSS/JS inside the builder iframe.
        // No mutation; nonce verification doesn't apply.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! is_admin() && ! empty($_GET['bricks']) ) return;
        // Don't enqueue on the reviewer dashboard surface — see
        // add_admin_bar_quick_review_node() for the rationale.
        if ( $this->is_on_reviewer_dashboard() ) return;

        // filemtime-based versioning (like the other Dox Feedback assets) so a content
        // change busts the cache on deploy — not only on a plugin version bump.
        wp_enqueue_style(
            'dxf-quick-review',
            DXF_URL . 'assets/admin/quick-review.css',
            [],
            DXF_Comments::asset_ver('assets/admin/quick-review.css')
        );
        wp_enqueue_script(
            'dxf-quick-review',
            DXF_URL . 'assets/admin/quick-review.js',
            ['jquery'],
            DXF_Comments::asset_ver('assets/admin/quick-review.js'),
            true
        );

        // Context: which post is the user on right now?
        $post_id = is_admin() ? (int) ($GLOBALS['post']->ID ?? 0) : (int) get_the_ID();
        $pt_obj  = $post_id ? get_post_type_object((string) get_post_type($post_id)) : null;

        // Open-comments count for the Reviews summary line. Mirrors the
        // admin-bar badge count exactly so the popout and the badge agree.
        // Custom-table COUNT(*) with a constant WHERE — values are literal,
        // not user input.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $open_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM %i
              WHERE status = 'open' AND (parent_id IS NULL OR parent_id = 0)",
            $wpdb->prefix . 'dxf_comments'
        ) );

        // Active Review count — surfaced in the popout's reviews block.
        $active_reviews = class_exists('DXF_Review')
            ? DXF_Review::count(['status' => DXF_Review::STATUS_ACTIVE])
            : 0;

        // Active Reviews list — surfaced as quick-manage rows in the popout.
        // Capped at 8; the popout links out to the full admin list if more.
        $active_list = [];
        if ( class_exists('DXF_Review') ) {
            $rows = DXF_Review::find([
                'status'   => DXF_Review::STATUS_ACTIVE,
                'per_page' => 8,
            ]);
            foreach ( $rows as $r ) {
                $active_list[] = [
                    'id'        => (int) $r['id'],
                    'name'      => (string) ( $r['name'] ?? '' ),
                    'slug'      => (string) ( $r['slug'] ?? '' ),
                    // Scope drives the "what does this link actually cover?" pill
                    // in the popout — the indicator that stops a single-page link
                    // being mistaken for a whole-site one.
                    'scopeType' => (string) ( $r['scope_type'] ?? DXF_Review::SCOPE_SINGLE ),
                    'manageUrl' => admin_url('admin.php?page=dxf-reviews&action=edit&id=' . (int) $r['id']),
                    // Public-facing URL so the popout can "Open" a review the way a
                    // client sees it (landing/checklist), not just manage it.
                    'openUrl'   => DXF_Review::landing_url((string) ( $r['slug'] ?? '' )),
                ];
            }
        }

        // Active per-page share-link state. Drives the link section at the
        // top of the popout. v0.21+ this is a DXF_Review (SCOPE_SINGLE +
        // MODE_LINK) tracked by post meta — same row that appears in the
        // "Active reviews" list below, so the two surfaces stay in sync.
        // Legacy dxf_review_tokens rows still surface here for revoke.
        $share         = $post_id ? DXF_Review_Mode::get_active_share_for_post($post_id) : null;
        $review_url    = $share ? (string) $share['url'] : '';
        $token_str     = $share ? (string) ($share['review_id'] ?? '') : '';

        wp_localize_script('dxf-quick-review', 'dxfQuickReview', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            // DXF_Review_Mode AJAX nonce — same action constant the meta
            // box uses. The link section in the popout shares these handlers.
            'linkNonce'   => wp_create_nonce('dxf_review'),
            // Reviews-admin nonce — used by the active-reviews list for the
            // inline delete action (dxf_review_delete).
            'adminNonce'  => wp_create_nonce('dxf_review_admin'),
            'menuBase'    => admin_url('admin.php?page=dxf-reviews'),
            'newReviewUrl' => admin_url('admin.php?page=dxf-reviews&action=new'),
            'settingsUrl' => admin_url('admin.php?page=dox-feedback'),
            'postId'      => $post_id,
            // "Open comments" count links straight into the Bricks builder
            // with the Dox Feedback panel force-opened (?dxf_open=1).
            'builderUrl'  => $post_id ? add_query_arg('dxf_open', '1', DXF_Comments::builder_url($post_id)) : '',
            'postTitle'   => $post_id ? (string) get_the_title($post_id) : '',
            'postType'    => $post_id ? (string) get_post_type($post_id) : '',
            'postTypeLabel' => $pt_obj && isset($pt_obj->labels->name) ? (string) $pt_obj->labels->name : '',
            'postTypeSingular' => $pt_obj && isset($pt_obj->labels->singular_name) ? (string) $pt_obj->labels->singular_name : '',
            'canManage'   => current_user_can('manage_options'),
            'activeReviewCount' => $active_reviews,
            'activeReviews'     => $active_list,
            'openCount'   => $open_count,
            'reviewUrl'   => $review_url,
            'token'       => $token_str,
            'i18n'        => [
                'adminBarTip' => __('👋 This is Dox Feedback. Click here to leave feedback on this page.', 'dox-feedback'),
                'copy'        => __('Copy', 'dox-feedback'),
                'copied'      => __('Copied!', 'dox-feedback'),
                'manage'      => __('Manage', 'dox-feedback'),
                'edit'        => __('Edit review', 'dox-feedback'),
                'open'        => __('Open', 'dox-feedback'),
                'openReview'  => __('Open the review (what your client sees)', 'dox-feedback'),
                'delete'      => __('Delete', 'dox-feedback'),
                'deleting'    => __('Deleting…', 'dox-feedback'),
                'deleteConfirm' => __('Delete this review and all its data? This cannot be undone.', 'dox-feedback'),
                'failed'      => __('Action failed. Please try again.', 'dox-feedback'),
                'reviews'         => __('Reviews', 'dox-feedback'),
                'newReview'       => __('New Review', 'dox-feedback'),
                'activeReviews'   => __('active reviews', 'dox-feedback'),
                'noActiveReviews' => __('No active reviews', 'dox-feedback'),
                'openComments'    => __('open comments', 'dox-feedback'),
                'openInBuilder'   => __('Open these comments in the builder', 'dox-feedback'),
                'untitledReview'  => __('(untitled review)', 'dox-feedback'),
                'viewAllReviews'  => __('View all reviews →', 'dox-feedback'),
                'noActiveLink' => __('No active review link', 'dox-feedback'),
                'activeLink'   => __('Review link active', 'dox-feedback'),
                'generateLink' => __('Generate public link for this page', 'dox-feedback'),
                'generating'   => __('Generating link…', 'dox-feedback'),
                'revoking'     => __('Revoking…', 'dox-feedback'),
                'revoke'       => __('Revoke', 'dox-feedback'),
                'openInBrowser' => __('Open in browser', 'dox-feedback'),
                'revokeConfirm' => __('Revoke this review link? Clients with the current URL will lose access.', 'dox-feedback'),
                'noPage'       => __('Navigate to a page to manage its review link.', 'dox-feedback'),
                'linkScope'    => __('This link is only for this page, not the whole site.', 'dox-feedback'),
                // Scope pills — make the coverage of each review unmistakable.
                'scopeSingle'   => __('This page', 'dox-feedback'),
                'scopeSelected' => __('Selected pages', 'dox-feedback'),
                'scopeEntire'   => __('Whole site', 'dox-feedback'),
                'scopeSingleTip'   => __('Covers only the single page it was created on.', 'dox-feedback'),
                'scopeSelectedTip' => __('Covers a hand-picked set of pages.', 'dox-feedback'),
                'scopeEntireTip'   => __('Covers every page on the site.', 'dox-feedback'),
                'proCreate'    => __('Multi-page or whole-site review', 'dox-feedback'),
                'proEditScope' => __('Edit scope', 'dox-feedback'),
                'settings'     => __('Dox Feedback Settings', 'dox-feedback'),
                // quick-review.js reads this (qr.*) key (previously hardcoded)
                'qr.dismiss'   => __('Dismiss', 'dox-feedback'),
            ],
        ]);
    }

    // ---------------------------------------------------------------------
    // Cron
    // ---------------------------------------------------------------------

    public static function maybe_schedule_cron(): void {
        if ( ! wp_next_scheduled(self::CRON_HOOK) ) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }
    }

    public static function cron_sweep(): void {
        DXF_Review::sweep_expiries();
    }

    // ---------------------------------------------------------------------
    // AJAX
    // ---------------------------------------------------------------------

    /**
     * Load a review and assert the current user may manage it. Mirrors the
     * ownership rule in DXF_Email_Reviews::verify_admin(): the review's creator,
     * or an editor who can manage other users' content. Exits with a JSON
     * 404/403 response on failure.
     *
     * @return array<string,mixed> the review row.
     */
    private static function require_owned_review( int $review_id ): array {
        $review = $review_id ? DXF_Review::get($review_id) : null;
        if ( ! $review ) {
            wp_send_json_error(['message' => __('Unknown review.', 'dox-feedback')], 404);
        }
        if ( (int) $review['created_by'] !== get_current_user_id() && ! current_user_can('edit_others_posts') ) {
            wp_send_json_error(['message' => __('Permission denied.', 'dox-feedback')], 403);
        }
        return $review;
    }

    public static function ajax_create(): void {
        check_ajax_referer('dxf_review_admin');
        if ( ! current_user_can('edit_posts') ) {
            wp_send_json_error(['message' => __('Not allowed.', 'dox-feedback')], 403);
        }
        $name       = isset($_POST['name'])       ? sanitize_text_field(wp_unslash((string) $_POST['name']))     : '';
        $scope_type = isset($_POST['scope_type']) ? sanitize_key((string) wp_unslash($_POST['scope_type']))     : DXF_Review::SCOPE_SINGLE;
        $mode       = isset($_POST['mode'])       ? sanitize_key((string) wp_unslash($_POST['mode']))           : DXF_Review::MODE_LINK;
        $post_ids   = isset($_POST['post_ids'])   ? array_map('intval', (array) wp_unslash($_POST['post_ids'])) : [];
        $include_future = ! empty($_POST['include_future']);
        $no_expiry  = ! empty($_POST['no_expiry']);
        $expires_at = isset($_POST['expires_at']) ? sanitize_text_field(wp_unslash((string) $_POST['expires_at'])) : '';

        $review = DXF_Review::create([
            'name'           => $name,
            'scope_type'     => $scope_type,
            'mode'           => $mode,
            'post_ids'       => $post_ids,
            'include_future' => $include_future,
            'no_expiry'      => $no_expiry,
            'expires_at'     => $expires_at,
        ]);

        if ( is_wp_error($review) ) {
            wp_send_json_error([
                'message' => $review->get_error_message(),
                'code'    => $review->get_error_code(),
            ], 400);
        }

        wp_send_json_success(['review' => $review]);
    }

    public static function ajax_close(): void {
        check_ajax_referer('dxf_review_admin');
        if ( ! current_user_can('edit_posts') ) wp_send_json_error(['message' => 'Forbidden'], 403);
        $review_id = isset($_POST['review_id']) ? (int) $_POST['review_id'] : 0;
        self::require_owned_review($review_id);
        $ok = DXF_Review::close($review_id);
        wp_send_json($ok ? ['success' => true] : ['success' => false]);
    }

    public static function ajax_reopen(): void {
        check_ajax_referer('dxf_review_admin');
        if ( ! current_user_can('edit_posts') ) wp_send_json_error(['message' => 'Forbidden'], 403);
        $review_id = isset($_POST['review_id']) ? (int) $_POST['review_id'] : 0;
        self::require_owned_review($review_id);
        $ok = DXF_Review::reopen($review_id);
        wp_send_json($ok ? ['success' => true] : ['success' => false]);
    }

    public static function ajax_delete(): void {
        check_ajax_referer('dxf_review_admin');
        if ( ! current_user_can('manage_options') ) wp_send_json_error(['message' => 'Forbidden'], 403);
        $review_id = isset($_POST['review_id']) ? (int) $_POST['review_id'] : 0;
        self::require_owned_review($review_id);
        $ok = DXF_Review::delete($review_id);
        wp_send_json($ok ? ['success' => true] : ['success' => false]);
    }

    public static function ajax_publish(): void {
        check_ajax_referer('dxf_review_admin');
        if ( ! current_user_can('edit_posts') ) wp_send_json_error(['message' => 'Forbidden'], 403);
        $review_id = isset($_POST['review_id']) ? (int) $_POST['review_id'] : 0;
        self::require_owned_review($review_id);
        $ok = DXF_Review::publish($review_id);
        wp_send_json($ok ? ['success' => true] : ['success' => false]);
    }

    /**
     * Admin-bar quick-review creator. One-click "spin me up a Review and
     * give me the share URL" for editors.
     *
     * Modes are always `link` (public URL) — email-restricted reviews need
     * the recipients list, which isn't a one-click flow.
     *
     * Scope is always Single (this post_id) for the one-click flow; broader
     * scopes are created through the full New-review wizard.
     *
     * Scope presets:
     *   - page : exactly this post_id (Single)
     */
    public static function ajax_quick_review(): void {
        check_ajax_referer('dxf_quick_review');
        if ( ! current_user_can('edit_posts') ) {
            wp_send_json_error(['message' => __('Not allowed.', 'dox-feedback')], 403);
        }

        $preset  = isset($_POST['scope_preset']) ? sanitize_key((string) $_POST['scope_preset']) : '';
        $post_id = isset($_POST['post_id'])      ? (int) $_POST['post_id']                       : 0;

        $name     = '';
        $scope    = '';
        $post_ids = [];

        switch ($preset) {
            case 'page':
                if ( ! $post_id || ! get_post($post_id) ) {
                    wp_send_json_error(['message' => __('No page context.', 'dox-feedback')], 400);
                }
                $scope    = DXF_Review::SCOPE_SINGLE;
                $post_ids = [$post_id];
                $name     = sprintf(
                    /* translators: 1 = page title, 2 = date */
                    __('Quick review — %1$s (%2$s)', 'dox-feedback'),
                    get_the_title($post_id) ?: ('#' . $post_id),
                    date_i18n('M j')
                );
                break;

            default:
                wp_send_json_error(['message' => __('Unknown scope preset.', 'dox-feedback')], 400);
        }

        $review = DXF_Review::create([
            'name'       => $name,
            'scope_type' => $scope,
            'mode'       => DXF_Review::MODE_LINK,
            'post_ids'   => $post_ids,
            'expires_at' => '', // default 30-day expiry — matches the wizard's default
        ]);

        if ( is_wp_error($review) ) {
            wp_send_json_error([
                'message' => $review->get_error_message(),
                'code'    => $review->get_error_code(),
            ], 400);
        }

        // Auto-activate so the share URL works on the first click.
        $activated = DXF_Review::publish((int) $review['id']);

        // Reload the row so the returned `review.status` matches reality.
        $review = DXF_Review::get((int) $review['id']) ?? $review;

        $landing = DXF_Review::landing_url((string) $review['slug']);
        $manage  = admin_url('admin.php?page=dxf-reviews&action=edit&id=' . (int) $review['id']);

        wp_send_json_success([
            'review'     => $review,
            'landingUrl' => $landing,
            'manageUrl'  => $manage,
            'activated'  => $activated,
        ]);
    }

}

/**
 * Session bridge — short-lived transient handing context from the Reviews
 * landing redirect to the legacy DXF_Review_Mode controller, so the
 * existing comment UI runs without needing per-page tokens.
 */
final class DXF_Review_Session_Bridge {

    public const TRANSIENT_PREFIX = 'dxf_rsb_';

    public static function set_context(int $review_id, int $post_id): void {
        $key = self::key();
        if ( ! $key ) return;
        set_transient(self::TRANSIENT_PREFIX . $key, [
            'review_id' => $review_id,
            'post_id'   => $post_id,
        ], 5 * MINUTE_IN_SECONDS);
    }

    public static function get_context(): ?array {
        $key = self::key();
        if ( ! $key ) return null;
        $ctx = get_transient(self::TRANSIENT_PREFIX . $key);
        return is_array($ctx) ? $ctx : null;
    }

    private static function key(): ?string {
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $ua  = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        if ( $ip === '' ) return null;
        return substr(hash('sha256', $ip . '|' . $ua . '|' . wp_salt('auth')), 0, 32);
    }
}
