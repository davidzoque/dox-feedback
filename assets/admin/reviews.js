/**
 * Dox Feedback Reviews — admin UI controller.
 *
 * Wires up:
 *   - Create-review wizard (scope → reviewers → publish)
 *   - Review detail screen (copy URL, invite, revoke, close, reopen, delete)
 *
 * @since 0.16.0
 */
(function ($) {
    'use strict';

    var R = window.dxfReviews || {};

    var I18N = (window.dxfReviews && window.dxfReviews.i18n) || {};
    function t(k, fb){ var v = I18N[k]; return (v === undefined || v === null || v === '') ? fb : v; }

    function ajax(action, data) {
        data = $.extend({ action: action, _ajax_nonce: R.nonce }, data || {});
        return $.post(R.ajaxUrl, data);
    }

    // -----------------------------------------------------------------------
    // Create wizard
    // -----------------------------------------------------------------------

    var $form = $('.dxf-review-form');
    if ($form.length) {
        var step = 1;
        var entireConfirmed   = false; // whole-site "include everything" acknowledged
        var ENTIRE_EXCESS_LIMIT = 10;  // warn above this many non-page items

        function goto(n) {
            step = n;
            $form.attr('data-step', n);
            $form.find('.dxf-step').removeClass('active');
            $form.find('.dxf-step-' + n).addClass('active');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Scope reveal — selecting a scope toggles the relevant extras.
        $form.on('change', 'input[name="scope_type"]', function () {
            var v = this.value;
            $form.find('.dxf-include-future').toggle(v === 'entire');
            $form.find('.dxf-page-picker').toggle(v === 'selected');
            $form.find('.dxf-page-single').toggle(v === 'single');
            if (v !== 'entire') { entireConfirmed = false; $form.find('.dxf-entire-warning').remove(); }
        }).find('input[name="scope_type"]:checked').trigger('change');

        // No-end-date toggle — greys out the date picker so the user can see
        // the date is being ignored, instead of silently dropping the value.
        $form.on('change', 'input[name="no_expiry"]', function () {
            $form.find('input[name="expires_at"]').prop('disabled', this.checked);
        });

        // Live filter for the grouped page picker. Hides non-matching items
        // and collapses groups that end up with zero visible matches; clearing
        // the filter restores the original collapsed/expanded state.
        $form.on('input', '.dxf-picker-filter', function () {
            var q = this.value.trim().toLowerCase();
            var $list = $form.find('.dxf-page-list');
            $list.find('.dxf-picker-item').each(function () {
                var match = !q || this.textContent.toLowerCase().indexOf(q) !== -1;
                this.style.display = match ? '' : 'none';
            });
            $list.find('.dxf-picker-group').each(function () {
                var $g = $(this);
                var hasVisible = $g.find('.dxf-picker-item').filter(function () { return this.style.display !== 'none'; }).length > 0;
                $g.css('display', hasVisible ? '' : 'none');
                if (q && hasVisible) $g.attr('open', 'open');
            });
        });

        $form.on('click', '.dxf-next', function () {
            if (step === 1) {
                var scope = $form.find('input[name="scope_type"]:checked').val();
                if (scope === 'single' && !$form.find('select[name="single_post_id"]').val()) {
                    alert(t('rv.pickPage', 'Please pick a page.'));
                    return;
                }
                if (scope === 'selected' && $form.find('input[name="post_ids[]"]:checked').length === 0) {
                    alert(t('rv.selectAtLeastOne', 'Please select at least one page.'));
                    return;
                }
                // Whole-site reviews that would pull in lots of non-page posts:
                // warn + offer to pick a subset so clients aren't handed 100s of items.
                if (scope === 'entire' && !entireConfirmed && maybeWarnEntire()) {
                    return;
                }
                goto(2);
            } else if (step === 2) {
                // Step 2 (reviewers) has no required fields in Free — the email-
                // restricted flow and its validation ship in Dox Feedback Pro.
                goto(3);
            }
        });

        $form.on('click', '.dxf-prev', function () { goto(step - 1); });

        $form.on('submit', function (e) {
            e.preventDefault();
            var scope = $form.find('input[name="scope_type"]:checked').val();
            var mode  = $form.find('input[name="mode"]:checked').val();
            var name  = $form.find('input[name="name"]').val();
            var no_expiry = $form.find('input[name="no_expiry"]').is(':checked') ? 1 : 0;
            var expires = no_expiry ? '' : $form.find('input[name="expires_at"]').val();
            var include_future = $form.find('input[name="include_future"]').is(':checked') ? 1 : 0;

            var post_ids = [];
            if (scope === 'single') {
                var pid = parseInt($form.find('select[name="single_post_id"]').val(), 10);
                if (pid) post_ids = [pid];
            } else if (scope === 'selected') {
                $form.find('input[name="post_ids[]"]:checked').each(function () {
                    post_ids.push(parseInt(this.value, 10));
                });
            }

            var createLabel = t('rv.createActivate', 'Create & activate');
            var $btn  = $form.find('.dxf-create').prop('disabled', true).text(t('rv.creating', 'Creating…'));
            var $out  = $form.find('.dxf-create-result').removeClass('error').hide();

            var payload = {
                name: name,
                scope_type: scope,
                mode: mode,
                post_ids: post_ids,
                include_future: include_future,
                no_expiry: no_expiry,
                expires_at: expires ? expires + ' 23:59:59' : ''
            };

            // The email-restricted reviewer list is injected by Dox Feedback Pro's
            // mode-config block. When that block is present, forward its fields
            // so Pro's server-side dxf_review_after_create listener can send
            // the magic-link invites. Neither field exists in Free.
            var $emails = $form.find('textarea[name="emails"]');
            if ($emails.length) {
                payload.emails = $emails.val() || '';
                payload.default_role = $form.find('select[name="default_role"]').val() || '';
            }

            ajax('dxf_review_create', payload).done(function (resp) {
                if (!resp || !resp.success) {
                    $out.addClass('error').text((resp && resp.data && resp.data.message) || t('rv.couldNotCreate', 'Could not create review.')).show();
                    $btn.prop('disabled', false).text(createLabel);
                    return;
                }
                publishAndRedirect(resp.data.review);
            }).fail(function () {
                $out.addClass('error').text(t('rv.networkError', 'Network error. Please try again.')).show();
                $btn.prop('disabled', false).text(createLabel);
            });
        });

        // Whole-site scope: count items in non-page post-type groups. If it's a
        // lot, show an inline "are you sure?" with the breakdown + a one-click
        // path to pick a subset instead. Returns true if it blocked (warned).
        function maybeWarnEntire() {
            var excess = 0;
            var breakdown = [];
            $form.find('.dxf-picker-group').each(function () {
                var pt    = this.getAttribute('data-pt') || '';
                var count = parseInt(this.getAttribute('data-count'), 10) || 0;
                if (pt === 'page' || count <= 0) return;       // pages are always fine
                excess += count;
                var lbl = (this.querySelector('summary') ? this.querySelector('summary').textContent : pt)
                            .replace(/\s*\(\d+\+?\)\s*$/, '').trim();
                breakdown.push(count + ' ' + lbl);
            });
            if (excess <= ENTIRE_EXCESS_LIMIT) { return false; } // small enough — proceed

            $form.find('.dxf-entire-warning').remove();
            var $w = $('<div class="dxf-entire-warning notice notice-warning inline" style="margin:14px 0;padding:12px 14px;"></div>').html(
                '<p style="margin:0 0 6px;">' +
                    t('rv.entireWarningLead', '<strong>Heads up — this whole-site review includes %d items beyond your pages</strong> (%s).')
                        .replace('%d', excess).replace('%s', breakdown.join(', ')) +
                '</p>' +
                '<p style="margin:0 0 10px;">' +
                    t('rv.entireWarningBody', 'Sending a client hundreds of items can be overwhelming. Want to pick just the ones that need reviewing?') +
                '</p>' +
                '<button type="button" class="button button-primary dxf-ew-pick">' + t('rv.pickSpecificItems', 'Pick specific items') + '</button> ' +
                '<button type="button" class="button dxf-ew-all">' + t('rv.includeEverything', 'Include everything') + '</button>'
            );
            $form.find('.dxf-step-1').append($w);
            $w[0].scrollIntoView({ behavior: 'smooth', block: 'center' });

            $w.find('.dxf-ew-pick').on('click', function () {
                $form.find('input[name="scope_type"][value="selected"]').prop('checked', true).trigger('change');
                $w.remove();
                var $picker = $form.find('.dxf-page-picker');
                if ($picker.length) { $picker[0].scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            });
            $w.find('.dxf-ew-all').on('click', function () {
                entireConfirmed = true;
                $w.remove();
                goto(2);
            });
            return true;
        }

        function publishAndRedirect(review) {
            ajax('dxf_review_publish', { review_id: review.id })
                .always(function () {
                    window.location = R.menuBase + '&action=edit&id=' + review.id;
                });
        }
    }

    // -----------------------------------------------------------------------
    // Edit / manage actions
    // -----------------------------------------------------------------------

    $(document).on('click', '.dxf-copy-url', function () {
        var $btn = $(this);
        var $url = $btn.siblings('.dxf-share-url');
        $url[0].select(); $url[0].setSelectionRange(0, 9999);
        try { document.execCommand('copy'); $btn.text(t('copied', 'Link copied')); setTimeout(function(){ $btn.text(t('rv.copy', 'Copy')); }, 1500); } catch(e) {}
    });

    function activationFailedMessage(resp) {
        return (resp && resp.data && resp.data.message) ||
               t('rv.couldNotChangeStatus', 'Could not change review status.');
    }

    $(document).on('click', '.dxf-publish', function () {
        var $btn = $(this).prop('disabled', true);
        var id = $btn.data('review-id');
        ajax('dxf_review_publish', { review_id: id })
            .done(function (resp) {
                if (resp && resp.success) { location.reload(); return; }
                alert(activationFailedMessage(resp));
                $btn.prop('disabled', false);
            })
            .fail(function () { location.reload(); });
    });

    $(document).on('click', '.dxf-close', function () {
        var id = $(this).data('review-id');
        ajax('dxf_review_close', { review_id: id }).always(function () { location.reload(); });
    });

    $(document).on('click', '.dxf-reopen', function () {
        var $btn = $(this).prop('disabled', true);
        var id = $btn.data('review-id');
        ajax('dxf_review_reopen', { review_id: id })
            .done(function (resp) {
                if (resp && resp.success) { location.reload(); return; }
                alert(activationFailedMessage(resp));
                $btn.prop('disabled', false);
            })
            .fail(function () { location.reload(); });
    });

    $(document).on('click', '.dxf-delete', function () {
        if (!confirm(t('confirmDelete', 'Delete this review and all its data? This cannot be undone.'))) return;
        var id = $(this).data('review-id');
        ajax('dxf_review_delete', { review_id: id }).done(function () {
            window.location = R.menuBase;
        });
    });

    // The reviewer-directory panel (invite / resend / revoke / inline role
    // change) belongs to the email-restricted review flow shipped in Dox Feedback
    // Pro. Pro enqueues its own admin script — with these handlers and the
    // row-flash helper — onto the review-edit screen via the
    // dxf_review_edit_reviewers_panel action. Free renders no directory,
    // so it binds nothing here.

})(jQuery);
