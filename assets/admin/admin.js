/* global dxfAdmin */
(function ($) {
  'use strict';

  var I18N = (window.dxfAdmin && window.dxfAdmin.i18n) || {};
  function t(k, fb){ var v = I18N[k]; return (v === undefined || v === null || v === '') ? fb : v; }

  // ---------------------------------------------------------------------------
  // Test email
  // ---------------------------------------------------------------------------
  function initTestEmail() {
    var $btn    = $('#dxf-test-email');
    var $result = $('#dxf-test-email-result');

    if ( ! $btn.length ) return;

    $btn.on('click', function () {
      var email = $('#dxf-notify-email').val().trim() || dxfAdmin.notifyEmail || '';

      $btn.prop('disabled', true).text(t('adm.sending_test', 'Sending…'));
      $result.text('').css('color', '');

      $.post(dxfAdmin.ajaxUrl, {
        action:      'dxf_send_test_email',
        _ajax_nonce: dxfAdmin.nonce,
        email:       email,
      })
        .done(function (res) {
          if ( res.success ) {
            $result.text(t('adm.test_email_sent', 'Test email sent successfully.')).css('color', '#16a34a');
          } else {
            var msg = (res.data && res.data.message) ? res.data.message : t('adm.test_email_failed', 'Test email failed.');
            $result.text(msg).css('color', '#dc2626');
          }
        })
        .fail(function () {
          $result.text(t('adm.error', 'Something went wrong.')).css('color', '#dc2626');
        })
        .always(function () {
          $btn.prop('disabled', false).text(t('adm.send_test_email', 'Send test email'));
        });
    });
  }

  // ---------------------------------------------------------------------------
  // Boot
  // ---------------------------------------------------------------------------
  $(function () {
    initTestEmail();
  });

}(jQuery));
