<?php
/**
 * Dox Feedback — "What's New" post-update notice.
 *
 * After the plugin is updated (not on a fresh install), admins see a one-time,
 * dismissible card listing what changed in the new version. Highlights are
 * pulled automatically from the current version's readme.txt changelog entry;
 * a site can curate them instead via the `dxf_whats_new_items` filter.
 *
 * Per-user dismissal (so each admin sees it once) and it re-arms on the next
 * version bump.
 *
 * @since 1.0.9
 */

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

final class DXF_Whats_New {

    /** Highest plugin version we've recorded running on this site. */
    private const OPT_VERSION = 'dxf_whatsnew_version';
    /** Set to a version when an actual update is detected (vs fresh install). */
    private const OPT_ARMED   = 'dxf_whatsnew_armed';
    /** Per-user meta: the version this admin has dismissed. */
    private const META_SEEN   = 'dxf_whatsnew_seen';

    public function __construct() {
        add_action('admin_init',    [$this, 'detect']);
        add_action('admin_notices', [$this, 'render']);
        add_action('wp_ajax_dxf_dismiss_whats_new', [$this, 'ajax_dismiss']);
    }

    /**
     * Record the running version and arm the notice when it increases. A fresh
     * install (no version recorded yet) just stores the version — nothing to
     * announce.
     */
    public function detect(): void {
        if ( ! current_user_can('manage_options') ) {
            return;
        }
        $prev = get_option(self::OPT_VERSION, null);
        if ( $prev === null ) {
            add_option(self::OPT_VERSION, DXF_VERSION, '', false);
            return;
        }
        if ( version_compare((string) $prev, DXF_VERSION, '<') ) {
            update_option(self::OPT_VERSION, DXF_VERSION, false);
            update_option(self::OPT_ARMED, DXF_VERSION, false);
        }
    }

    public function render(): void {
        if ( ! current_user_can('manage_options') ) {
            return;
        }
        if ( get_option(self::OPT_ARMED) !== DXF_VERSION ) {
            return;
        }
        if ( get_user_meta(get_current_user_id(), self::META_SEEN, true) === DXF_VERSION ) {
            return;
        }
        $items = self::highlights();
        if ( ! $items ) {
            return;
        }

        $changelog_url = apply_filters('dxf_changelog_url', 'https://doxstudio.com/changelog/');
        $nonce         = wp_create_nonce('dxf_dismiss_whats_new');
        ?>
        <div id="dxf-whats-new" class="notice notice-info" style="margin:14px 0;padding:16px 18px;border-left-color:#4f46e5;">
            <p style="margin:0 0 8px;font-size:15px;">
                <strong>
                    <?php
                    printf(
                        /* translators: %s = plugin version */
                        esc_html__('🎉 Dox Feedback was updated to v%s — here\'s what\'s new:', 'dox-feedback'),
                        esc_html(DXF_VERSION)
                    );
                    ?>
                </strong>
            </p>
            <ul style="margin:0 0 12px;padding-left:18px;list-style:disc;max-width:760px;">
                <?php foreach ( $items as $item ) : ?>
                    <li style="margin-bottom:4px;color:#3c434a;"><?php echo wp_kses( self::format( $item ), [ 'strong' => [], 'em' => [], 'code' => [] ] ); ?></li>
                <?php endforeach; ?>
            </ul>
            <p style="margin:0;">
                <a class="button button-primary" href="<?php echo esc_url($changelog_url); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e('View full changelog', 'dox-feedback'); ?>
                </a>
                <button type="button" class="button" id="dxf-whats-new-dismiss" style="margin-left:6px;">
                    <?php esc_html_e('Got it', 'dox-feedback'); ?>
                </button>
            </p>
        </div>
        <script>
        (function () {
            var btn = document.getElementById('dxf-whats-new-dismiss');
            if (!btn) return;
            btn.addEventListener('click', function () {
                var box = document.getElementById('dxf-whats-new');
                if (box) box.style.display = 'none';
                var body = new URLSearchParams();
                body.append('action', 'dxf_dismiss_whats_new');
                body.append('_ajax_nonce', '<?php echo esc_js($nonce); ?>');
                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
            });
        })();
        </script>
        <?php
    }

    public function ajax_dismiss(): void {
        check_ajax_referer('dxf_dismiss_whats_new');
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
        }
        update_user_meta(get_current_user_id(), self::META_SEEN, DXF_VERSION);
        wp_send_json_success();
    }

    // -------------------------------------------------------------------------
    // Highlights
    // -------------------------------------------------------------------------

    /**
     * Up to six highlights for the current version. Curated list via the
     * `dxf_whats_new_items` filter wins; otherwise parse readme.txt.
     *
     * @return string[]
     */
    private static function highlights(): array {
        $curated = apply_filters('dxf_whats_new_items', null, DXF_VERSION);
        $items   = is_array($curated) ? $curated : self::changelog_items(DXF_VERSION);
        $items   = array_values(array_filter(array_map('strval', $items)));
        return array_slice($items, 0, 6);
    }

    /**
     * Bullet lines under the `= {version} =` heading in readme.txt.
     *
     * @return string[]
     */
    private static function changelog_items(string $version): array {
        $file = DXF_DIR . 'readme.txt';
        if ( ! is_readable($file) ) {
            return [];
        }
        $txt = file_get_contents($file);
        if ( $txt === false ) {
            return [];
        }
        $items = [];
        $in    = false;
        foreach ( preg_split('/\r\n|\r|\n/', $txt) as $line ) {
            $t = trim($line);
            if ( preg_match('/^=\s*' . preg_quote($version, '/') . '\s*=$/', $t) ) {
                $in = true;
                continue;
            }
            if ( $in && preg_match('/^=\s*[0-9]/', $t) ) {
                break; // reached the next version block
            }
            if ( $in && strpos($t, '*') === 0 ) {
                $items[] = ltrim(substr($t, 1));
            }
        }
        return $items;
    }

    /** Trim a changelog line to a readable highlight (cap length). */
    private static function tidy(string $item): string {
        $item = trim($item);
        if ( function_exists('mb_strimwidth') ) {
            return mb_strimwidth($item, 0, 200, '…');
        }
        return strlen($item) > 200 ? substr($item, 0, 199) . '…' : $item;
    }

    /**
     * Trim, escape, then render the limited Markdown the readme changelog uses
     * (`**bold**` and `` `code` ``) as real tags. Everything is HTML-escaped
     * first, so the only markup present is what we inject; the caller passes the
     * result through wp_kses with a strong/em/code allowlist. Any lone `**` left
     * dangling by truncation is dropped so no raw markers ever surface.
     */
    private static function format(string $item): string {
        $item = esc_html( self::tidy( $item ) );
        $item = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $item);
        $item = preg_replace('/`([^`]+)`/', '<code>$1</code>', $item);
        return str_replace('**', '', $item);
    }
}
