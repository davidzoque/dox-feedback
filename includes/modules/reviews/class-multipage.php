<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Multi-page / whole-site reviews — original Dox Studio implementation built on
 * the review module's documented extension hooks.
 *
 *   - dxf_review_create            : creates selected / entire (and email) reviews
 *   - dxf_review_resolved_post_ids : expands 'entire' scope to all published pages
 *   - dxf_review_wizard_scope_*    : the Selected / Entire wizard cards + page picker
 *   - dxf_review_edit_scope_panel  : a read-only scope summary on the edit screen
 *   - dxf_review_render_landing    : the reviewer's multi-page checklist
 *
 * phpcs:disable WordPress.Security.NonceVerification.Missing
 */
final class DXF_Multipage {

    public function __construct() {
        add_filter('dxf_review_create',            [$this, 'handle_create'], 10, 2);
        add_filter('dxf_review_resolved_post_ids', [$this, 'resolve_entire'], 10, 2);

        add_action('dxf_review_wizard_scope_cards',  [$this, 'wizard_scope_cards'], 10, 4);
        add_action('dxf_review_wizard_scope_extras', [$this, 'wizard_scope_extras'], 10, 4);
        add_action('dxf_review_edit_scope_panel',    [$this, 'edit_scope_panel'], 10, 2);

        add_action('dxf_review_render_landing', [$this, 'render_landing'], 10, 3);
    }

    // -------------------------------------------------------------------------
    // Creation
    // -------------------------------------------------------------------------

