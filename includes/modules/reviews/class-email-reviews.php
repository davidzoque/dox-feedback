<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Email-invited reviewers with roles — original Dox Studio implementation built
 * on the review module's documented hooks. Provides:
 *
 *   - the wizard "email-restricted" mode card + invitee/role config
 *   - the edit-screen reviewer directory (invite / role / resend / revoke)
 *   - magic-link invitation emails (on create + on demand)
 *   - the reviewer-facing email gate ("get my private link")
 *
 * Roles + magic-link auth live in DXF_Review_Member / DXF_Review_Auth.
 *
 * phpcs:disable WordPress.Security.NonceVerification.Missing
 */
final class DXF_Email_Reviews {

    private const ADMIN_NONCE = 'dxf_members';
    private const GATE_NONCE   = 'dxf_member_request';

    public function __construct() {
        add_action('dxf_review_wizard_mode_cards',     [$this, 'wizard_mode_cards']);
        add_action('dxf_review_wizard_mode_config',    [$this, 'wizard_mode_config']);
        add_action('dxf_review_edit_reviewers_panel',  [$this, 'edit_reviewers_panel'], 10, 2);
        add_action('dxf_review_render_email_gate',     [$this, 'render_email_gate']);
        add_action('dxf_review_after_create',          [$this, 'send_invites_on_create'], 10, 2);

        // Admin (owner) management.
        add_action('wp_ajax_dxf_member_invite', [$this, 'ajax_invite']);
        add_action('wp_ajax_dxf_member_role',   [$this, 'ajax_role']);
        add_action('wp_ajax_dxf_member_revoke', [$this, 'ajax_revoke']);
        add_action('wp_ajax_dxf_member_resend', [$this, 'ajax_resend']);

        // Reviewer-facing "send me my link" from the gate (logged-out).
        add_action('wp_ajax_nopriv_dxf_member_request', [$this, 'ajax_request_link']);
        add_action('wp_ajax_dxf_member_request',        [$this, 'ajax_request_link']);
    }

    // -------------------------------------------------------------------------
    // Wizard
    // -------------------------------------------------------------------------

    public function wizard_mode_cards(): void {
        ?>
        <label class="dxf-mode-card">
            <input type="radio" name="mode" value="<?php echo esc_attr(DXF_Review::MODE_EMAIL); ?>">
            <div>
                <strong><?php esc_html_e('Specific people (by email)', 'dox-feedback'); ?></strong>
                <span class="description"><?php esc_html_e('Invite named reviewers. Each gets a private magic link and a role.', 'dox-feedback'); ?></span>
            </div>
        </label>
        <?php
    }

