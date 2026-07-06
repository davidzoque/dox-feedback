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

  var I18N = (window.dxfReviewAdmin && window.dxfReviewAdmin.i18n) || {};
  function t(k, fb){ var v = I18N[k]; return (v === undefined || v === null || v === '') ? fb : v; }

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
        flashBtn($btn, t('rva.copied', 'Copied!'), 1600);
      }).catch(function () { legacyCopy(text, $btn); });
    } else {
      legacyCopy(text, $btn);
    }
  }

  function legacyCopy( text, $btn ) {
    var $tmp = $('<textarea>').val(text).appendTo('body').select();
    try {
      document.execCommand('copy');
      flashBtn($btn, t('rva.copied', 'Copied!'), 1600);
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
      $box.html('<p class="description">' + escHtml(t('rva.no_page', 'Navigate to a page to manage its review link.')) + '</p>');
      return;
    }

    if ( cfg.reviewUrl ) {
      $box.html(
        '<p><strong>' + escHtml(t('rva.active_link', 'Active review link:')) + '</strong><br>'
        + '<input type="text" readonly class="widefat" value="' + escHtml(cfg.reviewUrl) + '" style="margin-top:4px;"></p>'
        + '<p style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;">'
        + '<button type="button" class="button" id="dxf-copy-link">' + escHtml(t('rva.copy', 'Copy')) + '</button>'
        + '<button type="button" class="button button-small dxf-danger-btn" id="dxf-revoke-link">' + escHtml(t('rva.revoke_link', 'Revoke link')) + '</button>'
        + '</p>'
      );
    } else {
      $box.html(
        '<p style="color:#666;">' + escHtml(t('rva.no_link', 'No active review link.')) + '</p>'
        + '<button type="button" class="button button-primary" id="dxf-generate-link">' + escHtml(t('rva.generate_link', 'Generate review link')) + '</button>'
      );
    }

    $box.find('#dxf-generate-link').on('click', function () {
      var $btn = $(this).prop('disabled', true).text(t('rva.generating', 'Generating…'));
      generateLink(function (ok) {
        if ( ok ) renderMetaBox();
        else $btn.prop('disabled', false).text(t('rva.generate_link', 'Generate review link'));
      });
    });

    $box.find('#dxf-revoke-link').on('click', function () {
      if ( ! confirm(t('rva.revoke_confirm', 'Revoke this review link? Clients with the current URL will lose access.')) ) return;
      var $btn = $(this).prop('disabled', true).text(t('rva.revoking', 'Revoking…'));
      revokeLink(function (ok) {
        if ( ok ) renderMetaBox();
        else $btn.prop('disabled', false).text(t('rva.revoke_link', 'Revoke link'));
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
