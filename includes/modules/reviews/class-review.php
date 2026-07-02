<?php
/**
 * Dox Feedback Review (project) — data class + repository for the v0.16 Review object.
 *
 * A Review is a parent object that bundles one or more pages under a single
 * shareable URL. Replaces the per-page-only `dxf_review_tokens` model for
 * new shares (the old table stays for back-compat).
 *
 * Modes:
 *   - 'link'  → anyone with the slug can comment as any name/email
 *   - 'email' → only invited members (with magic-link activation) can comment
 *
 * Scopes:
 *   - 'single'   → exactly one post (free-tier maximum)
 *   - 'selected' → admin-chosen subset (pro)
 *   - 'entire'   → all published pages, optionally including future ones (pro)
 *
 * @since 0.16.0
 */

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

// Data layer for the dxf_reviews / dxf_review_posts custom tables.
// All queries either insert/update/delete rows we own or read state that's
// freshness-sensitive (status, expiry, post set). Object caching is not
// applied because writes happen in the same request flows that read.
// Table names are composed from $wpdb->prefix + class constant; phpcs
// cannot statically trace that through $wpdb->prepare() in multi-line
// statements, hence the PreparedSQL.NotPrepared disable.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

final class DXF_Review {

    public const STATUS_DRAFT   = 'draft';
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_CLOSED  = 'closed';
    public const STATUS_EXPIRED = 'expired';

    public const SCOPE_SINGLE   = 'single';
    public const SCOPE_SELECTED = 'selected';
    public const SCOPE_ENTIRE   = 'entire';

    public const MODE_LINK  = 'link';
    public const MODE_EMAIL = 'email';

    public const PAGE_STATUS_TODO      = 'todo';
    public const PAGE_STATUS_IN_REVIEW = 'in_review';
    public const PAGE_STATUS_APPROVED  = 'approved';

    public const DEFAULT_EXPIRY_DAYS = 30;

    // ---------------------------------------------------------------------
    // Table helpers
    // ---------------------------------------------------------------------

