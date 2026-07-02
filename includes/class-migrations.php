<?php
/**
 * Schema migrations.
 *
 * This file owns our custom tables exclusively. Every direct query here is
 * either a dbDelta() invocation, an ALTER TABLE for additive columns, or a
 * SHOW COLUMNS guard. Object caching is irrelevant for schema operations —
 * they run once per upgrade and bypass cache by design. Table names are
 * interpolated from $wpdb->prefix.
 */

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

class DXF_Migrations {

    public static function run(string $from_version): void {
        if ( version_compare($from_version, '0.1.0', '<') ) {
            self::migrate_0_1_0();
        }
        if ( version_compare($from_version, '0.2.0', '<') ) {
            self::migrate_0_2_0();
        }
        if ( version_compare($from_version, '0.3.0', '<') ) {
            self::migrate_0_3_0();
        }
        if ( version_compare($from_version, '0.4.0', '<') ) {
            self::migrate_0_4_0();
        }
        if ( version_compare($from_version, '0.5.0', '<') ) {
            self::migrate_0_5_0();
        }
        if ( version_compare($from_version, '0.6.0', '<') ) {
            self::migrate_0_6_0();
        }
        if ( version_compare($from_version, '0.7.0', '<') ) {
            self::migrate_0_7_0();
        }
        if ( version_compare($from_version, '0.8.0', '<') ) {
            self::migrate_0_8_0();
        }
        if ( version_compare($from_version, '0.9.0', '<') ) {
            self::migrate_0_9_0();
        }
    }

    // -------------------------------------------------------------------------
    // v0.1.0 — initial schema
    // -------------------------------------------------------------------------

    private static function migrate_0_1_0(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        // Comments table — supports both logged-in authors and guest reviewers.
        // anchor_data stores all three fallback strategies as JSON.
        dbDelta("
            CREATE TABLE {$wpdb->prefix}dxf_comments (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id     BIGINT UNSIGNED NOT NULL,
                element_id  VARCHAR(100)    NOT NULL DEFAULT '',
                parent_id   BIGINT UNSIGNED          DEFAULT NULL,
                author_id   BIGINT UNSIGNED          DEFAULT NULL,
                author_name VARCHAR(100)    NOT NULL DEFAULT '',
                author_email VARCHAR(200)  NOT NULL DEFAULT '',
                body        TEXT            NOT NULL,
                status      ENUM('open','resolved') NOT NULL DEFAULT 'open',
                anchor_data JSON            NOT NULL,
                created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_post_id  (post_id),
                KEY idx_status   (status),
                KEY idx_parent   (parent_id)
            ) $charset;
        ");

        // Review tokens — one per share link, revocable, optionally password-protected.
        dbDelta("
            CREATE TABLE {$wpdb->prefix}dxf_review_tokens (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id       BIGINT UNSIGNED NOT NULL,
                token         VARCHAR(64)     NOT NULL,
                password_hash VARCHAR(255)             DEFAULT NULL,
                expires_at    DATETIME                 DEFAULT NULL,
                created_by    BIGINT UNSIGNED NOT NULL,
                created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revoked_at    DATETIME                 DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY   uidx_token   (token),
                KEY          idx_post_id  (post_id)
            ) $charset;
        ");
    }

    // -------------------------------------------------------------------------
    // v0.2.0 — immutable client approval records (sign-off proof)
    // -------------------------------------------------------------------------

    private static function migrate_0_2_0(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        dbDelta("
            CREATE TABLE {$wpdb->prefix}dxf_approvals (
                id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id        BIGINT UNSIGNED NOT NULL,
                token          VARCHAR(64)     NOT NULL DEFAULT '',
                approver_name  VARCHAR(100)    NOT NULL DEFAULT '',
                approver_email VARCHAR(200)    NOT NULL DEFAULT '',
                approver_ip    VARCHAR(45)     NOT NULL DEFAULT '',
                user_agent     VARCHAR(300)    NOT NULL DEFAULT '',
                page_title     TEXT            NOT NULL,
                page_url       TEXT            NOT NULL,
                approved_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY          idx_post_id  (post_id)
            ) $charset;
        ");
    }

    // -------------------------------------------------------------------------
    // v0.3.0 — extended statuses (in_progress) + comment assignee
    // -------------------------------------------------------------------------

    private static function migrate_0_3_0(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dxf_comments';

        // Add 'in_progress' to the status enum (non-destructive for existing rows).
        $wpdb->query(
            "ALTER TABLE {$table}
                MODIFY COLUMN status ENUM('open','in_progress','resolved')
                NOT NULL DEFAULT 'open'"
        );

        // Add an assignee column if it isn't already present.
        $has_assignee = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM {$table} LIKE %s",
                'assignee_id'
            )
        );
        if ( ! $has_assignee ) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN assignee_id BIGINT UNSIGNED NULL DEFAULT NULL");
            $wpdb->query("ALTER TABLE {$table} ADD KEY idx_assignee (assignee_id)");
        }
    }