    /**
     * @param array|\WP_Error|null $handled
     * @return array|\WP_Error|null  Created review row, a WP_Error, or $handled to decline.
     */
    public function handle_create($handled, array $args) {
        if ( $handled !== null ) {
            return $handled; // already handled upstream
        }

        $scope = isset($args['scope_type']) ? sanitize_key((string) $args['scope_type']) : DXF_Review::SCOPE_SINGLE;
        $mode  = isset($args['mode']) ? sanitize_key((string) $args['mode']) : DXF_Review::MODE_LINK;

        // The free single-page public-link path owns this exact case.
        if ( $scope === DXF_Review::SCOPE_SINGLE && $mode === DXF_Review::MODE_LINK ) {
            return $handled;
        }
        if ( ! in_array($scope, [DXF_Review::SCOPE_SINGLE, DXF_Review::SCOPE_SELECTED, DXF_Review::SCOPE_ENTIRE], true) ) {
            return new \WP_Error('bad_scope', __('Unknown review scope.', 'dox-feedback'));
        }
        if ( ! in_array($mode, [DXF_Review::MODE_LINK, DXF_Review::MODE_EMAIL], true) ) {
            return new \WP_Error('bad_mode', __('Unknown review mode.', 'dox-feedback'));
        }

        global $wpdb;

        $name           = isset($args['name']) ? mb_substr(sanitize_text_field((string) $args['name']), 0, 200) : '';
        $no_expiry      = ! empty($args['no_expiry']);
        $expires_in     = isset($args['expires_at']) ? (string) $args['expires_at'] : '';
        $include_future = ! empty($args['include_future']);
        $created_by     = isset($args['created_by']) ? (int) $args['created_by'] : get_current_user_id();

        $post_ids = isset($args['post_ids']) ? array_map('intval', (array) $args['post_ids']) : [];
        $post_ids = array_values(array_unique(array_filter($post_ids, static fn($id) => $id > 0)));

        if ( $scope === DXF_Review::SCOPE_SINGLE && count($post_ids) !== 1 ) {
            return new \WP_Error('bad_scope_posts', __('Pick exactly one page for a single-page review.', 'dox-feedback'));
        }
        if ( $scope === DXF_Review::SCOPE_SELECTED && count($post_ids) < 1 ) {
            return new \WP_Error('bad_scope_posts', __('Pick at least one page for this review.', 'dox-feedback'));
        }
        if ( $scope === DXF_Review::SCOPE_ENTIRE ) {
            $post_ids = []; // resolved dynamically (or snapshot, below)
        }

        // Email mode requires at least one valid invitee.
        $emails       = [];
        $default_role = DXF_Review_Member::ROLE_REVIEWER;
        if ( $mode === DXF_Review::MODE_EMAIL ) {
            $emails = self::read_invitee_emails($args);
            if ( empty($emails) ) {
                return new \WP_Error('no_invitees', __('Add at least one reviewer email address.', 'dox-feedback'));
            }
            $default_role = DXF_Review_Member::normalize_role(self::read_default_role($args));
        }

        $password      = isset($args['password']) ? (string) $args['password'] : '';
        $row = [
            'slug'           => bin2hex(random_bytes(24)),
            'name'           => $name,
            'status'         => DXF_Review::STATUS_DRAFT,
            'scope_type'     => $scope,
            'include_future' => $include_future ? 1 : 0,
            'mode'           => $mode,
            'password_hash'  => $password !== '' ? wp_hash_password($password) : null,
            'expires_at'     => self::resolve_expiry($no_expiry, $expires_in),
            'created_by'     => $created_by,
        ];
        $ok = $wpdb->insert(DXF_Review::reviews_table(), $row, ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d']);
        if ( $ok === false ) {
            return new \WP_Error('db_insert_failed', __('Could not create the review.', 'dox-feedback'));
        }
        $review_id = (int) $wpdb->insert_id;

        // Persist the post set.
        if ( $scope === DXF_Review::SCOPE_SELECTED || $scope === DXF_Review::SCOPE_SINGLE ) {
            DXF_Review::set_posts($review_id, $post_ids);
        } elseif ( $scope === DXF_Review::SCOPE_ENTIRE && ! $include_future ) {
            // Snapshot the current published set so future pages are NOT auto-added.
            DXF_Review::set_posts($review_id, self::all_published_ids());
        }
        // entire + include_future → persist nothing; resolved live by resolve_entire().

        if ( $mode === DXF_Review::MODE_EMAIL ) {
            DXF_Review_Member::seed_from_emails($review_id, $emails, $default_role);
        }

        if ( class_exists('DXF_Review_Audit') ) {
            DXF_Review_Audit::log($review_id, null, 'created', ['scope' => $scope, 'mode' => $mode]);
        }

        $review = DXF_Review::get($review_id) ?? [];
        if ( ! empty($review) ) {
            // Lets the email feature send magic-link invites for email reviews.
            do_action('dxf_review_after_create', $review, $args);
        }
        return $review;
    }

    // -------------------------------------------------------------------------
    // Scope resolution
    // -------------------------------------------------------------------------

    /**
     * @param array $ids   Persisted post ids (empty for live 'entire' scope).
     * @return array<int,int>
     */
    public function resolve_entire($ids, $review) {
        if ( ( $review['scope_type'] ?? '' ) !== DXF_Review::SCOPE_ENTIRE ) {
            return $ids;
        }
        if ( ! empty($ids) ) {
            return $ids; // snapshot (include_future was off)
        }
        return self::all_published_ids();
    }

    public static function all_published_ids(): array {
        $types = array_keys(DXF_Review::reviewable_post_types());
        if ( empty($types) ) {
            return [];
        }
        $ids = get_posts([
            'post_type'        => $types,
            'post_status'      => 'publish',
            'numberposts'      => -1,
            'fields'           => 'ids',
            'orderby'          => 'title',
            'order'            => 'ASC',
            'suppress_filters' => false,
        ]);
        return array_map('intval', (array) $ids);
    }

    // -------------------------------------------------------------------------
    // Admin wizard
    // -------------------------------------------------------------------------

    public function wizard_scope_cards($picker, $total_count, $truncated_any, $picker_cap): void {
        ?>
        <label class="dxf-scope-card">
            <input type="radio" name="scope_type" value="<?php echo esc_attr(DXF_Review::SCOPE_SELECTED); ?>">
            <div>
                <strong><?php esc_html_e('Selected pages', 'dox-feedback'); ?></strong>
                <span class="description"><?php esc_html_e('Pick exactly the pages this review should cover.', 'dox-feedback'); ?></span>
            </div>
        </label>
        <label class="dxf-scope-card">
            <input type="radio" name="scope_type" value="<?php echo esc_attr(DXF_Review::SCOPE_ENTIRE); ?>">
            <div>
                <strong><?php esc_html_e('Entire website', 'dox-feedback'); ?></strong>
                <span class="description">
                    <?php
                    /* translators: %d = number of published items */
                    printf(esc_html__('Every published page on the site — currently %d item(s) — behind one link.', 'dox-feedback'), (int) $total_count);
                    ?>
                </span>
            </div>
        </label>
        <?php
    }

    public function wizard_scope_extras($picker, $total_count, $truncated_any, $picker_cap): void {
        ?>
        <div class="dxf-page-picker" style="display:none;margin-top:14px;">
            <input type="text" class="dxf-picker-filter regular-text" style="width:100%;max-width:420px;margin-bottom:8px;"
                   placeholder="<?php esc_attr_e('Filter pages…', 'dox-feedback'); ?>">
            <div class="dxf-page-list" style="max-height:320px;overflow:auto;border:1px solid #dcdcde;border-radius:6px;padding:10px;">
                <?php foreach ( (array) $picker as $slug => $group ) :
                    $items = isset($group['items']) ? (array) $group['items'] : [];
                    $count = count($items);
                    ?>
                    <details class="dxf-picker-group" data-pt="<?php echo esc_attr((string) $slug); ?>" data-count="<?php echo (int) $count; ?>" open style="margin:0 0 6px;">
                        <summary style="cursor:pointer;font-weight:600;">
                            <?php echo esc_html((string) ($group['label'] ?? $slug)); ?>
                            (<?php echo (int) $count . ( ! empty($group['truncated']) ? '+' : '' ); ?>)
                        </summary>
                        <?php foreach ( $items as $it ) : ?>
                            <label class="dxf-picker-item" style="display:block;padding:3px 0 3px 14px;">
                                <input type="checkbox" name="post_ids[]" value="<?php echo (int) ($it['id'] ?? 0); ?>">
                                <?php echo esc_html((string) ($it['title'] ?? ('#' . (int) ($it['id'] ?? 0)))); ?>
                            </label>
                        <?php endforeach; ?>
                    </details>
                <?php endforeach; ?>
            </div>
            <?php if ( $truncated_any ) : ?>
                <p class="description">
                    <?php
                    /* translators: %d = per-post-type cap */
                    printf(esc_html__('Showing up to %d items per type.', 'dox-feedback'), (int) $picker_cap);
                    ?>
                </p>
            <?php endif; ?>
        </div>

        <label class="dxf-include-future" style="display:none;margin-top:14px;">
            <input type="checkbox" name="include_future" value="1" checked>
            <?php esc_html_e('Include pages published after this review is created', 'dox-feedback'); ?>
        </label>
        <?php
    }

    public function edit_scope_panel($id, $review): void {
        $ids = DXF_Review::resolve_post_ids((array) $review);
        ?>
        <p class="description" style="margin:10px 0;">
            <?php
            /* translators: %d = number of pages in the review */
            printf(esc_html__('This review currently covers %d page(s).', 'dox-feedback'), count($ids));
            ?>
        </p>
        <?php
    }

    // -------------------------------------------------------------------------
    // Reviewer landing — multi-page checklist
    // -------------------------------------------------------------------------

    /**
     * @param array      $review
     * @param array<int> $post_ids
     * @param array|null $member
     */
    public function render_landing($review, $post_ids, $member): void {
        $slug   = (string) $review['slug'];
        $states = DXF_Review::get_post_states((int) $review['id']);
        $title  = ( (string) ($review['name'] ?? '') ) !== '' ? (string) $review['name'] : __('Review', 'dox-feedback');

        $rows     = [];
        $approved = 0;
        foreach ( (array) $post_ids as $pid ) {
            $pid = (int) $pid;
            if ( $pid <= 0 || get_post_status($pid) === false ) {
                continue;
            }
            $status = (string) ( $states[$pid]['status'] ?? DXF_Review::PAGE_STATUS_TODO );
            if ( $status === DXF_Review::PAGE_STATUS_APPROVED ) {
                $approved++;
            }
            $rows[] = [
                'title'  => get_the_title($pid) ?: ( '#' . $pid ),
                'url'    => DXF_Review::item_url($slug, $pid),
                'status' => $status,
            ];
        }
        $total = count($rows);

        nocache_headers();
        status_header(200);
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html($title); ?></title>
    <?php wp_head(); ?>
    <style>
        body.dxf-landing{background:#f4f5f7;margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1f2329;}
        .dxf-l-wrap{max-width:680px;margin:0 auto;padding:40px 20px 64px;}
        .dxf-l-head{margin:0 0 4px;font-size:26px;font-weight:700;}
        .dxf-l-sub{margin:0 0 20px;color:#646970;font-size:14px;}
        .dxf-l-bar{height:8px;border-radius:6px;background:#e3e6ea;overflow:hidden;margin:0 0 24px;}
        .dxf-l-bar span{display:block;height:100%;background:#ff8d27;}
        .dxf-l-list{list-style:none;margin:0;padding:0;}
        .dxf-l-item{display:flex;align-items:center;justify-content:space-between;gap:12px;background:#fff;border:1px solid #e3e6ea;border-radius:10px;padding:14px 16px;margin:0 0 10px;text-decoration:none;color:inherit;box-shadow:0 1px 2px rgba(0,0,0,.03);}
        .dxf-l-item:hover{border-color:#ff8d27;}
        .dxf-l-title{font-weight:600;font-size:15px;}
        .dxf-l-badge{flex:none;font-size:12px;font-weight:600;padding:3px 10px;border-radius:999px;background:#eef0f2;color:#50575e;}
        .dxf-l-badge.is-approved{background:#e6f6ec;color:#1a7f37;}
        .dxf-l-badge.is-in_review{background:#fff3e0;color:#a15c00;}
        .dxf-l-foot{margin-top:22px;color:#8a8f98;font-size:12px;}
    </style>
</head>
<body class="dxf-landing">
    <div class="dxf-l-wrap">
        <h1 class="dxf-l-head"><?php echo esc_html($title); ?></h1>
        <p class="dxf-l-sub">
            <?php
            /* translators: 1: approved count, 2: total pages */
            printf(esc_html__('%1$d of %2$d pages approved', 'dox-feedback'), (int) $approved, (int) $total);
            if ( is_array($member) && ( (string) ($member['name'] ?? '') !== '' || (string) ($member['email'] ?? '') !== '' ) ) {
                echo ' · ' . esc_html(sprintf(
                    /* translators: %s = reviewer name or email */
                    __('signed in as %s', 'dox-feedback'),
                    (string) ($member['name'] ?? '') !== '' ? (string) $member['name'] : (string) $member['email']
                ));
            }
            ?>
        </p>
        <div class="dxf-l-bar"><span style="width:<?php echo (int) ( $total > 0 ? round($approved / $total * 100) : 0 ); ?>%;"></span></div>
        <ul class="dxf-l-list">
            <?php foreach ( $rows as $row ) : ?>
                <li>
                    <a class="dxf-l-item" href="<?php echo esc_url($row['url']); ?>">
                        <span class="dxf-l-title"><?php echo esc_html((string) $row['title']); ?></span>
                        <span class="dxf-l-badge is-<?php echo esc_attr($row['status']); ?>"><?php echo esc_html(self::status_label($row['status'])); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="dxf-l-foot"><?php esc_html_e('Click a page to open it and leave feedback.', 'dox-feedback'); ?></p>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
        <?php
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function status_label(string $status): string {
        switch ( $status ) {
            case DXF_Review::PAGE_STATUS_APPROVED:  return __('Approved', 'dox-feedback');
            case DXF_Review::PAGE_STATUS_IN_REVIEW: return __('In review', 'dox-feedback');
            default:                                return __('To do', 'dox-feedback');
        }
    }

    private static function resolve_expiry(bool $no_expiry, string $expires_in): ?string {
        if ( $no_expiry ) {
            return null;
        }
        if ( $expires_in === '' ) {
            return gmdate('Y-m-d H:i:s', time() + DXF_Review::DEFAULT_EXPIRY_DAYS * DAY_IN_SECONDS);
        }
        return $expires_in;
    }

    /** @return string[] */
    private static function read_invitee_emails(array $args): array {
        $raw = '';
        if ( isset($args['emails']) ) {
            $raw = (string) $args['emails'];
        } elseif ( isset($_POST['emails']) ) { // nonce + capability already checked by DXF_Reviews::ajax_create
            $raw = (string) wp_unslash($_POST['emails']);
        }
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $out   = [];
        foreach ( $parts as $part ) {
            $email = sanitize_email(trim((string) $part));
            if ( $email !== '' && is_email($email) && ! in_array($email, $out, true) ) {
                $out[] = $email;
            }
        }
        return $out;
    }

    private static function read_default_role(array $args): string {
        if ( isset($args['default_role']) ) {
            return (string) $args['default_role'];
        }
        if ( isset($_POST['default_role']) ) { // see note in read_invitee_emails()
            return sanitize_key((string) wp_unslash($_POST['default_role']));
        }
        return DXF_Review_Member::ROLE_REVIEWER;
    }
}
