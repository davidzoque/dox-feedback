<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Email-restricted reviewer records — roles, magic-link activation tokens and
 * signed-session secrets, stored in {prefix}dxf_review_members.
 *
 * Original Dox Studio implementation of the reviewer-membership layer the
 * review runtime calls into (DXF_Review_Member::role_can / ::table and the
 * directory used by the invite/revoke UI).
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */
final class DXF_Review_Member {

    public const ROLE_VIEWER   = 'viewer';
    public const ROLE_REVIEWER = 'reviewer';
    public const ROLE_APPROVER = 'approver';
    public const ROLE_LEAD     = 'lead';

    /** Ordered for display (least → most privileged). */
    public const ROLES = [
        self::ROLE_VIEWER,
        self::ROLE_REVIEWER,
        self::ROLE_APPROVER,
        self::ROLE_LEAD,
    ];

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'dxf_review_members';
    }

    /**
     * Role → capability matrix. Capabilities asked for by the runtime are
     * 'view', 'comment', 'approve' and 'invite'.
     *
     *   Viewer   : view
     *   Reviewer : view, comment
     *   Approver : view, comment, approve
     *   Lead     : view, comment, approve, invite
     */
    public static function role_can(string $role, string $capability): bool {
        $role = strtolower(trim($role));
        $matrix = [
            self::ROLE_VIEWER   => ['view' => true],
            self::ROLE_REVIEWER => ['view' => true, 'comment' => true],
            self::ROLE_APPROVER => ['view' => true, 'comment' => true, 'approve' => true],
            self::ROLE_LEAD     => ['view' => true, 'comment' => true, 'approve' => true, 'invite' => true],
        ];
        return ! empty($matrix[$role][$capability]);
    }

    public static function normalize_role(string $role): string {
        $role = strtolower(trim($role));
        return in_array($role, self::ROLES, true) ? $role : self::ROLE_REVIEWER;
    }

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    public static function get(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", self::table(), $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function for_review(int $review_id): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM %i WHERE review_id = %d ORDER BY id ASC", self::table(), $review_id ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }

    public static function find_by_review_email(int $review_id, string $email): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM %i WHERE review_id = %d AND email = %s LIMIT 1", self::table(), $review_id, $email ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_by_token(string $token): ?array {
        if ( $token === '' ) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM %i WHERE activation_token = %s LIMIT 1", self::table(), $token ),
            ARRAY_A
        );
        return $row ?: null;
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * Invite one reviewer by email. Returns the (new or existing) member row,
     * or null on invalid email / DB failure. Idempotent per (review, email).
     */
    public static function invite_one(int $review_id, string $email, string $role): ?array {
        global $wpdb;
        $email = sanitize_email($email);
        if ( $email === '' || ! is_email($email) ) {
            return null;
        }
        $existing = self::find_by_review_email($review_id, $email);
        if ( $existing ) {
            return $existing;
        }
        $ok = $wpdb->insert(
            self::table(),
            [
                'review_id'        => $review_id,
                'email'            => $email,
                'name'             => self::name_from_email($email),
                'role'             => self::normalize_role($role),
                'activation_token' => self::new_token(),
                'session_secret'   => self::new_secret(),
                'created_at'       => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        if ( $ok === false ) {
            return null;
        }
        return self::get((int) $wpdb->insert_id);
    }

    /** @param string[] $emails @return array<int,array<string,mixed>> created/existing members */
    public static function seed_from_emails(int $review_id, array $emails, string $default_role): array {
        $out = [];
        foreach ( $emails as $email ) {
            $member = self::invite_one($review_id, (string) $email, $default_role);
            if ( $member ) {
                $out[] = $member;
            }
        }
        return $out;
    }

    public static function update_role(int $member_id, string $role): bool {
        global $wpdb;
        return false !== $wpdb->update(
            self::table(),
            ['role' => self::normalize_role($role)],
            ['id' => $member_id],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Revoke access. Rotates session_secret so any cookie already minted for
     * this member stops validating immediately — revoked_at alone wouldn't, as
     * a later resend clears it (see reissue_token) while the cookie's HMAC,
     * keyed on session_secret, would otherwise still verify.
     */
    public static function revoke(int $member_id): bool {
        global $wpdb;
        return false !== $wpdb->update(
            self::table(),
            ['revoked_at' => current_time('mysql', true), 'activation_token' => null, 'session_secret' => self::new_secret()],
            ['id' => $member_id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Admin "resend": fresh single-use token AND a fresh session secret, clear
     * the device binding + any revoke. Rotating the secret guarantees every
     * previously issued link or cookie for this member is dead.
     */
    public static function reissue_token(int $member_id): ?string {
        global $wpdb;
        $token = self::new_token();
        $ok = $wpdb->update(
            self::table(),
            [
                'activation_token'  => $token,
                'session_secret'    => self::new_secret(),
                'activated_at'      => null,
                'activated_ua_hash' => '',
                'revoked_at'        => null,
            ],
            ['id' => $member_id],
            ['%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
        return $ok === false ? null : $token;
    }

    /**
     * Mark a member's link as used. Single-use: the activation token is consumed
     * (nulled) here so the magic link can never be replayed; further access for
     * this member needs an admin/self-service resend.
     */
    public static function mark_activated(int $member_id, string $ua_hash): void {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->update(
            self::table(),
            ['activated_at' => $now, 'activated_ua_hash' => substr($ua_hash, 0, 16), 'last_seen_at' => $now, 'activation_token' => null],
            ['id' => $member_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    public static function touch_seen(int $member_id): void {
        global $wpdb;
        $wpdb->update(
            self::table(),
            ['last_seen_at' => current_time('mysql', true)],
            ['id' => $member_id],
            ['%s'],
            ['%d']
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public static function new_token(): string {
        return bin2hex(random_bytes(20)); // 40 hex chars (fits VARCHAR(64))
    }

    public static function new_secret(): string {
        return bin2hex(random_bytes(32)); // 64 hex chars (fits VARCHAR(64))
    }

    public static function role_label(string $role): string {
        switch ( self::normalize_role($role) ) {
            case self::ROLE_VIEWER:   return __('Viewer', 'dox-feedback');
            case self::ROLE_APPROVER: return __('Approver', 'dox-feedback');
            case self::ROLE_LEAD:     return __('Lead', 'dox-feedback');
            default:                  return __('Reviewer', 'dox-feedback');
        }
    }

    private static function name_from_email(string $email): string {
        $at    = strpos($email, '@');
        $local = $at !== false ? substr($email, 0, $at) : $email;
        $local = trim((string) preg_replace('/\s+/', ' ', str_replace(['.', '_', '-', '+'], ' ', $local)));
        $name  = $local !== '' ? ucwords($local) : __('Reviewer', 'dox-feedback');
        return mb_substr($name, 0, 100);
    }
}
