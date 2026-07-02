<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

// Data layer for the custom dxf_approvals table (append-only sign-off
// records). Every query in this class targets that table; object caching is
// avoided because approvals must be readable immediately after they're written.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Immutable client approval records ("sign-off proof").
 *
 * When a reviewer marks a page approved, we store a server-stamped record
 * (who, when, from where) that the agency can view and export as a printable
 * certificate — useful as evidence that a client signed off on a page.
 */
class DXF_Approvals {

    public const MENU_SLUG = 'dxf-approvals';

    public function __construct() {
        // Own submenu under the Dox Feedback parent (peer to Reviews, replacing
        // the old Settings → Approvals tab).
        add_action('admin_menu',                       [$this, 'register_menu'], 25);
        // Back-compat: anything (third-party or in-tree) still firing the
        // legacy settings-tab hook keeps getting the rendered list.
        add_action('dxf_approvals_admin',           [$this, 'render_admin']);
        add_action('wp_ajax_dxf_unapprove_page',    [__CLASS__, 'ajax_unapprove']);
    }

    /**
     * Register the top-level Approvals submenu under the Dox Feedback parent.
     * Priority 25 keeps it just after the parent (registered at 10) and
     * before Reviews (registered at 20 by DXF_Reviews_Admin).
     */
    public function register_menu(): void {
        add_submenu_page(
            'dox-feedback',
            __('Approvals', 'dox-feedback'),
            __('Approvals', 'dox-feedback'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    /**
     * Top-of-page wrapper for the Approvals submenu — H1, intro, then the
     * approvals list. Calls render_admin() directly so the rendering logic
     * stays in one place (the same method also fires via the legacy hook).
     */
    public function render(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die(esc_html__('You do not have permission to view approvals.', 'dox-feedback'));
        }
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Approvals', 'dox-feedback'); ?></h1>
            <p class="description" style="margin: 12px 0 16px; max-width: 720px;">
                <?php esc_html_e('A record is stored each time a reviewer marks a page as approved — useful as evidence that a client signed off on a page.', 'dox-feedback'); ?>
            </p>

            <?php $this->render_admin(); ?>
        </div>
        <?php
    }

    /**
     * Return the most-recent approval record for a post (or null when none).
     * Used by the in-builder modal to show "Page approved by …" + Revert.
     */
    public static function latest_for_post( int $post_id ): ?array {
        if ( ! $post_id ) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, approver_name, approver_email, approved_at FROM %i
                  WHERE post_id = %d ORDER BY approved_at DESC LIMIT 1",
                $wpdb->prefix . 'dxf_approvals', $post_id
            ),
            ARRAY_A
        );
        if ( ! $row ) {
            return null;
        }
        return [
            'id'          => (int) $row['id'],
            'name'        => (string) $row['approver_name'],
            'email'       => (string) $row['approver_email'],
            'approved_at' => (string) $row['approved_at'],
        ];
    }

    /**
     * Revert all approvals for a post (clears dxf_approvals rows + every
     * `_dxf_complete_*` post-meta). Requires edit_post capability.
     */
    public static function ajax_unapprove(): void {
        check_ajax_referer( DXF_Comments::NONCE_ACTION );
        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'dox-feedback' ) ], 403 );
        }
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'dxf_approvals', [ 'post_id' => $post_id ], [ '%d' ] );
        // Clear all `_dxf_complete_*` meta keys for this post.
        $meta_rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
            $post_id,
            '_dxf_complete_%'
        ) );
        foreach ( $meta_rows as $k ) {
            delete_post_meta( $post_id, $k );
        }
        wp_send_json_success();
    }

    /**
     * Persist an approval. IP, user-agent, title and URL are captured
     * server-side so the record cannot be forged by the client.
     */
    public static function record( int $post_id, string $token, string $name, string $email ): void {
        if ( ! $post_id ) {
            return;
        }
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'dxf_approvals',
            [
                'post_id'        => $post_id,
                'token'          => mb_substr($token, 0, 64),
                'approver_name'  => mb_substr(sanitize_text_field($name), 0, 100),
                'approver_email' => sanitize_email($email),
                'approver_ip'    => self::client_ip(),
                'user_agent'     => mb_substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 300),
                'page_title'     => (string) get_the_title($post_id),
                'page_url'       => (string) ( get_permalink($post_id) ?: '' ),
                // Store the timestamp explicitly in UTC rather than relying on the
                // column's CURRENT_TIMESTAMP default, whose timezone follows the
                // MySQL session (not guaranteed UTC). Shown via get_date_from_gmt().
                'approved_at'    => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    private static function client_ip(): string {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return mb_substr($ip, 0, 45);
    }

    // -------------------------------------------------------------------------
    // Admin list (rendered inside the Dox Feedback → Approvals settings tab)
    // -------------------------------------------------------------------------

    public function render_admin(): void {
        if ( ! current_user_can('manage_options') ) {
            return;
        }
        global $wpdb;
        // Admin-only listing (manage_options checked above). Table name bound via
        // the %i identifier placeholder; no other user input.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM %i ORDER BY approved_at DESC LIMIT 200", $wpdb->prefix . 'dxf_approvals' ),
            ARRAY_A
        );

        if ( empty($rows) ) {
            echo '<p>' . esc_html__('No client approvals recorded yet. When a reviewer marks a page as approved, it will appear here.', 'dox-feedback') . '</p>';
            return;
        }
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Page', 'dox-feedback'); ?></th>
                    <th><?php esc_html_e('Approved by', 'dox-feedback'); ?></th>
                    <th><?php esc_html_e('Email', 'dox-feedback'); ?></th>
                    <th><?php esc_html_e('Date', 'dox-feedback'); ?></th>
                    <?php if ( has_action('dxf_approval_certificate_link') ) : ?>
                        <th><?php esc_html_e('Certificate', 'dox-feedback'); ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html($row['page_title'] ?: ('#' . $row['post_id'])); ?></td>
                        <td><?php echo esc_html($row['approver_name'] ?: '—'); ?></td>
                        <td><?php echo esc_html($row['approver_email'] ?: '—'); ?></td>
                        <td><?php echo esc_html(get_date_from_gmt($row['approved_at'], get_option('date_format') . ' ' . get_option('time_format'))); ?></td>
                        <?php if ( has_action('dxf_approval_certificate_link') ) : ?>
                            <td><?php
                                /**
                                 * A listener can render a "View / Print
                                 * certificate" button here from the stored
                                 * sign-off record.
                                 */
                                do_action('dxf_approval_certificate_link', $row);
                            ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // A printable / PDF approval certificate can be generated from the stored
    // approval record by hooking `dxf_approval_certificate_link` (the admin-list
    // button) and `dxf_notify_opts` (the emailed PDF attachment). The core
    // module records the sign-off; document generation is left to a listener.
}
