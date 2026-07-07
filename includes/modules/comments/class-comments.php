<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

// All direct DB queries in this class target our custom `dxf_comments`
// table (joined occasionally to {$wpdb->prefix} core tables). Object caching
// is intentionally avoided — comments are written and re-read in the same
// AJAX request flow (e.g. update_anchor → fetch → render). Table names are
// always interpolated as "{$wpdb->prefix}dxf_comments" which is safe.
// `UnfinishedPrepare` covers a hand-built IN (placeholders) DELETE where the
// placeholder list is generated programmatically.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// ReplacementsWrongNumber: the comment-fetch IN() lists are built with a
// dynamic placeholder count (one %d per id) bound via array spread, so the
// static analyser can't reconcile placeholder vs. argument counts. Every value
// (and the %i table identifier) is bound through prepare().
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

class DXF_Comments {

    public const NONCE_ACTION = 'dxf_comments';

    // Sentinel for get_comments_for_post()'s $review_scope: return every
    // comment on the post regardless of which review it belongs to. Builder/
    // admin reads use this. Reviewer-facing reads pass the reviewer's active
    // review id instead (0 = unscoped/legacy) so one client never sees another
    // client's review on the same page — nor the agency's internal
    // builder-direct comments (review_id IS NULL).
    private const REVIEW_SCOPE_ALL = -1;

    public function __construct() {
        add_action('bricks/builder/after_enqueue_scripts', [$this, 'enqueue_builder_assets']);
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_builder_assets'], 999);
        // Native in-editor comments inside the Elementor editor.
        add_action('elementor/editor/after_enqueue_scripts', [$this, 'enqueue_elementor_editor_assets']);
        // Native in-editor comments inside the Gutenberg block editor.
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_gutenberg_editor_assets']);
        add_action('wp_ajax_dxf_get_comments',     [$this, 'ajax_get_comments']);
        add_action('wp_ajax_dxf_add_comment',      [$this, 'ajax_add_comment']);
        add_action('wp_ajax_dxf_resolve_comment',  [$this, 'ajax_resolve_comment']);
        add_action('wp_ajax_dxf_edit_comment',     [$this, 'ajax_edit_comment']);
        add_action('wp_ajax_nopriv_dxf_edit_comment', [$this, 'ajax_edit_comment']);
        add_action('wp_ajax_dxf_set_comment_review', [$this, 'ajax_set_comment_review']);
        add_action('wp_ajax_dxf_import_to_media',  [$this, 'ajax_import_to_media']);
        add_action('wp_ajax_dxf_update_anchor',    [$this, 'ajax_update_anchor']);
        add_action('wp_ajax_dxf_upload_screenshot', [$this, 'ajax_upload_screenshot']);
        // Guest reviewers via review-mode token.
        add_action('wp_ajax_nopriv_dxf_add_comment',              [$this, 'ajax_add_comment']);
        add_action('wp_ajax_nopriv_dxf_get_public_comments',      [$this, 'ajax_get_public_comments']);
        add_action('wp_ajax_dxf_get_public_comments',             [$this, 'ajax_get_public_comments']);
        add_action('wp_ajax_dxf_delete_comment',                  [$this, 'ajax_delete_comment']);
        add_action('wp_ajax_nopriv_dxf_delete_comment',           [$this, 'ajax_delete_comment']);
        // Token-authenticated guest parity with the builder comment engine.
        add_action('wp_ajax_nopriv_dxf_resolve_comment',          [$this, 'ajax_resolve_comment']);
        add_action('wp_ajax_dxf_toggle_reaction',                 [$this, 'ajax_toggle_reaction']);
        add_action('wp_ajax_nopriv_dxf_toggle_reaction',          [$this, 'ajax_toggle_reaction']);
        add_action('wp_ajax_dxf_attach_screenshot',               [$this, 'ajax_attach_screenshot']);
        add_action('wp_ajax_nopriv_dxf_attach_screenshot',        [$this, 'ajax_attach_screenshot']);
        add_action('wp_ajax_nopriv_dxf_update_anchor',            [$this, 'ajax_update_anchor']);
        add_action('wp_ajax_nopriv_dxf_upload_screenshot',        [$this, 'ajax_upload_screenshot']);
        add_action('wp_ajax_dxf_get_public_all_comments',         [$this, 'ajax_get_public_all_comments']);
        add_action('wp_ajax_nopriv_dxf_get_public_all_comments',  [$this, 'ajax_get_public_all_comments']);
        add_action('dxf_comments_settings',                    [$this, 'render_settings_fields']);
        add_action('wp_ajax_dxf_get_all_builder_comments',     [$this, 'ajax_get_all_builder_comments']);
        // Coalesced notification flush — fires after the throttle window via wp-cron.
        add_action('dxf_notify_flush',                         [$this, 'flush_notification_queue'], 10, 2);
        add_action('dxf_notify_reply_flush',                   [$this, 'flush_reply_notification'], 10, 2);
    }

    /**
     * "View in Builder" URL for a post — opens whichever builder actually built
     * the page, so the CTA (notification emails, admin-bar "open comments")
     * lands the team in the right editor instead of always assuming Bricks:
     *   - Elementor → the Elementor editor (post.php?action=elementor)
     *   - Bricks    → the front-end permalink with ?bricks=run
     *   - otherwise → the block / classic editor
     * Filterable via `dxf_builder_url` for custom builders or overrides.
     */
    public static function builder_url( int $post_id ): string {
        $url = '';

        // Elementor — a page built with Elementor opens the Elementor editor.
        if ( get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder' ) {
            if ( class_exists('\Elementor\Plugin') && ! empty(\Elementor\Plugin::$instance->documents) ) {
                $doc = \Elementor\Plugin::$instance->documents->get($post_id);
                if ( $doc ) {
                    $url = (string) $doc->get_edit_url();
                }
            }
            if ( $url === '' ) {
                $url = admin_url('post.php?post=' . $post_id . '&action=elementor');
            }
        }

        // Bricks — page built with Bricks (or a Bricks-themed site) loads at the
        // front-end permalink with ?bricks=run.
        if ( $url === '' ) {
            $is_bricks = get_post_meta($post_id, '_bricks_editor_mode', true) === 'bricks'
                || metadata_exists('post', $post_id, '_bricks_page_content_2')
                || ( function_exists('get_template') && get_template() === 'bricks' );
            $permalink = get_permalink($post_id);
            if ( $is_bricks && $permalink ) {
                $url = add_query_arg('bricks', 'run', $permalink);
            }
        }

        // Block / classic editor — and the fallback when there's no permalink.
        if ( $url === '' ) {
            $url = admin_url('post.php?post=' . $post_id . '&action=edit');
        }

        /**
         * Filter the "View in builder" URL for a post.
         *
         * @param string $url     The resolved editor URL.
         * @param int    $post_id The post being opened.
         */
        return (string) apply_filters('dxf_builder_url', $url, $post_id);
    }

