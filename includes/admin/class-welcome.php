<?php
/**
 * Dox Feedback — first-run "Getting Started" experience.
 *
 * On a fresh activation we redirect once to a guided page so a new user lands
 * on "here's how to get value" instead of an empty admin screen. The page is
 * also a permanent, re-openable submenu item (Dox Feedback → Getting Started) so the
 * walkthrough — and the reminder of what's available — is always one click away.
 */

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

final class DXF_Welcome {

    public const PAGE_SLUG      = 'dxf-getting-started';
    private const REDIRECT_FLAG = 'dxf_welcome_redirect';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_page']);
        add_action('admin_init', [$this, 'maybe_redirect']);
    }

    /** Permanent, re-openable submenu item under the Dox Feedback top-level menu. */
    public function register_page(): void {
        add_submenu_page(
            'dox-feedback',
            __('Getting Started', 'dox-feedback'),
            __('Getting Started', 'dox-feedback'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /** Called from DXF_Plugin::activate(). Armed so the first admin load redirects. */
    public static function arm_redirect(): void {
        set_transient(self::REDIRECT_FLAG, 1, MINUTE_IN_SECONDS);
    }

    /** One-time redirect to the Getting Started page after activation. */
    public function maybe_redirect(): void {
        if ( ! get_transient(self::REDIRECT_FLAG) ) {
            return;
        }
        // Never bounce on AJAX, for users who can't see the page, or during a
        // bulk / network "activate all" (that would hijack the whole batch).
        if ( wp_doing_ajax() || ! current_user_can('manage_options') || is_network_admin() ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset($_GET['activate-multi']) ) {
            return;
        }
        // If Pro just started a trial it arms its own welcome — let that win so
        // a new Pro user doesn't get double-bounced.
        if ( get_transient('dxf_pro_welcome_redirect') ) {
            return;
        }
        // Already here? Nothing to do.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset($_GET['page']) && $_GET['page'] === self::PAGE_SLUG ) {
            delete_transient(self::REDIRECT_FLAG);
            return;
        }
        delete_transient(self::REDIRECT_FLAG);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    // ---------------------------------------------------------------------
    // Render
    // ---------------------------------------------------------------------

    public function render(): void {
        if ( ! current_user_can('manage_options') ) {
            return;
        }

        $reviews_url   = admin_url('admin.php?page=dxf-reviews');
        $approvals_url = admin_url('admin.php?page=dxf-approvals');
        $home_url      = home_url('/');

        $card  = 'background:#fff;border:1px solid #e2e4e9;border-radius:10px;padding:20px 22px;margin:0;box-shadow:0 1px 2px rgba(0,0,0,.03);';
        $num   = 'display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:#ff8d27;color:#fff;font-weight:700;font-size:13px;margin-right:8px;flex-shrink:0;';
        ?>
        <div class="wrap dxf-welcome" style="max-width:880px;">
            <?php DXF_Admin::brand_header( __('Getting Started', 'dox-feedback') ); ?>
            <h1 style="display:flex;align-items:center;gap:10px;">
                <?php esc_html_e('Welcome to Dox Feedback 👋', 'dox-feedback'); ?>
            </h1>
            <p style="font-size:15px;color:#50575e;max-width:680px;margin:6px 0 22px;">
                <?php esc_html_e('Dox Feedback lets your clients leave feedback by clicking the live page — no login, no account — and you resolve it right where you build. Here\'s how to get your first review running in a couple of minutes.', 'dox-feedback'); ?>
            </p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

                <div style="<?php echo esc_attr($card); ?>">
                    <h2 style="margin:0 0 8px;font-size:16px;"><span style="<?php echo esc_attr($num); ?>">1</span><?php esc_html_e('Try it on your live site', 'dox-feedback'); ?></h2>
                    <p style="color:#50575e;margin:0 0 14px;"><?php esc_html_e('Open any page on your site in the browser and click the Dox Feedback button in the admin bar at the top.', 'dox-feedback'); ?></p>
                    <a href="<?php echo esc_url(add_query_arg('dxf-welcome', '1', $home_url)); ?>" class="button button-primary" target="_blank" rel="noopener"><?php esc_html_e('Open my site →', 'dox-feedback'); ?></a>
                </div>

                <div style="<?php echo esc_attr($card); ?>">
                    <h2 style="margin:0 0 8px;font-size:16px;"><span style="<?php echo esc_attr($num); ?>">2</span><?php esc_html_e('Share a no-login link', 'dox-feedback'); ?></h2>
                    <p style="color:#50575e;margin:0 0 14px;"><?php esc_html_e('From that same Dox Feedback button in the admin bar — or the Reviews screen — generate a review link. Your client opens the live page and comments. No account, nothing to install.', 'dox-feedback'); ?></p>
                    <a href="<?php echo esc_url($reviews_url); ?>" class="button"><?php esc_html_e('Go to Reviews', 'dox-feedback'); ?></a>
                </div>

                <div style="<?php echo esc_attr($card); ?>">
                    <h2 style="margin:0 0 8px;font-size:16px;"><span style="<?php echo esc_attr($num); ?>">3</span><?php esc_html_e('Resolve & reply', 'dox-feedback'); ?></h2>
                    <p style="color:#50575e;margin:0;"><?php esc_html_e('Every comment becomes a pin on the page. Open the Dox Feedback panel — on the live page or in the Bricks Builder — to reply, set a status, drag pins and filter what\'s resolved, without leaving WordPress.', 'dox-feedback'); ?></p>
                </div>

                <div style="<?php echo esc_attr($card); ?>">
                    <h2 style="margin:0 0 8px;font-size:16px;"><span style="<?php echo esc_attr($num); ?>">4</span><?php esc_html_e('Get a clean sign-off', 'dox-feedback'); ?></h2>
                    <p style="color:#50575e;margin:0 0 14px;"><?php esc_html_e('Clients mark a page approved and you get a timestamped sign-off record in Approvals — your proof the work was accepted.', 'dox-feedback'); ?></p>
                    <a href="<?php echo esc_url($approvals_url); ?>" class="button"><?php esc_html_e('View Approvals', 'dox-feedback'); ?></a>
                </div>

            </div>

            <p style="margin-top:22px;color:#646970;">
                <?php
                printf(
                    /* translators: %s = support link */
                    esc_html__('Stuck on anything? %s and we\'ll help.', 'dox-feedback'),
                    '<a href="https://doxstudio.com" target="_blank" rel="noopener">' . esc_html__('Contact Dox Studio', 'dox-feedback') . '</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }
}