    public static function reviews_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'dxf_reviews';
    }

    public static function posts_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'dxf_review_posts';
    }

    // ---------------------------------------------------------------------
    // CRUD
    // ---------------------------------------------------------------------

    /**
     * Create a new Review. Returns the inserted row as an array, or WP_Error.
     *
     * @param array{
     *   name?:string, scope_type?:string, include_future?:bool, mode?:string,
     *   password?:?string, expires_at?:?string, post_ids?:int[], created_by?:int
     * } $args
     */
    public static function create(array $args): array|\WP_Error {
        // Multi-page (selected / entire-site) and email-restricted reviews are
        // created by the multi-page and email modules, which handle this filter
        // and return the created review row (or a WP_Error). When no module
        // claims it, we fall through to the single-page public-link path below.
        $handled = apply_filters('dxf_review_create', null, $args);
        if ( is_array($handled) || is_wp_error($handled) ) {
            return $handled;
        }
        return self::create_single_link_review($args);
    }

    /**
     * Create a single-page, public-link review. Scope is always 'single' and
     * mode always 'link'; exactly one page must be supplied. Selected / entire
     * scope and email mode are handled by the multi-page and email modules,
     * which claim the dxf_review_create filter before this fallback runs.
     *
     * @param array{name?:string,password?:?string,no_expiry?:bool,expires_at?:?string,post_ids?:int[],created_by?:int} $args
     */
    private static function create_single_link_review(array $args): array|\WP_Error {
        global $wpdb;

        // Reaching this path with a non-default scope/mode means no module
        // claimed the dxf_review_create filter for it — an unsupported request.
        $scope_type = isset($args['scope_type']) ? (string) $args['scope_type'] : self::SCOPE_SINGLE;
        $mode       = isset($args['mode'])       ? (string) $args['mode']       : self::MODE_LINK;
        if ( $scope_type !== self::SCOPE_SINGLE || $mode !== self::MODE_LINK ) {
            return new \WP_Error('scope_unavailable', __('This review type could not be created.', 'dox-feedback'));
        }

        $name       = isset($args['name'])     ? sanitize_text_field((string) $args['name']) : '';
        $password   = isset($args['password']) ? (string) $args['password']   : '';
        $no_expiry  = ! empty($args['no_expiry']);
        $expires_at = isset($args['expires_at']) ? (string) $args['expires_at'] : '';
        $post_ids   = isset($args['post_ids']) && is_array($args['post_ids'])
            ? array_values(array_unique(array_map('intval', $args['post_ids'])))
            : [];
        $created_by = isset($args['created_by']) ? (int) $args['created_by'] : get_current_user_id();

        if ( count($post_ids) !== 1 ) {
            return new \WP_Error('bad_scope_posts', __('Single-page reviews must include exactly one page.', 'dox-feedback'));
        }

        // Slug = 24 bytes random_bytes → 48 hex chars (192-bit entropy).
        $slug = bin2hex(random_bytes(24));

        // Expiry resolution:
        //  - no_expiry flag → NULL (review stays open until manually closed)
        //  - empty input    → default 30 days from now
        //  - otherwise      → use as-given
        if ( $no_expiry ) {
            $expires_at = null;
        } elseif ( $expires_at === '' ) {
            $expires_at = gmdate('Y-m-d H:i:s', time() + (self::DEFAULT_EXPIRY_DAYS * DAY_IN_SECONDS));
        }

        $password_hash = $password !== '' ? wp_hash_password($password) : null;

        $row = [
            'slug'           => $slug,
            'name'           => $name,
            'status'         => self::STATUS_DRAFT,
            'scope_type'     => self::SCOPE_SINGLE,
            'include_future' => 0,
            'mode'           => self::MODE_LINK,
            'password_hash'  => $password_hash,
            'expires_at'     => $expires_at,
            'created_by'     => $created_by,
        ];

        $ok = $wpdb->insert(self::reviews_table(), $row, ['%s','%s','%s','%s','%d','%s','%s','%s','%d']);
        if ( $ok === false ) {
            return new \WP_Error('db_insert_failed', __('Could not create review.', 'dox-feedback'));
        }

        $review_id = (int) $wpdb->insert_id;
        self::set_posts($review_id, $post_ids);
        DXF_Review_Audit::log($review_id, null, 'created', [ 'scope' => self::SCOPE_SINGLE, 'mode' => self::MODE_LINK ]);

        $review = self::get($review_id) ?? [];

        // Generic post-create extension point — e.g. the email module uses it to
        // send magic-link invites for the email-restricted reviews it creates.
        if ( ! empty($review) ) {
            do_action('dxf_review_after_create', $review, $args);
        }

        return $review;
    }

    public static function get(int $id): ?array {
        global $wpdb;
        // Custom-table read. Table name from $wpdb->prefix + class constant.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", self::reviews_table(), $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_by_slug(string $slug): ?array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM %i WHERE slug = %s LIMIT 1", self::reviews_table(), $slug ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function update(int $id, array $changes): bool {
        global $wpdb;
        $allowed = ['name','status','scope_type','include_future','mode','password_hash','expires_at','closed_at'];
        $update  = [];
        foreach ($changes as $k => $v) {
            if ( in_array($k, $allowed, true) ) {
                $update[$k] = $v;
            }
        }
        if ( empty($update) ) return false;
        $ok = $wpdb->update(self::reviews_table(), $update, ['id' => $id]);
        return $ok !== false;
    }

    public static function delete(int $id): bool {
        global $wpdb;
        // Purge cached copies of this review's pages BEFORE the rows are gone —
        // resolve_post_ids() needs them to know which pages to clear.
        do_action('dxf_review_cache_dirty', $id);
        // Cascade — no FKs, do it in app code.
        $wpdb->delete(self::posts_table(),                       ['review_id' => $id]);
        // Member rows belong to the email feature. If its directory class isn't
        // loaded, skip the cleanup — any leftover rows are harmless orphans
        // pointing at a deleted review.
        if ( class_exists('DXF_Review_Member') ) {
            $wpdb->delete(DXF_Review_Member::table(),         ['review_id' => $id]);
        }
        $wpdb->delete(DXF_Review_Audit::table(),              ['review_id' => $id]);
        $ok = $wpdb->delete(self::reviews_table(), ['id' => $id]);
        return $ok !== false;
    }

    // ---------------------------------------------------------------------
    // Listing
    // ---------------------------------------------------------------------

    /**
     * @param array{status?:string,created_by?:int,per_page?:int,offset?:int} $args
     */
    public static function find(array $args = []): array {
        global $wpdb;
        $where = ['1=1'];
        $params = [];
        if ( ! empty($args['status']) ) {
            $where[] = 'status = %s';
            $params[] = (string) $args['status'];
        }
        if ( ! empty($args['created_by']) ) {
            $where[] = 'created_by = %d';
            $params[] = (int) $args['created_by'];
        }
        self::apply_exclude_name_prefixes($where, $params);
        $per_page = isset($args['per_page']) ? max(1, (int) $args['per_page']) : 50;
        $offset   = isset($args['offset'])   ? max(0, (int) $args['offset'])   : 0;

        $sql = "SELECT * FROM %i"
             . " WHERE " . implode(' AND ', $where)
             . " ORDER BY created_at DESC LIMIT %d OFFSET %d";
        array_unshift($params, self::reviews_table());
        $params[] = $per_page;
        $params[] = $offset;

        // $where is built from a hardcoded allow-list of conditions, each with a
        // placeholder. Table name from $wpdb->prefix + class constant.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: [];
    }

    public static function count(array $args = []): int {
        global $wpdb;
        $table  = self::reviews_table();
        $where  = ['1=1'];
        $params = [];
        if ( ! empty($args['status']) ) {
            $where[]  = 'status = %s';
            $params[] = (string) $args['status'];
        }
        if ( ! empty($args['created_by']) ) {
            $where[]  = 'created_by = %d';
            $params[] = (int) $args['created_by'];
        }
        self::apply_exclude_name_prefixes($where, $params);

        // When there are bound params, build the WHERE and run it through
        // prepare(). When there are none, the WHERE reduces to the constant
        // `1=1`, so the statement is a fixed string whose only interpolation is
        // the $wpdb->prefix-derived table name — the pattern WP sanctions for
        // identifiers (prepare() cannot bind a table name). Either way the
        // query is never executed with an unprepared user value.
        if ( $params ) {
            $sql = "SELECT COUNT(*) FROM %i WHERE " . implode(' AND ', $where);
            array_unshift($params, $table);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
            return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
        }
        // Constant query — table name bound via the %i identifier placeholder.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $table ) );
    }

    /**
     * Append "name NOT LIKE …" clauses to a find/count WHERE list, driven by
     * the `dxf_review_exclude_name_prefixes` filter. Used by site-specific
     * extensions (e.g. the demo mu-plugin) to keep their generated Reviews
     * out of the admin list, status filters, and counts — without having to
     * patch the core query.
     *
     * Filter signature:
     *   add_filter('dxf_review_exclude_name_prefixes', function (array $prefixes): array {
     *       $prefixes[] = 'DEMO – ';
     *       return $prefixes;
     *   });
     *
     * Mutates the supplied $where/$params arrays in place.
     *
     * @param array<int,string> $where
     * @param array<int,mixed>  $params
     */
    private static function apply_exclude_name_prefixes(array &$where, array &$params): void {
        global $wpdb;
        $prefixes = apply_filters('dxf_review_exclude_name_prefixes', []);
        if ( ! is_array($prefixes) || empty($prefixes) ) return;
        foreach ($prefixes as $prefix) {
            if ( ! is_string($prefix) || $prefix === '' ) continue;
            $where[]  = 'name NOT LIKE %s';
            $params[] = $wpdb->esc_like($prefix) . '%';
        }
    }

    // ---------------------------------------------------------------------
    // Page set
    // ---------------------------------------------------------------------

    /**
     * Replace the post set for a review. Preserves per-page state where
     * possible (existing rows for kept post_ids are not touched).
     *
     * @param int[] $post_ids
     */
    public static function set_posts(int $review_id, array $post_ids): void {
        global $wpdb;
        $post_ids = array_values(array_unique(array_map('intval', $post_ids)));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $existing = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM %i WHERE review_id = %d",
            self::posts_table(), $review_id
        ) ) ?: [];
        $existing = array_map('intval', $existing);

        $to_add    = array_diff($post_ids, $existing);
        $to_remove = array_diff($existing, $post_ids);

        foreach ($to_add as $i => $pid) {
            $wpdb->insert(self::posts_table(), [
                'review_id'  => $review_id,
                'post_id'    => $pid,
                'sort_order' => $i,
                'status'     => self::PAGE_STATUS_TODO,
            ], ['%d','%d','%d','%s']);
        }
        if ( ! empty($to_remove) ) {
            // Fully parameterised: one %d placeholder per id, all bound through
            // prepare(). Table name from $wpdb->prefix + class constant (an
            // identifier, which prepare() cannot bind).
            $to_remove    = array_values(array_map('intval', $to_remove));
            $placeholders = implode(',', array_fill(0, count($to_remove), '%d'));
            // ReplacementsWrongNumber: the IN() list is built dynamically, so the
            // static placeholder count can't match the array_merge() arg count —
            // a known false positive for dynamic IN clauses. All values ARE bound.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM %i WHERE review_id = %d AND post_id IN (" . $placeholders . ")",
                array_merge([ self::posts_table(), $review_id ], $to_remove)
            ) );
        }
    }

    /**
     * Get the resolved list of post IDs in this review.
     *
     * Returns the pages persisted in the posts table (single/selected scope).
     * 'entire'-scope reviews persist no rows — their dynamic, all-published page
     * list is a Pro feature resolved via the dxf_review_resolved_post_ids
     * filter. With Pro absent the list is empty, so an existing 'entire' review
     * degrades to read-only (no pages) rather than 404ing — data is preserved.
     *
     * @return int[]
     */
    public static function resolve_post_ids(array $review): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM %i WHERE review_id = %d ORDER BY sort_order ASC, id ASC",
            self::posts_table(), (int) $review['id']
        ) );
        $ids = array_map('intval', $ids ?: []);

        return array_map('intval', (array) apply_filters('dxf_review_resolved_post_ids', $ids, $review));
    }

    /**
     * Which post types can be included in a review.
     *
     * Returns post type objects keyed by slug. Default set:
     *   - All `public => true` post types except `attachment` (media is not
     *     a reviewable surface — comments anchor to page DOM positions)
     *
     * Bricks templates (`bricks_template`) are intentionally NOT in the
     * default set: they're registered with `public => false` so they have
     * no standalone permalink — `get_permalink()` falls back to the home
     * URL, which is what was happening in the wild when users picked one
     * from the create-review picker. Templates are page *parts*, not
     * reviewable standalones; review the pages that use them instead.
     *
     * Customers with a CPT that's intentionally non-public (intranet,
     * members-only) but DOES have a renderable preview URL can opt it in
     * per-site via the `dxf_reviewable_post_types` filter — that's the
     * documented escape hatch rather than this helper guessing.
     *
     * @return array<string,\WP_Post_Type>
     */
    public static function reviewable_post_types(): array {
        $types = get_post_types(['public' => true], 'objects');
        unset($types['attachment']);
        // Belt-and-braces: some Bricks builds (and forks that re-register
        // templates) flag bricks_template as public. They still have no
        // standalone permalink, so explicitly drop them regardless.
        unset($types['bricks_template']);
        return apply_filters('dxf_reviewable_post_types', $types);
    }

    /**
     * Build a post-type-grouped catalogue of reviewable items for a page picker
     * (the New-review wizard and the Pro edit-scope panel both render it). Each
     * group is ['label'=>string, 'items'=>[['id'=>int,'title'=>string], …],
     * 'truncated'=>bool]. Each type is capped to keep the picker tractable on
     * large sites; truncation is flagged so the UI can say so.
     *
     * @return array{picker:array<string,array<string,mixed>>,total_count:int,truncated_any:bool,cap:int}
     */
    public static function build_picker_catalogue(int $cap = 500): array {
        $types         = self::reviewable_post_types();
        $picker        = [];
        $total_count   = 0;
        $truncated_any = false;
        foreach ($types as $slug => $pt) {
            // Include unpublished statuses too — reviewers can sign off on a
            // draft before it goes live (read-only via their private link).
            $ids = get_posts([
                'post_type'   => $slug,
                'post_status' => class_exists('DXF_Review_Mode')
                    ? DXF_Review_Mode::reviewable_post_statuses()
                    : ['publish'],
                'numberposts' => $cap + 1,
                'orderby'     => 'title',
                'order'       => 'ASC',
                'fields'      => 'ids',
            ]);
            if ( empty($ids) ) {
                continue;
            }
            $truncated = count($ids) > $cap;
            if ( $truncated ) {
                $ids           = array_slice($ids, 0, $cap);
                $truncated_any = true;
            }
            $items = [];
            foreach ($ids as $pid) {
                $title  = get_the_title((int) $pid) ?: ('#' . $pid);
                $status = (string) get_post_status((int) $pid);
                if ( $status !== 'publish' ) {
                    $st_obj = get_post_status_object($status);
                    $title .= ' (' . ( $st_obj && ! empty($st_obj->label) ? $st_obj->label : ucfirst($status) ) . ')';
                }
                $items[] = [ 'id' => (int) $pid, 'title' => $title ];
            }
            $picker[$slug] = [
                'label'     => $pt->labels->name ?? ucfirst($slug),
                'items'     => $items,
                'truncated' => $truncated,
            ];
            $total_count += count($items);
        }
        return [
            'picker'        => $picker,
            'total_count'   => $total_count,
            'truncated_any' => $truncated_any,
            'cap'           => $cap,
        ];
    }

    /**
     * Get per-page status for a review. Returns map post_id => ['status'=>..., 'approved_by_email'=>..., 'approved_at'=>...]
     */
    public static function get_post_states(int $review_id): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, status, approved_by_email, approved_at, reviewed_at, reviewed_by FROM %i WHERE review_id = %d",
            self::posts_table(), $review_id
        ), ARRAY_A ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['post_id']] = [
                'status'            => (string) $r['status'],
                'approved_by_email' => (string) $r['approved_by_email'],
                'approved_at'       => $r['approved_at'],
                // Per-page "Reviewed" marker (reviewer finished their pass —
                // distinct from client approval). Null until they mark it.
                'reviewed_at'       => $r['reviewed_at'] ?? null,
                'reviewed_by'       => (string) ($r['reviewed_by'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Mark (or clear) a page's "Reviewed" state — the reviewer has finished
     * their feedback pass on this page. Upserts the join row (entire-scope
     * reviews may not have one yet). $reviewed=false clears it (undo, or the
     * auto-revert when new feedback arrives). Never touches approval state.
     */
    public static function set_post_reviewed(int $review_id, int $post_id, bool $reviewed, string $reviewed_by = ''): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM %i WHERE review_id = %d AND post_id = %d LIMIT 1",
            self::posts_table(), $review_id, $post_id
        ) );

        $data = [
            'reviewed_at' => $reviewed ? current_time('mysql', true) : null,
            'reviewed_by' => $reviewed ? mb_substr($reviewed_by, 0, 100) : '',
        ];

        if ( $existing_id ) {
            return $wpdb->update(self::posts_table(), $data, ['id' => (int) $existing_id]) !== false;
        }
        $data['review_id']  = $review_id;
        $data['post_id']    = $post_id;
        $data['sort_order'] = 0;
        $data['status']     = self::PAGE_STATUS_IN_REVIEW;
        return $wpdb->insert(self::posts_table(), $data) !== false;
    }

    /**
     * Clear the "Reviewed" marker on every review that contains this post —
     * called when the reviewer leaves new feedback so the dashboard badge stays
     * honest ("Reviewed" must mean "no pending new feedback"). Cheap no-op when
     * nothing was marked.
     */
    public static function clear_reviewed_for_post(int $post_id): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( $wpdb->prepare(
            "UPDATE %i SET reviewed_at = NULL, reviewed_by = '' WHERE post_id = %d AND reviewed_at IS NOT NULL",
            self::posts_table(), $post_id
        ) );
    }

    public static function set_post_status(int $review_id, int $post_id, string $status, string $approved_by_email = ''): bool {
        global $wpdb;
        if ( ! in_array($status, [self::PAGE_STATUS_TODO, self::PAGE_STATUS_IN_REVIEW, self::PAGE_STATUS_APPROVED], true) ) {
            return false;
        }

        // Upsert — for entire-scope reviews, the row may not exist yet.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM %i WHERE review_id = %d AND post_id = %d LIMIT 1",
            self::posts_table(), $review_id, $post_id
        ) );

        $data = [ 'status' => $status ];
        if ( $status === self::PAGE_STATUS_APPROVED ) {
            $data['approved_by_email'] = $approved_by_email;
            $data['approved_at']       = current_time('mysql', true);
        }

        if ( $existing_id ) {
            return $wpdb->update(self::posts_table(), $data, ['id' => (int) $existing_id]) !== false;
        }
        $data['review_id']  = $review_id;
        $data['post_id']    = $post_id;
        $data['sort_order'] = 0;
        return $wpdb->insert(self::posts_table(), $data) !== false;
    }

    // ---------------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------------

    /**
     * Activate a review. Returns true on success, false on DB failure.
     */
    public static function publish(int $id): bool {
        $ok = self::update($id, ['status' => self::STATUS_ACTIVE]);
        if ( $ok ) {
            DXF_Review_Audit::log($id, null, 'published');
            do_action('dxf_review_cache_dirty', $id);
        }
        return $ok;
    }

    public static function close(int $id): bool {
        $ok = self::update($id, [
            'status'    => self::STATUS_CLOSED,
            'closed_at' => current_time('mysql', true),
        ]);
        if ( $ok ) {
            DXF_Review_Audit::log($id, null, 'closed');
            do_action('dxf_review_cache_dirty', $id);
        }
        return $ok;
    }

    /**
     * Re-activate a closed/expired review.
     */
    public static function reopen(int $id): bool {
        $ok = self::update($id, [
            'status'    => self::STATUS_ACTIVE,
            'closed_at' => null,
        ]);
        if ( $ok ) {
            DXF_Review_Audit::log($id, null, 'reopened');
            do_action('dxf_review_cache_dirty', $id);
        }
        return $ok;
    }

    public static function is_open(array $review): bool {
        if ( ($review['status'] ?? '') !== self::STATUS_ACTIVE ) return false;
        if ( ! empty($review['expires_at']) ) {
            $expires = strtotime((string) $review['expires_at'] . ' UTC');
            if ( $expires !== false && $expires < time() ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a review is read-only — existing comments stay visible, but no new
     * ones are accepted. Reviews are not read-only by default; the
     * `dxf_review_read_only` filter lets a listener mark specific review types
     * read-only. Used by both the comment-add endpoint (enforcement) and the
     * reviewer payload (UX) so the two can't drift.
     */
    public static function is_read_only(array $review): bool {
        return (bool) apply_filters('dxf_review_read_only', false, $review);
    }

    /**
     * True when the email-restricted review feature is available — i.e. its
     * member directory + magic-link auth classes are loaded. A defensive check:
     * if those classes are ever absent, any email-mode review is treated as
     * unavailable rather than fatally calling a class that doesn't exist.
     */
    public static function email_features_available(): bool {
        return class_exists('DXF_Review_Auth') && class_exists('DXF_Review_Member');
    }

    /**
     * Sweep — mark active reviews past their expiry as 'expired'. Called from cron.
     */
    public static function sweep_expiries(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM %i WHERE status = %s AND expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP()",
            self::reviews_table(), self::STATUS_ACTIVE
        ) ) ?: [];
        foreach ($rows as $rid) {
            $rid = (int) $rid;
            self::update($rid, ['status' => self::STATUS_EXPIRED]);
            DXF_Review_Audit::log($rid, null, 'expired');
            do_action('dxf_review_cache_dirty', $rid);
        }
    }

    // ---------------------------------------------------------------------
    // URL helpers
    // ---------------------------------------------------------------------

    public static function landing_url(string $slug): string {
        return self::review_url($slug);
    }

    public static function activation_url(string $review_slug, string $token): string {
        return self::review_url($review_slug, [ DXF_Reviews::QV_ACTIVATE => $token ]);
    }

    /** Per-page review-mode handoff URL (/dox-feedback/<slug>/item/<id>/). */
    public static function item_url(string $slug, int $post_id): string {
        return self::review_url($slug, [ DXF_Reviews::QV_PAGE => $post_id ]);
    }

    /**
     * Build a reviewer URL. Uses the pretty `/dox-feedback/<slug>/…` path when
     * the site has a permalink structure; otherwise falls back to a query-var
     * URL that works WITHOUT any rewrite rule — so a fresh install on the
     * default "Plain" permalink setting doesn't 404 on review links. The query
     * vars are registered in DXF_Reviews::add_query_vars(), so the router
     * picks them up either way.
     */
    private static function review_url(string $slug, array $extra = []): string {
        if ( get_option('permalink_structure') ) {
            $path = '/dox-feedback/' . rawurlencode($slug) . '/';
            if ( isset($extra[ DXF_Reviews::QV_ACTIVATE ]) ) {
                $path .= 'activate/' . rawurlencode((string) $extra[ DXF_Reviews::QV_ACTIVATE ]) . '/';
            } elseif ( isset($extra[ DXF_Reviews::QV_PAGE ]) ) {
                $path .= 'item/' . (int) $extra[ DXF_Reviews::QV_PAGE ] . '/';
            }
            return home_url($path);
        }

        $args = [ DXF_Reviews::QUERY_VAR => $slug ] + $extra;
        return add_query_arg($args, home_url('/'));
    }
}
