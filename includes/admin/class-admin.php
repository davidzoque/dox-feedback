<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

class DXF_Admin {

    public function __construct() {
        add_action('admin_menu',             [$this, 'register_menu']);
        // Late pass (after every module has registered its submenu) to rename
        // the auto-generated "Dox Feedback" duplicate to "Settings" and sink it to the
        // bottom of the submenu.
        add_action('admin_menu',             [$this, 'reorder_submenu'], 999);
        add_action('admin_enqueue_scripts',  [$this, 'enqueue_assets']);
        add_action('admin_head',             [$this, 'render_favicon']);
        add_action('admin_notices',          [$this, 'render_mail_notice']);
        add_action('admin_init',             [$this, 'handle_mail_notice_dismiss']);
        add_action('wp_ajax_dxf_send_test_email',     [$this, 'ajax_send_test_email']);
        add_action('wp_ajax_dxf_dismiss_bug_callout', ['DXF_Settings', 'ajax_dismiss_bug_callout']);
        add_action('wp_ajax_dxf_seed_dummy',          ['DXF_Settings', 'ajax_seed_dummy']);
        add_action('wp_ajax_dxf_seed_dummy_review',   ['DXF_Settings', 'ajax_seed_dummy_review']);
        add_action('wp_ajax_dxf_telemetry_optin',     ['DXF_Settings', 'ajax_telemetry_optin']);
        // Per-user notification opt-out, rendered on the WP profile page.
        add_action('show_user_profile',                  [$this, 'render_profile_optout']);
        add_action('edit_user_profile',                  [$this, 'render_profile_optout']);
        add_action('personal_options_update',            [$this, 'save_profile_optout']);
        add_action('edit_user_profile_update',           [$this, 'save_profile_optout']);
        add_action('admin_post_dxf_save_general',       ['DXF_Settings', 'save_general']);
        add_action('admin_post_dxf_save_comments',      ['DXF_Settings', 'save_comments']);
        add_action('admin_post_dxf_save_notifications', ['DXF_Settings', 'save_notifications']);
    }

    public function register_menu(): void {
        add_menu_page(
            __('Dox Feedback – Client Feedback & Approvals', 'dox-feedback'),
            __('Dox Feedback', 'dox-feedback'),
            'manage_options',
            'dox-feedback',
            [$this, 'render_settings'],
            DXF_URL . 'assets/images/icon.svg',
            // Sit at the bottom of the top content group (Posts/Pages/CPTs),
            // just above the Appearance separator at position 59 — Dox Feedback is
            // about the site's content, so it belongs with it rather than down
            // in the configuration block.
            58
        );
    }

    /**
     * Tidy the Dox Feedback submenu. add_menu_page() auto-creates a first submenu item
     * that duplicates the top-level menu label ("Dox Feedback") and points back at the
     * settings screen. Rename that item to "Settings" and move it to the bottom,
     * so the task-oriented items (Getting Started, Reviews, Approvals) lead and
     * configuration sits last. Runs at priority 999 so every module's
     * add_submenu_page() has already registered.
     */
    public function reorder_submenu(): void {
        global $submenu;
        if ( empty($submenu['dox-feedback']) || ! is_array($submenu['dox-feedback']) ) {
            return;
        }
        $items =& $submenu['dox-feedback'];
        foreach ( $items as $key => $item ) {
            // The duplicate is the one whose menu slug equals the parent slug.
            if ( isset($item[2]) && $item[2] === 'dox-feedback' ) {
                $item[0] = __('Settings', 'dox-feedback');
                unset($items[$key]);
                $items[] = $item;          // re-append at the end
                break;
            }
        }
        $items = array_values($items);     // re-key so WP renders in order
    }

    public function render_settings(): void {
        if ( ! current_user_can('manage_options') ) {
            return;
        }
        $settings = new DXF_Settings();
        $settings->render();
    }

    /**
     * True on any Dox Feedback admin screen (the top-level Settings page and
     * all its submenus — Getting Started, Reviews, Approvals, …). Used to scope
     * the brand stylesheet + favicon. Matches on the WP screen id, which is
     * prefixed with the menu slug for every submenu we register.
     */
    public static function is_dxf_screen(): bool {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ( ! $screen ) {
            return false;
        }
        $id = (string) $screen->id;
        return ( strpos($id, 'dox-feedback') !== false || strpos($id, 'dxf') !== false );
    }

    /**
     * Use the Dox Studio diamond as the browser tab favicon on our own screens,
     * matching the rest of the Dox Studio plugin suite.
     */
    public function render_favicon(): void {
        if ( ! self::is_dxf_screen() ) {
            return;
        }
        echo '<link rel="icon" type="image/svg+xml" href="' . esc_url( DXF_URL . 'assets/images/icon.svg' ) . '">' . "\n";
    }

