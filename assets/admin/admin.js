/* global dxfAdmin */
(function ($) {
  'use strict';

  // ---------------------------------------------------------------------------
  // Test email
  // ---------------------------------------------------------------------------
  function initTestEmail() {
    var $btn    = $('#dxf-test-email');
    var $result = $('#dxf-test-email-result');

    if ( ! $btn.length ) return;

    $btn.on('click', function () {
      var email = $('#dxf-notify-email').val().trim() || dxfAdmin.notifyEmail || '';

      $btn.prop('disabled', true).text(dxfAdmin.i18n.sendingTest || 'Sending…');
      $result.text('').css('color', '');

      $.post(dxfAdmin.ajaxUrl, {
        action:      'dxf_send_test_email',
        _ajax_nonce: dxfAdmin.nonce,
        email:       email,
      })
        .done(function (res) {
          if ( res.success ) {
            $result.text(dxfAdmin.i18n.testEmailSent || 'Test email sent successfully.').css('color', '#16a34a');
          } else {
            var msg = (res.data && res.data.message) ? res.data.message : (dxfAdmin.i18n.testEmailFailed || 'Test email failed.');
            $result.text(msg).css('color', '#dc2626');
          }
        })
        .fail(function () {
          $result.text(dxfAdmin.i18n.error || 'Something went wrong.').css('color', '#dc2626');
        })
        .always(function () {
          $btn.prop('disabled', false).text('Send test email');
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
