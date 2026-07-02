<?php
/**
 * Dox Feedback Review Audit — append-only log per review.
 *
 * Used by the admin Audit panel ("Sarah activated her link 22 May 14:03 from
 * 203.0.113.x"), and as forensic backup if a review goes sideways. We log
 * salted SHA-256 hashes of IPs, never raw IPs.
 *
 * @since 0.16.0
 */

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

// This class only ever touches its own custom table (dxf_review_audit).
// Every query is either an append-only insert or a bounded read filtered by
// review_id — caching the audit log doesn't make sense (writes invalidate
// reads constantly, and we read at most once per admin page-load). Table
// names are composed from $wpdb->prefix + class constant, which phpcs
// cannot statically trace through method calls in $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

final class DXF_Review_Audit {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'dxf_review_audit';
    }

    /**
     * Append an event. Fire-and-forget; if logging fails we don't disrupt
     * the caller.
     *
     * Known events: created, published, sent, activated, revoked, commented,
     * approved, expired, closed, reopened, scope_changed, link_used, link_blocked.
     */
    public static function log(int $review_id, ?int $member_id, string $event, array $meta = []): void {
        global $wpdb;
        if ( $review_id <= 0 || $event === '' ) return;

        $ip      = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $ua      = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 300) : '';
        $ip_hash = $ip !== '' ? substr(hash('sha256', $ip . wp_salt('auth')), 0, 16) : '';

        $wpdb->insert(self::table(), [
            'review_id'   => $review_id,
            'member_id'   => $member_id,
            'event'       => substr($event, 0, 40),
            'ip_hash'     => $ip_hash,
            'user_agent'  => $ua,
            'meta'        => $meta ? wp_json_encode($meta) : null,
            'occurred_at' => current_time('mysql', true),
        ], ['%d','%d','%s','%s','%s','%s','%s']);
    }

    /** True if at least one row of the given event exists for the review. */
    public static function has_event(int $review_id, string $event): bool {
        global $wpdb;
        if ( $review_id <= 0 || $event === '' ) return false;
        // Custom-table existence probe; no object cache layer.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM %i WHERE review_id = %d AND event = %s LIMIT 1",
            self::table(), $review_id, substr($event, 0, 40)
        ) );
    }

    /**
     * Most recent occurrence (UTC mysql datetime) of an event for a review, or
     * '' if it never happened. Used for the "Client last viewed …" indicator.
     */
    public static function last_occurred(int $review_id, string $event): string {
        global $wpdb;
        if ( $review_id <= 0 || $event === '' ) return '';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        return (string) ( $wpdb->get_var( $wpdb->prepare(
            "SELECT occurred_at FROM %i
              WHERE review_id = %d AND event = %s
              ORDER BY occurred_at DESC, id DESC LIMIT 1",
            self::table(), $review_id, substr($event, 0, 40)
        ) ) ?: '' );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function list_for_review(int $review_id, int $limit = 200): array {
        global $wpdb;
        // Table name composed from $wpdb->prefix + class constant. Custom-table
        // read, ordered by composite index — no object cache layer.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i WHERE review_id = %d ORDER BY occurred_at DESC, id DESC LIMIT %d",
            self::table(), $review_id, $limit
        ), ARRAY_A ) ?: [];
    }
}
