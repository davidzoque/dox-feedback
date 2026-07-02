<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Feedback dashboard — a builder-agnostic inbox for every pinned comment on the
 * site, surfaced in wp-admin. This is the Tier-A review/resolve surface that
 * makes Dox Feedback useful on ANY WordPress site (not just Bricks): the agency
 * triages feedback here regardless of which builder — or no builder — produced
 * the page. Bricks/Elementor/Gutenberg users still get the deeper in-editor
 * panel; this is the universal floor.
 *
 * Read-only listing is server-rendered here; status changes reuse the existing
 * `dxf_resolve_comment` AJAX endpoint (DXF_Comments::NONCE_ACTION).
 */
final class DXF_Pins_Dashboard {

    public const MENU_SLUG = 'dxf-feedback';
    private const PER_PAGE  = 25;

    public function __construct() {
        add_action('admin_menu',            [$this, 'register_menu'], 15);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menu(): void {
        add_submenu_page(
            'dox-feedback',
            __('Feedback', 'dox-feedback'),
            __('Feedback', 'dox-feedback'),
            'edit_posts',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function enqueue_assets(string $hook): void {
        if ( strpos($hook, self::MENU_SLUG) === false ) {
            return;
        }
        wp_enqueue_style(
            'dxf-pins-dashboard',
            DXF_URL . 'assets/admin/pins-dashboard.css',
            [],
            DXF_Comments::asset_ver('assets/admin/pins-dashboard.css')
        );
        wp_enqueue_script(
            'dxf-pins-dashboard',
            DXF_URL . 'assets/admin/pins-dashboard.js',
            ['jquery'],
            DXF_Comments::asset_ver('assets/admin/pins-dashboard.js'),
            true
        );
        wp_localize_script('dxf-pins-dashboard', 'dxfPins', [
            // Matches DXF_Comments::NONCE_ACTION — the resolve endpoint reads
            // it via check_ajax_referer() as _ajax_nonce.
            'nonce'   => wp_create_nonce(DXF_Comments::NONCE_ACTION),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'i18n'    => [
                'error'    => __('Something went wrong. Please try again.', 'dox-feedback'),
                'open'     => __('Open', 'dox-feedback'),
                'resolved' => __('Resolved', 'dox-feedback'),
                'reopen'   => __('Reopen', 'dox-feedback'),
                'resolve'  => __('Mark resolved', 'dox-feedback'),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(): void {
        if ( ! current_user_can('edit_posts') ) {
            wp_die(esc_html__('You do not have permission to view feedback.', 'dox-feedback'));
        }

        // Read-only filters drive the WHERE clause; mutations are nonce'd AJAX.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $status = isset($_GET['status']) ? sanitize_key((string) wp_unslash($_GET['status'])) : '';
        $post   = isset($_GET['post'])   ? absint($_GET['post'])                               : 0;
        $paged  = isset($_GET['paged'])  ? max(1, absint($_GET['paged']))                      : 1;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ( ! in_array($status, ['open', 'in_progress', 'resolved'], true) ) {
            $status = '';
        }

        $counts = $this->status_counts($post);
        $data   = $this->query($status, $post, $paged);

        echo '<div class="wrap dxf-pins-wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Feedback', 'dox-feedback') . '</h1>';
        echo '<p class="description dxf-pins-intro">'
            . esc_html__('Every comment your clients and team have pinned, across the whole site — on any builder. Review the context, open the page, and mark items resolved as you action them.', 'dox-feedback')
            . '</p>';

        $this->render_filters($status, $post, $counts);

        if ( empty($data['rows']) ) {
            echo '<div class="dxf-pins-empty"><p>'
                . esc_html__('No feedback yet. Share a review link from the Reviews screen, or open a page and leave a comment, and it will appear here.', 'dox-feedback')
                . '</p></div>';
            echo '</div>';
            return;
        }

        echo '<div class="dxf-pins-list">';
        foreach ( $data['rows'] as $row ) {
            $this->render_card($row);
        }
        echo '</div>';

        $this->render_pagination($data['total'], $paged, $status, $post);

        echo '</div>';
    }

    private function render_filters(string $status, int $post, array $counts): void {
        $base = admin_url('admin.php?page=' . self::MENU_SLUG);
        $link = static function ( string $s ) use ( $base, $post ): string {
            $args = [];
            if ( $s !== '' )  { $args['status'] = $s; }
            if ( $post )      { $args['post']   = $post; }
            return add_query_arg($args, $base);
        };
        $tabs = [
            ''            => __('All', 'dox-feedback'),
            'open'        => __('Open', 'dox-feedback'),
            'in_progress' => __('In progress', 'dox-feedback'),
            'resolved'    => __('Resolved', 'dox-feedback'),
        ];
        echo '<ul class="subsubsub dxf-pins-filters">';
        $i = 0;
        foreach ( $tabs as $key => $label ) {
            $count = $counts[$key] ?? 0;
            $cls   = ($status === $key) ? 'current' : '';
            $sep   = $i++ ? ' | ' : '';
            echo '<li>' . esc_html($sep) . '<a href="' . esc_url($link($key)) . '" class="' . esc_attr($cls) . '">'
                . esc_html($label) . ' <span class="count">(' . esc_html((string) $count) . ')</span></a></li>';
        }
        echo '</ul>';
        if ( $post ) {
            echo '<p class="dxf-pins-postfilter">'
                . esc_html__('Filtered to one page.', 'dox-feedback')
                . ' <a href="' . esc_url(add_query_arg($status ? ['status' => $status] : [], $base)) . '">'
                . esc_html__('Show all pages', 'dox-feedback') . '</a></p>';
        }
    }

    private function render_card( array $row ): void {
        $anchor   = is_array($row['anchor']) ? $row['anchor'] : [];
        $shot     = isset($anchor['screenshot']) ? (string) $anchor['screenshot'] : '';
        $builder  = isset($anchor['builder']) && $anchor['builder'] !== '' ? (string) $anchor['builder'] : 'bricks';
        $label    = $this->anchor_label($anchor);
        $permalink = get_permalink($row['post_id']);
        $title     = $row['post_title'] !== '' ? $row['post_title'] : ('#' . $row['post_id']);
        $author    = $row['author_name'] !== '' ? $row['author_name'] : __('Reviewer', 'dox-feedback');
        $when       = $this->time_ago($row['created_at']);
        $is_resolved = ($row['status'] === 'resolved');

        echo '<div class="dxf-pin-card status-' . esc_attr($row['status']) . '" data-id="' . esc_attr((string) $row['id']) . '">';

        // Screenshot thumbnail (the captured context — works regardless of builder).
        echo '<div class="dxf-pin-shot">';
        if ( $shot ) {
            echo '<a href="' . esc_url($shot) . '" target="_blank" rel="noopener"><img src="' . esc_url($shot) . '" alt="" loading="lazy"></a>';
        } else {
            echo '<span class="dxf-pin-noshot" aria-hidden="true">💬</span>';
        }
        echo '</div>';

        echo '<div class="dxf-pin-main">';

        echo '<div class="dxf-pin-meta">';
        echo '<span class="dxf-pin-author">' . esc_html($author) . '</span>';
        echo ' <span class="dxf-pin-on">' . esc_html__('on', 'dox-feedback') . '</span> ';
        echo '<a class="dxf-pin-page" href="' . esc_url((string) $permalink) . '" target="_blank" rel="noopener">' . esc_html($title) . ' ↗</a>';
        echo ' <span class="dxf-pin-when">· ' . esc_html($when) . '</span>';
        echo '</div>';

        echo '<div class="dxf-pin-body">' . esc_html(wp_trim_words($row['body'], 60)) . '</div>';

        echo '<div class="dxf-pin-tags">';
        echo '<span class="dxf-pin-badge builder-' . esc_attr($builder) . '">' . esc_html($this->builder_label($builder)) . '</span>';
        if ( $label !== '' ) {
            echo '<span class="dxf-pin-target">' . esc_html($label) . '</span>';
        }
        if ( (int) $row['reply_count'] > 0 ) {
            /* translators: %d: number of replies. */
            echo '<span class="dxf-pin-replies">' . esc_html(sprintf(_n('%d reply', '%d replies', (int) $row['reply_count'], 'dox-feedback'), (int) $row['reply_count'])) . '</span>';
        }
        echo '</div>';

        echo '</div>'; // .dxf-pin-main

        // Status control — reuses the dxf_resolve_comment endpoint.
        echo '<div class="dxf-pin-actions">';
        echo '<span class="dxf-pin-status-label">' . esc_html($this->status_label($row['status'])) . '</span>';
        echo '<button type="button" class="button dxf-pin-toggle" data-id="' . esc_attr((string) $row['id']) . '" data-status="' . ($is_resolved ? 'open' : 'resolved') . '">'
            . ( $is_resolved ? esc_html__('Reopen', 'dox-feedback') : esc_html__('Mark resolved', 'dox-feedback') )
            . '</button>';
        echo '</div>';

        echo '</div>'; // .dxf-pin-card
    }

    private function render_pagination( int $total, int $paged, string $status, int $post ): void {
        $pages = (int) ceil($total / self::PER_PAGE);
        if ( $pages < 2 ) {
            return;
        }
        $base = admin_url('admin.php?page=' . self::MENU_SLUG);
        $args = [];
        if ( $status ) { $args['status'] = $status; }
        if ( $post )   { $args['post']   = $post; }

        echo '<div class="tablenav"><div class="tablenav-pages dxf-pins-pages">';
        echo '<span class="displaying-num">' . esc_html(sprintf(
            /* translators: %d: total number of feedback items. */
            _n('%d item', '%d items', $total, 'dox-feedback'),
            $total
        )) . '</span> ';
        for ( $p = 1; $p <= $pages; $p++ ) {
            $url = add_query_arg(array_merge($args, ['paged' => $p]), $base);
            if ( $p === $paged ) {
                echo '<span class="dxf-pins-page current">' . esc_html((string) $p) . '</span> ';
            } else {
                echo '<a class="dxf-pins-page" href="' . esc_url($url) . '">' . esc_html((string) $p) . '</a> ';
            }
        }
        echo '</div></div>';
    }

    // -------------------------------------------------------------------------
    // Data
    // -------------------------------------------------------------------------

    /** Top-level comments (parent_id IS NULL) across the whole site, paginated. */
    private function query( string $status, int $post, int $paged ): array {
        global $wpdb;
        $c = $wpdb->prefix . 'dxf_comments';
        $p = $wpdb->posts;
        $u = $wpdb->users;

        $where  = ['c.parent_id IS NULL'];
        $params = [];
        if ( $status !== '' ) {
            $where[]  = 'c.status = %s';
            $params[] = $status;
        }
        if ( $post ) {
            $where[]  = 'c.post_id = %d';
            $params[] = $post;
        }
        $where_sql = implode(' AND ', $where);

        // Total (for pagination) under the same filters.
        $count_sql = "SELECT COUNT(*) FROM {$c} c WHERE {$where_sql}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) ( $params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql) );

        $offset = ( $paged - 1 ) * self::PER_PAGE;
        $sql = "SELECT c.id, c.post_id, c.author_name, c.body, c.status, c.anchor_data, c.created_at,
                       po.post_title AS post_title,
                       us.display_name AS user_display_name,
                       (SELECT COUNT(*) FROM {$c} r WHERE r.parent_id = c.id) AS reply_count
                  FROM {$c} c
             LEFT JOIN {$p} po ON po.ID = c.post_id
             LEFT JOIN {$u} us ON us.ID = c.author_id
                 WHERE {$where_sql}
              ORDER BY c.created_at DESC
                 LIMIT %d OFFSET %d";
        $q_params = array_merge($params, [self::PER_PAGE, $offset]);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $q_params), ARRAY_A);

        $out = [];
        foreach ( (array) $rows as $r ) {
            $name = (string) $r['author_name'];
            if ( $name === '' && ! empty($r['user_display_name']) ) {
                $name = (string) $r['user_display_name'];
            }
            $out[] = [
                'id'          => (int) $r['id'],
                'post_id'     => (int) $r['post_id'],
                'post_title'  => (string) ( $r['post_title'] ?? '' ),
                'author_name' => $name,
                'body'        => (string) $r['body'],
                'status'      => (string) $r['status'],
                'anchor'      => json_decode((string) $r['anchor_data'], true),
                'created_at'  => (string) $r['created_at'],
                'reply_count' => (int) $r['reply_count'],
            ];
        }
        return ['rows' => $out, 'total' => $total];
    }

    /** Per-status counts for the filter tabs (respecting the post filter). */
    private function status_counts( int $post ): array {
        global $wpdb;
        $c = $wpdb->prefix . 'dxf_comments';
        $where  = ['parent_id IS NULL'];
        $params = [];
        if ( $post ) {
            $where[]  = 'post_id = %d';
            $params[] = $post;
        }
        $where_sql = implode(' AND ', $where);
        $sql = "SELECT status, COUNT(*) AS n FROM {$c} WHERE {$where_sql} GROUP BY status";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        $counts = ['' => 0, 'open' => 0, 'in_progress' => 0, 'resolved' => 0];
        foreach ( (array) $rows as $r ) {
            $s = (string) $r['status'];
            $n = (int) $r['n'];
            if ( isset($counts[$s]) ) { $counts[$s] = $n; }
            $counts[''] += $n;
        }
        return $counts;
    }

    // -------------------------------------------------------------------------
    // Presentation helpers
    // -------------------------------------------------------------------------

    /** Human label for the pinned element, derived from the stored anchor. */
    private function anchor_label( array $a ): string {
        $fp = '';
        if ( isset($a['strategies']['text_fp']) ) {
            $fp = trim((string) $a['strategies']['text_fp']);
        }
        if ( $fp !== '' ) {
            return '“' . mb_substr($fp, 0, 48) . '”';
        }
        if ( ! empty($a['element_id']) ) {
            return (string) $a['element_id'];
        }
        return __('Pinned location', 'dox-feedback');
    }

    private function builder_label( string $builder ): string {
        $map = [
            'bricks'    => 'Bricks',
            'elementor' => 'Elementor',
            'gutenberg' => 'Gutenberg',
            'generic'   => __('Page', 'dox-feedback'),
        ];
        return $map[$builder] ?? ucfirst($builder);
    }

    private function status_label( string $status ): string {
        $map = [
            'open'        => __('Open', 'dox-feedback'),
            'in_progress' => __('In progress', 'dox-feedback'),
            'resolved'    => __('Resolved', 'dox-feedback'),
        ];
        return $map[$status] ?? ucfirst($status);
    }

    private function time_ago( string $mysql_datetime ): string {
        $ts = strtotime($mysql_datetime . ' UTC');
        if ( ! $ts ) {
            return '';
        }
        /* translators: %s: human time difference, e.g. "2 days". */
        return sprintf(__('%s ago', 'dox-feedback'), human_time_diff($ts, time()));
    }
}
