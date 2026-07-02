<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Thin wrapper around wp_mail() that:
 *   1. Sends branded HTML emails using the site's own identity (logo / name / colour).
 *   2. Captures failures via the wp_mail_failed hook.
 *   3. Persists the last error message in a transient for the admin notice.
 */
class DXF_Mailer {

    private const ERROR_TRANSIENT  = 'dxf_mail_error';
    private const DISMISS_OPTION   = 'dxf_mail_notice_dismissed';
    private const DISMISS_DURATION = WEEK_IN_SECONDS;

    // -------------------------------------------------------------------------
    // Brand config
    // -------------------------------------------------------------------------

    /**
     * Returns brand colours and identity for plugin emails. Defaults to the
     * site's own logo / name / accent; overridable via the `dxf_mailer_brand`
     * filter.
     */
    public static function get_brand(): array {
        // Default to the SITE's own identity so notifications carry the agency /
        // client brand rather than the plugin's. The Customizer "custom logo"
        // (Appearance → Customize → Site Identity) is used in the header when
        // set; otherwise the site name renders as text. Fully overridable via
        // the `dxf_mailer_brand` filter.
        $logo_url = '';
        $logo_id  = (int) get_theme_mod('custom_logo');
        if ( $logo_id ) {
            $src = wp_get_attachment_image_src($logo_id, 'medium');
            if ( $src ) {
                $logo_url = (string) $src[0];
            }
        }

        return (array) apply_filters( 'dxf_mailer_brand', [
            'primary'    => '#ff8d27',
            'on_primary' => '#ffffff',
            'name'       => get_bloginfo('name'),
            'logo_url'   => $logo_url,
        ] );
    }

    // -------------------------------------------------------------------------
    // HTML email builder
    // -------------------------------------------------------------------------

    /**
     * Build a branded HTML email.
     *
     * @param string $heading  Short heading shown below the logo.
     * @param string $body_html Sanitised HTML for the body (paragraphs, etc.).
     * @param array  $actions  Optional CTA buttons: [['url' => …, 'label' => …], …]
     *
     * @return string Full HTML document.
     */
    public static function build_html( string $heading, string $body_html, array $actions = [] ): string {
        $b = self::get_brand();

        // Logo or brand-name text.
        $logo_part = $b['logo_url']
            ? '<img src="' . esc_url( $b['logo_url'] ) . '" alt="' . esc_attr( $b['name'] ) . '"'
              . ' height="36" style="display:block;max-width:180px;height:36px;object-fit:contain;">'
            : '<span style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,sans-serif;'
              . 'font-size:20px;font-weight:700;color:' . esc_attr( $b['on_primary'] ) . ';letter-spacing:-0.3px;">'
              . esc_html( $b['name'] ) . '</span>';

        // Action buttons.
        $buttons_html = '';
        foreach ( $actions as $a ) {
            $buttons_html .= '<a href="' . esc_url( $a['url'] ) . '"'
                . ' style="display:inline-block;margin:0 8px 8px 0;padding:10px 20px;'
                . 'background:' . esc_attr( $b['primary'] ) . ';color:' . esc_attr( $b['on_primary'] ) . ';'
                . 'text-decoration:none;border-radius:5px;font-weight:600;font-size:14px;'
                . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,sans-serif;">'
                . esc_html( $a['label'] ) . '</a>';
        }
        $buttons_wrap = $buttons_html ? '<div style="margin-top:22px;">' . $buttons_html . '</div>' : '';

        $heading_esc = esc_html( $heading );
        $site_name   = esc_html( get_bloginfo('name') );
        $site_url    = esc_url( home_url() );
        $brand_name  = esc_html( $b['name'] );
        $primary     = esc_attr( $b['primary'] );

        // Footer attribution. When the brand name differs from the site name
        // (e.g. a custom name via the filter) show both; otherwise just link the
        // site once so it doesn't read "Sent by ACME · ACME".
        $attribution = ( $brand_name !== '' && $brand_name !== $site_name )
            ? 'Sent by <strong>' . $brand_name . '</strong> &nbsp;&middot;&nbsp; <a href="' . $site_url . '" style="color:#999;">' . $site_name . '</a>'
            : 'Sent by <a href="' . $site_url . '" style="color:#999;font-weight:600;">' . $site_name . '</a>';

        return '<!DOCTYPE html>'
            . '<html lang="en">'
            . '<head>'
            . '<meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $heading_esc . '</title>'
            . '</head>'
            . '<body style="margin:0;padding:0;background:#f2f2f8;">'
            . '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f2f2f8;padding:40px 16px;">'
            . '<tr><td align="center">'
            . '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:540px;">'
            . '<tr>'
            . '<td style="background:' . $primary . ';padding:20px 28px;border-radius:8px 8px 0 0;">'
            . $logo_part
            . '</td>'
            . '</tr>'
            . '<tr>'
            . '<td style="background:#ffffff;padding:28px 28px 24px;border:1px solid #e0e0e8;border-top:none;border-radius:0 0 8px 8px;">'
            . '<h2 style="margin:0 0 14px;font-size:18px;font-weight:700;color:#1a1a2e;'
            . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,sans-serif;">'
            . $heading_esc
            . '</h2>'
            . '<div style="font-size:15px;line-height:1.65;color:#38385a;'
            . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,sans-serif;">'
            . $body_html
            . '</div>'
            . $buttons_wrap
            . '</td>'
            . '</tr>'
            . '<tr>'
            . '<td style="padding:18px 0 0;text-align:center;font-size:12px;color:#999;'
            . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,sans-serif;">'
            . $attribution
            . '</td>'
            . '</tr>'
            . '</table>'
            . '</td></tr>'
            . '</table>'
            . '</body>'
            . '</html>';
    }