    // -------------------------------------------------------------------------
    // v0.4.0 — review rounds / revision cycles
    // -------------------------------------------------------------------------

    private static function migrate_0_4_0(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dxf_comments';

        $has_round = $wpdb->get_var(
            $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'round')
        );
        if ( ! $has_round ) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN round SMALLINT UNSIGNED NOT NULL DEFAULT 1");
            $wpdb->query("ALTER TABLE {$table} ADD KEY idx_round (round)");
        }
    }

    // -------------------------------------------------------------------------
    // v0.5.0 — Reviews: project-level review objects (multi-page, optional
    // email-restricted access). The legacy per-page dxf_review_tokens table
    // stays in place for back-compat; this adds the parent Review object on top.
    // -------------------------------------------------------------------------

    private static function migrate_0_5_0(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        // The Review (project) object itself.
        dbDelta("
            CREATE TABLE {$wpdb->prefix}dxf_reviews (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug            VARCHAR(64)     NOT NULL,
                name            VARCHAR(200)    NOT NULL DEFAULT '',
                status          ENUM('draft','active','closed','expired') NOT NULL DEFAULT 'draft',
                scope_type      ENUM('single','selected','entire')        NOT NULL DEFAULT 'single',
                include_future  TINYINT(1)      NOT NULL DEFAULT 0,
                mode            ENUM('link','email') NOT NULL DEFAULT 'link',
                password_hash   VARCHAR(255)             DEFAULT NULL,
                expires_at      DATETIME                 DEFAULT NULL,
                created_by      BIGINT UNSIGNED NOT NULL,
                created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                closed_at       DATETIME                 DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY   uidx_slug    (slug),
                KEY          idx_status   (status),
                KEY          idx_creator  (created_by)
            ) $charset;
        ");

        // M:N join + per-page state cache (status, approval).
        dbDelta("
            CREATE TABLE {$wpdb->prefix}dxf_review_posts (
                id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                review_id         BIGINT UNSIGNED NOT NULL,
                post_id           BIGINT UNSIGNED NOT NULL,
                sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                status            ENUM('todo','in_review','approved') NOT NULL DEFAULT 'todo',
                approved_by_email VARCHAR(200)    NOT NULL DEFAULT '',
                approved_at       DATETIME                 DEFAULT NULL,
                reviewed_at       DATETIME                 DEFAULT NULL,
                reviewed_by       VARCHAR(100)    NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                UNIQUE KEY   uidx_review_post (review_id, post_id),
                KEY          idx_review_status (review_id, status)
            ) $charset;
        ");

        // Per-reviewer membership (only populated when mode = 'email').
        dbDelta("
            CREATE TABLE {$wpdb->prefix}dxf_review_members (
                id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                review_id         BIGINT UNSIGNED NOT NULL,
                email             VARCHAR(200)    NOT NULL,
                name              VARCHAR(100)    NOT NULL DEFAULT '',
                role              ENUM('viewer','reviewer','approver','lead') NOT NULL DEFAULT 'reviewer',
                activation_token  VARCHAR(64)              DEFAULT NULL,
                activated_at      DATETIME                 DEFAULT NULL,
                activated_ua_hash CHAR(16)        NOT NULL DEFAULT '',
                session_secret    VARCHAR(64)     NOT NULL,
                revoked_at        DATETIME                 DEFAULT NULL,
                last_seen_at      DATETIME                 DEFAULT NULL,
                created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY   uidx_review_email (review_id, email),
                KEY          idx_activation (activation_token),
                KEY          idx_review (review_id)
            ) $charset;
        ");

        // Audit log — covers both link-mode and email-mode events.
        dbDelta("
            CREATE TABLE {$wpdb->prefix}dxf_review_audit (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                review_id   BIGINT UNSIGNED NOT NULL,
                member_id   BIGINT UNSIGNED          DEFAULT NULL,
                event       VARCHAR(40)     NOT NULL,
                ip_hash     CHAR(16)        NOT NULL DEFAULT '',
                user_agent  VARCHAR(300)    NOT NULL DEFAULT '',
                meta        JSON                     DEFAULT NULL,
                occurred_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY          idx_review_time (review_id, occurred_at)
            ) $charset;
        ");
    }

    // -------------------------------------------------------------------------
    // v0.6.0 — link comments to Reviews so the in-builder "Reviews" dropdown
    // can filter by which Review a comment was left under. NULL = comment was
    // not made in a Review session (legacy review-mode token, or builder/editor
    // direct). The legacy `round` column stays in place for back-compat but is
    // no longer surfaced in the UI.
    // -------------------------------------------------------------------------

    private static function migrate_0_6_0(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dxf_comments';

        $has_review_id = $wpdb->get_var(
            $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'review_id')
        );
        if ( ! $has_review_id ) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN review_id BIGINT UNSIGNED NULL DEFAULT NULL");
            $wpdb->query("ALTER TABLE {$table} ADD KEY idx_review_id (review_id)");
        }
    }

    // -------------------------------------------------------------------------
    // v0.7.0 — scrub Bricks templates from existing Review selections.
    //
    // Reviewable_post_types() filters by `public => true` so new reviews can no
    // longer include `bricks_template`. But reviews created before that guard
    // landed (selected/single scopes) still carry template IDs in
    // wp_dxf_review_posts — and clicking those on the reviewer landing
    // bounces to the home URL because templates have no standalone permalink.
    // This is a one-shot cleanup so existing customer projects stop showing
    // dead rows. Append-only audit table is not touched.
    // -------------------------------------------------------------------------

    private static function migrate_0_7_0(): void {
        global $wpdb;
        $posts_table = $wpdb->prefix . 'dxf_review_posts';

        $template_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'bricks_template'"
        );
        if ( empty($template_ids) ) return;

        $template_ids = array_map('intval', $template_ids);
        $placeholders = implode(',', array_fill(0, count($template_ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$posts_table} WHERE post_id IN ({$placeholders})",
            $template_ids
        ));
    }

    // -------------------------------------------------------------------------
    // v0.8.0 — one-tap emoji reactions on comments.
    //
    // One row per (comment, reaction, person). `author_key` is a fixed-width
    // identity hash (md5 of "u:<user_id>" for logged-in users, or of the
    // lowercased "name|email" pair for guests) so the UNIQUE key stays inside
    // legacy InnoDB index limits and one person can react once per emoji —
    // toggle = atomic INSERT/DELETE, counts = GROUP BY, no read-modify-write
    // race. We never expose author identities to guests; only counts + "mine".
    // -------------------------------------------------------------------------

    private static function migrate_0_8_0(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        dbDelta("
            CREATE TABLE {$wpdb->prefix}dxf_comment_reactions (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                comment_id  BIGINT UNSIGNED NOT NULL,
                reaction    VARCHAR(20)     NOT NULL,
                author_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
                author_key  CHAR(32)        NOT NULL,
                author_name VARCHAR(100)    NOT NULL DEFAULT '',
                created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uidx_react (comment_id, reaction, author_key),
                KEY idx_comment (comment_id)
            ) $charset;
        ");
    }

    // -------------------------------------------------------------------------
    // v0.9.0 — per-page "Reviewed" marker (reviewer finished their pass on a
    // page, distinct from client approval). Additive columns on the join table.
    // -------------------------------------------------------------------------

    private static function migrate_0_9_0(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dxf_review_posts';

        $has_reviewed = $wpdb->get_var(
            $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'reviewed_at')
        );
        if ( ! $has_reviewed ) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN reviewed_at DATETIME NULL DEFAULT NULL");
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN reviewed_by VARCHAR(100) NOT NULL DEFAULT ''");
        }
    }

}
