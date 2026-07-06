/* global dxfQuickReview, jQuery */
/**
 * Dox Feedback — unified admin-bar popout.
 *
 * Renders into #dxf-ab-quick-inner (the lone content node seeded by
 * DXF_Reviews::add_admin_bar_quick_review_node). Sections, top-down:
 *
 *   1. Link section     — active public review link OR generate CTA
 *                         (per-page, NOT site-wide — labeled explicitly).
 *                         AJAX calls go to DXF_Review_Mode handlers
 *                         (dxf_generate_review_link / _revoke_).
 *   2. Active reviews   — list of open Reviews with inline manage + delete
 *                         actions (delete is admin-only and confirms first).
 *                         "New Review" link in the section header opens the
 *                         admin Reviews → New screen.
 *   3. Settings         — link to Dox Feedback settings screen.
 *
 * The link section is the meta-box-equivalent for the front-end. The
 * post-edit meta box stays owned by review-admin.js.
 */
(function ($) {
    'use strict';

    var R = window.dxfQuickReview;
    if (!R) return;

    var I18N = (window.dxfQuickReview && window.dxfQuickReview.i18n) || {};
    function t(k, fb){ var v = I18N[k]; return (v === undefined || v === null || v === '') ? fb : v; }

    var $host = $('#dxf-ab-quick-inner');
    if (!$host.length) return;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // SVG paths — kept inline so we don't have to ship an icon font.
    var ICONS = {
        link:     'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71',
        copy:     'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2M9 2h6a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z',
        check:    'M20 6 9 17l-5-5',
        cross:    'M18 6 6 18M6 6l12 12',
        external: 'M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3',
        home:     'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM9 22V12h6v10',
        globe:    'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM2 12h20M12 2c-2.667 3.333-4 6.667-4 10s1.333 6.667 4 10M12 2c2.667 3.333 4 6.667 4 10s-1.333 6.667-4 10',
        comment:  'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
        edit:     'M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z',
        cog:      'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 0 0-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 0 0-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 0 0-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 0 0-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 0 0 1.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z',
        chev:     'M9 18l6-6-6-6'
    };

    function ico(path, opts) {
        opts = opts || {};
        var sz   = opts.size || 14;
        var sw   = opts.sw   || 1.75;
        var cls  = opts.cls ? ' class="' + opts.cls + '"' : '';
        // Inline width/height in the style (not just attributes) because the
        // admin bar's default `#wpadminbar svg` rules can stretch an
        // attribute-sized SVG to fill its flex container — looks like a
        // garbled blob in the Generate button. Inline style wins.
        return '<svg' + cls + ' width="' + sz + '" height="' + sz + '" viewBox="0 0 24 24" fill="none" '
            +  'stroke="currentColor" stroke-width="' + sw + '" stroke-linecap="round" stroke-linejoin="round" '
            +  'style="display:block;flex-shrink:0;width:' + sz + 'px;height:' + sz + 'px;"><path d="' + path + '"/></svg>';
    }

    // A small coloured pill spelling out exactly what a review covers, so a
    // single-page link can't be mistaken for a site-wide one (and vice versa).
    function scopePill(scopeType) {
        var map = {
            single:   { label: t('scopeSingle', 'This page'),       tip: t('scopeSingleTip', 'Covers only the single page it was created on.'),   cls: 'rqr-scope-single' },
            selected: { label: t('scopeSelected', 'Selected pages'), tip: t('scopeSelectedTip', 'Covers a hand-picked set of pages.'), cls: 'rqr-scope-selected' },
            entire:   { label: t('scopeEntire', 'Whole site'),       tip: t('scopeEntireTip', 'Covers every page on the site.'),   cls: 'rqr-scope-entire' }
        };
        var m = map[scopeType] || map.single;
        return '<span class="rqr-scope-pill ' + m.cls + '" title="' + esc(m.tip) + '">' + esc(m.label) + '</span>';
    }

    // A shortcut to scoped (multi-page / whole-site) reviews from the popout.
    // The tiny admin-bar popout is the wrong place for a full page-picker, so
    // this hands off to the New-review wizard, which has the scope cards.
    function scopeLinkRow() {
        if (!R.newReviewUrl) return '';
        return ''
            + '<a class="rqr-pro-create" href="' + esc(R.newReviewUrl) + '">'
            + ico(ICONS.globe, { size: 12 })
            + '<span>' + esc(t('proCreate', 'Multi-page or whole-site review')) + '</span>'
            + '</a>';
    }

    // -----------------------------------------------------------------------
    // Section renderers
    // -----------------------------------------------------------------------

    function linkSection() {
        if (!R.postId) {
            return ''
                + '<div class="rqr-link-pad rqr-link-empty">'
                + '<div class="rqr-link-status">'
                + '<span class="rqr-dot rqr-dot-mute"></span>'
                + '<span>' + esc(t('noPage', 'Navigate to a page to manage its review link.')) + '</span>'
                + '</div>'
                + '</div>';
        }
        if (R.reviewUrl) {
            return ''
                + '<div class="rqr-link-pad">'
                + '<div class="rqr-link-status rqr-link-active">'
                + '<span class="rqr-dot rqr-dot-active"></span>'
                + '<span>' + esc(t('activeLink', 'Review link active')) + '</span>'
                + scopePill('single')
                + '</div>'
                + '<div class="rqr-link-url-row">'
                + '<div class="rqr-link-url">' + esc(R.reviewUrl) + '</div>'
                + '<button type="button" class="rqr-copy-btn" data-copy="' + esc(R.reviewUrl) + '">'
                + ico(ICONS.copy, { size: 12 }) + '<span>' + esc(t('copy', 'Copy')) + '</span>'
                + '</button>'
                + '</div>'
                + '<div class="rqr-link-actions">'
                + '<a class="rqr-link-open" href="' + esc(R.reviewUrl) + '" target="_blank" rel="noopener">'
                + ico(ICONS.external, { size: 12 }) + '<span>' + esc(t('open', 'Open') || t('openInBrowser', 'Open in browser')) + '</span>'
                + '</a>'
                + '<button type="button" class="rqr-revoke-btn">'
                + ico(ICONS.cross, { size: 11, sw: 2.2 }) + '<span>' + esc(t('revoke', 'Revoke')) + '</span>'
                + '</button>'
                + '</div>'
                // Scope note dropped here — the "This page" pill above already
                // says it, and the Multi-page button below covers the rest.
                + '</div>';
        }
        return ''
            + '<div class="rqr-link-pad rqr-link-empty">'
            + '<div class="rqr-link-status">'
            + '<span class="rqr-dot rqr-dot-mute"></span>'
            + '<span>' + esc(t('noActiveLink', 'No active review link')) + '</span>'
            + '</div>'
            + '<button type="button" class="rqr-cta rqr-generate-btn">'
            + ico(ICONS.link, { size: 13 }) + '<span>' + esc(t('generateLink', 'Generate public link for this page')) + '</span>'
            + '</button>'
            + '<div class="rqr-link-scope">' + esc(t('linkScope', 'This link is only for this page, not the whole site.')) + '</div>'
            + '</div>';
    }

    function reviewsSection() {
        var open = R.openCount | 0;
        var activeCount = (R.activeReviewCount | 0);
        var rows = (R.activeReviews && R.activeReviews.length) ? R.activeReviews : [];
        var newUrl = R.newReviewUrl || '';
        var canDelete = !!R.canManage;

        var countLine = activeCount > 0
            ? '<span><strong>' + activeCount + '</strong> ' + esc(t('activeReviews', 'active reviews')) + '</span>'
            : '<span>' + esc(t('noActiveReviews', 'No active reviews')) + '</span>';

        var newLink = newUrl
            ? '<a class="rqr-new-review" href="' + esc(newUrl) + '">'
                + esc(t('newReview', 'New Review'))
                + ico(ICONS.chev, { size: 11, sw: 2.5 })
              + '</a>'
            : '';

        var listHtml = '';
        if (rows.length) {
            listHtml += '<ul class="rqr-review-list">';
            rows.forEach(function (r) {
                var name = r.name && r.name.length ? r.name : t('untitledReview', '(untitled review)');
                var manage = r.manageUrl || '';
                listHtml += '<li class="rqr-review-item" data-review-id="' + (r.id | 0) + '">'
                    + '<a class="rqr-review-name" href="' + esc(manage) + '" title="' + esc(name) + '">'
                    +   esc(name)
                    + '</a>'
                    + scopePill(r.scopeType)
                    + '<span class="rqr-review-actions">'
                    +   (r.openUrl
                            ? '<a class="rqr-review-open" href="' + esc(r.openUrl) + '" target="_blank" rel="noopener" aria-label="' + esc(t('openReview', 'Open')) + '" title="' + esc(t('openReview', 'Open')) + '">'
                                + ico(ICONS.external, { size: 13 })
                              + '</a>'
                            : '')
                    +   '<a class="rqr-review-manage" href="' + esc(manage) + '" aria-label="' + esc(t('edit', 'Edit review')) + '" title="' + esc(t('edit', 'Edit review')) + '">'
                    +     ico(ICONS.edit, { size: 13 })
                    +   '</a>'
                    +   (canDelete
                            ? '<button type="button" class="rqr-review-delete" aria-label="' + esc(t('delete', 'Delete')) + '" title="' + esc(t('delete', 'Delete')) + '">'
                                + ico(ICONS.cross, { size: 12, sw: 2.2 })
                              + '</button>'
                            : '')
                    + '</span>'
                + '</li>';
            });
            listHtml += '</ul>';
            if (activeCount > rows.length) {
                listHtml += '<a class="rqr-review-viewall" href="' + esc(R.menuBase || newUrl) + '">'
                    + esc(t('viewAllReviews', 'View all reviews →'))
                    + '</a>';
            }
        }

        return ''
            + '<div class="rqr-round-block">'
            + '<div class="rqr-round-head">'
            + '<span class="rqr-round-title">' + esc(t('reviews', 'Reviews')) + '</span>'
            + newLink
            + '</div>'
            + '<div class="rqr-round-meta">'
            + ico(ICONS.comment, { size: 12, cls: 'rqr-round-meta-icon' })
            + countLine
            + (R.postId
                ? ' &middot; ' + (R.builderUrl
                    ? '<a class="rqr-open-comments" href="' + esc(R.builderUrl) + '" title="' + esc(t('openInBuilder', 'Open in the builder')) + '"><strong>' + open + '</strong> ' + esc(t('openComments', 'open comments')) + '</a>'
                    : '<span><strong>' + open + '</strong> ' + esc(t('openComments', 'open comments')) + '</span>')
                : '')
            + '</div>'
            + listHtml
            + '</div>';
    }

    function settingsRow() {
        return ''
            + '<a class="rqr-settings" href="' + esc(R.settingsUrl) + '">'
            + ico(ICONS.cog, { size: 13 }) + '<span>' + esc(t('settings', 'Dox Feedback Settings')) + '</span>'
            + '</a>';
    }

    function render() {
        $host.html(''
            + '<div class="rqr-popout">'
            +   linkSection()
            +   scopeLinkRow()
            +   '<div class="rqr-divider"></div>'
            +   reviewsSection()
            +   '<div class="rqr-divider"></div>'
            +   settingsRow()
            +   '<div class="rqr-result" style="display:none;"></div>'
            + '</div>'
        );
    }

    function showError(msg) {
        $host.find('.rqr-result').html('<div class="rqr-result-err">' + esc(msg) + '</div>').show();
    }

    // -----------------------------------------------------------------------
    // Link section — generate / revoke / copy
    // -----------------------------------------------------------------------

    function generateLink($btn) {
        if (!R.postId) return;
        var origHtml = $btn.html();
        $btn.prop('disabled', true).html(
            ico(ICONS.link, { size: 13 }) + '<span>' + esc(t('generating', 'Generating link…')) + '</span>'
        );
        $.post(R.ajaxUrl, {
            action:   'dxf_generate_review_link',
            _wpnonce: R.linkNonce,
            post_id:  R.postId
        }).done(function (resp) {
            if (resp && resp.success && resp.data && resp.data.url) {
                R.reviewUrl = resp.data.url;
                R.token     = resp.data.token;
                // Public share links ARE Reviews now — splice the new row
                // into the Active Reviews list so the popout reflects it
                // without a page reload. De-duped by id in case the user
                // just re-generated an existing one.
                if (resp.data.review && resp.data.review.id) {
                    var newRow = resp.data.review;
                    R.activeReviews = (R.activeReviews || []).filter(function (r) {
                        return (r.id | 0) !== (newRow.id | 0);
                    });
                    R.activeReviews.unshift(newRow);
                    R.activeReviewCount = (R.activeReviews || []).length;
                }
                render();
            } else {
                var msg = (resp && resp.data && resp.data.message) || t('failed', 'Action failed. Please try again.');
                $btn.prop('disabled', false).html(origHtml);
                showError(msg);
            }
        }).fail(function () {
            $btn.prop('disabled', false).html(origHtml);
            showError(t('failed', 'Action failed. Please try again.'));
        });
    }

    function revokeLink($btn) {
        if (!R.token || !R.postId) return;
        if ($btn.prop('disabled')) return; // belt-and-braces against double-fire
        if (!window.confirm(t('revokeConfirm', 'Revoke this review link? Clients with the current URL will lose access.'))) return;
        $btn.prop('disabled', true).text(t('revoking', 'Revoking…'));

        // Apply the revoke locally and re-render. The server endpoint is
        // idempotent, so we treat any response (success, 404, network blip)
        // as "consider it revoked client-side" — this used to wedge into
        // a state where the server had already revoked but the popout
        // still showed the active link with no way to recover short of
        // a page reload.
        var applyRevoked = function () {
            var revoked = (R.token | 0) || 0;
            if (revoked) {
                R.activeReviews = (R.activeReviews || []).filter(function (r) {
                    return (r.id | 0) !== revoked;
                });
                R.activeReviewCount = (R.activeReviews || []).length;
            }
            R.reviewUrl = '';
            R.token     = '';
            render();
        };

        $.post(R.ajaxUrl, {
            action:   'dxf_revoke_review_link',
            _wpnonce: R.linkNonce,
            post_id:  R.postId,
            token:    R.token
        }).done(function () {
            applyRevoked();
        }).fail(function (xhr) {
            if (xhr && xhr.status === 404) {
                // "Nothing to revoke" — already gone server-side.
                applyRevoked();
                return;
            }
            $btn.prop('disabled', false).text(t('revoke', 'Revoke'));
            showError(t('failed', 'Action failed. Please try again.'));
        });
    }

    function copyText(text, $btn) {
        var orig = $btn.text() || t('copy', 'Copy');
        var done = function () {
            $btn.text(t('copied', 'Copied!'));
            setTimeout(function () { render(); }, 1400);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, function () { legacyCopy(text, done, orig, $btn); });
        } else {
            legacyCopy(text, done, orig, $btn);
        }
    }

    function legacyCopy(text, done, orig, $btn) {
        var $tmp = $('<textarea>').val(text).appendTo('body').select();
        try { document.execCommand('copy'); done(); } catch (e) { $btn.text(orig); }
        $tmp.remove();
    }

    // -----------------------------------------------------------------------
    // Active-reviews list — delete (manage is just a link, no JS needed)
    // -----------------------------------------------------------------------

    function deleteReview($btn) {
        var $item = $btn.closest('.rqr-review-item');
        var id = parseInt($item.attr('data-review-id'), 10) || 0;
        if (!id) return;
        if (!window.confirm(t('deleteConfirm', 'Delete this review and all its data? This cannot be undone.'))) return;

        $btn.prop('disabled', true).attr('title', t('deleting', 'Deleting…'));
        $item.addClass('is-deleting');

        $.post(R.ajaxUrl, {
            action:      'dxf_review_delete',
            _ajax_nonce: R.adminNonce,
            review_id:   id
        }).done(function (resp) {
            if (resp && resp.success) {
                // Optimistically drop from local state, then re-render.
                R.activeReviews = (R.activeReviews || []).filter(function (r) { return (r.id | 0) !== id; });
                R.activeReviewCount = Math.max(0, (R.activeReviewCount | 0) - 1);
                render();
            } else {
                $btn.prop('disabled', false);
                $item.removeClass('is-deleting');
                showError(t('failed', 'Action failed. Please try again.'));
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $item.removeClass('is-deleting');
            showError(t('failed', 'Action failed. Please try again.'));
        });
    }

    // Link section delegation
    $host.on('click', '.rqr-generate-btn',  function () { generateLink($(this)); });
    $host.on('click', '.rqr-revoke-btn',    function () { revokeLink($(this));   });
    $host.on('click', '.rqr-copy-btn',      function () { copyText(R.reviewUrl, $(this)); });
    $host.on('click', '.rqr-review-delete', function () { deleteReview($(this)); });

    // First-visit coachmark for the admin bar. When the user arrives from the
    // Getting Started "Open my site" link (?dxf-welcome=1), point them at the
    // Dox Feedback admin-bar button — the front-end twin of the reviewer FAB tip.
    // Shown once per browser; dismissed on close, on clicking the button, or
    // after a timeout (the timeout does NOT mark it done, mirroring the FAB tip).
    function maybeShowAdminBarTip() {
        try {
            if (!/[?&]dxf-welcome=1(?:&|$)/.test(window.location.search)) return;
            if (window.localStorage.getItem('dxf_adminbar_tip_done') === '1') return;
        } catch (e) { return; }
        var node = document.getElementById('wp-admin-bar-dxf');
        if (!node) return;

        var tip = document.createElement('div');
        tip.id = 'dxf-ab-tip';
        var msg = document.createElement('span');
        msg.className = 'dxf-ab-tip-msg';
        msg.textContent = t('adminBarTip', 'Click Dox Feedback to leave feedback on this page.');
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'dxf-ab-tip-close';
        close.setAttribute('aria-label', t('qr.dismiss', 'Dismiss'));
        close.innerHTML = '&times;';
        tip.appendChild(msg);
        tip.appendChild(close);
        document.body.appendChild(tip);

        function place() {
            var r = node.getBoundingClientRect();
            var left = Math.max(8, Math.min(r.left, window.innerWidth - tip.offsetWidth - 8));
            tip.style.top  = (r.bottom + 9) + 'px';
            tip.style.left = left + 'px';
            // Arrow points up at the button centre, clamped within the tip width.
            var ax = Math.max(14, Math.min(r.left + r.width / 2 - left, tip.offsetWidth - 14));
            tip.style.setProperty('--rv-ab-arrow', ax + 'px');
        }
        function remove() {
            window.removeEventListener('resize', place);
            if (!tip.parentNode) return;
            tip.classList.add('dxf-ab-tip--hide');
            setTimeout(function () { if (tip.parentNode) tip.parentNode.removeChild(tip); }, 200);
        }
        function done() {
            try { window.localStorage.setItem('dxf_adminbar_tip_done', '1'); } catch (e) {}
            remove();
        }

        place();
        window.addEventListener('resize', place);
        close.addEventListener('click', done);
        node.addEventListener('click', done, { once: true });
        // Retire if untouched — without marking done, so it can reappear later.
        setTimeout(remove, 15000);
    }
    maybeShowAdminBarTip();

    render();

})(jQuery);