    // -------------------------------------------------------------------------
    // Public send API
    // -------------------------------------------------------------------------

    /**
     * Send a branded HTML email and record any delivery failure.
     *
     * @param string|array $to    Recipient address, or array of addresses.
     * @param string $subject     Subject line.
     * @param string $plain       Plain-text fallback (auto-wrapped in HTML if $html omitted).
     * @param string $html        Pre-built HTML body. If empty, built from $plain automatically.
     * @param array  $opts        {
     *     @type string $from_name    Friendly name on the From header.
     *     @type string $from_email   From address (must be on your own domain to avoid SPF/DMARC bounces).
     *     @type string $reply_to     Single Reply-To address (sanitised).
     *     @type array  $attachments  Absolute paths passed straight to wp_mail()'s $attachments.
     * }
     *
     * @return bool True on success.
     */
    public static function send( $to, string $subject, string $plain, string $html = '', array $opts = [] ): bool {
        if ( ! $html ) {
            $html = self::build_html( $subject, '<p>' . nl2br( esc_html( $plain ) ) . '</p>' );
        }

        // Normalise + sanitise recipients (string or array). Deduped, capped.
        $recipients = [];
        $candidates = is_array( $to ) ? $to : [ $to ];
        foreach ( $candidates as $candidate ) {
            $email = sanitize_email( (string) $candidate );
            if ( $email && ! in_array( $email, $recipients, true ) ) {
                $recipients[] = $email;
                if ( count( $recipients ) >= 25 ) break; // hard cap defence
            }
        }
        if ( empty( $recipients ) ) {
            return false;
        }

        // Build headers.
        $headers = [ 'Content-Type: text/html; charset=UTF-8', 'MIME-Version: 1.0' ];
        $from_email = isset( $opts['from_email'] ) ? sanitize_email( (string) $opts['from_email'] ) : '';
        $from_name  = isset( $opts['from_name'] )  ? sanitize_text_field( (string) $opts['from_name'] ) : '';
        if ( $from_email ) {
            $headers[] = 'From: ' . ( $from_name ? sprintf( '%s <%s>', $from_name, $from_email ) : $from_email );
        }
        $reply_to = isset( $opts['reply_to'] ) ? sanitize_email( (string) $opts['reply_to'] ) : '';
        if ( $reply_to ) {
            $headers[] = 'Reply-To: ' . $reply_to;
        }

        $attachments = [];
        if ( isset( $opts['attachments'] ) && is_array( $opts['attachments'] ) ) {
            foreach ( $opts['attachments'] as $path ) {
                if ( is_string( $path ) && $path !== '' && file_exists( $path ) ) {
                    $attachments[] = $path;
                }
            }
        }

        $caught   = null;
        $listener = static function ( \WP_Error $error ) use ( &$caught ): void {
            $caught = $error;
        };

        add_action( 'wp_mail_failed', $listener );
        $sent = wp_mail( $recipients, $subject, $html, $headers, $attachments );
        remove_action( 'wp_mail_failed', $listener );

        if ( ! $sent || $caught instanceof \WP_Error ) {
            if ( $caught instanceof \WP_Error ) {
                $msg = $caught->get_error_message();
            } else {
                // No SMTP plugin? Surface a helpful hint with a (safe) link.
                $msg = __( 'wp_mail() returned false. WordPress couldn\'t deliver the email — usually because no SMTP plugin is configured. Try installing <a href="https://wordpress.org/plugins/fluent-smtp/" target="_blank" rel="noopener">FluentSMTP</a> or <a href="https://wordpress.org/plugins/post-smtp/" target="_blank" rel="noopener">Post SMTP</a>.', 'dox-feedback' );
            }
            set_transient( self::ERROR_TRANSIENT, $msg, WEEK_IN_SECONDS );
        } else {
            delete_transient( self::ERROR_TRANSIENT );
        }

        return $sent && ! ( $caught instanceof \WP_Error );
    }

    // -------------------------------------------------------------------------
    // Admin notice helpers
    // -------------------------------------------------------------------------

    public static function last_error(): string {
        return (string) get_transient( self::ERROR_TRANSIENT );
    }

    public static function clear_error(): void {
        delete_transient( self::ERROR_TRANSIENT );
    }

    public static function dismiss_notice(): void {
        update_option( self::DISMISS_OPTION, time(), false );
    }

    public static function is_notice_dismissed(): bool {
        $ts = (int) get_option( self::DISMISS_OPTION, 0 );
        return $ts > 0 && ( time() - $ts ) < self::DISMISS_DURATION;
    }

    public static function should_show_notice(): bool {
        return self::last_error() !== '' && ! self::is_notice_dismissed();
    }
}
