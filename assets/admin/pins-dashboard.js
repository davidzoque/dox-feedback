/* global jQuery, dxfPins */
(function ($) {
  'use strict';

  var cfg = window.dxfPins || {};
  var I18N = (window.dxfPins && window.dxfPins.i18n) || {};
  function t(k, fb){ var v = I18N[k]; return (v === undefined || v === null || v === '') ? fb : v; }

  // Mark a comment resolved / reopened via the shared resolve endpoint.
  $(document).on('click', '.dxf-pin-toggle', function () {
    var $btn = $(this);
    if ($btn.prop('disabled')) return;
    var id     = $btn.data('id');
    var status = $btn.data('status'); // the status we're moving TO
    var $card  = $btn.closest('.dxf-pin-card');

    $btn.prop('disabled', true).addClass('is-busy');

    $.post(cfg.ajaxUrl, {
      action: 'dxf_resolve_comment',
      _ajax_nonce: cfg.nonce,
      id: id,
      status: status
    }).done(function (res) {
      if (!res || !res.success) {
        window.alert((res && res.data && res.data.message) || t('pin.error', 'Error'));
        $btn.prop('disabled', false).removeClass('is-busy');
        return;
      }
      var resolved = (status === 'resolved');
      // Flip the card + button to reflect the new state.
      $card.removeClass('status-open status-in_progress status-resolved')
           .addClass(resolved ? 'status-resolved' : 'status-open');
      $card.find('.dxf-pin-status-label').text(resolved ? t('pin.resolved', 'Resolved') : t('pin.open', 'Open'));
      $btn.data('status', resolved ? 'open' : 'resolved')
          .text(resolved ? t('pin.reopen', 'Reopen') : t('pin.resolve', 'Mark resolved'))
          .prop('disabled', false).removeClass('is-busy');
    }).fail(function () {
      window.alert(t('pin.error', 'Error'));
      $btn.prop('disabled', false).removeClass('is-busy');
    });
  });
})(jQuery);
