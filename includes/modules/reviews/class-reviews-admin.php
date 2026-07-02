<?php
/**
 * Dox Feedback Reviews — admin UI (submenu under Dox Feedback top-level).
 *
 * Pages:
 *   - dxf-reviews        — list of all reviews
 *   - dxf-reviews&action=new       — create wizard (scope → recipients → send)
 *   - dxf-reviews&action=edit&id=X — detail / manage members + audit log
 *
 * @since 0.16.0
 */

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

final class DXF_Reviews_Admin {

    public const MENU_SLUG = 'dxf-reviews';

    public function __construct() {
        add_action('admin_menu',            [$this, 'register_menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menu(): void {
        add_submenu_page(
            'dox-feedback',
            __('Reviews', 'dox-feedback'),
            __('Reviews', 'dox-feedback'),
            'edit_posts',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function enqueue_assets(string $hook): void {
        if ( strpos($hook, self::MENU_SLUG) === false ) return;
        wp_enqueue_style(
            'dxf-reviews-admin',
            DXF_URL . 'assets/admin/reviews.css',
            [],
            DXF_VERSION
        );
        wp_enqueue_script(
            'dxf-reviews-admin',
            DXF_URL . 'assets/admin/reviews.js',
            ['jquery'],
            DXF_VERSION,
            true
        );
        wp_localize_script('dxf-reviews-admin', 'dxfReviews', [
            'nonce'         => wp_create_nonce('dxf_review_admin'),
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'menuBase'      => admin_url('admin.php?page=' . self::MENU_SLUG),
            'i18n'        => [
                'confirmDelete'   => __('Delete this review and all its data? This cannot be undone.', 'dox-feedback'),
                'copied'          => __('Link copied', 'dox-feedback'),
            ],
        ]);
    }

    // ---------------------------------------------------------------------
    // Routing
    // ---------------------------------------------------------------------

    public function render(): void {
        if ( ! current_user_can('edit_posts') ) {
            wp_die(esc_html__('You do not have permission to view reviews.', 'dox-feedback'));
        }
        // Read-only screen routing — action/id only choose which view to render.
        // Mutating endpoints (create/edit) are AJAX and have their own nonces.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset($_GET['action']) ? sanitize_key((string) wp_unslash($_GET['action'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $id     = isset($_GET['id'])     ? (int) $_GET['id']                                   : 0;

        echo '<div class="wrap dxf-reviews-wrap">';

        switch ($action) {
            case 'new':
                $this->render_create_wizard();
                break;
            case 'edit':
                $this->render_edit($id);
                break;
            default:
                $this->render_list();
        }

        echo '</div>';
    }

    // ---------------------------------------------------------------------
    // List
    // ---------------------------------------------------------------------

    private function render_list(): void {
        // Read-only filter — drives the WHERE clause via DXF_Review::find().
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status_filter = isset($_GET['status']) ? sanitize_key((string) wp_unslash($_GET['status'])) : '';
        $args = $status_filter ? ['status' => $status_filter] : [];
        $reviews = DXF_Review::find($args);
        $new_url = admin_url('admin.php?page=' . self::MENU_SLUG . '&action=new');
        ?>
        <h1 class="wp-heading-inline"><?php esc_html_e('Reviews', 'dox-feedback'); ?></h1>
        <a class="page-title-action" href="<?php echo esc_url($new_url); ?>">
            <?php esc_html_e('New review', 'dox-feedback'); ?>
        </a>

        <p class="description" style="margin: 12px 0 16px; max-width: 720px;">
            <?php esc_html_e('A review bundles one or more pages under a single shareable link. Send the link (or magic-link emails) to your client; their feedback appears here.', 'dox-feedback'); ?>
        </p>

        <ul class="subsubsub" style="margin-bottom: 12px;">
            <?php
            $filters = [
                ''                              => __('All',     'dox-feedback'),
                DXF_Review::STATUS_ACTIVE    => __('Active',  'dox-feedback'),
                DXF_Review::STATUS_DRAFT     => __('Draft',   'dox-feedback'),
                DXF_Review::STATUS_CLOSED    => __('Closed',  'dox-feedback'),
                DXF_Review::STATUS_EXPIRED   => __('Expired', 'dox-feedback'),
            ];
            $i = 0; $count = count($filters);
            foreach ($filters as $f_status => $label):
                $url = $f_status === '' ? admin_url('admin.php?page=' . self::MENU_SLUG) : add_query_arg('status', $f_status, admin_url('admin.php?page=' . self::MENU_SLUG));
                $class = $status_filter === $f_status ? 'current' : '';
                ?>
                <li><a class="<?php echo esc_attr($class); ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a><?php echo (++$i < $count) ? ' |' : ''; ?></li>
            <?php endforeach; ?>
        </ul>

        <table class="wp-list-table widefat striped dxf-reviews-table">
            <thead>
                <tr>
                    <th class="column-name"><?php esc_html_e('Name',     'dox-feedback'); ?></th>
                    <th class="column-scope"><?php esc_html_e('Scope',    'dox-feedback'); ?></th>
                    <th class="column-mode"><?php esc_html_e('Mode',     'dox-feedback'); ?></th>
                    <th class="column-status"><?php esc_html_e('Status',   'dox-feedback'); ?></th>
                    <th class="column-created"><?php esc_html_e('Created',  'dox-feedback'); ?></th>
                    <th class="column-expires"><?php esc_html_e('Expires',  'dox-feedback'); ?></th>
                    <th class="column-viewed"><?php esc_html_e('Last viewed', 'dox-feedback'); ?></th>
                    <th class="column-actions" style="width: 180px; text-align: right;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty($reviews) ): ?>
                    <tr><td colspan="8" style="padding:32px;text-align:center;color:#666;">
                        <?php esc_html_e('No reviews yet.', 'dox-feedback'); ?>
                        <a href="<?php echo esc_url($new_url); ?>"><?php esc_html_e('Create your first review →', 'dox-feedback'); ?></a>
                    </td></tr>
                <?php else: foreach ($reviews as $r):
                    $edit_url   = admin_url('admin.php?page=' . self::MENU_SLUG . '&action=edit&id=' . (int) $r['id']);
                    $row_status = (string) $r['status'];
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url($edit_url); ?>"><strong><?php echo esc_html($r['name'] ?: __('(unnamed)', 'dox-feedback')); ?></strong></a></td>
                        <td><?php echo esc_html(self::scope_label((string) $r['scope_type'])); ?></td>
                        <td><?php echo esc_html(self::mode_label((string) $r['mode'])); ?></td>
                        <td><span class="dxf-status dxf-status-<?php echo esc_attr($row_status); ?>"><?php echo esc_html(self::status_label($row_status)); ?></span></td>
                        <td><?php echo esc_html(date_i18n('M j, Y', strtotime($r['created_at']))); ?></td>
                        <td><?php echo $r['expires_at'] ? esc_html(date_i18n('M j, Y', strtotime($r['expires_at']))) : '—'; ?></td>
                        <td><?php
                            $r_seen = DXF_Review_Audit::last_occurred((int) $r['id'], 'viewed');
                            if ( $r_seen !== '' ) {
                                echo esc_html(self::viewed_ago($r_seen));
                            } else {
                                echo '<span style="color:#999;">' . esc_html__('Not yet', 'dox-feedback') . '</span>';
                            }
                        ?></td>
                        <td class="column-actions" style="white-space: nowrap; text-align: right;">
                            <?php if ( $row_status === DXF_Review::STATUS_DRAFT ): ?>
                                <button type="button"
                                        class="button button-small dxf-publish"
                                        data-review-id="<?php echo (int) $r['id']; ?>">
                                    <?php esc_html_e('Activate', 'dox-feedback'); ?>
                                </button>
                            <?php elseif ( $row_status === DXF_Review::STATUS_ACTIVE ): ?>
                                <button type="button" class="button button-small dxf-close" data-review-id="<?php echo (int) $r['id']; ?>"><?php esc_html_e('Deactivate', 'dox-feedback'); ?></button>
                            <?php elseif ( in_array($row_status, [DXF_Review::STATUS_CLOSED, DXF_Review::STATUS_EXPIRED], true) ): ?>
                                <button type="button"
                                        class="button button-small dxf-reopen"
                                        data-review-id="<?php echo (int) $r['id']; ?>">
                                    <?php esc_html_e('Activate', 'dox-feedback'); ?>
                                </button>
                            <?php endif; ?>
                            <a href="<?php echo esc_url($edit_url); ?>" style="margin-left:6px;"><?php esc_html_e('Manage →', 'dox-feedback'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    // ---------------------------------------------------------------------
    // Create wizard
    // ---------------------------------------------------------------------

    private function render_create_wizard(): void {
        // Grouped catalogue of every reviewable item for the page picker.
        // Shared with the Pro edit-scope panel via DXF_Review.
        $catalogue     = DXF_Review::build_picker_catalogue(500);
        $picker        = $catalogue['picker'];
        $total_count   = $catalogue['total_count'];   // for the Entire-scope summary
        $truncated_any = $catalogue['truncated_any'];
        $picker_cap    = $catalogue['cap'];
        ?>
        <h1><?php esc_html_e('New review', 'dox-feedback'); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>" class="page-title-action">
                <?php esc_html_e('Cancel', 'dox-feedback'); ?>
            </a>
        </h1>

        <form class="dxf-review-form" data-step="1">

            <!-- Step 1 — Scope -->
            <div class="dxf-step dxf-step-1 active">
                <h2><?php esc_html_e('1. What should they review?', 'dox-feedback'); ?></h2>

                <label class="dxf-scope-card">
                    <input type="radio" name="scope_type" value="single" checked>
                    <div>
                        <strong><?php esc_html_e('Single page', 'dox-feedback'); ?></strong>
                        <span class="description"><?php esc_html_e('Pick one page to review.', 'dox-feedback'); ?></span>
                    </div>
                </label>

                <?php
                /**
                 * Selected-pages / Entire-website scope cards. Same args as the
                 * extras hook so the Entire card can show the live item count.
                 *
                 * @param array $picker        post-type-grouped item catalogue
                 * @param int   $total_count   total reviewable items
                 * @param bool  $truncated_any whether any group hit the cap
                 * @param int   $picker_cap    per-post-type item cap
                 */
                do_action('dxf_review_wizard_scope_cards', $picker, $total_count, $truncated_any, $picker_cap);
                ?>

                <div class="dxf-scope-extras" style="margin-top: 16px;">
                    <div class="dxf-page-single" style="display:none;">
                        <label><?php esc_html_e('Which item?', 'dox-feedback'); ?>
                            <select name="single_post_id" style="width: 100%; margin-top: 6px;">
                                <option value=""><?php esc_html_e('— select an item —', 'dox-feedback'); ?></option>
                                <?php foreach ($picker as $slug => $group): ?>
                                    <optgroup label="<?php echo esc_attr($group['label']); ?>">
                                        <?php foreach ($group['items'] as $item): ?>
                                            <option value="<?php echo (int) $item['id']; ?>"><?php echo esc_html($item['title']); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <?php
                    /**
                     * Scope-specific extras for the Pro scopes (the
                     * include-future checkbox for Entire, the multi-select page
                     * picker for Selected). Pro renders these with the same
                     * .dxf-include-future / .dxf-page-picker classes the
                     * wizard JS toggles. $picker is the grouped catalogue of
                     * reviewable items already built above.
                     *
                     * @param array $picker        post-type-grouped item catalogue
                     * @param int   $total_count   total reviewable items
                     * @param bool  $truncated_any whether any group hit the cap
                     * @param int   $picker_cap    per-post-type item cap
                     */
                    do_action('dxf_review_wizard_scope_extras', $picker, $total_count, $truncated_any, $picker_cap);
                    ?>
                </div>

                <p class="dxf-step-actions">
                    <button type="button" class="button button-primary dxf-next"><?php esc_html_e('Next: Reviewers', 'dox-feedback'); ?></button>
                </p>
            </div>

            <!-- Step 2 — Mode + Reviewers -->
            <div class="dxf-step dxf-step-2">
                <h2><?php esc_html_e('2. Who should review it?', 'dox-feedback'); ?></h2>

                <label class="dxf-mode-card">
                    <input type="radio" name="mode" value="link" checked>
                    <div>
                        <strong><?php esc_html_e('Anyone with the link', 'dox-feedback'); ?></strong>
                        <span class="description"><?php esc_html_e('Friction-free. Reviewer enters their name/email when they open the link.', 'dox-feedback'); ?></span>
                    </div>
                </label>

                <?php
                /**
                 * The email-restricted mode card (invite specific people by
                 * email, with role-based access).
                 */
                do_action('dxf_review_wizard_mode_cards');

                /**
                 * The email-config block (invitee addresses + default role).
                 * Pro renders it with the .dxf-email-config class the wizard
                 * JS toggles when the email mode card is selected.
                 */
                do_action('dxf_review_wizard_mode_config');
                ?>

                <p class="dxf-step-actions">
                    <button type="button" class="button dxf-prev"><?php esc_html_e('Back', 'dox-feedback'); ?></button>
                    <button type="button" class="button button-primary dxf-next"><?php esc_html_e('Next: Review &amp; send', 'dox-feedback'); ?></button>
                </p>
            </div>

            <!-- Step 3 — Review + send -->
            <div class="dxf-step dxf-step-3">
                <h2><?php esc_html_e('3. Name &amp; publish', 'dox-feedback'); ?></h2>
                <label><?php esc_html_e('Review name (shown to the reviewer)', 'dox-feedback'); ?>
                    <input type="text" name="name" style="width: 100%; margin-top: 6px;" placeholder="<?php esc_attr_e('e.g. Acme — Homepage redesign R2', 'dox-feedback'); ?>">
                </label>

                <label style="margin-top: 12px; display: block;"><?php esc_html_e('Expires on (UTC)', 'dox-feedback'); ?>
                    <input type="date" name="expires_at" style="margin-top: 6px;" value="<?php echo esc_attr(gmdate('Y-m-d', time() + 30 * DAY_IN_SECONDS)); ?>">
                    <span class="description"><?php esc_html_e('Default 30 days. The review auto-closes after this date.', 'dox-feedback'); ?></span>
                </label>
                <label style="margin-top: 8px; display: block;">
                    <input type="checkbox" name="no_expiry" value="1">
                    <?php esc_html_e('No end date — keep this review open until I close it manually', 'dox-feedback'); ?>
                </label>

                <div class="dxf-create-result" style="margin-top: 16px; display:none;"></div>

                <p class="dxf-step-actions">
                    <button type="button" class="button dxf-prev"><?php esc_html_e('Back', 'dox-feedback'); ?></button>
                    <button type="submit" class="button button-primary dxf-create">
                        <?php esc_html_e('Create &amp; activate', 'dox-feedback'); ?>
                    </button>
                </p>
            </div>
        </form>
        <?php
    }

    // ---------------------------------------------------------------------
    // Edit / manage
    // ---------------------------------------------------------------------

    private function render_edit(int $id): void {
        $review = DXF_Review::get($id);
        if ( ! $review ) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Review not found.', 'dox-feedback') . '</p></div>';
            return;
        }

        $landing_url = DXF_Review::landing_url((string) $review['slug']);
        $is_email    = $review['mode'] === DXF_Review::MODE_EMAIL;
        $audit       = DXF_Review_Audit::list_for_review($id, 50);
        $post_ids    = DXF_Review::resolve_post_ids($review);
        $states      = DXF_Review::get_post_states($id);
        ?>
        <h1 class="wp-heading-inline">
            <?php echo esc_html($review['name'] ?: __('(unnamed review)', 'dox-feedback')); ?>
        </h1>
        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>" class="page-title-action">
            <?php esc_html_e('← Back to all reviews', 'dox-feedback'); ?>
        </a>

        <div class="dxf-review-meta" style="margin: 16px 0; display:flex; gap:24px; flex-wrap:wrap; color:#555;">
            <span><strong><?php esc_html_e('Status', 'dox-feedback'); ?>:</strong> <span class="dxf-status dxf-status-<?php echo esc_attr($review['status']); ?>"><?php echo esc_html(self::status_label((string) $review['status'])); ?></span></span>
            <span><strong><?php esc_html_e('Scope', 'dox-feedback'); ?>:</strong> <?php echo esc_html(self::scope_label((string) $review['scope_type'])); ?></span>
            <span><strong><?php esc_html_e('Mode', 'dox-feedback'); ?>:</strong> <?php echo esc_html(self::mode_label((string) $review['mode'])); ?></span>
            <?php if ( $review['expires_at'] ): ?>
                <span><strong><?php esc_html_e('Expires', 'dox-feedback'); ?>:</strong> <?php echo esc_html(date_i18n('M j, Y', strtotime($review['expires_at']))); ?></span>
            <?php endif; ?>
            <?php $last_seen = DXF_Review_Audit::last_occurred($id, 'viewed'); ?>
            <span><strong><?php esc_html_e('Client last viewed', 'dox-feedback'); ?>:</strong>
                <?php if ( $last_seen !== '' ): ?>
                    <?php echo esc_html(self::viewed_ago($last_seen)); ?>
                <?php else: ?>
                    <span style="color:#a00;"><?php esc_html_e('Not opened yet', 'dox-feedback'); ?></span>
                <?php endif; ?>
            </span>
        </div>

        <div class="dxf-review-actions" style="margin: 16px 0;">
            <?php if ( $is_email ): ?>
                <p class="description" style="margin: 0 0 10px; max-width: 720px;">
                    <?php esc_html_e('This review is email-restricted — each invitee gets their own private magic link. There is no shareable public URL. Invite reviewers below; they\'ll receive an email with their link.', 'dox-feedback'); ?>
                </p>
            <?php else: ?>
                <input type="text" readonly value="<?php echo esc_attr($landing_url); ?>" style="width: 480px;" class="dxf-share-url">
                <button type="button" class="button dxf-copy-url"><?php esc_html_e('Copy', 'dox-feedback'); ?></button>
                <a href="<?php echo esc_url($landing_url); ?>" target="_blank" class="button"><?php esc_html_e('Open', 'dox-feedback'); ?></a>
            <?php endif; ?>

            <?php if ( $review['status'] === DXF_Review::STATUS_DRAFT ): ?>
                <button type="button"
                        class="button button-primary dxf-publish"
                        data-review-id="<?php echo (int) $id; ?>">
                    <?php esc_html_e('Activate', 'dox-feedback'); ?>
                </button>
            <?php elseif ( $review['status'] === DXF_Review::STATUS_ACTIVE ): ?>
                <button type="button" class="button dxf-close" data-review-id="<?php echo (int) $id; ?>"><?php esc_html_e('Deactivate', 'dox-feedback'); ?></button>
            <?php elseif ( in_array($review['status'], [DXF_Review::STATUS_CLOSED, DXF_Review::STATUS_EXPIRED], true) ): ?>
                <button type="button"
                        class="button button-primary dxf-reopen"
                        data-review-id="<?php echo (int) $id; ?>">
                    <?php esc_html_e('Activate', 'dox-feedback'); ?>
                </button>
            <?php endif; ?>

            <button type="button" class="button button-link-delete dxf-delete" data-review-id="<?php echo (int) $id; ?>" style="margin-left:8px;"><?php esc_html_e('Delete', 'dox-feedback'); ?></button>
        </div>

        <?php
        /**
         * Editable scope & settings panel. The multi-page module hooks this
         * action to render the scope/expiry/future-inclusion editor and enqueue
         * its save handler. With no listener, scope shows as read-only meta
         * above and nothing renders here.
         *
         * @since 1.0.9
         * @param int   $id     Review ID.
         * @param array $review Review row.
         */
        do_action('dxf_review_edit_scope_panel', $id, $review);
        ?>

        <h2><?php esc_html_e('Items', 'dox-feedback'); ?> <span class="count">(<?php echo count($post_ids); ?>)</span></h2>
        <table class="wp-list-table widefat fixed striped" style="max-width: 720px;">
            <thead><tr>
                <th><?php esc_html_e('Item', 'dox-feedback'); ?></th>
                <th><?php esc_html_e('Type', 'dox-feedback'); ?></th>
                <th><?php esc_html_e('Status', 'dox-feedback'); ?></th>
                <th><?php esc_html_e('Approved by', 'dox-feedback'); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($post_ids as $pid):
                    $st = $states[$pid] ?? ['status' => 'todo', 'approved_by_email' => '', 'approved_at' => null];
                    $pt = get_post_type_object((string) get_post_type($pid));
                    $type_label = $pt && isset($pt->labels->singular_name) ? $pt->labels->singular_name : (string) get_post_type($pid);
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url(get_permalink($pid)); ?>" target="_blank"><?php echo esc_html(get_the_title($pid) ?: ('#' . $pid)); ?></a></td>
                        <td><span style="color:#666;font-size:12px;"><?php echo esc_html($type_label); ?></span></td>
                        <td><?php echo esc_html(self::page_status_label((string) $st['status'])); ?></td>
                        <td><?php
                            // The per-page row only stores the approver's email, which is
                            // blank for public-link reviewers who signed off without
                            // entering one. Fall back to the immutable approval record,
                            // which always captures a name (and email when given).
                            $approved_by = (string) $st['approved_by_email'];
                            if ( $approved_by === '' && $st['status'] === DXF_Review::PAGE_STATUS_APPROVED && class_exists('DXF_Approvals') ) {
                                $rec = DXF_Approvals::latest_for_post($pid);
                                if ( $rec ) {
                                    $approved_by = $rec['name'] !== '' ? $rec['name'] : $rec['email'];
                                }
                            }
                            echo $approved_by !== '' ? esc_html($approved_by) : '—';
                        ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        /**
         * Reviewers management panel — invite form, member directory and per-
         * member role controls. The email module hooks this action to render the
         * panel and enqueue its admin handler. With no listener, nothing renders:
         * link-mode reviews have no reviewer directory to manage.
         */
        do_action('dxf_review_edit_reviewers_panel', $id, $review);
        ?>

        <h2 style="margin-top: 32px;"><?php esc_html_e('Audit log', 'dox-feedback'); ?></h2>
        <table class="wp-list-table widefat fixed striped" style="max-width: 720px;">
            <thead><tr>
                <th><?php esc_html_e('When', 'dox-feedback'); ?></th>
                <th><?php esc_html_e('Event', 'dox-feedback'); ?></th>
                <th><?php esc_html_e('Details', 'dox-feedback'); ?></th>
            </tr></thead>
            <tbody>
                <?php if ( empty($audit) ): ?>
                    <tr><td colspan="3" style="padding:16px;color:#666;"><?php esc_html_e('Nothing logged yet.', 'dox-feedback'); ?></td></tr>
                <?php else: foreach ($audit as $row):
                    $meta = $row['meta'] ? json_decode((string) $row['meta'], true) : [];
                    ?>
                    <tr>
                        <td><?php echo esc_html(date_i18n('M j, Y H:i', strtotime((string) $row['occurred_at'] . ' UTC'))); ?></td>
                        <td><?php echo esc_html((string) $row['event']); ?></td>
                        <td><?php echo is_array($meta) ? esc_html(implode(' · ', array_map(fn($k, $v) => $k . '=' . (is_scalar($v) ? (string) $v : 'json'), array_keys($meta), $meta))) : '—'; ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    // ---------------------------------------------------------------------
    // Label helpers
    // ---------------------------------------------------------------------

    /** "2 hours ago" from a UTC mysql datetime (audit occurred_at is UTC). */
    public static function viewed_ago(string $gmt): string {
        $ts = strtotime($gmt . ' UTC');
        if ( ! $ts ) return '';
        /* translators: %s = human-readable time difference, e.g. "2 hours" */
        return sprintf(__('%s ago', 'dox-feedback'), human_time_diff($ts));
    }

    public static function scope_label(string $type): string {
        return match($type) {
            DXF_Review::SCOPE_ENTIRE   => __('Entire site',  'dox-feedback'),
            DXF_Review::SCOPE_SELECTED => __('Selected pages', 'dox-feedback'),
            default                       => __('Single page',  'dox-feedback'),
        };
    }
    public static function mode_label(string $mode): string {
        return $mode === DXF_Review::MODE_EMAIL
            ? __('Email-restricted', 'dox-feedback')
            : __('Public link',      'dox-feedback');
    }
    public static function status_label(string $status): string {
        return match($status) {
            DXF_Review::STATUS_ACTIVE  => __('Active',  'dox-feedback'),
            DXF_Review::STATUS_DRAFT   => __('Draft',   'dox-feedback'),
            DXF_Review::STATUS_CLOSED  => __('Closed',  'dox-feedback'),
            DXF_Review::STATUS_EXPIRED => __('Expired', 'dox-feedback'),
            default                       => $status,
        };
    }
    public static function page_status_label(string $status): string {
        return match($status) {
            DXF_Review::PAGE_STATUS_APPROVED  => __('Approved',  'dox-feedback'),
            DXF_Review::PAGE_STATUS_IN_REVIEW => __('In review', 'dox-feedback'),
            default                              => __('To do',    'dox-feedback'),
        };
    }
}
