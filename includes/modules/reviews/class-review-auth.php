<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Magic-link activation + signed-cookie session auth for email-restricted
 * reviews. Original Dox Studio implementation.
 *
 * Flow:
 *   1. An invitee opens /dox-feedback/<slug>/activate/<token>/. activate_token()
 *      validates the single-use token against the review, binds it to the
 *      opening device, and sets a long-lived HMAC-signed cookie.
 *   2. current_member() verifies that cookie on every reviewer request and
 *      returns the member row (role/email/name) or null.
 *
 * The cookie carries no PII — only member id, review id and an HMAC keyed on the
 * member's per-row session_secret plus the site auth salt, so it cannot be
 * forged and is invalidated by revoke (revoked_at) or a secret/salt rotation.
 */
final class DXF_Review_Auth {

    private const COOKIE = 'dxf_member';
    private const TTL    = 30 * DAY_IN_SECONDS;

    /**
     * Resolve the verified member for $review from the signed session cookie.
     * Returns the member row array (keys incl. 'role','email','name') or null.
     */
    public static function current_member(array $review): ?array {
        $review_id = (int) ( $review['id'] ?? 0 );
        if ( $review_id <= 0 ) {
            return null;
        }
        $raw = isset($_COOKIE[self::COOKIE]) ? (string) wp_unslash($_COOKIE[self::COOKIE]) : '';
        if ( $raw === '' || substr_count($raw, '|') !== 2 ) {
            return null;
        }
        [$mid, $rid, $sig] = explode('|', $raw);
        $mid = (int) $mid;
        $rid = (int) $rid;
        if ( $mid <= 0 || $rid !== $review_id ) {
            return null;
        }
        $member = DXF_Review_Member::get($mid);
        if ( ! $member || (int) $member['review_id'] !== $review_id || ! empty($member['revoked_at']) ) {
            return null;
        }
        $expected = self::sign($mid, $review_id, (string) $member['session_secret']);
        if ( ! hash_equals($expected, (string) $sig) ) {
            return null;
        }
        return $member;
    }

    /**
     * Consume a magic-link token: validate it for $slug, enforce one-device
     * binding, set the session cookie. Returns true, or a WP_Error whose
     * error-data may carry a 'status' for the HTTP response code.
     */
    public static function activate_token(string $token, string $slug): bool|\WP_Error {
        if ( ! self::rate_ok() ) {
            return new \WP_Error('rate_limited', __('Too many attempts. Please wait a few minutes and try again.', 'dox-feedback'), ['status' => 429]);
        }
        $token  = (string) preg_replace('/[^a-f0-9]/', '', strtolower($token));
        $member = $token !== '' ? DXF_Review_Member::get_by_token($token) : null;
        if ( ! $member ) {
            return new \WP_Error('bad_token', __('This invite link is no longer valid. Ask the site owner to resend it.', 'dox-feedback'), ['status' => 410]);
        }
        $review = DXF_Review::get((int) $member['review_id']);
        if ( ! $review || (string) $review['slug'] !== $slug ) {
            return new \WP_Error('bad_token', __('This invite link is not valid for this review.', 'dox-feedback'), ['status' => 404]);
        }
        if ( ! empty($member['revoked_at']) ) {
            return new \WP_Error('revoked', __('Your access to this review has been revoked.', 'dox-feedback'), ['status' => 403]);
        }

        // Single-use: mark_activated() consumes the token (nulls it), so the
        // magic link can't be replayed. A later click finds no token and is sent
        // to the gate to request a fresh link.
        DXF_Review_Member::mark_activated((int) $member['id'], self::ua_hash());
        if ( class_exists('DXF_Review_Audit') ) {
            DXF_Review_Audit::log((int) $review['id'], (int) $member['id'], 'activated', []);
        }

        self::set_cookie((int) $member['id'], (int) $review['id'], (string) $member['session_secret']);
        return true;
    }

    public static function clear(): void {
        if ( ! headers_sent() ) {
            setcookie(self::COOKIE, '', self::cookie_options(time() - DAY_IN_SECONDS));
        }
        unset($_COOKIE[self::COOKIE]);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function set_cookie(int $mid, int $rid, string $secret): void {
        $value = $mid . '|' . $rid . '|' . self::sign($mid, $rid, $secret);
        if ( ! headers_sent() ) {
            setcookie(self::COOKIE, $value, self::cookie_options(time() + self::TTL));
        }
        $_COOKIE[self::COOKIE] = $value;
    }

    private static function sign(int $mid, int $rid, string $secret): string {
        return hash_hmac('sha256', $mid . '|' . $rid, $secret . '|' . wp_salt('auth'));
    }

    private static function ua_hash(): string {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
        return substr(hash('sha256', $ua), 0, 16);
    }

    private static function cookie_path(): string {
        return defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
    }

    private static function cookie_domain(): string {
        return defined('COOKIE_DOMAIN') ? (string) COOKIE_DOMAIN : '';
    }

    /** Cookie options (PHP 7.3+ array form) with explicit SameSite hardening. */
    private static function cookie_options(int $expires): array {
        return [
            'expires'  => $expires,
            'path'     => self::cookie_path(),
            'domain'   => self::cookie_domain(),
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    /** Crude per-IP throttle on token activation (30 / hour). */
    private static function rate_ok(): bool {
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : '';
        $key = 'dxf_act_' . substr(hash('sha256', $ip), 0, 20);
        $n   = (int) get_transient($key);
        if ( $n >= 30 ) {
            return false;
        }
        set_transient($key, $n + 1, HOUR_IN_SECONDS);
        return true;
    }
}