    /**
     * Shared branded header — the Dox Studio wordmark + a product pill, on a
     * white card. Rendered at the top of Settings and Getting Started so every
     * Dox Feedback screen leads with the logo (consistent with Dox Functions /
     * Sales Booster). The visible page <h1> is hidden by admin.css and replaced
     * by this bar; a screen-reader-only title is kept for accessibility.
     *
     * @param string $pill  Short product label shown in the orange pill.
     * @param string $meta  Optional right-aligned HTML (already escaped) — e.g.
     *                      a version string or a docs link.
     */
    public static function brand_header( string $pill = '', string $meta = '' ): void {
        $pill = $pill !== '' ? $pill : __('Feedback', 'dox-feedback');
        ?>
        <div class="dxf-brandbar">
            <div class="dxf-brandbar__brand">
                <img src="<?php echo esc_url( DXF_URL . 'assets/images/logo.svg' ); ?>"
                     alt="Dox Studio" class="dxf-brandbar__logo">
                <span class="dxf-brandbar__pill"><?php echo esc_html( $pill ); ?></span>
            </div>
            <?php if ( $meta !== '' ) : ?>
                <div class="dxf-brandbar__meta"><?php echo wp_kses_post( $meta ); ?></div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function enqueue_assets(string $hook): void {
        // The admin menu icon is visible on every admin screen, so this one
        // stylesheet is enqueued globally (before the page-specific return).
        wp_enqueue_style(
            'dxf-menu-icon',
            DXF_URL . 'assets/admin/menu-icon.css',
            [],
            DXF_VERSION
        );

        // Dox Studio brand stylesheet (tokens + header + orange accents) loads
        // on every Dox Feedback admin screen — Settings, Getting Started,
        // Reviews, Approvals — so the whole suite reads as one brand.
        if ( self::is_dxf_screen() ) {
            wp_enqueue_style(
                'dxf-admin',
                DXF_URL . 'assets/admin/admin.css',
                [],
                DXF_VERSION
            );
        }

        if ( $hook !== 'toplevel_page_dox-feedback' ) {
            return;
        }

        wp_enqueue_script(
            'dxf-admin',
            DXF_URL . 'assets/admin/admin.js',
            ['jquery'],
            DXF_VERSION,
            true
        );

        // admin.js carries the test-email helper for the General tab.
        wp_localize_script('dxf-admin', 'dxfAdmin', [
            'nonce'         => wp_create_nonce('dxf_admin'),
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'notifyEmail'   => DXF_Settings::get('review_notify_email', get_option('admin_email')),
            'i18n' => [
                'error'           => __('Something went wrong. Please try again.', 'dox-feedback'),
                'sendingTest'     => __('Sending…', 'dox-feedback'),
                'testEmailSent'   => __('Test email sent successfully.', 'dox-feedback'),
                'testEmailFailed' => __('Test email failed. See the error below.', 'dox-feedback'),
                // admin.js reads these (snake_case) keys
                'adm.error'             => __('Something went wrong. Please try again.', 'dox-feedback'),
                'adm.sending_test'      => __('Sending…', 'dox-feedback'),
                'adm.test_email_sent'   => __('Test email sent successfully.', 'dox-feedback'),
                'adm.test_email_failed' => __('Test email failed. See the error below.', 'dox-feedback'),
                'adm.send_test_email'   => __('Send test email', 'dox-feedback'),
            ],
        ]);

        // Settings-screen helpers (dev-tools seeder, bug callout dismiss,
        // telemetry opt-in). Each block self-gates on element presence, so a
        // single script + nonce payload covers all three regardless of which
        // notices happen to be rendered this request.
        wp_enqueue_script(
            'dxf-settings',
            DXF_URL . 'assets/admin/settings.js',
            [],
            DXF_VERSION,
            true
        );
        wp_localize_script('dxf-settings', 'dxfSettings', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'seedNonce'      => wp_create_nonce('dxf_seed_dummy'),
            'bugNonce'       => wp_create_nonce('dxf_dismiss_bug_callout'),
            'telemetryNonce' => wp_create_nonce('dxf_telemetry_optin'),
            'i18n' => [
                'set.seed_need_id'  => __('Enter a post or page ID first.', 'dox-feedback'),
                'set.seeding'       => __('Seeding…', 'dox-feedback'),
                'set.seeded_count'  => __('Seeded %d comments.', 'dox-feedback'),
                'set.open_review'   => __('Open review →', 'dox-feedback'),
                'set.seed_failed'   => __('Seed failed.', 'dox-feedback'),
                'set.network_error' => __('Network error.', 'dox-feedback'),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Mail notice
    // -------------------------------------------------------------------------

    public function render_mail_notice(): void {
        if ( ! current_user_can('manage_options') ) {
            return;
        }
        if ( ! DXF_Mailer::should_show_notice() ) {
            return;
        }

        // The mailer may include a single inline <a> (e.g. SMTP plugin hint),
        // so allow safe HTML here rather than esc_html the whole message.
        $error        = DXF_Mailer::last_error();
        $dismiss_url  = wp_nonce_url(
            add_query_arg('dxf_dismiss_mail_notice', '1'),
            'dxf_dismiss_mail_notice'
        );
        $settings_url = add_query_arg(
            ['page' => 'dox-feedback', 'tab' => 'general'],
            admin_url('admin.php')
        );
        ?>
        <div class="notice notice-error dxf-mail-notice" style="position:relative; padding-right: 48px;">
            <p>
                <strong><?php esc_html_e('Dox Feedback — email delivery problem', 'dox-feedback'); ?></strong><br>
                <?php esc_html_e('A notification email could not be sent. Your site may not have SMTP configured.', 'dox-feedback'); ?>
                <?php if ( $error ) : ?>
                    <br><em><?php echo wp_kses_post($error); ?></em>
                <?php endif; ?>
                &nbsp;
                <a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Review settings →', 'dox-feedback'); ?></a>
            </p>
            <a href="<?php echo esc_url($dismiss_url); ?>"
               style="position:absolute; top:12px; right:14px; text-decoration:none; font-size:18px; color:#787c82; line-height:1;"
               aria-label="<?php esc_attr_e('Dismiss this notice', 'dox-feedback'); ?>">
                &times;
            </a>
        </div>
        <?php
    }

    public function handle_mail_notice_dismiss(): void {
        if (
            ! isset($_GET['dxf_dismiss_mail_notice']) ||
            ! current_user_can('manage_options') ||
            ! check_admin_referer('dxf_dismiss_mail_notice')
        ) {
            return;
        }

        DXF_Mailer::dismiss_notice();

        // Redirect back without the query var so the URL stays clean.
        $redirect = remove_query_arg(['dxf_dismiss_mail_notice', '_wpnonce']);
        wp_safe_redirect($redirect);
        exit;
    }

    // -------------------------------------------------------------------------
    // Test email AJAX
    // -------------------------------------------------------------------------

    public function ajax_send_test_email(): void {
        check_ajax_referer('dxf_admin');

        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
        }

        $to = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if ( ! $to ) {
            $to = DXF_Settings::get('review_notify_email', get_option('admin_email'));
        }

        $site = get_bloginfo('name');
        /* translators: %s = site name */
        $subject = sprintf(__('[%s] Dox Feedback test email', 'dox-feedback'), $site);
        $message = __("This is a test email sent from Dox Feedback.\n\nIf you received this message, WordPress email delivery is working correctly.", 'dox-feedback');

        $ok = DXF_Mailer::send($to, $subject, $message);

        if ( $ok ) {
            wp_send_json_success(['message' => __('Test email sent successfully.', 'dox-feedback')]);
        } else {
            wp_send_json_error(['message' => DXF_Mailer::last_error() ?: __('wp_mail() returned false.', 'dox-feedback')]);
        }
    }

    /**
     * Render the Dox Feedback notifications section on a user's profile page.
     * Lets each user opt out of reply + assignment emails for themselves.
     */
    public function render_profile_optout( \WP_User $user ): void {
        // Visible to the user themselves or admins editing them — WP's own
        // template guards both cases. We add a tighter guard for safety.
        if ( ! current_user_can('edit_user', $user->ID) ) {
            return;
        }
        // Stored as '0' = opt-out (legacy key). Any other value = opted in.
        $opted_out = get_user_meta($user->ID, 'dxf_notify_builder', true) === '0';
        wp_nonce_field('dxf_profile_optout_' . $user->ID, 'dxf_profile_optout_nonce');
        ?>
        <h2 id="dxf-notifications"><?php esc_html_e('Dox Feedback notifications', 'dox-feedback'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Email notifications', 'dox-feedback'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="dxf_notify_optout" value="1" <?php checked($opted_out); ?>>
                        <?php esc_html_e('Don\'t send me Dox Feedback comment notifications (replies and assignments).', 'dox-feedback'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Site-wide notifications configured by an administrator are unaffected. This setting only mutes the emails sent personally to you.', 'dox-feedback'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_profile_optout( int $user_id ): void {
        if ( ! current_user_can('edit_user', $user_id) ) {
            return;
        }
        if ( ! isset($_POST['dxf_profile_optout_nonce'])
             || ! wp_verify_nonce(
                    sanitize_text_field(wp_unslash($_POST['dxf_profile_optout_nonce'])),
                    'dxf_profile_optout_' . $user_id
                )
        ) {
            return;
        }
        if ( ! empty($_POST['dxf_notify_optout']) ) {
            update_user_meta($user_id, 'dxf_notify_builder', '0');
        } else {
            delete_user_meta($user_id, 'dxf_notify_builder');
        }
    }
}