    public function maybe_enqueue_builder_assets(): void {
        // Read-only check for Bricks builder query vars (?bricks=run, ?brickspreview)
        // to decide whether to enqueue our builder assets. No mutation, so
        // nonce verification doesn't apply.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty($_GET['bricks']) || $_GET['bricks'] !== 'run' || ! empty($_GET['brickspreview']) ) {
            return;
        }
        if ( wp_script_is('dxf-builder', 'enqueued') ) {
            return;
        }
        $this->enqueue_builder_assets();
    }

    /**
     * Cache-busting asset version: the file's mtime, so every deploy
     * invalidates browser/page caches without needing a plugin-version bump
     * (stale `?ver=` assets repeatedly masked fixes during 1.0.7 testing).
     * Falls back to DXF_VERSION if the file is unreadable.
     */
    public static function asset_ver(string $rel): string {
        $t = @filemtime(DXF_DIR . $rel);
        return $t ? (string) $t : DXF_VERSION;
    }

    public function enqueue_builder_assets(): void {
        wp_enqueue_style(
            'dxf-builder',
            DXF_URL . 'assets/builder/builder.css',
            [],
            self::asset_ver('assets/builder/builder.css')
        );

        // snapDOM (MIT) powers the per-comment screenshot capture — browser-
        // engine rendering via SVG foreignObject.
        wp_enqueue_script(
            'dxf-snapdom',
            DXF_URL . 'assets/vendor/snapdom.min.js',
            [],
            self::asset_ver('assets/vendor/snapdom.min.js'),
            true
        );

        // Builder anchor adapters — must load before the engine (it routes all
        // element anchoring through window.DxfAnchors).
        wp_enqueue_script(
            'dxf-anchors',
            DXF_URL . 'assets/comment-engine/adapters.js',
            [],
            self::asset_ver('assets/comment-engine/adapters.js'),
            true
        );

        // Shared comment engine — must load before any host adapter.
        wp_enqueue_script(
            'dxf-comment-engine',
            DXF_URL . 'assets/comment-engine/engine.js',
            ['dxf-snapdom', 'dxf-anchors'],
            self::asset_ver('assets/comment-engine/engine.js'),
            true
        );

        wp_enqueue_script(
            'dxf-builder',
            DXF_URL . 'assets/builder/builder.js',
            ['dxf-comment-engine'],
            self::asset_ver('assets/builder/builder.js'),
            true
        );

        wp_localize_script('dxf-builder', 'dxfComments', $this->editor_localize_data((int) get_the_ID()));
    }

    /**
     * Translatable strings for the shared comment engine (engine.js).
     * Supplied to BOTH the builder overlay and the front-end review portal so
     * engine.js resolves every label via cfg.i18n (English is the JS fallback).
     * Keys must match the t('key', …) calls in assets/comment-engine/engine.js.
     */
    public static function engine_i18n(): array {
        return [
            // Statuses (also read by the builder host)
            'open'                => __('Open', 'dox-feedback'),
            'inProgress'          => __('In review', 'dox-feedback'),
            'resolved'            => __('Resolved', 'dox-feedback'),
            'orphaned'            => __('Element removed', 'dox-feedback'),
            'unassigned'          => __('Unassigned', 'dox-feedback'),
            // Relative time
            'time.justNow'        => __('just now', 'dox-feedback'),
            'time.minutesAgo'     => __('%dm ago', 'dox-feedback'),
            'time.hoursAgo'       => __('%dh ago', 'dox-feedback'),
            'time.daysAgo'        => __('%dd ago', 'dox-feedback'),
            // Theme toggle
            'theme.switchToDark'  => __('Switch to dark mode', 'dox-feedback'),
            'theme.switchToLight' => __('Switch to light mode', 'dox-feedback'),
            'theme.toggle'        => __('Toggle theme', 'dox-feedback'),
            'theme.toggleTitle'   => __('Toggle light / dark', 'dox-feedback'),
            // Comment card
            'comment.delete'        => __('Delete comment', 'dox-feedback'),
            'comment.deleteConfirm' => __('Click again to delete', 'dox-feedback'),
            'comment.edit'          => __('Edit comment', 'dox-feedback'),
            'comment.numberTitle'   => __('Comment #%d', 'dox-feedback'),
            // Screenshot
            'shot.unavailable'    => __('⚠ Screenshot unavailable', 'dox-feedback'),
            'shot.annotated'      => __('✓ Screenshot annotated', 'dox-feedback'),
            'shot.preparing'      => __('Screenshot still preparing…', 'dox-feedback'),
            'shot.view'           => __('View screenshot', 'dox-feedback'),
            'shot.label'          => __('Screenshot', 'dox-feedback'),
            // Comment form
            'form.addComment'     => __('Add comment', 'dox-feedback'),
            'form.placeholder'    => __('Leave a comment… (Enter to send, Shift+Enter for a new line)', 'dox-feedback'),
            'form.attachFiles'    => __('Attach files', 'dox-feedback'),
            'form.annotateShot'   => __('Annotate screenshot', 'dox-feedback'),
            'form.emptyError'     => __('Please enter a comment.', 'dox-feedback'),
            // Generic actions
            'action.close'        => __('Close', 'dox-feedback'),
            'action.cancel'       => __('Cancel', 'dox-feedback'),
            'action.save'         => __('Save', 'dox-feedback'),
            'file.remove'         => __('Remove file', 'dox-feedback'),
            // Busy states
            'state.saving'        => __('Saving…', 'dox-feedback'),
            'state.sending'       => __('Sending…', 'dox-feedback'),
            'state.adding'        => __('Adding…', 'dox-feedback'),
            'state.failed'        => __('Failed', 'dox-feedback'),
            // Errors
            'error.generic'       => __('Something went wrong.', 'dox-feedback'),
            'error.network'       => __('Network error. Please try again.', 'dox-feedback'),
            'error.jsOne'         => __('⚠ %d JS error', 'dox-feedback'),
            'error.jsMany'        => __('⚠ %d JS errors', 'dox-feedback'),
            // Annotator
            'annot.title'         => __('Draw on the screenshot', 'dox-feedback'),
            'annot.clear'         => __('Clear', 'dox-feedback'),
            // Sidebar / scope / device filters
            'sidebar.brand'       => __('Comments', 'dox-feedback'),
            'scope.thisPage'      => __('This page', 'dox-feedback'),
            'scope.everything'    => __('Everything', 'dox-feedback'),
            'device.all'          => __('All devices', 'dox-feedback'),
            'device.desktop'      => __('Desktop', 'dox-feedback'),
            'device.tablet'       => __('Tablet', 'dox-feedback'),
            'device.mobile'       => __('Mobile', 'dox-feedback'),
            'resolved.toggleHint' => __('Include resolved comments in the list', 'dox-feedback'),
            // AI summary
            'ai.summarize'        => __('Summarize feedback', 'dox-feedback'),
            'ai.summarizeTitle'   => __('Summarize feedback (AI)', 'dox-feedback'),
            'ai.summarizeFailed'  => __('Could not summarize.', 'dox-feedback'),
            'ai.failedTitle'      => __('Summarize failed.', 'dox-feedback'),
            'ai.modelHint'        => __('If the message names a model id, the AI provider rejected it — update the model under Dox Feedback → AI.', 'dox-feedback'),
            'ai.summaryTitle'     => __('Feedback summary', 'dox-feedback'),
            // Dock
            'dock.toSide'         => __('Dock to side', 'dox-feedback'),
            'dock.toSideFloat'    => __('Dock to side / float', 'dox-feedback'),
            // Approval flow
            'approve.done'        => __('Page approved', 'dox-feedback'),
            'approve.blockedTip'  => __('Resolve every open comment before approving — %d still open.', 'dox-feedback'),
            'approve.mark'        => __('Mark page as approved', 'dox-feedback'),
            'approve.authority'   => __('I confirm I have the authority to approve this page.', 'dox-feedback'),
            'approve.recordNote'  => __('Your name, email, and the date & time will be recorded as a record of this approval.', 'dox-feedback'),
            'approve.button'      => __('Approve page', 'dox-feedback'),
            'approve.bannerTitle' => __('Page approved by %s', 'dox-feedback'),
            'approve.revert'      => __('Revert approval', 'dox-feedback'),
            'approve.revertConfirm' => __('Revert this page back to unapproved? The original approval record is removed.', 'dox-feedback'),
            'approve.reverting'   => __('Reverting…', 'dox-feedback'),
            // Review / finish flow
            'review.reviewedUndo' => __('Changes requested — undo', 'dox-feedback'),
            'review.markReviewed' => __('Request these changes', 'dox-feedback'),
            'review.finishNotify' => __('Finish & notify developer', 'dox-feedback'),
            'review.assignTo'     => __('Assign to a Review', 'dox-feedback'),
            'review.assign'       => __('Assign', 'dox-feedback'),
            'review.untitled'     => __('(untitled review)', 'dox-feedback'),
            'review.all'          => __('All Reviews', 'dox-feedback'),
            'review.outside'      => __('Outside any Review', 'dox-feedback'),
            'review.numbered'     => __('Review #%d', 'dox-feedback'),
            'review.new'          => __('+ New Review', 'dox-feedback'),
            'review.noneActive'   => __('No active reviews', 'dox-feedback'),
            'review.assignFailed' => __('Could not assign comment.', 'dox-feedback'),
            'finish.notePlaceholder' => __('Add a note for your developer (optional)…', 'dox-feedback'),
            'finish.send'         => __('Send to developer', 'dox-feedback'),
            'finish.notified'     => __('Developer notified', 'dox-feedback'),
            // Identity strip
            'identity.commentingAs' => __('Commenting as', 'dox-feedback'),
            'identity.change'     => __('Change', 'dox-feedback'),
            'role.reviewer'       => __('Reviewer', 'dox-feedback'),
            'role.teamMember'     => __('Team member', 'dox-feedback'),
            // Replies / threads
            'react'               => __('React', 'dox-feedback'),
            'reply'               => __('Reply', 'dox-feedback'),
            'reply.placeholder'   => __('Write a reply… (Enter to send)', 'dox-feedback'),
            'reply.peekMore'      => __('+%d more', 'dox-feedback'),
            'thread.hide'         => __('Hide thread', 'dox-feedback'),
            'thread.show'         => __('Show thread', 'dox-feedback'),
            'thread.showReply'    => __('Show thread & reply', 'dox-feedback'),
            'thread.replyOne'     => __('%d reply', 'dox-feedback'),
            'thread.replyMany'    => __('%d replies', 'dox-feedback'),
            'resolve.reopen'      => __('Reopen this comment', 'dox-feedback'),
            'resolve.mark'        => __('Mark as resolved', 'dox-feedback'),
            // Media library
            'media.added'         => __('✓ Added', 'dox-feedback'),
            'media.addToLibrary'  => __('Add to Media Library', 'dox-feedback'),
            'media.addShort'      => __('+ Media', 'dox-feedback'),
            'media.addedToLibrary'=> __('✓ Added to Media Library', 'dox-feedback'),
            'media.failedRetry'   => __('Failed — try again', 'dox-feedback'),
            'attach.image'        => __('Image', 'dox-feedback'),
            'attach.attachment'   => __('Attachment', 'dox-feedback'),
            'attach.file'         => __('File', 'dox-feedback'),
            'page.openInBuilder'  => __('Open in builder', 'dox-feedback'),
            // Pills / modes
            'pill.status'         => __('Status', 'dox-feedback'),
            'pill.assignee'       => __('Assignee', 'dox-feedback'),
            'mode.cursor'         => __('Cursor mode', 'dox-feedback'),
            'mode.browse'         => __('Browse', 'dox-feedback'),
            'mode.comment'        => __('Comment', 'dox-feedback'),
            // Empty states
            'approvedEmpty.title' => __('This page has been approved', 'dox-feedback'),
            'approvedEmpty.body'  => __('New comments are closed because this page has been marked as approved. If something\'s changed, ask the team to re-open the review.', 'dox-feedback'),
            'empty.noComments'    => __('No %s comments%s.', 'dox-feedback'),
            'empty.filter.open'   => __('open', 'dox-feedback'),
            'empty.filter.resolved' => __('resolved', 'dox-feedback'),
            'empty.filter.mine'   => __('mine', 'dox-feedback'),
            'empty.filter.all'    => __('all', 'dox-feedback'),
            'empty.siteWideSuffix'=> __(' site-wide', 'dox-feedback'),
        ];
    }

    /**
     * The `dxfComments` config consumed by every in-editor host (the Bricks
     * builder host and the Elementor editor host). Parameterised by the post
     * being edited so each host gets the right page id + approval state.
     */
    public function editor_localize_data( int $post_id ): array {
        $current_user = wp_get_current_user();
        return [
            'accent'         => self::accent_color(),
            'modalTheme'     => (string) DXF_Settings::get('comment_modal_theme', 'follow_bricks'),
            'approvedBy'     => DXF_Approvals::latest_for_post($post_id),
            'nonce'          => wp_create_nonce(self::NONCE_ACTION),
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'postId'         => $post_id,
            'currentUserId'  => get_current_user_id(),
            'currentUser'    => $current_user->display_name ?: $current_user->user_login,
            'captureLibUrl'  => DXF_URL . 'assets/vendor/snapdom.min.js',
            'showStatusPill' => (bool) DXF_Settings::get('comment_show_status_pill', 1),
            'showAssignPill' => (bool) DXF_Settings::get('comment_show_assign_pill', 1),
            'canImportMedia' => current_user_can('upload_files'),
            // Comment assignment is exposed as an availability filter (defaulting
            // false), so the assignee UI is only offered when a listener provides
            // the endpoint behind it.
            'canAssignComments' => (bool) apply_filters('dxf_can_assign_comments', false),
            'assignees'      => $this->assignable_users(),
            // Active Reviews drive the Reviews filter in the in-builder sidebar.
            // Replaces the legacy per-post "rounds" picker — comments now carry
            // a review_id (NULL = pre-Reviews / builder-direct).
            'reviews'        => self::active_reviews_for_picker(),
            'newReviewUrl'   => admin_url('admin.php?page=dxf-reviews&action=new'),
            'aiEnabled'      => class_exists('DXF_AI') ? DXF_AI::is_ready() : false,
            'i18n'          => array_merge( self::engine_i18n(), [
                // Builder-host-only labels (Bricks toolbar); engine strings come from engine_i18n().
                'commentMode' => __('Comment mode', 'dox-feedback'),
                'addComment'  => __('Add a comment…', 'dox-feedback'),
                'resolve'     => __('Resolve', 'dox-feedback'),
                'reopen'      => __('Reopen', 'dox-feedback'),
                'reply'       => __('Reply', 'dox-feedback'),
                // Bricks builder host FAB + identity gate (builder.js)
                'bld.comments'                  => __('Comments', 'dox-feedback'),
                'bld.identityIntro'             => __('You\'re commenting from this browser for the first time. Confirm the name to show on your comments — handy when your team shares one login.', 'dox-feedback'),
                'bld.yourName'                  => __('Your name', 'dox-feedback'),
                'bld.namePlaceholder'           => __('Jane Smith', 'dox-feedback'),
                'bld.back'                      => __('Back', 'dox-feedback'),
                'bld.save'                      => __('Save', 'dox-feedback'),
                'bld.continue'                  => __('Continue', 'dox-feedback'),
                'bld.nameRequired'              => __('Please enter your name.', 'dox-feedback'),
                'bld.resizeWidth'               => __('Drag to resize width', 'dox-feedback'),
                'bld.resizeHeight'              => __('Drag to resize height (divider)', 'dox-feedback'),
                'bld.dockPanel'                 => __('Dock panel', 'dox-feedback'),
                'bld.floatPanel'                => __('Float panel', 'dox-feedback'),
                'bld.dockOrFloat'               => __('Dock or float the panel', 'dox-feedback'),
                // Elementor editor host (elementor-host.js)
                'elh.fab_aria'                  => __('Feedback', 'dox-feedback'),
                'elh.fab_label'                 => __('Comments', 'dox-feedback'),
                'elh.identity_intro'            => __('You\'re commenting from this browser for the first time. Confirm the name to show on your comments — handy when your team shares one login.', 'dox-feedback'),
                'elh.identity_name_label'       => __('Your name', 'dox-feedback'),
                'elh.identity_name_placeholder' => __('Jane Smith', 'dox-feedback'),
                'elh.identity_back'             => __('Back', 'dox-feedback'),
                'elh.identity_save'             => __('Save', 'dox-feedback'),
                'elh.identity_continue'         => __('Continue', 'dox-feedback'),
                'elh.identity_error_required'   => __('Please enter your name.', 'dox-feedback'),
                'elh.brand_name'                => __('Comments', 'dox-feedback'),
                // Gutenberg editor host (gutenberg-host.js)
                'gbh.fab_aria'                  => __('Comments', 'dox-feedback'),
                'gbh.fab_label'                 => __('Comments', 'dox-feedback'),
                'gbh.identity_intro'            => __('You\'re commenting from this browser for the first time. Confirm the name to show on your comments — handy when your team shares one login.', 'dox-feedback'),
                'gbh.identity_name_label'       => __('Your name', 'dox-feedback'),
                'gbh.identity_name_placeholder' => __('Jane Smith', 'dox-feedback'),
                'gbh.identity_back'             => __('Back', 'dox-feedback'),
                'gbh.identity_save'             => __('Save', 'dox-feedback'),
                'gbh.identity_continue'         => __('Continue', 'dox-feedback'),
                'gbh.identity_error_required'   => __('Please enter your name.', 'dox-feedback'),
                'gbh.toolbar_aria'              => __('Comments', 'dox-feedback'),
                'gbh.toolbar_label'             => __('Comments', 'dox-feedback'),
                'gbh.brand_name'                => __('Comments', 'dox-feedback'),
            ] ),
        ];
    }

    /**
     * Enqueue the comment overlay inside the Elementor editor. Mirrors the
     * Bricks builder enqueue, but uses the floating-FAB host (elementor-host.js)
     * and the same overlay CSS the front-end reviewer UI uses, since Elementor
     * has no Bricks-style toolbar to dock into.
     */
    public function enqueue_elementor_editor_assets(): void {
        $post_id = 0;
        if ( class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->editor) ) {
            $post_id = (int) \Elementor\Plugin::$instance->editor->get_post_id();
        }
        if ( ! $post_id ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        }
        if ( ! $post_id || ! current_user_can('edit_post', $post_id) ) {
            return;
        }

        // Shared overlay styles (sidebar/cards/form) + floating-FAB overrides —
        // identical to the front-end reviewer UI.
        wp_enqueue_style(
            'dxf-builder',
            DXF_URL . 'assets/builder/builder.css',
            [],
            self::asset_ver('assets/builder/builder.css')
        );
        wp_enqueue_style(
            'dxf-frontend',
            DXF_URL . 'assets/frontend/review.css',
            ['dxf-builder'],
            self::asset_ver('assets/frontend/review.css')
        );

        wp_enqueue_script(
            'dxf-snapdom',
            DXF_URL . 'assets/vendor/snapdom.min.js',
            [],
            self::asset_ver('assets/vendor/snapdom.min.js'),
            true
        );
        wp_enqueue_script(
            'dxf-anchors',
            DXF_URL . 'assets/comment-engine/adapters.js',
            [],
            self::asset_ver('assets/comment-engine/adapters.js'),
            true
        );
        wp_enqueue_script(
            'dxf-comment-engine',
            DXF_URL . 'assets/comment-engine/engine.js',
            ['dxf-snapdom', 'dxf-anchors'],
            self::asset_ver('assets/comment-engine/engine.js'),
            true
        );
        wp_enqueue_script(
            'dxf-elementor-host',
            DXF_URL . 'assets/builder/elementor-host.js',
            ['dxf-comment-engine'],
            self::asset_ver('assets/builder/elementor-host.js'),
            true
        );

        wp_localize_script('dxf-elementor-host', 'dxfComments', $this->editor_localize_data($post_id));
    }

    /**
     * Enqueue the comment overlay inside the Gutenberg block editor. Restricted
     * to the post/page editor (not the site or widgets editor, which have no
     * single post to review), and uses the floating-FAB host + the same overlay
     * CSS as the front-end reviewer UI.
     */
    public function enqueue_gutenberg_editor_assets(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ( ! $screen || $screen->base !== 'post' ) {
            return; // site editor / widgets editor / etc. — no single post.
        }
        $post_id = (int) get_the_ID();
        if ( ! $post_id ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        }
        if ( ! $post_id || ! current_user_can('edit_post', $post_id) ) {
            return;
        }

        wp_enqueue_style(
            'dxf-builder',
            DXF_URL . 'assets/builder/builder.css',
            [],
            self::asset_ver('assets/builder/builder.css')
        );
        wp_enqueue_style(
            'dxf-frontend',
            DXF_URL . 'assets/frontend/review.css',
            ['dxf-builder'],
            self::asset_ver('assets/frontend/review.css')
        );

        wp_enqueue_script(
            'dxf-snapdom',
            DXF_URL . 'assets/vendor/snapdom.min.js',
            [],
            self::asset_ver('assets/vendor/snapdom.min.js'),
            true
        );
        wp_enqueue_script(
            'dxf-anchors',
            DXF_URL . 'assets/comment-engine/adapters.js',
            [],
            self::asset_ver('assets/comment-engine/adapters.js'),
            true
        );
        wp_enqueue_script(
            'dxf-comment-engine',
            DXF_URL . 'assets/comment-engine/engine.js',
            ['dxf-snapdom', 'dxf-anchors'],
            self::asset_ver('assets/comment-engine/engine.js'),
            true
        );
        wp_enqueue_script(
            'dxf-gutenberg-host',
            DXF_URL . 'assets/builder/gutenberg-host.js',
            ['dxf-comment-engine'],
            self::asset_ver('assets/builder/gutenberg-host.js'),
            true
        );

        wp_localize_script('dxf-gutenberg-host', 'dxfComments', $this->editor_localize_data($post_id));
    }

    /**
     * The accent colour used for the comment cursor, canvas pins and the
     * reviewer sidebar's primary actions. Defaults to the Dox Studio orange;
     * filterable via `dxf_accent_color` for per-site customisation.
     */
    public static function accent_color(): string {
        return apply_filters('dxf_accent_color', '#ff8d27');
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    public function ajax_get_comments(): void {
        check_ajax_referer(self::NONCE_ACTION);

        $post_id = absint($_GET['post_id'] ?? 0);
        if ( ! $post_id || ! current_user_can('edit_post', $post_id) ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
        }

        wp_send_json_success($this->get_comments_for_post($post_id));
    }

    /**
     * Return all comments across every post — builder-side only (requires login).
     * Adds `post_title` to each row so the sidebar can label cross-page comments.
     */
    public function ajax_get_all_builder_comments(): void {
        check_ajax_referer(self::NONCE_ACTION);

        if ( ! current_user_can('edit_posts') ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
        }

        global $wpdb;

        // Admin dashboard listing (edit_posts checked above). Constant query
        // with literal WHERE values — no user input; table names from $wpdb.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.*, u.display_name AS user_display_name, au.display_name AS assignee_name, p.post_title
                   FROM %i c
                   LEFT JOIN {$wpdb->users} u  ON c.author_id   = u.ID
                   LEFT JOIN {$wpdb->users} au ON c.assignee_id = au.ID
                   LEFT JOIN {$wpdb->posts}  p ON c.post_id     = p.ID
                  WHERE p.post_status IN ('publish','draft','private','pending')
                     OR p.ID IS NULL
                  ORDER BY c.created_at ASC",
                $wpdb->prefix . 'dxf_comments'
            ),
            ARRAY_A
        );

        foreach ( $rows as &$row ) {
            if ( empty($row['author_name']) && ! empty($row['user_display_name']) ) {
                $row['author_name'] = $row['user_display_name'];
            }
            unset($row['user_display_name']);

            // Bricks builder URL for cross-page navigation in the sidebar.
            $row['post_url'] = ! empty($row['post_id'])
                ? add_query_arg('bricks', 'run', get_permalink((int) $row['post_id']))
                : '';
        }
        unset($row);

        wp_send_json_success($rows);
    }

    public function ajax_add_comment(): void {
        check_ajax_referer(self::NONCE_ACTION);

        $post_id     = absint($_POST['post_id'] ?? 0);
        $element_id  = sanitize_text_field(wp_unslash($_POST['element_id'] ?? ''));
        $body        = sanitize_textarea_field(wp_unslash($_POST['body'] ?? ''));
        $parent_id   = absint($_POST['parent_id'] ?? 0) ?: null;
        // parse_anchor_data() validates the JSON structurally and sanitises
        // each field. Raw $_POST string is intentionally not run through a
        // generic sanitize_* (it's JSON — those would corrupt braces).
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $anchor_data = $this->parse_anchor_data(wp_unslash($_POST['anchor_data'] ?? ''));

        if ( ! $post_id || ! $body ) {
            wp_send_json_error(['message' => __('Missing required fields.', 'dox-feedback')], 400);
        }

        // Resolve author + authorization BEFORE any file work, so a request
        // without a valid token can never trigger a screenshot save or upload.
        $author_id    = null;
        $author_name  = '';
        $author_email = '';

        if ( is_user_logged_in() ) {
            $author_id = get_current_user_id();
            // Agencies often share ONE WP login across a whole team. A per-browser
            // "claimed name" (set via the identity prompt) is stored on the row so
            // each teammate's comments stay attributable. Empty falls back to the
            // WP display name at render time. The email is NOT taken from POST for
            // logged-in users (it would be spoofable + feeds notification Reply-To).
            $author_name = sanitize_text_field(wp_unslash($_POST['author_name'] ?? ''));
        } else {
            $token        = sanitize_text_field(wp_unslash($_POST['review_token'] ?? ''));
            $author_name  = sanitize_text_field(wp_unslash($_POST['author_name'] ?? ''));
            $author_email = sanitize_email(wp_unslash($_POST['author_email'] ?? ''));

            if ( ! $token || ! $this->validate_review_token($token, $post_id) || ! $this->post_is_reviewable($post_id) ) {
                wp_send_json_error(['message' => __('Invalid review link.', 'dox-feedback')], 403);
            }
            // Name required; email optional. When provided it must validate
            // as an email — we use it verbatim in admin notification emails,
            // so a malformed value (free-text in the email field) would
            // poison the display string. Empty is fine — notifications
            // render as "— Jane Smith" instead of "— Jane Smith (jane@…)".
            if ( ! $author_name ) {
                wp_send_json_error(['message' => __('Name is required.', 'dox-feedback')], 400);
            }
            // sanitize_email returns '' for invalid input, so an empty
            // $author_email means EITHER nothing was submitted (fine) OR
            // the input was non-empty but invalid. We re-sanitize the raw
            // POST'd value through sanitize_text_field — losslessly true
            // for any plain-text input — purely to check emptiness, so we
            // can distinguish "left it blank" from "typed something bad".
            $raw_email = sanitize_text_field(wp_unslash((string) ($_POST['author_email'] ?? '')));
            if ( $raw_email !== '' && $author_email === '' ) {
                wp_send_json_error(['message' => __('Please enter a valid email address, or leave it blank.', 'dox-feedback')], 400);
            }
            // Throttle anonymous submissions to blunt spam/abuse via a shared link.
            if ( ! $this->guest_rate_limit('comment', 30, 600) ) {
                wp_send_json_error(['message' => __('Too many submissions. Please wait a moment and try again.', 'dox-feedback')], 429);
            }
        }

        // A reply must attach to a parent on THIS page, and a guest may only
        // reply within their own review scope — the same scope their reads and
        // other writes are confined to. Without this, a reviewer on one review
        // could graft replies onto (and email the participants of) another
        // review's thread on a page the two reviews happen to share.
        if ( $parent_id ) {
            global $wpdb;
            $parent = $wpdb->get_row($wpdb->prepare(
                "SELECT post_id, review_id FROM %i WHERE id = %d LIMIT 1",
                $wpdb->prefix . 'dxf_comments', $parent_id
            ), ARRAY_A);
            $parent_ok = $parent && (int) $parent['post_id'] === $post_id;
            if ( $parent_ok && ! is_user_logged_in() ) {
                $parent_ok = $this->guest_can_write_comment($parent['review_id'] ?? 0);
            }
            if ( ! $parent_ok ) {
                wp_send_json_error(['message' => __('Invalid reply target.', 'dox-feedback')], 400);
            }
        }

        // Read-only guard: a review can be flagged read-only (existing comments
        // stay visible, no new ones accepted) — e.g. by an add-on for the review
        // types it manages. This is the enforcement boundary (a client could POST
        // straight to admin-ajax, bypassing the front-end flag). Single-page link
        // reviews are never read-only. Checked before any file work so a
        // read-only review can't trigger uploads.
        $active_review_id = self::resolve_active_review_id();
        if ( $active_review_id && class_exists('DXF_Review') ) {
            $active_review = DXF_Review::get($active_review_id);
            if ( $active_review && DXF_Review::is_read_only($active_review) ) {
                wp_send_json_error(['message' => __('This review is read-only and isn\'t accepting new comments.', 'dox-feedback')], 403);
            }
        }

        // Extensible gate: add-ons (e.g. the demo launcher) can refuse a new
        // comment — return false, or a string to use as the error message —
        // for per-review caps, quotas, etc. Checked before any file work so a
        // rejected comment never triggers an upload. Authoritative: a client
        // POSTing straight to admin-ajax still hits this.
        $gate = apply_filters('dxf_pre_add_comment', true, $post_id, $active_review_id, $parent_id);
        if ( $gate !== true ) {
            wp_send_json_error([
                'message' => is_string($gate) && $gate !== ''
                    ? $gate
                    : __('This review isn\'t accepting new comments right now.', 'dox-feedback'),
            ], 403);
        }

        // Prefer a pre-uploaded screenshot URL (fast path — JS uploads immediately
        // after capture so the URL is ready by submit time). Fall back to an
        // inline data URL for any legacy/guest callers that still send base64.
        $shot_url = '';
        $pre_url  = esc_url_raw(wp_unslash($_POST['screenshot_url'] ?? ''));
        if ( $pre_url ) {
            $uploads  = wp_upload_dir();
            $base_url = trailingslashit($uploads['baseurl']) . 'dxf/';
            if ( strpos($pre_url, $base_url) === 0 ) {
                $shot_url = $pre_url;
            }
        }
        if ( ! $shot_url ) {
            // save_screenshot() decodes a data URL, validates the mime, and
            // discards anything that isn't a real PNG/JPEG. Raw binary base64
            // payload must NOT be run through sanitize_* (would mangle bytes).
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $shot_url = $this->save_screenshot(wp_unslash($_POST['screenshot'] ?? ''), $post_id);
        }
        if ( $shot_url ) {
            $anchor_data['screenshot'] = $shot_url;
        }

        // Optional file attachments (any WP-allowed mime), saved to /uploads/dxf/.
        // Uploads can be disabled per review (e.g. the public demo, where letting
        // anonymous visitors upload files is an abuse vector) via the filter.
        $allow_uploads = (bool) apply_filters('dxf_allow_comment_uploads', true, $post_id, $active_review_id);
        $attachments   = $allow_uploads ? $this->handle_uploads() : [];
        if ( $attachments ) {
            $anchor_data['attachments'] = $attachments;
        }

        // Auto-captured environment context (browser, viewport, JS errors).
        // parse_context() validates the JSON shape and sanitises each field.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $context = $this->parse_context(wp_unslash($_POST['context'] ?? ''));
        if ( ! empty($context['ua']) || ! empty($context['viewport']) ) {
            $anchor_data['context'] = $context;
        }

        $id = $this->insert_comment([
            'post_id'      => $post_id,
            'element_id'   => $element_id,
            'parent_id'    => $parent_id,
            'author_id'    => $author_id,
            'author_name'  => $author_name,
            'author_email' => $author_email,
            'body'         => $body,
            'anchor_data'  => $anchor_data,
            'round'        => $this->current_round($post_id),
            'review_id'    => $active_review_id,
        ]);

        if ( ! $id ) {
            wp_send_json_error(['message' => __('Could not save comment.', 'dox-feedback')], 500);
        }

        // Top-level comments → queue (or send immediately) a "new comment" email.
        if ( ! $parent_id ) {
            $kind = is_user_logged_in() ? 'builder' : 'review';
            $this->dispatch_comment_notification((int) $post_id, (int) $id, $kind);
            // A reviewer leaving NEW feedback un-marks any "Reviewed" state on
            // this page, so the dashboard badge stays honest ("Reviewed" =
            // no pending new feedback). Comments left BEFORE marking reviewed
            // never trigger this — the page isn't reviewed yet at that point.
            if ( $kind === 'review' && class_exists('DXF_Review') ) {
                DXF_Review::clear_reviewed_for_post((int) $post_id);
            }
        }
        // Replies → notify thread participants who are WP users (respects opt-out).
        if ( $parent_id ) {
            $this->notify_thread_reply((int) $post_id, (int) $parent_id, (int) $id);
        }

        // Fire an event any listener can hook (e.g. an outbound integration).
        $display = $author_name;
        if ( is_user_logged_in() && ! $display ) {
            $u       = wp_get_current_user();
            $display = $u->display_name ?: $u->user_login;
        }
        DXF_Events::emit($parent_id ? 'comment.replied' : 'comment.created', [
            'post_id'     => $post_id,
            'page_title'  => get_the_title($post_id),
            'page_url'    => get_permalink($post_id) ?: '',
            'author_name' => $display ?: 'Reviewer',
            'body'        => $body,
            'is_guest'    => ! is_user_logged_in(),
        ]);

        // Async AI triage for new top-level comments (no-op unless the AI
        // module is present + enabled).
        if ( ! $parent_id && class_exists('DXF_AI') ) {
            DXF_AI::queue_triage($id);
        }

        wp_send_json_success(['id' => $id]);
    }

    public function ajax_delete_comment(): void {
        check_ajax_referer(self::NONCE_ACTION);

        $id = absint($_POST['id'] ?? 0);
        if ( ! $id ) {
            wp_send_json_error(['message' => __('Invalid comment ID.', 'dox-feedback')], 400);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'dxf_comments';

        $comment = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d LIMIT 1", $table, $id),
            ARRAY_A
        );

        if ( ! $comment ) {
            wp_send_json_error(['message' => __('Comment not found.', 'dox-feedback')], 404);
        }

        if ( is_user_logged_in() ) {
            // Logged-in users can delete their own comments; editors can delete any.
            if (
                (int) $comment['author_id'] !== get_current_user_id() &&
                ! current_user_can('edit_posts')
            ) {
                wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
            }
        } else {
            // Guest reviewers must supply a valid token + matching author credentials.
            $token        = sanitize_text_field(wp_unslash($_POST['review_token'] ?? ''));
            $author_name  = sanitize_text_field(wp_unslash($_POST['author_name'] ?? ''));
            $author_email = sanitize_email(wp_unslash($_POST['author_email'] ?? ''));

            if (
                ! $token ||
                ! $this->validate_review_token($token, (int) $comment['post_id']) ||
                ! $this->post_is_reviewable((int) $comment['post_id']) ||
                ! $this->guest_can_write_comment($comment['review_id'] ?? 0) ||
                $comment['author_name']  !== $author_name ||
                $comment['author_email'] !== $author_email
            ) {
                wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
            }
        }

        // Remove uploaded files belonging to this comment and its replies before
        // deleting the rows, so screenshots/attachments don't orphan on disk.
        $this->delete_comment_files(json_decode((string) $comment['anchor_data'], true));
        $reply_anchors = $wpdb->get_col($wpdb->prepare(
            "SELECT anchor_data FROM %i WHERE parent_id = %d",
            $table, $id
        ));
        foreach ( $reply_anchors as $ra ) {
            $this->delete_comment_files(json_decode((string) $ra, true));
        }

        // Reactions cascade with the thread: collect reply ids BEFORE the rows
        // go, then clear reactions for the root + replies.
        $reaction_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM %i WHERE parent_id = %d",
            $table, $id
        ));
        $reaction_ids[] = $id;
        foreach ( $reaction_ids as $rid ) {
            $wpdb->delete($wpdb->prefix . 'dxf_comment_reactions', ['comment_id' => (int) $rid], ['%d']);
        }

        // Delete the comment and any replies it has.
        $wpdb->delete($table, ['parent_id' => $id], ['%d']);
        $wpdb->delete($table, ['id'        => $id], ['%d']);

        wp_send_json_success();
    }

    /**
     * Edit the BODY TEXT of an existing comment. Only the comment's own author
     * may edit it: a logged-in user matched by author_id, a guest matched by
     * token + author_name + author_email (and only on a guest-authored row).
     * Editors are intentionally NOT given blanket edit-anyone's-words power —
     * status changes and deletion already cover moderation. Status, anchor,
     * screenshots and attachments are untouched; updated_at auto-bumps so the
     * live-poll picks the change up for everyone else.
     */
    public function ajax_edit_comment(): void {
        check_ajax_referer(self::NONCE_ACTION);

        $id   = absint($_POST['id'] ?? 0);
        $body = sanitize_textarea_field(wp_unslash($_POST['body'] ?? ''));
        if ( ! $id || $body === '' ) {
            wp_send_json_error(['message' => __('Missing required fields.', 'dox-feedback')], 400);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'dxf_comments';

        $comment = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d LIMIT 1", $table, $id),
            ARRAY_A
        );
        if ( ! $comment ) {
            wp_send_json_error(['message' => __('Comment not found.', 'dox-feedback')], 404);
        }

        if ( is_user_logged_in() ) {
            if ( $comment['author_id'] === null || (int) $comment['author_id'] !== get_current_user_id() ) {
                wp_send_json_error(['message' => __('You can only edit your own comments.', 'dox-feedback')], 403);
            }
        } else {
            $token        = sanitize_text_field(wp_unslash($_POST['review_token'] ?? ''));
            $author_name  = sanitize_text_field(wp_unslash($_POST['author_name'] ?? ''));
            $author_email = sanitize_email(wp_unslash($_POST['author_email'] ?? ''));
            if (
                ! $token ||
                ! $this->validate_review_token($token, (int) $comment['post_id']) ||
                ! $this->post_is_reviewable((int) $comment['post_id']) ||
                ! $this->guest_can_write_comment($comment['review_id'] ?? 0) ||
                $comment['author_id'] !== null ||
                $comment['author_name']  !== $author_name ||
                $comment['author_email'] !== $author_email
            ) {
                wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
            }
        }

        // A read-only review accepts no edits either (mirrors the add guard).
        if ( class_exists('DXF_Review') ) {
            $rid = (int) ($comment['review_id'] ?? 0);
            if ( $rid ) {
                $rev = DXF_Review::get($rid);
                if ( $rev && DXF_Review::is_read_only($rev) ) {
                    wp_send_json_error(['message' => __('This review is read-only.', 'dox-feedback')], 403);
                }
            }
        }

        $wpdb->update($table, ['body' => $body], ['id' => $id], ['%s'], ['%d']);
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT updated_at FROM %i WHERE id = %d", $table, $id),
            ARRAY_A
        );

        wp_send_json_success([
            'id'         => $id,
            'body'       => $body,
            'updated_at' => $row['updated_at'] ?? '',
        ]);
    }

    /** Unlink a comment's screenshot + attachments, confined to /uploads/dxf/. */
    private function delete_comment_files( $anchor ): void {
        if ( ! is_array($anchor) ) {
            return;
        }
        $uploads = wp_upload_dir();
        if ( ! empty($uploads['error']) ) {
            return;
        }
        $base_url = trailingslashit($uploads['baseurl']) . 'dxf/';
        $base_dir = trailingslashit($uploads['basedir']) . 'dxf/';

        $urls = [];
        if ( ! empty($anchor['screenshot']) ) {
            $urls[] = (string) $anchor['screenshot'];
        }
        if ( ! empty($anchor['attachments']) && is_array($anchor['attachments']) ) {
            foreach ( $anchor['attachments'] as $att ) {
                if ( ! empty($att['url']) ) {
                    $urls[] = (string) $att['url'];
                }
            }
        }
        foreach ( $urls as $url ) {
            // Only ever touch files inside our own uploads subdirectory.
            if ( strpos($url, $base_url) !== 0 ) {
                continue;
            }
            $path = $base_dir . basename($url);
            if ( is_file($path) ) {
                wp_delete_file($path);
            }
        }

        // Force the storage total to recompute now space has been freed.
        delete_transient('dxf_storage_bytes');
    }

    /**
     * Soft storage guardrail: returns true when /uploads/dxf/ has reached the
     * cap (default 2 GB, filterable; 0 = unlimited). The total is cached in a
     * transient so we don't scan the directory on every request.
     */
    private function storage_over_quota(): bool {
        $cap_mb = (int) apply_filters('dxf_storage_max_mb', 2048);
        if ( $cap_mb <= 0 ) {
            return false;
        }
        $bytes = get_transient('dxf_storage_bytes');
        if ( $bytes === false ) {
            $uploads = wp_upload_dir();
            $dir     = trailingslashit($uploads['basedir']) . 'dxf';
            $bytes   = 0;
            if ( is_dir($dir) ) {
                foreach ( (array) glob($dir . '/*') as $f ) {
                    if ( is_file($f) ) {
                        $bytes += (int) filesize($f);
                    }
                }
            }
            set_transient('dxf_storage_bytes', $bytes, 5 * MINUTE_IN_SECONDS);
        }
        return (int) $bytes >= $cap_mb * 1024 * 1024;
    }

    public function ajax_resolve_comment(): void {
        check_ajax_referer(self::NONCE_ACTION);

        $id     = absint($_POST['id'] ?? 0);
        $req    = sanitize_key(wp_unslash($_POST['status'] ?? ''));
        $status = in_array($req, ['open', 'in_progress', 'resolved'], true) ? $req : 'open';

        if ( ! $id ) {
            wp_send_json_error(['message' => __('Invalid comment.', 'dox-feedback')], 400);
        }

        global $wpdb;

        $c_row = $wpdb->get_row($wpdb->prepare(
            "SELECT post_id, review_id FROM %i WHERE id = %d",
            $wpdb->prefix . 'dxf_comments', $id
        ), ARRAY_A);
        if ( ! $c_row ) {
            wp_send_json_error(['message' => __('Comment not found.', 'dox-feedback')], 404);
        }
        $c_post = (int) $c_row['post_id'];

        // Logged-in users need edit rights on the post; guests need a valid
        // token for a page that is actually shared for review AND the comment
        // must belong to their own active review (see guest_can_write_comment()).
        if ( is_user_logged_in() ) {
            if ( ! current_user_can('edit_post', $c_post) ) {
                wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
            }
        } else {
            $token = sanitize_text_field(wp_unslash($_POST['review_token'] ?? ''));
            if ( ! $token || ! $this->validate_review_token($token, $c_post)
                || ! $this->guest_can_write_comment($c_row['review_id']) ) {
                wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
            }
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'dxf_comments',
            ['status' => $status],
            ['id'     => $id],
            ['%s'],
            ['%d']
        );

        $updated !== false
            ? wp_send_json_success()
            : wp_send_json_error(['message' => __('Update failed.', 'dox-feedback')], 500);
    }

    // -------------------------------------------------------------------------
    // Emoji reactions — one-tap acknowledgements on comments/replies
    // -------------------------------------------------------------------------

    /** Reaction keys the server accepts; the UI maps them to emoji. */
    private const REACTIONS = ['thumbs_up', 'heart', 'check', 'eyes'];

    /**
     * Fixed-width identity key for reaction rows: logged-in users key on their
     * user id; guests on their lowercased name|email pair — the same loose
     * identity the guest edit/delete flow already uses. Only the hash is ever
     * stored or compared; reactor identities never leave the server.
     */
    private function reaction_author_key(string $name, string $email): string {
        if ( is_user_logged_in() ) {
            return md5('u:' . get_current_user_id());
        }
        return md5('g:' . strtolower(trim($name)) . '|' . strtolower(trim($email)));
    }

    /**
     * The requester's reaction identity, derived from the same author_name/
     * author_email hints the comment flow sends. Identity hints are NEVER
     * trusted for authorisation — they only decide which "mine" flags light
     * up; auth is the nonce/capability/token check in each endpoint.
     */
    private function current_reaction_key(): string {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $name  = sanitize_text_field(wp_unslash($_POST['author_name'] ?? $_GET['author_name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['author_email'] ?? $_GET['author_email'] ?? ''));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        return $this->reaction_author_key($name, $email);
    }

    /**
     * Aggregate reactions for a set of comment ids.
     * Returns [comment_id => [['reaction','count','mine'], …]] — counts plus
     * whether the caller reacted, nothing else.
     */
    private function reactions_for_comments(array $comment_ids, string $author_key): array {
        $comment_ids = array_values(array_filter(array_map('intval', $comment_ids)));
        if ( ! $comment_ids ) {
            return [];
        }
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($comment_ids), '%d'));
        $sql = "SELECT comment_id, reaction, COUNT(*) AS cnt,
                       MAX(CASE WHEN author_key = %s THEN 1 ELSE 0 END) AS mine
                  FROM %i
                 WHERE comment_id IN ({$placeholders})
                 GROUP BY comment_id, reaction
                 ORDER BY reaction ASC";
        $params = array_merge([$author_key, $wpdb->prefix . 'dxf_comment_reactions'], $comment_ids);

        // Constant SQL fragments + a dynamically-sized placeholder list, all
        // bound via prepare() (see the file-level ReplacementsWrongNumber note).
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        $out = [];
        foreach ( $rows as $r ) {
            $out[(int) $r['comment_id']][] = [
                'reaction' => (string) $r['reaction'],
                'count'    => (int) $r['cnt'],
                'mine'     => (bool) (int) $r['mine'],
            ];
        }
        return $out;
    }

    public function ajax_toggle_reaction(): void {
        check_ajax_referer(self::NONCE_ACTION);

        $id       = absint($_POST['id'] ?? 0);
        $reaction = sanitize_key(wp_unslash($_POST['reaction'] ?? ''));
        $name     = sanitize_text_field(wp_unslash($_POST['author_name'] ?? ''));
        $email    = sanitize_email(wp_unslash($_POST['author_email'] ?? ''));

        if ( ! $id || ! in_array($reaction, self::REACTIONS, true) ) {
            wp_send_json_error(['message' => __('Invalid reaction.', 'dox-feedback')], 400);
        }

        global $wpdb;

        $c_row = $wpdb->get_row($wpdb->prepare(
            "SELECT post_id, review_id FROM %i WHERE id = %d",
            $wpdb->prefix . 'dxf_comments', $id
        ), ARRAY_A);
        if ( ! $c_row ) {
            wp_send_json_error(['message' => __('Comment not found.', 'dox-feedback')], 404);
        }
        $c_post = (int) $c_row['post_id'];

        // Same auth split as status changes: logged-in users need edit rights
        // on the post; guests need a valid token scoped to that page, the
        // comment must be in their own active review (see guest_can_write_comment()),
        // plus a name so the reaction has an identity to dedupe on.
        if ( is_user_logged_in() ) {
            if ( ! current_user_can('edit_post', $c_post) ) {
                wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
            }
            if ( $name === '' ) {
                $user = wp_get_current_user();
                $name = $user->display_name ?: $user->user_login;
            }
        } else {
            $token = sanitize_text_field(wp_unslash($_POST['review_token'] ?? ''));
            if ( ! $token || ! $this->validate_review_token($token, $c_post) || ! $this->post_is_reviewable($c_post)
                || ! $this->guest_can_write_comment($c_row['review_id']) ) {
                wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
            }
            if ( ! $this->guest_rate_limit('react', 60, 10 * MINUTE_IN_SECONDS) ) {
                wp_send_json_error(['message' => __('Too many actions — please slow down.', 'dox-feedback')], 429);
            }
            if ( $name === '' ) {
                wp_send_json_error(['message' => __('Please add your name before reacting.', 'dox-feedback')], 400);
            }
        }

        $key   = $this->reaction_author_key($name, $email);
        $table = $wpdb->prefix . 'dxf_comment_reactions';

        // Toggle: remove an existing row first; if none was there, insert one.
        // The UNIQUE key (comment_id, reaction, author_key) makes the insert
        // race-safe under concurrent taps — INSERT IGNORE swallows the dupe.
        $deleted = $wpdb->delete(
            $table,
            ['comment_id' => $id, 'reaction' => $reaction, 'author_key' => $key],
            ['%d', '%s', '%s']
        );
        if ( ! $deleted ) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO %i (comment_id, reaction, author_id, author_key, author_name, created_at)
                 VALUES (%d, %s, %d, %s, %s, %s)",
                $table, $id, $reaction, get_current_user_id(), $key, $name,
                current_time('mysql', true)
            ));
        }

        wp_send_json_success([
            'id'        => $id,
            'reactions' => $this->reactions_for_comments([$id], $key)[ $id ] ?? [],
        ]);
    }

    /** The current (latest) review round for a post. Defaults to 1. */
    private function current_round( int $post_id ): int {
        $r = (int) get_post_meta($post_id, '_dxf_round', true);
        return $r > 0 ? $r : 1;
    }

    /**
     * Active reviews surfaced to the in-builder Reviews picker. Returns a
     * lightweight {id, name, status} list, capped — the sidebar dropdown
     * isn't meant to scale to hundreds of items. Includes 'draft' as well
     * as 'active' because draft reviews may have already collected
     * builder-side comments under their context.
     */
    public static function active_reviews_for_picker(): array {
        if ( ! class_exists('DXF_Review') ) {
            return [];
        }
        $rows = array_merge(
            DXF_Review::find(['status' => DXF_Review::STATUS_ACTIVE, 'per_page' => 50]),
            DXF_Review::find(['status' => 'draft', 'per_page' => 25])
        );
        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [
                'id'     => (int) $r['id'],
                'name'   => (string) ($r['name'] ?: ('Review #' . (int) $r['id'])),
                'status' => (string) $r['status'],
            ];
        }
        return $out;
    }

    /** Editors/admins who can be assigned a comment. */
    private function assignable_users(): array {
        $users = get_users([
            'capability' => 'edit_posts',
            'fields'     => ['ID', 'display_name'],
            'number'     => 100,
            'orderby'    => 'display_name',
        ]);
        return array_map(static function ($u) {
            return ['id' => (int) $u->ID, 'name' => $u->display_name];
        }, $users);
    }

    /**
     * Move a comment into (or out of) a Review by updating its review_id.
     * Used by the per-comment "Assign to Review" mini dropdown that
     * appears beside the number badge when a comment is orphaned. The
     * target Review must include the comment's page; otherwise the
     * assignment is silently rejected so we don't end up with comments
     * showing inside a Review whose scope no longer covers their page.
     */
    public function ajax_set_comment_review(): void {
        check_ajax_referer(self::NONCE_ACTION);

        $id        = absint($_POST['id'] ?? 0);
        $review_id = absint($_POST['review_id'] ?? 0); // 0 = detach

        if ( ! $id ) {
            wp_send_json_error(['message' => __('Invalid comment.', 'dox-feedback')], 400);
        }

        global $wpdb;
        $table   = $wpdb->prefix . 'dxf_comments';
        $post_id = (int) $wpdb->get_var($wpdb->prepare("SELECT post_id FROM %i WHERE id = %d", $table, $id));

        if ( ! $post_id || ! current_user_can('edit_post', $post_id) ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
        }

        if ( $review_id > 0 ) {
            $review = class_exists('DXF_Review') ? DXF_Review::get($review_id) : null;
            if ( ! $review ) {
                wp_send_json_error(['message' => __('Review not found.', 'dox-feedback')], 404);
            }
            $allowed = DXF_Review::resolve_post_ids($review);
            if ( ! in_array($post_id, $allowed, true) ) {
                wp_send_json_error([
                    'message' => __("That Review doesn't include this page.", 'dox-feedback'),
                ], 400);
            }
        }

        $wpdb->update(
            $table,
            ['review_id'  => $review_id ?: null],
            ['id'         => $id],
            [$review_id ? '%d' : '%s'],
            ['%d']
        );

        wp_send_json_success(['review_id' => $review_id]);
    }

    /**
     * Copy a Dox Feedback screenshot/attachment into the WP Media Library so it can be
     * reused on the site. Only files inside /uploads/dxf/ may be imported.
     */
    public function ajax_import_to_media(): void {
        check_ajax_referer(self::NONCE_ACTION);

        if ( ! current_user_can('upload_files') ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
        }

        $url     = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
        $uploads = wp_upload_dir();

        // Restrict to our own screenshots directory — never import arbitrary URLs.
        $base_url = trailingslashit($uploads['baseurl']) . 'dxf/';
        if ( ! $url || strpos($url, $base_url) !== 0 ) {
            wp_send_json_error(['message' => __('Only Dox Feedback attachments can be imported.', 'dox-feedback')], 400);
        }

        $source = trailingslashit($uploads['basedir']) . 'dxf/' . basename($url);
        if ( ! file_exists($source) ) {
            wp_send_json_error(['message' => __('File not found.', 'dox-feedback')], 404);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';

        $filename = wp_unique_filename($uploads['path'], basename($source));
        $dest     = trailingslashit($uploads['path']) . $filename;
        if ( ! @copy($source, $dest) ) {
            wp_send_json_error(['message' => __('Could not copy file.', 'dox-feedback')], 500);
        }

        $filetype   = wp_check_filetype($filename, null);
        $attach_id  = wp_insert_attachment([
            'post_mime_type' => $filetype['type'],
            'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $dest);

        if ( is_wp_error($attach_id) || ! $attach_id ) {
            wp_send_json_error(['message' => __('Import failed.', 'dox-feedback')], 500);
        }

        wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $dest));

        wp_send_json_success([
            'id'  => $attach_id,
            'url' => wp_get_attachment_url($attach_id),
        ]);
    }

    public function ajax_get_public_comments(): void {
        // Guest endpoint: authentication is the review token, not a WP nonce.
        // validate_review_token() rate-limits + hashes failures (see below).
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $token   = sanitize_text_field(wp_unslash($_GET['token'] ?? $_POST['token'] ?? ''));
        $post_id = absint($_GET['post_id'] ?? $_POST['post_id'] ?? 0);
        // phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing

        if (
            ! $token || ! $post_id ||
            ! $this->validate_review_token($token, $post_id) ||
            ! $this->post_is_reviewable($post_id)
        ) {
            wp_send_json_error(['message' => __('Invalid review link.', 'dox-feedback')], 403);
        }

        // Scope to the reviewer's active review so a client never sees another
        // client's review (or the agency's builder-direct comments) on a page
        // they happen to share. resolve_active_review_id() returns the SAME id
        // that ajax_add_comment() stamps onto new comments, so a reviewer sees
        // exactly the comments their own session can create — no more, no less.
        $rows = $this->get_comments_for_post($post_id, self::resolve_active_review_id());

        // Strip sensitive / internal fields before sending to guests.
        $rows = array_map(function (array $row): array {
            unset($row['author_email'], $row['author_id'], $row['assignee_id'], $row['assignee_name']);
            return $row;
        }, $rows);

        wp_send_json_success($rows);
    }

    /**
     * Public, token-authenticated comment list. A review token authorises exactly
     * ONE page, so — to prevent any cross-tenant leakage — this returns only the
     * comments for the token's own post. (The guest "Everything" scope is
     * intentionally limited to that page; site-wide review needs a future
     * project/client grouping model.)
     */
    public function ajax_get_public_all_comments(): void {
        // Guest endpoint — auth is the review token, not a WP nonce.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? $_POST['token'] ?? ''));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( ! $token || ! $this->validate_review_token($token, 0) ) {
            wp_send_json_error(['message' => __('Invalid review link.', 'dox-feedback')], 403);
        }

        global $wpdb;
        $post_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM %i
              WHERE token = %s AND revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > NOW())
              LIMIT 1",
            $wpdb->prefix . 'dxf_review_tokens', $token
        ));
        if ( ! $post_id ) {
            wp_send_json_success([]);
        }

        $rows = array_map(function (array $row): array {
            unset($row['author_email'], $row['author_id'], $row['assignee_id'], $row['assignee_name']);
            $row['post_url'] = ! empty($row['post_id']) ? get_permalink((int) $row['post_id']) : '';
            return $row;
        }, $this->get_comments_for_post($post_id, self::resolve_active_review_id()));

        wp_send_json_success($rows);
    }

    /**
     * A post is "reviewable" by guests only if it currently has at least one
     * active (non-revoked, unexpired) review token — i.e. it was explicitly
     * shared for review. This scopes the guest attack surface to shared pages
     * and prevents a single token from reaching comments on never-shared pages.
     */
    private function post_is_reviewable(int $post_id): bool {
        if ( ! $post_id ) {
            return false;
        }
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i
              WHERE post_id = %d AND revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > NOW())
              LIMIT 1",
            $wpdb->prefix . 'dxf_review_tokens', $post_id
        ));
    }

    /**
     * Lightweight per-IP rate limiter for unauthenticated reviewer actions.
     * Returns false when the caller has exceeded $max actions within $window
     * seconds. Backed by a transient; best-effort (not a hard guarantee under
     * object-cache flushes) but enough to blunt abuse via a shared review link.
     */
    private function guest_rate_limit(string $bucket, int $max, int $window): bool {
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $key = 'dxf_rl_' . $bucket . '_' . md5($ip);
        $n   = (int) get_transient($key);
        if ( $n >= $max ) {
            return false;
        }
        set_transient($key, $n + 1, $window);
        return true;
    }

    /**
     * Validate a review token. When $post_id > 0 the token MUST belong to that
     * post — this scopes a guest to the single page their link was issued for and
     * prevents one client's token from reaching another client's pages on a
     * multi-tenant install. Pass 0 only for pure validity checks (no post scope).
     */
    private function validate_review_token(string $token, int $post_id): bool {
        if ( $token === '' ) {
            return false;
        }

        // ── Rate-limit defensive shield ──────────────────────────────────
        // Tokens are 48-char hex (192-bit entropy) so a real brute-force is
        // infeasible. This shield is a belt-and-braces measure: once an IP
        // has produced ~30 bad lookups in a 5-minute window, short-circuit
        // further attempts without hitting the DB. Salted hash so we never
        // store the raw IP in a transient key.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $ip_hash  = $ip !== '' ? substr(hash('sha256', $ip . wp_salt()), 0, 16) : 'unknown';
        $fail_key = 'dxf_token_fails_' . $ip_hash;
        $fails    = (int) get_transient($fail_key);
        if ( $fails >= 30 ) {
            return false;
        }

        global $wpdb;

        $sql    = "SELECT id FROM %i
                     WHERE token = %s AND revoked_at IS NULL
                       AND (expires_at IS NULL OR expires_at > NOW())";
        $params = [$wpdb->prefix . 'dxf_review_tokens', $token];

        if ( $post_id > 0 ) {
            $sql     .= ' AND post_id = %d';
            $params[] = $post_id;
        }
        $sql .= ' LIMIT 1';

        // $sql is assembled from constant SQL fragments + placeholders only.
        // Table name interpolated above is $wpdb->prefix-prefixed (see line 786).
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $valid = (bool) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );

        if ( $valid ) {
            // Reset the failure counter for this IP on success — a legitimate
            // reviewer whose link works shouldn't be penalised for prior typos.
            delete_transient($fail_key);
        } else {
            set_transient($fail_key, $fails + 1, 5 * MINUTE_IN_SECONDS);
        }
        return $valid;
    }

    // -------------------------------------------------------------------------
    // Data helpers
    // -------------------------------------------------------------------------

    private function get_comments_for_post(int $post_id, int $review_scope = self::REVIEW_SCOPE_ALL): array {
        global $wpdb;

        // Reviewer-facing reads pass an active review id so a client only ever
        // sees comments belonging to the review they're in. 0 narrows to the
        // legacy/unscoped bucket (review_id IS NULL) — what a pre-Reviews
        // single-page token reviewer should see. REVIEW_SCOPE_ALL (builder/
        // admin) keeps the full, unfiltered set.
        $where  = 'c.post_id = %d';
        $params = [ $post_id ];
        if ( $review_scope === 0 ) {
            $where .= ' AND c.review_id IS NULL';
        } elseif ( $review_scope > 0 ) {
            $where  .= ' AND c.review_id = %d';
            $params[] = $review_scope;
        }

        $sql = "SELECT c.*,
                       u.display_name  AS user_display_name,
                       au.display_name AS assignee_name
                  FROM %i c
                  LEFT JOIN {$wpdb->users} u  ON c.author_id   = u.ID
                  LEFT JOIN {$wpdb->users} au ON c.assignee_id = au.ID
                 WHERE {$where}
                 ORDER BY c.created_at ASC";
        array_unshift($params, $wpdb->prefix . 'dxf_comments');

        // $sql is constant fragments + placeholders; $params feeds prepare().
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        // Resolve the display name: prefer stored guest name, fall back to WP user.
        foreach ( $rows as &$row ) {
            if ( empty($row['author_name']) && ! empty($row['user_display_name']) ) {
                $row['author_name'] = $row['user_display_name'];
            }
            unset($row['user_display_name']);
        }
        unset($row);

        // Attach aggregated emoji reactions (counts + whether the requester
        // reacted). Identity hints only personalise the "mine" flags — the
        // caller was already authorised by the endpoint that got us here.
        $react = $this->reactions_for_comments(array_column($rows, 'id'), $this->current_reaction_key());
        foreach ( $rows as &$row ) {
            $row['reactions'] = $react[ (int) $row['id'] ] ?? [];
        }
        unset($row);

        return $rows;
    }

    private function insert_comment(array $data): int|false {
        global $wpdb;

        $review_id = isset($data['review_id']) ? (int) $data['review_id'] : 0;
        $row = [
            'post_id'      => $data['post_id'],
            'element_id'   => $data['element_id'],
            'parent_id'    => $data['parent_id'],
            'author_id'    => $data['author_id'],
            'author_name'  => $data['author_name'],
            'author_email' => $data['author_email'],
            'body'         => $data['body'],
            'status'       => 'open',
            'anchor_data'  => wp_json_encode($data['anchor_data']),
            'round'        => $data['round'] ?? 1,
            'review_id'    => $review_id ?: null,
        ];
        $formats = ['%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', $review_id ? '%d' : '%s'];

        $inserted = $wpdb->insert($wpdb->prefix . 'dxf_comments', $row, $formats);

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Resolve the active Review id for the current request, or 0 if none.
     *
     * Strategy (cheapest → most reliable):
     *  1. Review-session cookie (DXF_Review_Mode::SESSION_COOKIE) — the
     *     slug of an open Review the reviewer is signed into.
     *  2. DXF_Review_Session_Bridge — short-lived IP+UA transient set
     *     when the reviewer first lands on a Review page; covers cases
     *     where the cookie wasn't yet readable on the comment POST.
     *
     * Builder/editor requests (logged-in WP users posting via the builder
     * adapter) intentionally return 0 — they aren't acting inside a Review.
     */
    public static function resolve_active_review_id(): int {
        if ( ! class_exists('DXF_Review') ) {
            return 0;
        }
        if ( class_exists('DXF_Review_Mode') ) {
            $cookie = DXF_Review_Mode::SESSION_COOKIE;
            $slug   = isset($_COOKIE[$cookie]) ? sanitize_text_field(wp_unslash($_COOKIE[$cookie])) : '';
            if ( $slug !== '' && preg_match('/^[a-f0-9]{16,64}$/', $slug) ) {
                $review = DXF_Review::get_by_slug($slug);
                if ( $review && DXF_Review::is_open($review) ) {
                    return (int) $review['id'];
                }
            }
        }
        if ( class_exists('DXF_Review_Session_Bridge') ) {
            $ctx = DXF_Review_Session_Bridge::get_context();
            if ( is_array($ctx) && ! empty($ctx['review_id']) ) {
                return (int) $ctx['review_id'];
            }
        }
        return 0;
    }

    /**
     * May a token-authenticated guest WRITE to this specific comment?
     *
     * A review token proves access to a PAGE, not membership of a particular
     * review. Guest READS are already scoped to the reviewer's active review
     * (see get_comments_for_post()), but the write handlers only checked that
     * the token was valid for the comment's page — so a reviewer holding a
     * valid token for a page shared by two reviews could edit/resolve/react to
     * the OTHER review's comments on that page. Comment ids are sequential and
     * enumerable, so that was a cross-tenant IDOR. This applies the identical
     * scope to writes: the comment's review_id must match what this reviewer's
     * session is allowed to see.
     *
     *   - Active review session (resolve_active_review_id() > 0): the comment
     *     must belong to that exact review.
     *   - No active review session (legacy single-page link): only the
     *     unscoped bucket (review_id IS NULL/0) is writable — mirrors
     *     get_comments_for_post(0).
     *
     * Only guest (token) writes call this; logged-in builder users are
     * authorised by edit_post on the page and legitimately span every review.
     *
     * @param int|string|null $comment_review_id The comment row's stored review_id.
     */
    private function guest_can_write_comment( $comment_review_id ): bool {
        $scope  = self::resolve_active_review_id();
        $target = (int) $comment_review_id; // NULL / '' → 0 (legacy bucket)
        if ( $scope > 0 ) {
            return $target === $scope;
        }
        return $target === 0;
    }

    /**
     * Total comments stored across all reviews/pages. Read API for addons
     * (the Pro trial progress banner); a plain table count, no filtering.
     */
    public static function count_all(): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $wpdb->prefix . 'dxf_comments' ) );
    }

    /**
     * Sanitize the auto-captured browser/environment context sent with a comment.
     * All values are attacker-controllable (a UA string can be spoofed), so every
     * field is sanitized, length-capped, and the error list is bounded.
     */
    private function parse_context(mixed $raw): array {
        $decoded = is_string($raw) ? json_decode(wp_unslash($raw), true) : $raw;
        if ( ! is_array($decoded) ) {
            return [];
        }

        $bp        = (string) ($decoded['breakpoint'] ?? '');
        $allowedBp = ['Mobile', 'Tablet', 'Desktop'];

        $out = [
            'ua'         => mb_substr(sanitize_text_field((string) ($decoded['ua'] ?? '')), 0, 300),
            'platform'   => mb_substr(sanitize_text_field((string) ($decoded['platform'] ?? '')), 0, 60),
            'viewport'   => mb_substr(sanitize_text_field((string) ($decoded['viewport'] ?? '')), 0, 24),
            'breakpoint' => in_array($bp, $allowedBp, true) ? $bp : '',
            'dpr'        => round((float) ($decoded['dpr'] ?? 1), 2),
            'url'        => mb_substr(esc_url_raw((string) ($decoded['url'] ?? '')), 0, 500),
            'errors'     => [],
        ];

        if ( ! empty($decoded['errors']) && is_array($decoded['errors']) ) {
            foreach ( array_slice($decoded['errors'], 0, 5) as $e ) {
                if ( ! is_array($e) ) {
                    continue;
                }
                $out['errors'][] = [
                    'msg'  => mb_substr(sanitize_text_field((string) ($e['msg'] ?? '')), 0, 300),
                    'src'  => mb_substr(sanitize_text_field((string) ($e['src'] ?? '')), 0, 200),
                    'line' => (int) ($e['line'] ?? 0),
                ];
            }
        }

        return $out;
    }

    private function parse_anchor_data(mixed $raw): array {
        if ( is_string($raw) ) {
            $decoded = json_decode(stripslashes($raw), true);
        } else {
            $decoded = $raw;
        }

        if ( ! is_array($decoded) ) {
            return [];
        }

        return [
            // Which page builder produced the anchor. Drives element resolution
            // on the client; absent/empty means a legacy (pre-multi-builder)
            // anchor, which the engine treats as the active page adapter.
            'builder'      => $this->sanitize_builder_id($decoded['builder'] ?? ''),
            'element_id'   => sanitize_text_field($decoded['element_id'] ?? ''),
            'css_selector' => sanitize_text_field($decoded['css_selector'] ?? ''),
            // Generic fallback anchor cascade (css path / xpath / nth-of-type /
            // text fingerprint / bbox ratio) used when the native id goes stale
            // or on non-Bricks builders. Null when not captured.
            'strategies'   => $this->sanitize_anchor_strategies($decoded['strategies'] ?? null),
            'viewport_x'   => (float) ($decoded['viewport_x'] ?? 0),
            'viewport_y'   => (float) ($decoded['viewport_y'] ?? 0),
            // Pin placement: fractional offset within the target element plus an
            // absolute document-coordinate fallback for orphaned/removed elements.
            'offset_x'     => (float) ($decoded['offset_x'] ?? 0),
            'offset_y'     => (float) ($decoded['offset_y'] ?? 0),
            'doc_x'        => (float) ($decoded['doc_x'] ?? 0),
            'doc_y'        => (float) ($decoded['doc_y'] ?? 0),
        ];
    }

    /** Restrict the builder id to a known set so it's safe to use client-side. */
    private function sanitize_builder_id(mixed $raw): string {
        $id = sanitize_key(is_string($raw) ? $raw : '');
        return in_array($id, ['bricks', 'elementor', 'gutenberg', 'generic'], true) ? $id : '';
    }

    /**
     * Sanitise the generic anchor-strategy cascade. Only known keys survive,
     * each coerced to a safe scalar/shape — this is stored as JSON and read back
     * into the DOM-resolution code on the client, so it must be tightly bounded.
     */
    private function sanitize_anchor_strategies(mixed $raw): ?array {
        if ( ! is_array($raw) ) {
            return null;
        }
        $nth = is_array($raw['nth_of_type'] ?? null) ? $raw['nth_of_type'] : [];
        $box = is_array($raw['bbox_ratio'] ?? null) ? $raw['bbox_ratio'] : null;

        $out = [
            'css_path'    => mb_substr(sanitize_text_field($raw['css_path'] ?? ''), 0, 600),
            'xpath'       => mb_substr(sanitize_text_field($raw['xpath'] ?? ''), 0, 600),
            'nth_of_type' => [
                'tag'   => mb_substr(sanitize_key($nth['tag'] ?? ''), 0, 40),
                'index' => max(0, (int) ($nth['index'] ?? 0)),
            ],
            'text_fp'     => mb_substr(sanitize_text_field($raw['text_fp'] ?? ''), 0, 200),
            'bbox_ratio'  => $box === null ? null : [
                'x' => (float) ($box['x'] ?? 0), 'y' => (float) ($box['y'] ?? 0),
                'w' => (float) ($box['w'] ?? 0), 'h' => (float) ($box['h'] ?? 0),
            ],
        ];
        // Drop entirely empty cascades so we don't store noise.
        if ( $out['css_path'] === '' && $out['xpath'] === '' && $out['text_fp'] === '' && $out['bbox_ratio'] === null ) {
            return null;
        }
        return $out;
    }

    /**
     * Persist a base64 data-URL screenshot to /uploads/dxf/ and return its URL.
     * Returns '' when no/invalid image is supplied or the size cap is exceeded.
     */
    private function save_screenshot(mixed $raw, int $post_id): string {
        $data_url = is_string($raw) ? $raw : '';
        if ( $data_url === '' ) {
            return '';
        }

        if ( ! preg_match('#^data:image/(png|jpe?g|webp);base64,#', $data_url, $m) ) {
            return '';
        }

        if ( $this->storage_over_quota() ) {
            return '';
        }

        $ext   = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        $bytes = base64_decode(substr($data_url, strpos($data_url, ',') + 1), true);
        if ( $bytes === false || $bytes === '' ) {
            return '';
        }

        $max = (int) DXF_Settings::get('comment_attachment_max_mb', 5) * 1024 * 1024;
        if ( $max > 0 && strlen($bytes) > $max ) {
            return '';
        }

        // Verify the decoded bytes are genuinely an image before writing.
        if ( function_exists('finfo_open') ) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? finfo_buffer($finfo, $bytes) : '';
            if ( $finfo ) {
                finfo_close($finfo);
            }
            if ( strncmp((string) $mime, 'image/', 6) !== 0 ) {
                return '';
            }
        }

        $uploads = wp_upload_dir();
        if ( ! empty($uploads['error']) ) {
            return '';
        }

        $dir = trailingslashit($uploads['basedir']) . 'dxf';
        if ( ! wp_mkdir_p($dir) ) {
            return '';
        }

        $filename = 'shot-' . $post_id . '-' . wp_generate_password(12, false, false) . '.' . $ext;
        if ( file_put_contents(trailingslashit($dir) . $filename, $bytes) === false ) {
            return '';
        }

        delete_transient('dxf_storage_bytes'); // recompute total after a write

        return trailingslashit($uploads['baseurl']) . 'dxf/' . $filename;
    }

    /**
     * Handle multipart file attachments sent as attachments[]. Each file is
     * validated against WordPress' allowed mime types (via wp_handle_upload)
     * and stored in /uploads/dxf/. Returns a list of attachment descriptors.
     */
    private function handle_uploads(): array {
        // Nonce check happens in the AJAX entry-point (ajax_add_comment) before
        // this is called. $_FILES is handled by wp_handle_upload which validates
        // mime/type/size — no sanitisation needed on the structural array.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( empty($_FILES['attachments']) || empty($_FILES['attachments']['name'][0]) ) {
            return [];
        }
        // Skip saving attachments once the storage cap is reached (the comment
        // itself still posts; files just stop attaching when full).
        if ( $this->storage_over_quota() ) {
            return [];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $files     = $_FILES['attachments'];
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $count     = is_array($files['name']) ? count($files['name']) : 0;
        $count     = min($count, 8); // Hard cap: max 8 files per comment.
        $max       = (int) DXF_Settings::get('comment_attachment_max_mb', 5) * 1024 * 1024;
        $total_cap = 40 * 1024 * 1024; // Hard cap: 40 MB total per request.
        $total     = 0;
        $out       = [];

        // Redirect uploads to our /dxf/ subfolder for the duration of this call.
        $to_dxf = static function (array $dirs): array {
            $dirs['subdir'] = '/dxf';
            $dirs['path']   = $dirs['basedir'] . '/dxf';
            $dirs['url']    = $dirs['baseurl'] . '/dxf';
            return $dirs;
        };
        add_filter('upload_dir', $to_dxf);

        for ( $i = 0; $i < $count; $i++ ) {
            if ( (int) $files['error'][$i] !== UPLOAD_ERR_OK ) {
                continue;
            }
            if ( $max > 0 && (int) $files['size'][$i] > $max ) {
                continue;
            }
            // Stop once the cumulative per-request size cap is reached.
            if ( $total + (int) $files['size'][$i] > $total_cap ) {
                break;
            }
            $total += (int) $files['size'][$i];

            $file = [
                'name'     => $files['name'][$i],
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];

            $res = wp_handle_upload($file, ['test_form' => false]);
            if ( ! empty($res['url']) && empty($res['error']) ) {
                $out[] = [
                    'url'  => esc_url_raw($res['url']),
                    'name' => sanitize_file_name($files['name'][$i]),
                    'mime' => sanitize_text_field($res['type']),
                    'size' => (int) $files['size'][$i],
                ];
            }
        }

        remove_filter('upload_dir', $to_dxf);

        if ( $out ) {
            delete_transient('dxf_storage_bytes'); // recompute total after writes
        }

        return $out;
    }

    /**
     * Pre-upload a screenshot immediately after the capture library renders it so the
     * URL is ready before the user finishes typing their comment. The JS sends
     * the base64 data URL here, gets back a server URL, and only passes that URL
     * in the subsequent add-comment call — making submission near-instant.
     */
    public function ajax_upload_screenshot(): void {
        check_ajax_referer(self::NONCE_ACTION);

        $post_id = absint($_POST['post_id'] ?? 0);
        if ( ! $post_id ) {
            wp_send_json_error(['message' => __('Missing post_id.', 'dox-feedback')], 400);
        }

        // Guests may upload screenshots when holding a valid review token for a
        // page that is actually shared, and within a sane rate limit.
        if ( ! is_user_logged_in() ) {
            $token = sanitize_text_field(wp_unslash($_POST['review_token'] ?? ''));
            if ( ! $token || ! $this->validate_review_token($token, $post_id) || ! $this->post_is_reviewable($post_id) ) {
                wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
            }
            if ( ! $this->guest_rate_limit('shot', 60, 600) ) {
                wp_send_json_error(['message' => __('Too many uploads. Please wait a moment.', 'dox-feedback')], 429);
            }
        }

        // Raw binary base64 payload — see save_screenshot() for validation.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $url = $this->save_screenshot(wp_unslash($_POST['screenshot'] ?? ''), $post_id);
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! $url ) {
            wp_send_json_error(['message' => __('Screenshot could not be saved.', 'dox-feedback')], 500);
        }

        wp_send_json_success(['url' => $url]);
    }

    /**
     * Update the positional anchor of an existing comment (pin drag-to-reposition).
     * Only updates location fields; preserves screenshot, attachments, and all
     * other anchor_data that was set when the comment was originally created.
     */
    public function ajax_update_anchor(): void {
        check_ajax_referer(self::NONCE_ACTION);

        $id = absint($_POST['id'] ?? 0);
        // Raw JSON payload — sanitize_* would corrupt JSON (e.g. strip braces),
        // so we json_decode and then run the decoded array through the canonical
        // schema whitelist (parse_anchor_data) before anything reaches the DB.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $anchor_raw = wp_unslash($_POST['anchor_data'] ?? '');
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $new        = json_decode((string) $anchor_raw, true);

        if ( ! $id || ! is_array($new) ) {
            wp_send_json_error(['message' => __('Invalid anchor.', 'dox-feedback')], 400);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'dxf_comments';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT anchor_data, post_id, review_id FROM %i WHERE id = %d LIMIT 1",
            $table, $id
        ));

        if ( ! $row ) {
            wp_send_json_error(['message' => __('Comment not found.', 'dox-feedback')], 404);
        }

        // Logged-in users need edit rights on the post; guests need a valid
        // token AND the comment must belong to their own active review
        // (see guest_can_write_comment()).
        if ( is_user_logged_in() ) {
            if ( ! current_user_can('edit_post', (int) $row->post_id) ) {
                wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
            }
        } else {
            $token = sanitize_text_field(wp_unslash($_POST['review_token'] ?? ''));
            if ( ! $token || ! $this->validate_review_token($token, (int) $row->post_id)
                || ! $this->guest_can_write_comment($row->review_id ?? 0) ) {
                wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
            }
        }

        $existing = json_decode($row->anchor_data, true);
        if ( ! is_array($existing) ) {
            $existing = [];
        }

        // Whitelist + type-coerce the inbound anchor via the canonical schema
        // parser, then merge position fields only — screenshot/attachments and
        // the original selector/viewport are preserved from the stored record.
        $clean = $this->parse_anchor_data($new);
        $existing['builder']    = $clean['builder'];
        $existing['element_id'] = $clean['element_id'];
        $existing['strategies'] = $clean['strategies'];
        $existing['offset_x']   = $clean['offset_x'];
        $existing['offset_y']   = $clean['offset_y'];
        $existing['doc_x']      = $clean['doc_x'];
        $existing['doc_y']      = $clean['doc_y'];

        $result = $wpdb->update(
            $table,
            ['anchor_data' => wp_json_encode($existing)],
            ['id'          => $id],
            ['%s'],
            ['%d']
        );

        $result !== false
            ? wp_send_json_success()
            : wp_send_json_error(['message' => __('Update failed.', 'dox-feedback')], 500);
    }

    /**
     * Late-attach a pre-uploaded screenshot to a comment. The composer no
     * longer blocks submit on capture+upload (deferred-shot flow) — when the
     * upload finishes after the comment row was created, this fills
     * anchor_data.screenshot. It only fills an EMPTY slot (never overwrites
     * an existing/annotated shot) and only accepts files already inside
     * uploads/dxf/ — the same constraint as ajax_add_comment's fast path.
     */
    public function ajax_attach_screenshot(): void {
        check_ajax_referer(self::NONCE_ACTION);

        $id  = absint($_POST['id'] ?? 0);
        $url = esc_url_raw(wp_unslash($_POST['screenshot_url'] ?? ''));
        if ( ! $id || $url === '' ) {
            wp_send_json_error(['message' => __('Invalid request.', 'dox-feedback')], 400);
        }

        $uploads  = wp_upload_dir();
        $base_url = trailingslashit($uploads['baseurl']) . 'dxf/';
        if ( strpos($url, $base_url) !== 0 ) {
            wp_send_json_error(['message' => __('Invalid screenshot.', 'dox-feedback')], 400);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'dxf_comments';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT anchor_data, post_id, review_id FROM %i WHERE id = %d LIMIT 1",
            $table, $id
        ));
        if ( ! $row ) {
            wp_send_json_error(['message' => __('Comment not found.', 'dox-feedback')], 404);
        }

        // Same auth split as anchor updates: logged-in users need edit rights
        // on the post; guests need a valid token scoped to that page AND the
        // comment must belong to their own active review (see guest_can_write_comment()).
        if ( is_user_logged_in() ) {
            if ( ! current_user_can('edit_post', (int) $row->post_id) ) {
                wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
            }
        } else {
            $token = sanitize_text_field(wp_unslash($_POST['review_token'] ?? ''));
            if ( ! $token || ! $this->validate_review_token($token, (int) $row->post_id)
                || ! $this->guest_can_write_comment($row->review_id ?? 0) ) {
                wp_send_json_error(['message' => __('Unauthorized.', 'dox-feedback')], 403);
            }
        }

        $existing = json_decode((string) $row->anchor_data, true);
        if ( ! is_array($existing) ) {
            $existing = [];
        }
        if ( ! empty($existing['screenshot']) ) {
            // Already has one (e.g. annotated before submit) — idempotent no-op.
            wp_send_json_success();
        }

        $existing['screenshot'] = $url;
        $result = $wpdb->update(
            $table,
            ['anchor_data' => wp_json_encode($existing)],
            ['id'          => $id],
            ['%s'],
            ['%d']
        );

        $result !== false
            ? wp_send_json_success()
            : wp_send_json_error(['message' => __('Update failed.', 'dox-feedback')], 500);
    }

    /**
     * Queue (or immediately send) a new-comment notification for this post.
     *
     * When several comments arrive within the throttle window they're coalesced
     * into a single email — controlled by the "Coalesce bursts" setting (0 =
     * send each comment immediately). The recipient list and delivery options
     * are filterable, so a listener can add multi-recipient delivery or custom
     * From / Reply-To on top of the coalesced send.
     */
    private function dispatch_comment_notification( int $post_id, int $comment_id, string $kind ): void {
        if ( ! DXF_Settings::notify_event_enabled('comment') ) {
            return;
        }
        $throttle = (int) DXF_Settings::get('notify_throttle_minutes', 5);
        if ( $throttle <= 0 ) {
            $this->send_comment_notification($post_id, [$comment_id], $kind);
            return;
        }

        $key   = 'dxf_notify_q_' . $kind . '_' . $post_id;
        $queue = get_transient($key);
        $queue = is_array($queue) ? array_map('intval', $queue) : [];
        $queue[] = $comment_id;
        $queue = array_values(array_unique($queue));
        // Generous TTL so the queue survives a brief wp-cron delay (cron isn't
        // precise on low-traffic sites).
        set_transient($key, $queue, max(HOUR_IN_SECONDS, $throttle * 60 * 4));

        $args = [ $post_id, $kind ];
        if ( ! wp_next_scheduled('dxf_notify_flush', $args) ) {
            wp_schedule_single_event(time() + $throttle * 60, 'dxf_notify_flush', $args);
        }
    }

    /** wp-cron handler — drains the queue for a post/kind into a single email. */
    public function flush_notification_queue( int $post_id, string $kind ): void {
        $key   = 'dxf_notify_q_' . $kind . '_' . $post_id;
        $queue = get_transient($key);
        if ( empty($queue) ) {
            return;
        }
        delete_transient($key);
        $ids = array_values(array_unique(array_map('intval', (array) $queue)));
        if ( empty($ids) ) {
            return;
        }
        $this->send_comment_notification($post_id, $ids, $kind);
    }

    /**
     * Build + send one notification email summarising N new comments on a post.
     *
     * @param string $kind 'review' (guest reviewer via link) | 'builder' (logged-in editor)
     */
    private function send_comment_notification( int $post_id, array $comment_ids, string $kind ): void {
        if ( ! DXF_Settings::notify_event_enabled('comment') ) {
            return;
        }
        $post = get_post($post_id);
        if ( ! $post ) {
            return;
        }
        global $wpdb;

        // Fetch the queued comment rows in order, capped at 25 to keep emails sane.
        $ids = array_slice(array_map('intval', $comment_ids), 0, 25);
        if ( empty($ids) ) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, author_name, author_email, body, created_at, author_id
                   FROM %i
                  WHERE id IN ($placeholders)
               ORDER BY created_at ASC",
                $wpdb->prefix . 'dxf_comments', ...$ids
            ),
            ARRAY_A
        );
        if ( empty($rows) ) {
            return;
        }

        // ── Recipients ──
        $recipients = [];
        $exclude_id = 0;
        if ( $kind === 'review' ) {
            $recipients = DXF_Settings::notify_recipients();
        } else {
            // Builder comments: notify the CONFIGURED recipients only (the
            // primary notification address + any Pro-added recipients), minus
            // the commenter themselves. This previously also enumerated every
            // edit_posts user, which meant on a multi-admin client site ALL
            // admins were emailed — not just the address(es) in Settings.
            $exclude_id   = (int) ($rows[count($rows) - 1]['author_id'] ?? 0);
            $author_email = strtolower((string) ( $exclude_id ? (get_userdata($exclude_id)->user_email ?? '') : '' ));
            foreach ( DXF_Settings::notify_recipients() as $primary ) {
                if ( strtolower($primary) === $author_email ) continue;
                $recipients[] = $primary;
            }
            $recipients = array_values(array_unique(array_map('strtolower', $recipients)));
            // Honour each recipient's personal opt-out where they're a WP user
            // (the per-profile "mute builder emails" toggle).
            $recipients = array_values(array_filter($recipients, static function ( $email ) {
                $u = get_user_by('email', $email);
                return ! ( $u && get_user_meta($u->ID, 'dxf_notify_builder', true) === '0' );
            }));
        }
        if ( empty($recipients) ) {
            return;
        }

        // ── Email content ──
        $site_name  = get_bloginfo('name');
        $post_title = $post->post_title;
        $count      = count($rows);
        $edit_url   = self::builder_url($post_id);
        $page_url   = (string) (get_permalink($post_id) ?: '');

        $subject = sprintf(
            /* translators: 1: site name, 2: count, 3: page title */
            _n('[%1$s] %2$d new comment on "%3$s"', '[%1$s] %2$d new comments on "%3$s"', $count, 'dox-feedback'),
            $site_name,
            $count,
            $post_title
        );

        $items_html  = '';
        $items_plain = '';
        $reply_to    = '';
        foreach ( $rows as $r ) {
            $name  = $r['author_name'] ?: ($r['author_id'] ? (get_userdata((int) $r['author_id'])->display_name ?? 'Someone') : 'Reviewer');
            $email = $r['author_email'] ?? '';
            if ( ! $reply_to && $email ) $reply_to = $email; // first author with an email becomes Reply-To
            $items_html .=
                '<div style="margin:0 0 14px;padding:10px 14px;background:#f8f8ff;border-left:3px solid #ff8d27;border-radius:4px;">' .
                  '<div style="font-size:13px;color:#666;margin-bottom:4px;">' .
                    '<strong style="color:#1a1a2e;">' . esc_html($name) . '</strong>' .
                    ( $email ? ' <span style="color:#888;">(' . esc_html($email) . ')</span>' : '' ) .
                  '</div>' .
                  '<div style="font-size:14px;color:#38385a;line-height:1.55;">' . nl2br(esc_html($r['body'])) . '</div>' .
                '</div>';
            $items_plain .= '— ' . $name . ($email ? ' (' . $email . ')' : '') . "\n" . $r['body'] . "\n\n";
        }

        $heading_html = $count === 1
            ? sprintf(
                /* translators: %s = page title */
                __('New comment on "%s"', 'dox-feedback'),
                $post_title
            )
            : sprintf(
                /* translators: 1: count, 2: page title */
                _n('%1$d new comment on "%2$s"', '%1$d new comments on "%2$s"', $count, 'dox-feedback'),
                $count,
                $post_title
            );

        $html = DXF_Mailer::build_html(
            $heading_html,
            $items_html,
            [['url' => $edit_url, 'label' => __('View in builder →', 'dox-feedback')]]
        );

        $plain = $heading_html . "\n\n" . $items_plain
               . __('View in builder:', 'dox-feedback') . ' ' . $edit_url
               . ( $page_url ? "\n" . __('Page URL:', 'dox-feedback') . ' ' . $page_url : '' );

        DXF_Mailer::send($recipients, $subject, $plain, $html, DXF_Settings::notify_opts($reply_to));
    }

    /**
     * Notify EVERYONE engaged in a thread when a reply lands — WP users AND
     * guest reviewers (matched by the email they left) — except the author of
     * each reply. Coalesced with the same "Coalesce bursts" window as new
     * comments: when several replies arrive in quick succession they're batched
     * into one email per recipient (0 = send immediately).
     */
    private function notify_thread_reply( int $post_id, int $parent_id, int $reply_id ): void {
        if ( ! DXF_Settings::notify_event_enabled('reply') ) {
            return;
        }
        $throttle = (int) DXF_Settings::get('notify_throttle_minutes', 5);
        if ( $throttle <= 0 ) {
            $this->send_reply_notification($post_id, $parent_id, [$reply_id]);
            return;
        }

        // Queue per thread (parent) so a burst of replies coalesces into one
        // email per recipient when the window goes quiet.
        $key   = 'dxf_notify_rq_' . $post_id . '_' . $parent_id;
        $queue = get_transient($key);
        $queue = is_array($queue) ? array_map('intval', $queue) : [];
        $queue[] = $reply_id;
        $queue = array_values(array_unique($queue));
        set_transient($key, $queue, max(HOUR_IN_SECONDS, $throttle * 60 * 4));

        $args = [ $post_id, $parent_id ];
        if ( ! wp_next_scheduled('dxf_notify_reply_flush', $args) ) {
            wp_schedule_single_event(time() + $throttle * 60, 'dxf_notify_reply_flush', $args);
        }
    }

    /** wp-cron handler — drains a thread's queued replies into one email per recipient. */
    public function flush_reply_notification( int $post_id, int $parent_id ): void {
        $key   = 'dxf_notify_rq_' . $post_id . '_' . $parent_id;
        $queue = get_transient($key);
        if ( empty($queue) ) {
            return;
        }
        delete_transient($key);
        $ids = array_values(array_unique(array_map('intval', (array) $queue)));
        if ( empty($ids) ) {
            return;
        }
        $this->send_reply_notification($post_id, $parent_id, $ids);
    }

    /**
     * Send the reply notification(s) for a thread. Each participant (WP user or
     * guest with an email on record) receives ONE email summarising the queued
     * replies they did NOT write themselves. Respects each WP user's personal
     * opt-out. Guests are matched by the email they left on the thread.
     */
    private function send_reply_notification( int $post_id, int $parent_id, array $reply_ids ): void {
        if ( ! DXF_Settings::notify_event_enabled('reply') ) {
            return;
        }
        $post = get_post($post_id);
        if ( ! $post ) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'dxf_comments';

        $ids = array_slice(array_map('intval', $reply_ids), 0, 25);
        if ( empty($ids) ) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $replies = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, author_id, author_name, author_email, body, created_at
                   FROM %i WHERE id IN ($placeholders) ORDER BY created_at ASC",
                $table, ...$ids
            ),
            ARRAY_A
        );
        if ( empty($replies) ) {
            return;
        }

        // Everyone who has touched this thread (the parent comment + its replies).
        $participants = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT author_id, author_email FROM %i
                  WHERE id = %d OR parent_id = %d",
                $table, $parent_id, $parent_id
            ),
            ARRAY_A
        );

        $post_title = $post->post_title;
        $site_name  = get_bloginfo('name');
        $edit_url   = self::builder_url($post_id);
        $count      = count($replies);

        $seen = [];
        foreach ( $participants as $p ) {
            $uid   = (int) ($p['author_id'] ?? 0);
            $email = '';
            if ( $uid > 0 ) {
                if ( isset($seen['u' . $uid]) ) continue;
                $seen['u' . $uid] = true;
                if ( get_user_meta($uid, 'dxf_notify_builder', true) === '0' ) continue;
                $u = get_userdata($uid);
                if ( ! $u || ! $u->user_email ) continue;
                $email = $u->user_email;
            } else {
                $email = sanitize_email((string) ($p['author_email'] ?? ''));
                if ( ! $email || isset($seen['e' . strtolower($email)]) ) continue;
                $seen['e' . strtolower($email)] = true;
            }

            // Replies this participant did NOT author (never notify about own).
            $relevant = array_values(array_filter($replies, function ( $r ) use ( $uid, $email ) {
                if ( $uid > 0 ) return (int) $r['author_id'] !== $uid;
                return strcasecmp((string) $r['author_email'], $email) !== 0;
            }));
            if ( empty($relevant) ) continue;

            $items_html  = '';
            $items_plain = '';
            $reply_to    = '';
            foreach ( $relevant as $r ) {
                $rname = $r['author_name'] ?: ( $r['author_id'] ? ( get_userdata((int) $r['author_id'])->display_name ?? __('Someone', 'dox-feedback') ) : __('Reviewer', 'dox-feedback') );
                if ( ! $reply_to && ! empty($r['author_email']) ) $reply_to = (string) $r['author_email'];
                $items_html .=
                    '<div style="margin:0 0 12px;padding:10px 14px;background:#f8f8ff;border-left:3px solid #ff8d27;border-radius:4px;">' .
                      '<div style="font-size:13px;color:#1a1a2e;margin-bottom:4px;"><strong>' . esc_html($rname) . '</strong></div>' .
                      '<div style="font-size:14px;color:#38385a;line-height:1.55;">' . nl2br(esc_html((string) $r['body'])) . '</div>' .
                    '</div>';
                $items_plain .= '— ' . $rname . "\n" . (string) $r['body'] . "\n\n";
            }

            $subject = sprintf(
                /* translators: 1: site name, 2: count, 3: page title */
                _n('[%1$s] %2$d new reply on "%3$s"', '[%1$s] %2$d new replies on "%3$s"', count($relevant), 'dox-feedback'),
                $site_name, count($relevant), $post_title
            );
            $heading = sprintf(
                /* translators: %s = page title */
                _n('New reply on "%s"', 'New replies on "%s"', count($relevant), 'dox-feedback'),
                $post_title
            );
            $html  = DXF_Mailer::build_html($heading, $items_html, [['url' => $edit_url, 'label' => __('View in builder →', 'dox-feedback')]]);
            $plain = $heading . "\n\n" . $items_plain . __('View in builder:', 'dox-feedback') . ' ' . $edit_url;

            DXF_Mailer::send($email, $subject, $plain, $html, DXF_Settings::notify_opts($reply_to));
        }
    }

    public function render_settings_fields(): void {
        $max   = (int)    DXF_Settings::get('comment_attachment_max_mb', 5);
        $theme = (string) DXF_Settings::get('comment_modal_theme', 'follow_bricks');
        $show_status = (bool) DXF_Settings::get('comment_show_status_pill', 1);
        $show_assign = (bool) DXF_Settings::get('comment_show_assign_pill', 1);
        $options = [
            // Stored value stays 'follow_bricks' for back-compat; the label is
            // builder-agnostic now that the panel runs in Elementor & Gutenberg
            // too (and auto-matches each editor's own theme).
            'follow_bricks' => __('Match the editor (default — dark in the builder, follow OS on the front-end)', 'dox-feedback'),
            'os'            => __('Follow operating system preference', 'dox-feedback'),
            'dark'          => __('Always dark', 'dox-feedback'),
            'light'         => __('Always light', 'dox-feedback'),
        ];
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Comment card controls', 'dox-feedback'); ?></th>
                <td>
                    <label style="display:block;margin-bottom:6px;">
                        <input type="checkbox" name="dxf_options[comment_show_status_pill]" value="1" <?php checked($show_status); ?> />
                        <?php esc_html_e('Show the status dropdown on comment cards', 'dox-feedback'); ?>
                    </label>
                    <label style="display:block;">
                        <input type="checkbox" name="dxf_options[comment_show_assign_pill]" value="1" <?php checked($show_assign); ?> />
                        <?php esc_html_e('Show the assignee dropdown on comment cards', 'dox-feedback'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Untick to declutter the cards. Status still shows as a small read-only chip; comments can still be resolved via the tick button.', 'dox-feedback'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="br-attachment-max"><?php esc_html_e('Max attachment size (MB)', 'dox-feedback'); ?></label>
                </th>
                <td>
                    <input type="number" id="br-attachment-max" name="dxf_options[comment_attachment_max_mb]"
                           value="<?php echo esc_attr($max); ?>" min="1" max="100" class="small-text" />
                    <p class="description">
                        <?php esc_html_e('Maximum size per file attached to a comment (screenshots and uploads). 1–100 MB.', 'dox-feedback'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="br-modal-theme"><?php esc_html_e('Comment modal theme', 'dox-feedback'); ?></label>
                </th>
                <td>
                    <select id="br-modal-theme" name="dxf_options[comment_modal_theme]">
                        <?php foreach ($options as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($theme, $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Default theme for the comments modal. Each user can still flip light/dark via the toggle in the modal header — their choice is remembered for them and overrides this default.', 'dox-feedback'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }
}
