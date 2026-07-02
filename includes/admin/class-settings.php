<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

// Settings UI + persistence.
//   - NonceVerification.Recommended is disabled at file scope: every $_GET read
//     in this file is display-only (which tab to render, "Settings saved"
//     banner, license-deactivated banner). Mutating endpoints — save_general,
//     save_comments, etc. — are each guarded by check_admin_referer().
//   - DirectDatabaseQuery / NoCaching are for the storage-bytes lookups against
//     {$wpdb->prefix}dxf_uploads and similar, which target our own data.
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

class DXF_Settings {

    private const OPTION_GROUP = 'dxf_settings';
    private const OPTION_KEY   = 'dxf_options';

    public static function get(string $key, mixed $default = null): mixed {
        $options = get_option(self::OPTION_KEY, []);
        return $options[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void {
        $options        = get_option(self::OPTION_KEY, []);
        $options[$key]  = $value;
        update_option(self::OPTION_KEY, $options);
    }

    /** Allowed positions for the reviewer-page Feedback button. */
    public const FAB_POSITIONS = ['bottom-right', 'bottom-left', 'top-right', 'top-left'];

    /**
     * Resolved, validated Feedback-button position. Falls back to the default
     * if the stored value is somehow out of range.
     */
    public static function fab_position(): string {
        $pos = (string) self::get('fab_position', 'bottom-right');
        return in_array($pos, self::FAB_POSITIONS, true) ? $pos : 'bottom-right';
    }

    public static function defaults(): array {
        return [
            // Review mode
            'review_link_expiry_days' => 30,
            'review_notify_email'     => get_option('admin_email'),
            // Where the floating "Feedback" button sits on reviewer pages.
            // One of: bottom-right (default) | bottom-left | top-right | top-left.
            'fab_position'            => 'bottom-right',

            // Comments
            'comment_attachment_max_mb' => 5,
            // Default theme for the comment modal. Per-user toggle in the modal
            // header can override this; the override persists in localStorage.
            // Values: 'follow_bricks' | 'os' | 'dark' | 'light'.
            'comment_modal_theme'       => 'follow_bricks',

            // Email notifications — per-event on/off toggles + burst coalescing.
            'notify_events'            => [                            // per-event opt-in (defaults all on)
                'comment'  => true,
                'reply'    => true,
                'assign'   => true,
                'approval' => true,
            ],
            // Coalesce a burst of new-comment emails (per post) into one, sent
            // after this many minutes of quiet. 0 = send each immediately.
            'notify_throttle_minutes'  => 5,
        ];
    }

    // -------------------------------------------------------------------------
    // Notification helpers (resolve recipients, per-event toggle)
    // -------------------------------------------------------------------------

    /**
     * Resolve the recipient list for a notification. Sends to the primary
     * notification address (`review_notify_email`, defaulting to the site admin
     * email); the `dxf_notify_recipients` filter can expand the list.
     */
    public static function notify_recipients(): array {
        $primary = sanitize_email((string) self::get('review_notify_email', get_option('admin_email')));
        $list    = $primary ? [ $primary ] : [];
        $list    = (array) apply_filters('dxf_notify_recipients', $list);

        $clean = [];
        foreach ( $list as $candidate ) {
            $email = sanitize_email((string) $candidate);
            if ( $email && ! in_array($email, $clean, true) ) {
                $clean[] = $email;
                if ( count($clean) >= 25 ) break;
            }
        }
        return $clean;
    }

    /**
     * Is a notification event enabled? Defaults to enabled when the per-event
     * setting hasn't been touched yet (sensible for upgrades).
     */
    public static function notify_event_enabled(string $event): bool {
        $events = (array) self::get('notify_events', []);
        return ! array_key_exists($event, $events) || (bool) $events[$event];
    }

    /**
     * Mailer opts (Reply-To, From, attachments) for an outbound notification.
     *
     * Sets Reply-To to the person who triggered the email and otherwise uses
     * WordPress's default From address. The returned array is filterable, and
     * $context names the event (e.g. 'approval') and carries the related IDs so
     * a listener can act on it.
     *
     * @param string $reply_to Email to set as Reply-To.
     * @param array  $context  e.g. ['event' => 'approval', 'post_id' => 123]
     */
    public static function notify_opts(string $reply_to = '', array $context = []): array {
        $opts = [];
        if ( $reply_to !== '' ) {
            $rt = sanitize_email($reply_to);
            if ( $rt ) {
                $opts['reply_to'] = $rt;
            }
        }
        return (array) apply_filters('dxf_notify_opts', $opts, $context);
    }

    public function render(): void {
        // Read-only tab routing — capability check happens in the menu callback
        // upstream; the mutating actions on each tab are nonce-checked.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'general' ) );
        // Unknown / no-longer-available tab (e.g. a bookmarked Pro tab after
        // Pro was deactivated) falls back to General. 'approvals' is a legacy
        // redirect handled in the router below.
        if ( $tab !== 'approvals' && ! isset( $this->tabs()[ $tab ] ) ) {
            $tab = 'general';
        }
        ?>
        <div class="wrap dxf-settings">
            <h1><?php esc_html_e('Dox Feedback', 'dox-feedback'); ?></h1>

            <?php DXF_Admin::brand_header(
                __('Feedback', 'dox-feedback'),
                sprintf( esc_html__('Version %s', 'dox-feedback'), DXF_VERSION )
            ); ?>

            <?php $this->render_bug_callout(); ?>

            <nav class="nav-tab-wrapper">
                <?php foreach ($this->tabs() as $slug => $label) : ?>
                    <a href="<?php echo esc_url(add_query_arg('tab', $slug)); ?>"
                       class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="dxf-settings__body">
                <?php match ($tab) {
                    'comments'      => $this->render_tab_comments(),
                    'notifications' => $this->render_tab_notifications(),
                    // Approvals moved to its own top-level submenu (Dox Feedback →
                    // Approvals). If an old deep-link lands here, redirect.
                    'approvals' => $this->redirect_to_approvals_page(),
                    'general'   => $this->render_tab_general(),
                    // Any other tab registered via the dxf_settings_tabs filter
                    // renders through its own dxf_settings_render_tab_{slug} hook.
                    default     => $this->render_addon_tab($tab),
                }; ?>
            </div>
        </div>
        <?php
    }

    private function tabs(): array {
        // Approvals lives at Dox Feedback → Approvals now (own submenu, not a
        // settings tab) — managed records belong next to Reviews, not
        // buried under Settings.
        $tabs = [
            'general'       => __('General', 'dox-feedback'),
            'comments'      => __('Comments', 'dox-feedback'),
            'notifications' => __('Notifications & Integrations', 'dox-feedback'),
        ];

        // Add-ons may register extra tabs via this filter; the bundled plugin
        // ships the three above.
        $tabs = apply_filters('dxf_settings_tabs', $tabs);

        return $tabs;
    }

    /**
     * Bookmarks pointing at the old Settings → Approvals tab redirect to
     * the new Dox Feedback → Approvals submenu. Uses wp_safe_redirect so a
     * forged tab value can't bounce admins off-site.
     */
    private function redirect_to_approvals_page(): void {
        wp_safe_redirect(admin_url('admin.php?page=' . DXF_Approvals::MENU_SLUG));
        exit;
    }

    /**
     * Render a settings tab owned by an addon (Pro). The addon hooks
     * dxf_settings_render_tab_{slug} to emit its own heading + form. Falls
     * back to General if nothing handles the tab (defensive — tabs() already
     * filters out tabs that aren't available).
     */
    private function render_addon_tab( string $tab ): void {
        if ( has_action( "dxf_settings_render_tab_{$tab}" ) ) {
            do_action( "dxf_settings_render_tab_{$tab}" );
            return;
        }
        $this->render_tab_general();
    }

    private function render_tab_general(): void {
        $notify_email      = (string) self::get('review_notify_email', get_option('admin_email'));
        $fab_position      = self::fab_position();
        $fab_labels        = [
            'bottom-right' => __('Bottom right (default)', 'dox-feedback'),
            'bottom-left'  => __('Bottom left', 'dox-feedback'),
            'top-right'    => __('Top right', 'dox-feedback'),
            'top-left'     => __('Top left', 'dox-feedback'),
        ];

        if ( isset($_GET['dxf-saved']) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' .
                 esc_html__('Settings saved.', 'dox-feedback') .
                 '</p></div>';
        }
        ?>
        <h2><?php esc_html_e('General Settings', 'dox-feedback'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('dxf_general_save', 'dxf_general_nonce'); ?>
            <input type="hidden" name="action" value="dxf_save_general">

            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row">
                        <label for="dxf-notify-email"><?php esc_html_e('Primary notification email', 'dox-feedback'); ?></label>
                    </th>
                    <td>
                        <input type="email" id="dxf-notify-email" name="review_notify_email"
                               value="<?php echo esc_attr($notify_email); ?>"
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('Where review comment and approval notifications are sent.', 'dox-feedback'); ?>
                        </p>
                        <p style="margin-top:8px;">
                            <button type="button" id="dxf-test-email" class="button">
                                <?php esc_html_e('Send test email', 'dox-feedback'); ?>
                            </button>
                            <span id="dxf-test-email-result" style="margin-left:10px; font-size:13px;"></span>
                        </p>
                        <?php if ( DXF_Mailer::last_error() ) : ?>
                            <div class="notice notice-warning inline" style="margin-top:8px;">
                                <p>
                                    <strong><?php esc_html_e('Last delivery error:', 'dox-feedback'); ?></strong>
                                    <?php echo wp_kses_post(DXF_Mailer::last_error()); ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="dxf-fab-position"><?php esc_html_e('Feedback button position', 'dox-feedback'); ?></label>
                    </th>
                    <td>
                        <select id="dxf-fab-position" name="fab_position">
                            <?php foreach ( $fab_labels as $value => $label ) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($fab_position, $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Where the floating "Feedback" button appears for reviewers on a review page.', 'dox-feedback'); ?>
                        </p>
                    </td>
                </tr>

                <?php do_action('dxf_general_settings_extra'); ?>

            </table>


            <?php submit_button(__('Save settings', 'dox-feedback')); ?>
        </form>
        <?php $this->render_dev_tools(); ?>
        <?php
    }

    /**
     * Demo/dev tools — visible only on non-production environments.
     * Currently: a dummy-content seeder for demos and screenshots.
     */
    private function render_dev_tools(): void {
        if ( ! function_exists('wp_get_environment_type') || wp_get_environment_type() === 'production' ) {
            return;
        }
        ?>
        <hr style="margin-top:28px;">
        <h2 style="margin-top:0;"><?php esc_html_e('Developer tools', 'dox-feedback'); ?>
            <span style="font-size:11px;font-weight:500;background:#e5e7eb;color:#374151;padding:2px 8px;border-radius:999px;margin-left:8px;vertical-align:middle;"><?php esc_html_e('Non-production only', 'dox-feedback'); ?></span>
        </h2>
        <p class="description">
            <?php esc_html_e('Hidden on production. Use these to set up realistic content for demos and screenshots.', 'dox-feedback'); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="dxf-seed-post"><?php esc_html_e('Seed dummy comments', 'dox-feedback'); ?></label></th>
                <td>
                    <input type="number" id="dxf-seed-post" placeholder="<?php esc_attr_e('Post / page ID', 'dox-feedback'); ?>" class="small-text" min="1" style="width:120px;">
                    <input type="number" id="dxf-seed-count" value="20" min="1" max="100" class="small-text" style="width:80px;">
                    <button type="button" class="button" id="dxf-seed-btn"><?php esc_html_e('Seed comments', 'dox-feedback'); ?></button>
                    <button type="button" class="button" id="dxf-seed-review-btn" style="margin-left:6px;"><?php esc_html_e('Seed Review with dummy comments', 'dox-feedback'); ?></button>
                    <span id="dxf-seed-result" style="margin-left:10px;font-size:13px;"></span>
                    <p class="description">
                        <?php esc_html_e('"Seed comments" inserts loose comments onto the chosen post. "Seed Review with dummy comments" additionally creates an active single-page Review wrapping the post, scopes the comments to that review, and writes a few audit events so the audit log isn\'t empty.', 'dox-feedback'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Dismissible callout asking users to report bugs at doxstudio.com/contact.
     * Dismissed per-user for 30 days (stored as a unix timestamp in user meta).
     * Shown on every tier — bug feedback shouldn't be paywalled.
     */
    private function render_bug_callout(): void {
        // Sequence the prompts: hold the recurring bug/feedback callout until the
        // one-time anonymous-diagnostics opt-in has been answered, so a fresh
        // install never shows both at once.
        $tel_decided = (bool) get_option('dxf_telemetry_asked', false) || DXF_Telemetry::is_enabled();
        if ( ! $tel_decided ) {
            return;
        }

        $until = (int) get_user_meta(get_current_user_id(), 'dxf_bug_callout_until', true);
        if ( $until && $until > time() ) {
            return;
        }
        ?>
        <div id="dxf-bug-callout" class="notice notice-info" style="margin:14px 0;padding:12px 14px;border-left-color:#ff8d27;">
            <p style="margin:0;display:flex;align-items:center;gap:10px;">
                <span style="flex:1;">
                    <strong><?php esc_html_e('Spotted a bug or have feedback?', 'dox-feedback'); ?></strong>
                    <?php
                    printf(
                        /* translators: %s = contact URL */
                        esc_html__('Dox Feedback is in active development — please report anything off at %s. It really helps.', 'dox-feedback'),
                        '<a href="https://doxstudio.com/contact" target="_blank" rel="noopener"><strong>doxstudio.com/contact</strong></a>'
                    );
                    ?>
                </span>
                <button type="button" class="button-link" id="dxf-bug-callout-dismiss" aria-label="<?php esc_attr_e('Dismiss for 30 days', 'dox-feedback'); ?>">
                    <?php esc_html_e('Dismiss', 'dox-feedback'); ?>
                </button>
            </p>
        </div>
        <?php
    }

    public static function ajax_dismiss_bug_callout(): void {
        check_ajax_referer('dxf_dismiss_bug_callout');
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
        }
        update_user_meta(get_current_user_id(), 'dxf_bug_callout_until', time() + 30 * DAY_IN_SECONDS);
        wp_send_json_success();
    }

    /**
     * One-time opt-in banner shown above the settings tabs until the user
     * makes a decision (either Allow or Decline). After that we never ask
     * again — they can change their mind any time from the Telemetry row
     * in the General tab.
     */
    private function render_telemetry_optin_banner(): void {
        $asked   = (bool) get_option('dxf_telemetry_asked', false);
        $enabled = DXF_Telemetry::is_enabled();
        if ( $asked || $enabled ) {
            return;
        }
        ?>
        <div id="dxf-telemetry-callout" class="notice notice-info" style="margin:14px 0;padding:14px 18px;border-left-color:#4f46e5;">
            <p style="margin:0 0 8px;font-size:14px;">
                <strong><?php esc_html_e('Help us improve Dox Feedback?', 'dox-feedback'); ?></strong>
                <?php esc_html_e('We\'d love to collect anonymous diagnostics so we know which PHP/WP/Bricks versions to keep supporting and which features are useful. You can change your mind any time.', 'dox-feedback'); ?>
            </p>
            <p style="margin:8px 0 12px;font-size:13px;color:#4a4a4a;">
                <strong><?php esc_html_e('What we collect:', 'dox-feedback'); ?></strong>
                <?php esc_html_e('plugin/WP/PHP/Bricks versions, locale, timezone, license tier, which features are turned on, and bucketed comment counts (e.g. "11–100"). An anonymous install ID is sent so we can dedupe — never your site URL.', 'dox-feedback'); ?>
                <br>
                <strong><?php esc_html_e('What we never collect:', 'dox-feedback'); ?></strong>
                <?php esc_html_e('site URL or domain, admin email, license key, page URLs, comment content, reviewer names or emails, IP addresses.', 'dox-feedback'); ?>
            </p>
            <p style="margin:0;">
                <button type="button" class="button button-primary" id="dxf-tel-allow"><?php esc_html_e('Allow anonymous diagnostics', 'dox-feedback'); ?></button>
                <button type="button" class="button" id="dxf-tel-decline" style="margin-left:6px;"><?php esc_html_e('No thanks', 'dox-feedback'); ?></button>
                <a href="https://doxstudio.com/privacy" target="_blank" rel="noopener" style="margin-left:10px;font-size:13px;"><?php esc_html_e('Read the privacy policy', 'dox-feedback'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Toggle anonymous telemetry — opt-in by definition. Capability + nonce
     * checked. The user can flip this back off at any time from the same UI.
     */
    public static function ajax_telemetry_optin(): void {
        check_ajax_referer('dxf_telemetry_optin');
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
        }
        // Record that the admin saw the prompt so we don't ask again.
        update_option('dxf_telemetry_asked', 1, false);
        if ( ! empty($_POST['opt_in']) ) {
            DXF_Telemetry::enable();
        } else {
            DXF_Telemetry::disable();
        }
        wp_send_json_success(['enabled' => DXF_Telemetry::is_enabled()]);
    }

    /**
     * Demo seeder: insert N realistic-looking comments on a post — varied
     * authors, statuses, priorities, threaded replies — so AI summarize and the
     * client review flow have meaningful content for screenshots and demos.
     *
     * Hard-disabled on production via wp_get_environment_type(). Staging-only.
     */
    public static function ajax_seed_dummy(): void {
        check_ajax_referer('dxf_seed_dummy');
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
        }
        if ( function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production' ) {
            wp_send_json_error(['message' => __('The seeder is disabled on production.', 'dox-feedback')], 403);
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        $count   = max(1, min(100, absint($_POST['count'] ?? 20)));
        if ( ! $post_id || ! get_post($post_id) ) {
            wp_send_json_error(['message' => __('Pick a valid post/page to seed onto.', 'dox-feedback')], 400);
        }

        $result = self::seed_dummy_comments_for_post($post_id, $count, null);

        wp_send_json_success([
            'inserted' => $result['inserted'],
            'message'  => sprintf(
                /* translators: %d = count of seeded comments */
                _n('Seeded %d dummy comment.', 'Seeded %d dummy comments.', $result['inserted'], 'dox-feedback'),
                $result['inserted']
            ),
        ]);
    }

    /**
     * Demo seeder (Review variant): create a fresh Review wrapping the chosen
     * post, attach N realistic-looking comments scoped to that review, and
     * sprinkle a few audit events so the review's audit panel isn't empty.
     *
     * Hard-disabled on production. Staging-only — used for screenshots and to
     * exercise the v0.16 Review object end-to-end without manual setup.
     */
    public static function ajax_seed_dummy_review(): void {
        check_ajax_referer('dxf_seed_dummy');
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
        }
        if ( function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production' ) {
            wp_send_json_error(['message' => __('The seeder is disabled on production.', 'dox-feedback')], 403);
        }
        if ( ! class_exists('DXF_Review') || ! class_exists('DXF_Review_Audit') ) {
            wp_send_json_error(['message' => __('Reviews module is unavailable.', 'dox-feedback')], 500);
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        $count   = max(1, min(100, absint($_POST['count'] ?? 20)));
        if ( ! $post_id || ! get_post($post_id) ) {
            wp_send_json_error(['message' => __('Pick a valid post/page to seed onto.', 'dox-feedback')], 400);
        }

        global $wpdb;
        $reviews_table = DXF_Review::reviews_table();

        // Slug per the spec: demo-YYYYMMDDHHMM. On UNIQUE collision (two clicks
        // in the same minute) fall back to demo-YYYYMMDDHHMM-XXXX.
        $base_slug = 'demo-' . current_time('YmdHi');
        $slug      = $base_slug;
        $row       = [
            'slug'           => $slug,
            /* translators: %s = current date/time stamp */
            'name'           => sprintf(__('Demo review %s', 'dox-feedback'), current_time('Y-m-d H:i')),
            'status'         => DXF_Review::STATUS_ACTIVE,
            'scope_type'     => DXF_Review::SCOPE_SINGLE,
            'include_future' => 0,
            'mode'           => DXF_Review::MODE_LINK,
            'password_hash'  => null,
            'expires_at'     => null,
            'created_by'     => get_current_user_id(),
        ];
        $formats = ['%s','%s','%s','%s','%d','%s','%s','%s','%d'];

        $ok = $wpdb->insert($reviews_table, $row, $formats);
        if ( $ok === false ) {
            // Almost certainly a UNIQUE(slug) collision — retry once with a suffix.
            $row['slug'] = $slug = $base_slug . '-' . bin2hex(random_bytes(2));
            $ok = $wpdb->insert($reviews_table, $row, $formats);
        }
        if ( $ok === false ) {
            wp_send_json_error(['message' => __('Could not create the demo review.', 'dox-feedback')], 500);
        }
        $review_id = (int) $wpdb->insert_id;

        DXF_Review::set_posts($review_id, [$post_id]);

        $seeded = self::seed_dummy_comments_for_post($post_id, $count, $review_id);

        // A few audit entries so the audit panel has something to render.
        DXF_Review_Audit::log($review_id, null, 'created',   ['scope' => 'single', 'mode' => 'link', 'demo' => true]);
        DXF_Review_Audit::log($review_id, null, 'published', ['demo' => true]);
        DXF_Review_Audit::log($review_id, null, 'link_used', ['demo' => true]);
        $event_count = min($seeded['inserted'], 5);
        for ( $i = 0; $i < $event_count; $i++ ) {
            DXF_Review_Audit::log($review_id, null, 'commented', ['demo' => true]);
        }

        wp_send_json_success([
            'inserted'  => $seeded['inserted'],
            'review_id' => $review_id,
            'slug'      => $slug,
            'url'       => DXF_Review::landing_url($slug),
            'message'   => sprintf(
                /* translators: 1: count of seeded comments, 2: review slug */
                __('Created review %2$s and seeded %1$d comments.', 'dox-feedback'),
                $seeded['inserted'],
                $slug
            ),
        ]);
    }

    /**
     * Shared comment-seeding loop used by both demo-seeder AJAX handlers.
     * When $review_id is non-null, each inserted row is tagged so it shows up
     * inside that Review's filtered view.
     *
     * @return array{inserted:int, parent_ids:int[]}
     */
    private static function seed_dummy_comments_for_post(int $post_id, int $count, ?int $review_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'dxf_comments';

        $personas = [
            ['name' => 'Sarah Mitchell', 'email' => 'sarah@acmeco.example'],
            ['name' => 'James Rowan',    'email' => 'james@acmeco.example'],
            ['name' => 'Priya Patel',    'email' => 'priya@acmeco.example'],
            ['name' => 'Marcus Lee',     'email' => 'marcus@acmeco.example'],
            ['name' => 'Elena Cortez',   'email' => 'elena@acmeco.example'],
        ];
        $bodies = [
            "Can we make the headline a bit bigger? It's getting lost on mobile.",
            "Love this section — the spacing is perfect.",
            "The CTA button colour doesn't match our brand palette. Can we use #2A6FDB instead?",
            "Partner logos aren't centred properly in their row.",
            "Could we shorten this paragraph? It feels a bit wordy.",
            "Image looks pixelated on retina — do we have a higher-res version?",
            "Heading hierarchy on this section is confusing — H3 looks bigger than H2.",
            "Form submit button needs a loading state, currently looks like nothing happened.",
            "Mobile menu cuts off at the bottom on iPhone SE.",
            "Pricing toggle is great but the annual savings should be more prominent.",
            "Hero copy doesn't match the messaging brief — should say 'collaboration' not 'feedback'.",
            "404 page is missing the brand colours.",
            "Testimonial avatars are tiny — can we double the size?",
            "Footer social icons need a hover state.",
            "Background video autoplays with sound on mobile — should be muted.",
            "Reading order on this card grid is off when you tab through.",
            "Contact form returns a console error on submit (no actual failure).",
            "Could we add an FAQ accordion below this section?",
            "Section padding is too tight on tablet — feels cramped.",
            "The animated counter doesn't trigger on slow connections.",
        ];
        $replies = [
            "Good catch — will fix in the next round.",
            "Agreed. Pushed an update.",
            "Can you send the higher-res version?",
            "Resolved in the latest deploy, can you double-check?",
            "Pushing back on this — see brand guidelines doc.",
            "Logged this for review round 2.",
        ];
        $priorities = ['low', 'medium', 'high'];
        $types      = ['design', 'content', 'bug', 'question'];
        $statuses   = ['open', 'open', 'open', 'open', 'in_progress', 'in_progress', 'resolved'];

        $inserted = 0;
        $parents  = [];

        for ( $i = 0; $i < $count; $i++ ) {
            // ~25% of inserts (after we've created at least 3 parents) are replies.
            $make_reply = count($parents) >= 3 && random_int(0, 100) < 25;
            $parent_id  = $make_reply ? $parents[array_rand($parents)] : null;

            $p     = $personas[array_rand($personas)];
            $body  = $parent_id ? $replies[array_rand($replies)] : $bodies[array_rand($bodies)];
            $stat  = $parent_id ? 'open' : $statuses[array_rand($statuses)];
            $tri   = ['type' => $types[array_rand($types)], 'priority' => $priorities[array_rand($priorities)]];
            $anchor = [
                'element_id' => '',
                'doc_x'      => random_int(80, 1200),
                'doc_y'      => random_int(120, 3000),
                'offset_x'   => 0.5,
                'offset_y'   => 0.5,
                'triage'     => $tri,
                'context'    => ['os' => 'macOS', 'browser' => 'Chrome', 'viewport' => '1440×900', 'breakpoint' => 'Desktop'],
            ];

            $data = [
                'post_id'      => $post_id,
                'author_id'    => 0,
                'author_name'  => $p['name'],
                'author_email' => $p['email'],
                'body'         => $body,
                'status'       => $stat,
                'round'        => 1,
                'anchor_data'  => wp_json_encode($anchor),
                'created_at'   => current_time('mysql'),
            ];
            $formats = ['%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s'];
            if ( $parent_id ) {
                $data['parent_id'] = $parent_id;
                $formats[]         = '%d';
            }
            if ( $review_id ) {
                $data['review_id'] = $review_id;
                $formats[]         = '%d';
            }
            $ok = $wpdb->insert($table, $data, $formats);
            if ( $ok ) {
                $inserted++;
                if ( ! $parent_id ) { $parents[] = (int) $wpdb->insert_id; }
            }
        }

        return ['inserted' => $inserted, 'parent_ids' => $parents];
    }

    public static function save_general(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die(esc_html__('Unauthorized.', 'dox-feedback'), 403);
        }

        check_admin_referer('dxf_general_save', 'dxf_general_nonce');

        $email  = sanitize_email(wp_unslash($_POST['review_notify_email'] ?? ''));

        self::set('review_notify_email', $email ?: get_option('admin_email'));

        // Feedback-button position (reviewer pages). Whitelisted against the
        // allowed set; anything else falls back to the default.
        $fab_position = sanitize_key(wp_unslash($_POST['fab_position'] ?? 'bottom-right'));
        if ( ! in_array($fab_position, self::FAB_POSITIONS, true) ) {
            $fab_position = 'bottom-right';
        }
        self::set('fab_position', $fab_position);

        // Notification settings (per-event toggles, burst coalescing) and the
        // Pro advanced-delivery / digest fields moved to their own
        // "Notifications & Integrations" tab — see save_notifications(). They
        // are no longer part of this form, so nothing here touches them.

        // Diagnostics: opt-in telemetry. We treat any change as a decision so
        // the one-time banner stops asking either way.
        update_option('dxf_telemetry_asked', 1, false);
        if ( ! empty($_POST['dxf_telemetry_enabled']) ) {
            DXF_Telemetry::enable();
        } else {
            DXF_Telemetry::disable();
        }

        wp_safe_redirect(add_query_arg([
            'page'         => 'dox-feedback',
            'tab'          => 'general',
            'dxf-saved' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * "Notifications & Integrations" tab. Holds the per-event email toggles +
     * burst coalescing, plus any advanced-delivery or integration sections an
     * add-on injects via hooks (each as its own form). Every form is
     * self-contained and posts to its own nonce-checked handler, so saving one
     * section never wipes another's fields.
     */
    private function render_tab_notifications(): void {
        $notify_events   = (array) self::get('notify_events', ['comment' => true, 'reply' => true, 'assign' => true, 'approval' => true]);
        $notify_throttle = (int)   self::get('notify_throttle_minutes', 5);

        if ( isset($_GET['dxf-saved']) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' .
                 esc_html__('Settings saved.', 'dox-feedback') .
                 '</p></div>';
        }
        ?>
        <h2><?php esc_html_e('Notifications', 'dox-feedback'); ?></h2>
        <p class="description" style="max-width:720px;">
            <?php esc_html_e('Control which events trigger an email, and coalesce bursts of comments into one.', 'dox-feedback'); ?>
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('dxf_notifications_save', 'dxf_notifications_nonce'); ?>
            <input type="hidden" name="action" value="dxf_save_notifications">

            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row"><?php esc_html_e('Send an email when…', 'dox-feedback'); ?></th>
                    <td>
                        <?php
                        $event_labels = [
                            'comment'  => __('A reviewer leaves a new comment',      'dox-feedback'),
                            'reply'    => __('Someone replies in a thread',         'dox-feedback'),
                            'assign'   => __('A comment is assigned to a teammate', 'dox-feedback'),
                            'approval' => __('A page is marked as approved',        'dox-feedback'),
                            'viewed'   => __('A client first opens a review link',  'dox-feedback'),
                        ];
                        foreach ( $event_labels as $key => $label ) :
                            $on = ! array_key_exists($key, $notify_events) || (bool) $notify_events[$key];
                            ?>
                            <label style="display:block;margin-bottom:4px;">
                                <input type="checkbox" name="notify_events[<?php echo esc_attr($key); ?>]" value="1" <?php checked($on); ?>>
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php esc_html_e('Reply and assignment emails also respect each WordPress user\'s personal opt-out (in their profile).', 'dox-feedback'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="dxf-notify-throttle"><?php esc_html_e('Coalesce bursts', 'dox-feedback'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="dxf-notify-throttle" name="notify_throttle_minutes"
                               value="<?php echo esc_attr((string) $notify_throttle); ?>" min="0" max="60" class="small-text">
                        <span><?php esc_html_e('minutes', 'dox-feedback'); ?></span>
                        <p class="description">
                            <?php esc_html_e('When a reviewer leaves several comments in a row, combine them into a single email after this many minutes of quiet. Set to 0 to send each comment immediately.', 'dox-feedback'); ?>
                        </p>
                    </td>
                </tr>

                <?php
                /**
                 * Advanced delivery fields (multi-recipient list, custom From /
                 * Reply-To) and the digest-frequency row are injected here.
                 */
                do_action('dxf_notify_settings_fields');
                do_action('dxf_notifications_settings_extra');
                ?>

            </table>

            <?php submit_button(__('Save notifications', 'dox-feedback')); ?>
        </form>

        <?php
        /**
         * Integrations (Slack / Discord / webhooks) render their own complete
         * <form> here when an integrations add-on hooks this section.
         */
        if ( has_action('dxf_notifications_integrations_section') ) {
            do_action('dxf_notifications_integrations_section');
        }
    }

    /**
     * Persist the Notifications tab. Saves the free per-event toggles + burst
     * coalescing, then fires dxf_notifications_settings_save so Pro persists
     * its advanced-delivery and digest fields from the same form. Integrations
     * are a separate form with their own handler (dxf_save_integrations).
     */
    public static function save_notifications(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die(esc_html__('Unauthorized.', 'dox-feedback'), 403);
        }

        check_admin_referer('dxf_notifications_save', 'dxf_notifications_nonce');

        // Per-event toggles (free). Unchecked boxes don't post. Each value is
        // only inspected with empty() — no string output — so structural read
        // is fine without per-key sanitisation.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $posted_events = (array) ($_POST['notify_events'] ?? []);
        $events = [
            'comment'  => ! empty($posted_events['comment']),
            'reply'    => ! empty($posted_events['reply']),
            'assign'   => ! empty($posted_events['assign']),
            'approval' => ! empty($posted_events['approval']),
            'viewed'   => ! empty($posted_events['viewed']),
        ];
        self::set('notify_events', $events);

        // Burst coalescing is a free feature — persist the per-post debounce.
        $throttle = max(0, min(60, absint($_POST['notify_throttle_minutes'] ?? 5)));
        self::set('notify_throttle_minutes', $throttle);

        // Pro persists its advanced delivery (multi-recipient list, From /
        // Reply-To) and digest frequency from this same form.
        do_action('dxf_notifications_settings_save');

        wp_safe_redirect(add_query_arg([
            'page'         => 'dox-feedback',
            'tab'          => 'notifications',
            'dxf-saved' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    private function render_tab_comments(): void {
        if ( isset($_GET['dxf-saved']) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' .
                 esc_html__('Settings saved.', 'dox-feedback') .
                 '</p></div>';
        }
        ?>
        <h2><?php esc_html_e('Comments Settings', 'dox-feedback'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('dxf_comments_save', 'dxf_comments_nonce'); ?>
            <input type="hidden" name="action" value="dxf_save_comments">
            <?php do_action('dxf_comments_settings'); ?>
            <?php submit_button(__('Save settings', 'dox-feedback')); ?>
        </form>
        <?php
    }

    public static function save_comments(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die(esc_html__('Unauthorized.', 'dox-feedback'), 403);
        }

        check_admin_referer('dxf_comments_save', 'dxf_comments_nonce');

        // Each field below is individually sanitised (absint / sanitize_key /
        // sanitize_text_field) before persistence, so the structural read here
        // doesn't need a sanitize_* wrapper.
        $opts = isset($_POST['dxf_options']) && is_array($_POST['dxf_options'])
            ? wp_unslash($_POST['dxf_options']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : [];

        $max = isset($opts['comment_attachment_max_mb']) ? absint($opts['comment_attachment_max_mb']) : 5;
        $max = max(1, min(100, $max)); // clamp 1–100 MB
        self::set('comment_attachment_max_mb', $max);

        $allowed_themes = ['follow_bricks', 'os', 'dark', 'light'];
        $theme = isset($opts['comment_modal_theme']) ? sanitize_key($opts['comment_modal_theme']) : 'follow_bricks';
        if ( ! in_array($theme, $allowed_themes, true) ) { $theme = 'follow_bricks'; }
        self::set('comment_modal_theme', $theme);

        // Card-control toggles — checkboxes are simply absent when unticked.
        self::set('comment_show_status_pill', empty($opts['comment_show_status_pill']) ? 0 : 1);
        self::set('comment_show_assign_pill', empty($opts['comment_show_assign_pill']) ? 0 : 1);

        wp_safe_redirect(add_query_arg([
            'page'         => 'dox-feedback',
            'tab'          => 'comments',
            'dxf-saved' => '1',
        ], admin_url('admin.php')));
        exit;
    }
}
