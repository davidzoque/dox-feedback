/* global jQuery, dxfPins */
(function ($) {
  'use strict';

  var cfg = window.dxfPins || {};
  var i18n = cfg.i18n || {};

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
        window.alert((res && res.data && res.data.message) || i18n.error || 'Error');
        $btn.prop('disabled', false).removeClass('is-busy');
        return;
      }
      var resolved = (status === 'resolved');
      // Flip the card + button to reflect the new state.
      $card.removeClass('status-open status-in_progress status-resolved')
           .addClass(resolved ? 'status-resolved' : 'status-open');
      $card.find('.dxf-pin-status-label').text(resolved ? (i18n.resolved || 'Resolved') : i18n.open || 'Open');
      $btn.data('status', resolved ? 'open' : 'resolved')
          .text(resolved ? (i18n.reopen || 'Reopen') : (i18n.resolve || 'Mark resolved'))
          .prop('disabled', false).removeClass('is-busy');
    }).fail(function () {
      window.alert(i18n.error || 'Error');
      $btn.prop('disabled', false).removeClass('is-busy');
    });
  });
})(jQuery);