    public function wizard_mode_config(): void {
        ?>
        <div class="dxf-email-config" style="display:none;margin-top:14px;">
            <p style="margin:0 0 4px;"><strong><?php esc_html_e('Reviewer emails', 'dox-feedback'); ?></strong></p>
            <textarea name="emails" rows="4" class="large-text" placeholder="alice@example.com&#10;bob@example.com"></textarea>
            <p class="description"><?php esc_html_e('One email per line. Each reviewer receives a private magic link.', 'dox-feedback'); ?></p>
            <p>
                <label><strong><?php esc_html_e('Default role', 'dox-feedback'); ?></strong>
                    <select name="default_role" style="margin-left:6px;">
                        <?php foreach ( self::role_choices() as $value => $label ) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($value, DXF_Review_Member::ROLE_REVIEWER); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </p>
        </div>
        <script>
        (function () {
            var f = document.querySelector('.dxf-review-form');
            if (!f) { return; }
            function sync() {
                var m = f.querySelector('input[name="mode"]:checked');
                var cfg = f.querySelector('.dxf-email-config');
                if (cfg) { cfg.style.display = (m && m.value === '<?php echo esc_js(DXF_Review::MODE_EMAIL); ?>') ? '' : 'none'; }
            }
            f.addEventListener('change', function (e) { if (e.target && e.target.name === 'mode') { sync(); } });
            sync();
        })();
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Edit screen — reviewer directory
    // -------------------------------------------------------------------------

    public function edit_reviewers_panel($id, $review): void {
        $id = (int) $id;
        if ( ( $review['mode'] ?? '' ) !== DXF_Review::MODE_EMAIL ) {
            return; // link-mode reviews have no directory
        }
        $members = DXF_Review_Member::for_review($id);
        ?>
        <h2 style="margin-top:28px;"><?php esc_html_e('Reviewers', 'dox-feedback'); ?></h2>
        <div class="dxf-reviewers" data-review-id="<?php echo esc_attr((string) $id); ?>">
            <p class="dxf-invite-row">
                <input type="email" class="dxf-invite-email regular-text" placeholder="<?php esc_attr_e('reviewer@example.com', 'dox-feedback'); ?>">
                <select class="dxf-invite-role">
                    <?php foreach ( self::role_choices() as $value => $label ) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($value, DXF_Review_Member::ROLE_REVIEWER); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button button-primary dxf-invite-btn"><?php esc_html_e('Invite', 'dox-feedback'); ?></button>
                <span class="dxf-invite-msg" style="margin-left:8px;color:#646970;"></span>
            </p>

            <table class="widefat striped dxf-members-table" style="margin-top:10px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Reviewer', 'dox-feedback'); ?></th>
                        <th><?php esc_html_e('Role', 'dox-feedback'); ?></th>
                        <th><?php esc_html_e('Status', 'dox-feedback'); ?></th>
                        <th><?php esc_html_e('Actions', 'dox-feedback'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty($members) ) : ?>
                        <tr class="dxf-no-members"><td colspan="4"><?php esc_html_e('No reviewers invited yet.', 'dox-feedback'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $members as $m ) : ?>
                            <?php $this->render_member_row($m); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <script>
        (function ($) {
            var cfg = { url: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, nonce: <?php echo wp_json_encode(wp_create_nonce(self::ADMIN_NONCE)); ?> };
            var $wrap = $('.dxf-reviewers');
            if (!$wrap.length) { return; }
            var reviewId = $wrap.data('review-id');

            function post(action, data) {
                return $.post(cfg.url, $.extend({ action: action, _ajax_nonce: cfg.nonce, review_id: reviewId }, data));
            }
            $wrap.on('click', '.dxf-invite-btn', function () {
                var $btn = $(this).prop('disabled', true);
                var email = $wrap.find('.dxf-invite-email').val();
                var role = $wrap.find('.dxf-invite-role').val();
                var $msg = $wrap.find('.dxf-invite-msg').text('');
                post('dxf_member_invite', { email: email, role: role }).done(function (resp) {
                    if (resp && resp.success) {
                        $wrap.find('.dxf-no-members').remove();
                        $wrap.find('.dxf-members-table tbody').append(resp.data.row);
                        $wrap.find('.dxf-invite-email').val('');
                    } else {
                        $msg.text((resp && resp.data && resp.data.message) || 'Could not invite.');
                    }
                }).always(function () { $btn.prop('disabled', false); });
            });
            $wrap.on('change', '.dxf-member-role', function () {
                var $sel = $(this);
                post('dxf_member_role', { member_id: $sel.data('member-id'), role: $sel.val() });
            });
            $wrap.on('click', '.dxf-member-resend', function () {
                var $btn = $(this).prop('disabled', true);
                post('dxf_member_resend', { member_id: $btn.data('member-id') }).always(function () {
                    $btn.prop('disabled', false).text('Sent');
                    setTimeout(function () { $btn.text('Resend'); }, 1500);
                });
            });
            $wrap.on('click', '.dxf-member-revoke', function () {
                if (!confirm('Revoke this reviewer’s access?')) { return; }
                var $btn = $(this);
                post('dxf_member_revoke', { member_id: $btn.data('member-id') }).done(function (resp) {
                    if (resp && resp.success) { $btn.closest('tr').css('opacity', 0.5).find('.dxf-member-status').text('Revoked'); $btn.remove(); }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    private function render_member_row(array $m): void {
        $status = ! empty($m['revoked_at']) ? __('Revoked', 'dox-feedback')
            : ( ! empty($m['activated_at']) ? __('Active', 'dox-feedback') : __('Pending', 'dox-feedback') );
        $revoked = ! empty($m['revoked_at']);
        ?>
        <tr>
            <td>
                <strong><?php echo esc_html((string) ($m['name'] ?? '')); ?></strong><br>
                <span style="color:#646970;"><?php echo esc_html((string) ($m['email'] ?? '')); ?></span>
            </td>
            <td>
                <select class="dxf-member-role" data-member-id="<?php echo esc_attr((string) $m['id']); ?>" <?php disabled($revoked); ?>>
                    <?php foreach ( self::role_choices() as $value => $label ) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($value, DXF_Review_Member::normalize_role((string) ($m['role'] ?? ''))); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td class="dxf-member-status"><?php echo esc_html($status); ?></td>
            <td>
                <?php if ( ! $revoked ) : ?>
                    <button type="button" class="button-link dxf-member-resend" data-member-id="<?php echo esc_attr((string) $m['id']); ?>"><?php esc_html_e('Resend', 'dox-feedback'); ?></button>
                    &nbsp;|&nbsp;
                    <button type="button" class="button-link dxf-member-revoke" data-member-id="<?php echo esc_attr((string) $m['id']); ?>" style="color:#b32d2e;"><?php esc_html_e('Revoke', 'dox-feedback'); ?></button>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    // -------------------------------------------------------------------------
    // Reviewer-facing email gate
    // -------------------------------------------------------------------------

    public function render_email_gate($review): void {
        $slug = (string) ( $review['slug'] ?? '' );
        $name = ( (string) ($review['name'] ?? '') ) !== '' ? (string) $review['name'] : __('this review', 'dox-feedback');
        nocache_headers();
        status_header(200);
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php esc_html_e('Private review', 'dox-feedback'); ?></title>
    <?php wp_head(); ?>
    <style>
        body.dxf-gate{background:#f4f5f7;margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1f2329;}
        .dxf-gate-card{max-width:420px;margin:10vh auto 0;background:#fff;border:1px solid #e3e6ea;border-radius:14px;padding:30px 28px;box-shadow:0 4px 18px rgba(0,0,0,.06);}
        .dxf-gate-card h1{font-size:20px;margin:0 0 8px;}
        .dxf-gate-card p{color:#646970;font-size:14px;margin:0 0 18px;}
        .dxf-gate-card input[type=email]{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #c9ced6;border-radius:8px;font-size:15px;margin:0 0 12px;}
        .dxf-gate-card button{width:100%;padding:11px 12px;border:0;border-radius:8px;background:#ff8d27;color:#1a1a1a;font-weight:700;font-size:15px;cursor:pointer;}
        .dxf-gate-msg{margin-top:14px;font-size:13px;color:#1a7f37;min-height:18px;}
    </style>
</head>
<body class="dxf-gate">
    <div class="dxf-gate-card">
        <h1><?php esc_html_e('This is a private review', 'dox-feedback'); ?></h1>
        <p>
            <?php
            /* translators: %s = review name */
            printf(esc_html__('Open the private link from your invitation email to access %s. Lost it? Enter your email and we\'ll resend it.', 'dox-feedback'), esc_html($name));
            ?>
        </p>
        <form class="dxf-gate-form">
            <input type="email" required placeholder="<?php esc_attr_e('you@example.com', 'dox-feedback'); ?>" class="dxf-gate-email">
            <button type="submit"><?php esc_html_e('Send me my link', 'dox-feedback'); ?></button>
            <div class="dxf-gate-msg" role="status"></div>
        </form>
    </div>
    <script>
        (function () {
            var cfg = { url: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, nonce: <?php echo wp_json_encode(wp_create_nonce(self::GATE_NONCE)); ?>, slug: <?php echo wp_json_encode($slug); ?> };
            var form = document.querySelector('.dxf-gate-form');
            if (!form) { return; }
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var email = form.querySelector('.dxf-gate-email').value;
                var msg = form.querySelector('.dxf-gate-msg');
                msg.textContent = '<?php echo esc_js(__('Sending…', 'dox-feedback')); ?>';
                var body = new URLSearchParams({ action: 'dxf_member_request', _ajax_nonce: cfg.nonce, slug: cfg.slug, email: email });
                fetch(cfg.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                    .then(function (r) { return r.json(); })
                    .then(function () { msg.textContent = '<?php echo esc_js(__('If that address was invited, a fresh link is on its way.', 'dox-feedback')); ?>'; })
                    .catch(function () { msg.textContent = '<?php echo esc_js(__('Something went wrong. Please try again.', 'dox-feedback')); ?>'; });
            });
        })();
    </script>
    <?php wp_footer(); ?>
</body>
</html>
        <?php
        exit;
    }

    // -------------------------------------------------------------------------
    // Invitations
    // -------------------------------------------------------------------------

    public function send_invites_on_create($review, $args): void {
        if ( ( $review['mode'] ?? '' ) !== DXF_Review::MODE_EMAIL ) {
            return;
        }
        foreach ( DXF_Review_Member::for_review((int) $review['id']) as $member ) {
            if ( ! empty($member['activated_at']) || ! empty($member['revoked_at']) || empty($member['activation_token']) ) {
                continue;
            }
            $this->mail_invite($review, $member);
        }
    }

    private function mail_invite(array $review, array $member): bool {
        if ( ! class_exists('DXF_Mailer') || empty($member['activation_token']) ) {
            return false;
        }
        $url     = DXF_Review::activation_url((string) $review['slug'], (string) $member['activation_token']);
        $site    = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $rname   = ( (string) ($review['name'] ?? '') ) !== '' ? (string) $review['name'] : $site;
        $heading = sprintf(
            /* translators: %s = review or site name */
            __('You\'re invited to review %s', 'dox-feedback'),
            $rname
        );
        $body = '<p>' . esc_html(sprintf(
            /* translators: %s = review name */
            __('You\'ve been invited to leave feedback on "%s". Use the button below to open your private review link — it\'s tied to this device.', 'dox-feedback'),
            $rname
        )) . '</p>';
        $html  = DXF_Mailer::build_html($heading, $body, [['url' => $url, 'label' => __('Open my review', 'dox-feedback')]]);
        $plain = $heading . "\n\n" . sprintf(
            /* translators: %s = activation URL */
            __('Open your private review link: %s', 'dox-feedback'),
            $url
        );
        $sent = DXF_Mailer::send((string) $member['email'], $heading, $plain, $html);
        if ( $sent && class_exists('DXF_Review_Audit') ) {
            DXF_Review_Audit::log((int) $review['id'], (int) $member['id'], 'sent', []);
        }
        return (bool) $sent;
    }

    // -------------------------------------------------------------------------
    // AJAX — owner management (capability: edit_posts)
    // -------------------------------------------------------------------------

    public function ajax_invite(): void {
        $review = $this->verify_admin();
        $email  = isset($_POST['email']) ? sanitize_email((string) wp_unslash($_POST['email'])) : '';
        $role   = isset($_POST['role']) ? sanitize_key((string) wp_unslash($_POST['role'])) : DXF_Review_Member::ROLE_REVIEWER;
        if ( $email === '' || ! is_email($email) ) {
            wp_send_json_error(['message' => __('Enter a valid email address.', 'dox-feedback')], 400);
        }
        $member = DXF_Review_Member::invite_one((int) $review['id'], $email, $role);
        if ( ! $member ) {
            wp_send_json_error(['message' => __('Could not add that reviewer.', 'dox-feedback')], 400);
        }
        // Send the magic link straight away.
        $this->mail_invite($review, $member);
        ob_start();
        $this->render_member_row($member);
        wp_send_json_success(['row' => ob_get_clean()]);
    }

    public function ajax_role(): void {
        $review    = $this->verify_admin();
        $member_id = isset($_POST['member_id']) ? (int) $_POST['member_id'] : 0;
        $role      = isset($_POST['role']) ? sanitize_key((string) wp_unslash($_POST['role'])) : '';
        $member    = $member_id ? DXF_Review_Member::get($member_id) : null;
        if ( ! $member || (int) $member['review_id'] !== (int) $review['id'] ) {
            wp_send_json_error(['message' => __('Unknown reviewer.', 'dox-feedback')], 404);
        }
        DXF_Review_Member::update_role($member_id, $role);
        wp_send_json_success();
    }

    public function ajax_revoke(): void {
        $review    = $this->verify_admin();
        $member_id = isset($_POST['member_id']) ? (int) $_POST['member_id'] : 0;
        $member    = $member_id ? DXF_Review_Member::get($member_id) : null;
        if ( ! $member || (int) $member['review_id'] !== (int) $review['id'] ) {
            wp_send_json_error(['message' => __('Unknown reviewer.', 'dox-feedback')], 404);
        }
        DXF_Review_Member::revoke($member_id);
        if ( class_exists('DXF_Review_Audit') ) {
            DXF_Review_Audit::log((int) $review['id'], $member_id, 'revoked', []);
        }
        wp_send_json_success();
    }

    public function ajax_resend(): void {
        $review    = $this->verify_admin();
        $member_id = isset($_POST['member_id']) ? (int) $_POST['member_id'] : 0;
        $member    = $member_id ? DXF_Review_Member::get($member_id) : null;
        if ( ! $member || (int) $member['review_id'] !== (int) $review['id'] ) {
            wp_send_json_error(['message' => __('Unknown reviewer.', 'dox-feedback')], 404);
        }
        DXF_Review_Member::reissue_token($member_id);
        $member = DXF_Review_Member::get($member_id);
        if ( $member ) {
            $this->mail_invite($review, $member);
        }
        wp_send_json_success();
    }

    /** @return array the review row; exits with JSON error on failure. */
    private function verify_admin(): array {
        check_ajax_referer(self::ADMIN_NONCE);
        if ( ! current_user_can('edit_posts') ) {
            wp_send_json_error(['message' => __('Permission denied.', 'dox-feedback')], 403);
        }
        $review_id = isset($_POST['review_id']) ? (int) $_POST['review_id'] : 0;
        $review    = $review_id ? DXF_Review::get($review_id) : null;
        if ( ! $review ) {
            wp_send_json_error(['message' => __('Unknown review.', 'dox-feedback')], 404);
        }
        // Only the review's owner — or an editor who can manage others' content —
        // may touch its reviewer list. Stops a low-trust role from managing
        // another user's private reviewers via a forged review_id.
        if ( (int) $review['created_by'] !== get_current_user_id() && ! current_user_can('edit_others_posts') ) {
            wp_send_json_error(['message' => __('Permission denied.', 'dox-feedback')], 403);
        }
        return $review;
    }

    // -------------------------------------------------------------------------
    // AJAX — reviewer "send me my link" (public, anti-enumeration + throttled)
    // -------------------------------------------------------------------------

    public function ajax_request_link(): void {
        check_ajax_referer(self::GATE_NONCE);
        $slug  = isset($_POST['slug']) ? preg_replace('/[^a-f0-9]/', '', strtolower((string) wp_unslash($_POST['slug']))) : '';
        $email = isset($_POST['email']) ? sanitize_email((string) wp_unslash($_POST['email'])) : '';

        // Always answer the same way (don't reveal who is invited) + throttle.
        $generic = ['ok' => true];
        if ( $slug === '' || $email === '' || ! is_email($email) || ! $this->request_rate_ok() ) {
            wp_send_json_success($generic);
        }
        $review = DXF_Review::get_by_slug($slug);
        if ( $review && ( $review['mode'] ?? '' ) === DXF_Review::MODE_EMAIL ) {
            $member = DXF_Review_Member::find_by_review_email((int) $review['id'], $email);
            // Per-(review, email) throttle so a known address can't be mail-bombed.
            if ( $member && empty($member['revoked_at']) && $this->email_resend_ok((int) $review['id'], $email) ) {
                // Re-send the pending link as-is; only mint a fresh token when
                // none is left (already activated / consumed) — so a self-service
                // resend can never break a reviewer's still-valid link.
                if ( empty($member['activation_token']) ) {
                    DXF_Review_Member::reissue_token((int) $member['id']);
                    $member = DXF_Review_Member::get((int) $member['id']);
                }
                if ( $member ) {
                    $this->mail_invite($review, $member);
                }
            }
        }
        wp_send_json_success($generic);
    }

    private function request_rate_ok(): bool {
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : '';
        $key = 'dxf_req_' . substr(hash('sha256', $ip), 0, 20);
        $n   = (int) get_transient($key);
        if ( $n >= 10 ) {
            return false;
        }
        set_transient($key, $n + 1, HOUR_IN_SECONDS);
        return true;
    }

    /** Per-(review, email) resend throttle: at most one link every 5 minutes. */
    private function email_resend_ok(int $review_id, string $email): bool {
        $key = 'dxf_rsnd_' . substr(hash('sha256', $review_id . '|' . strtolower($email)), 0, 24);
        if ( get_transient($key) ) {
            return false;
        }
        set_transient($key, 1, 5 * MINUTE_IN_SECONDS);
        return true;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array<string,string> */
    private static function role_choices(): array {
        return [
            DXF_Review_Member::ROLE_VIEWER   => __('Viewer — can see the site only', 'dox-feedback'),
            DXF_Review_Member::ROLE_REVIEWER => __('Reviewer — can comment', 'dox-feedback'),
            DXF_Review_Member::ROLE_APPROVER => __('Approver — can comment & approve', 'dox-feedback'),
            DXF_Review_Member::ROLE_LEAD     => __('Lead — can comment, approve & invite', 'dox-feedback'),
        ];
    }
}
