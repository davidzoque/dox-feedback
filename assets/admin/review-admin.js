/* global dxfReviewAdmin, jQuery */
/**
 * Dox Feedback — post-edit meta box ("Client Review Link").
 *
 * The front-end admin-bar popout used to live in this file too. As of the
 * popout redesign it's owned by quick-review.js — this file is meta-box only.
 * Shared AJAX endpoints (dxf_generate_review_link, _revoke_) still live in
 * DXF_Review_Mode.
 */
(function ($) {
  'use strict';

  var cfg = window.dxfReviewAdmin;
  if ( ! cfg ) return;

  function escHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function generateLink(done) {
    if ( ! cfg.postId ) { if ( done ) done(false); return; }
    $.post(cfg.ajaxUrl, {
      action:   'dxf_generate_review_link',
      _wpnonce: cfg.nonce,
      post_id:  cfg.postId,
    }).done(function (res) {
      if ( res.success ) {
        cfg.reviewUrl = res.data.url;
        cfg.token     = res.data.token;
        if ( done ) done(true);
      } else { if ( done ) done(false); }
    }).fail(function () { if ( done ) done(false); });
  }

  function revokeLink(done) {
    if ( ! cfg.postId || ! cfg.token ) return;
    $.post(cfg.ajaxUrl, {
      action:   'dxf_revoke_review_link',
      _wpnonce: cfg.nonce,
      post_id:  cfg.postId,
      token:    cfg.token,
    }).done(function (res) {
      if ( res.success ) {
        cfg.reviewUrl = '';
        cfg.token     = '';
        if ( done ) done(true);
      } else { if ( done ) done(false); }
    }).fail(function () { if ( done ) done(false); });
  }

  function copyToClipboard( text, $btn ) {
    if ( navigator.clipboard && navigator.clipboard.writeText ) {
      navigator.clipboard.writeText(text).then(function () {
        flashBtn($btn, cfg.i18n.copied, 1600);
      }).catch(function () { legacyCopy(text, $btn); });
    } else {
      legacyCopy(text, $btn);
    }
  }

  function legacyCopy( text, $btn ) {
    var $tmp = $('<textarea>').val(text).appendTo('body').select();
    try {
      document.execCommand('copy');
      flashBtn($btn, cfg.i18n.copied, 1600);
    } catch (e) { /* silently fail */ }
    $tmp.remove();
  }

  function flashBtn( $btn, text, duration ) {
    var orig = $btn.text();
    $btn.text(text);
    setTimeout(function () { $btn.text(orig); }, duration);
  }

  function renderMetaBox() {
    var $box = $('#dxf-review-meta-box');
    if ( ! $box.length ) return;

    if ( ! cfg.postId ) {
      $box.html('<p class="description">' + cfg.i18n.noPage + '</p>');
      return;
    }

    if ( cfg.reviewUrl ) {
      $box.html(
        '<p><strong>' + escHtml(cfg.i18n.activeLink) + '</strong><br>'
        + '<input type="text" readonly class="widefat" value="' + escHtml(cfg.reviewUrl) + '" style="margin-top:4px;"></p>'
        + '<p style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;">'
        + '<button type="button" class="button" id="dxf-copy-link">Copy</button>'
        + '<button type="button" class="button button-small dxf-danger-btn" id="dxf-revoke-link">Revoke link</button>'
        + '</p>'
      );
    } else {
      $box.html(
        '<p style="color:#666;">' + escHtml(cfg.i18n.noLink) + '</p>'
        + '<button type="button" class="button button-primary" id="dxf-generate-link">Generate review link</button>'
      );
    }

    $box.find('#dxf-generate-link').on('click', function () {
      var $btn = $(this).prop('disabled', true).text(cfg.i18n.generating);
      generateLink(function (ok) {
        if ( ok ) renderMetaBox();
        else $btn.prop('disabled', false).text('Generate review link');
      });
    });

    $box.find('#dxf-revoke-link').on('click', function () {
      if ( ! confirm(cfg.i18n.revokeConfirm) ) return;
      var $btn = $(this).prop('disabled', true).text(cfg.i18n.revoking);
      revokeLink(function (ok) {
        if ( ok ) renderMetaBox();
        else $btn.prop('disabled', false).text('Revoke link');
      });
    });

    $box.find('#dxf-copy-link').on('click', function () {
      copyToClipboard(cfg.reviewUrl, $(this));
    });
  }

  $(function () {
    cfg.i18n             = cfg.i18n || {};
    cfg.i18n.noLink      = cfg.i18n.noLink      || 'No active review link.';
    cfg.i18n.activeLink  = cfg.i18n.activeLink  || 'Active review link:';
    cfg.i18n.generating  = cfg.i18n.generating  || 'Generating…';
    cfg.i18n.noPage      = cfg.i18n.noPage      || 'Navigate to a page to manage its review link.';
    renderMetaBox();
  });

}(jQuery));
