/**
 * Dox Feedback — Elementor editor host adapter
 *
 * Thin shim over the shared comment engine for the Elementor editor, mirroring
 * the Bricks builder host (assets/builder/builder.js):
 *  - canvas        = the #elementor-preview-iframe document
 *  - anchoring     = forced to the Elementor adapter (data-id) via builderId
 *  - api           = nonce-based admin-ajax (identical contract to Bricks)
 *  - toggle        = a floating "Feedback" button (Elementor has no Bricks-style
 *                    toolbar to inject into, so we use the front-end FAB model)
 *  - viewport      = resizes Elementor's responsive preview wrapper
 *
 * All rendering, state and behaviour live in assets/comment-engine/engine.js;
 * element identification lives in assets/comment-engine/adapters.js. This file
 * only abstracts the parts that genuinely differ in the Elementor editor.
 */
(function () {
  'use strict';

  var cfg = window.dxfComments;
  if (!cfg || !cfg.postId) return;
  if (!window.DxfCommentEngine) { console.warn('Dox Feedback: comment engine missing'); return; }

  var I18N = (window.dxfComments && window.dxfComments.i18n) || {};
  function t(k, fb){ var v = I18N[k]; return (v === undefined || v === null || v === '') ? fb : v; }

  var ACCENT = (cfg.accent && /^#[0-9a-fA-F]{3,6}$/.test(cfg.accent)) ? cfg.accent : '#ff8d27';
  var POLL_INTERVAL = 300;
  var MAX_POLLS     = 60; // Elementor's preview iframe can be slow to mount.

  // ---------------------------------------------------------------------------
  // Per-browser identity (agencies often share one WP login across a team) —
  // same model as the Bricks host: a claimed name in a cookie, attributed onto
  // every comment, prompted once via the engine's identity gate.
  // ---------------------------------------------------------------------------
  var NAME_COOKIE = 'dxf_identity_name';
  function getCookie(name) {
    var m = document.cookie.match('(?:^|; )' + name.replace(/([.$?*|{}()\[\]\\\/\+^])/g, '\\$1') + '=([^;]*)');
    return m ? decodeURIComponent(m[1]) : '';
  }
  function setCookie(name, val, days) {
    var d = new Date();
    d.setTime(d.getTime() + days * 86400000);
    document.cookie = name + '=' + encodeURIComponent(val) + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
  }
  var builderName = getCookie(NAME_COOKIE) || '';

  // ---------------------------------------------------------------------------
  // Canvas iframe (Elementor renders the page inside #elementor-preview-iframe)
  // ---------------------------------------------------------------------------
  function getIframe() { return document.getElementById('elementor-preview-iframe'); }
  function getCanvasDoc() {
    var f = getIframe();
    if (!f) return null;
    try { return f.contentDocument || (f.contentWindow && f.contentWindow.document); }
    catch (e) { return null; }
  }
  function getCanvasWindow() { var f = getIframe(); return f ? f.contentWindow : null; }

  // Comment-mode cursor injected into the canvas iframe, scoped to Elementor
  // elements so the crosshair only shows over something anchorable.
  function commentCursor() {
    var fill = encodeURIComponent(ACCENT);
    return (
      "%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='30'%20height='30'%20viewBox='0%200%2030%2030'%3E" +
      "%3Cpath%20d='M5%203%20h20%20a2%202%200%200%201%202%202%20v12%20a2%202%200%200%201%20-2%202%20h-13%20l-5%205%20v-5%20h-2%20a2%202%200%200%201%20-2%20-2%20v-12%20a2%202%200%200%201%202%20-2%20z'%20fill='" + fill + "'%20stroke='%23ffffff'%20stroke-width='2'%20stroke-linejoin='round'/%3E" +
      "%3Cline%20x1='11'%20y1='11'%20x2='19'%20y2='11'%20stroke='%23ffffff'%20stroke-width='2'%20stroke-linecap='round'/%3E" +
      "%3Cline%20x1='15'%20y1='7'%20x2='15'%20y2='15'%20stroke='%23ffffff'%20stroke-width='2'%20stroke-linecap='round'/%3E" +
      "%3C/svg%3E"
    );
  }
  function applyCommentModeStyle(on) {
    var doc = getCanvasDoc();
    if (!doc) return;
    var el = doc.getElementById('dxf-canvas-style');
    if (on) {
      if (el) return;
      var s = doc.createElement('style');
      s.id = 'dxf-canvas-style';
      s.textContent =
        'body.dxf-comment-mode, body.dxf-comment-mode * { user-select: none !important; }' +
        'body.dxf-comment-mode .elementor-element, body.dxf-comment-mode .elementor-element * {' +
          'cursor: url("data:image/svg+xml,' + commentCursor() + '") 7 24, crosshair !important;' +
        '}';
      (doc.head || doc.documentElement).appendChild(s);
    } else if (el) { el.remove(); }
  }

  // Canvas click coords → screen coords (accounts for the iframe's offset, since
  // the sidebar/comment form render in the top editor window).
  function canvasClickToScreen(e) {
    var iframe = getIframe();
    var offset = iframe ? iframe.getBoundingClientRect() : { left: 0, top: 0 };
    return { x: e.clientX + offset.left, y: e.clientY + offset.top };
  }

  // ---------------------------------------------------------------------------
  // Canvas-ready / resize hooks
  // ---------------------------------------------------------------------------
  var canvasReadyCbs = [];
  function onCanvasReady(cb) {
    canvasReadyCbs.push(cb);
    var ifr = getIframe();
    if (ifr && !ifr.__dxfLoadHook) {
      ifr.__dxfLoadHook = true;
      // Elementor reloads the preview iframe on save / device switches; re-fire.
      ifr.addEventListener('load', function () {
        setTimeout(function () { canvasReadyCbs.forEach(function (f) { try { f(); } catch (e) {} }); }, 350);
      });
    }
    var doc = getCanvasDoc();
    if (doc && doc.readyState === 'complete') setTimeout(cb, 0);
  }
  var resizeCbs = [];
  function onCanvasResize(cb) {
    resizeCbs.push(cb);
    var win = getCanvasWindow();
    if (win && !win.__dxfResizeHook) {
      win.__dxfResizeHook = true;
      win.addEventListener('resize', function () { resizeCbs.forEach(function (f) { try { f(); } catch (e) {} }); }, { passive: true });
    }
  }

  // ---------------------------------------------------------------------------
  // Viewport switcher — resize Elementor's responsive preview wrapper so the
  // engine's device filter changes the canvas width like it does in Bricks.
  // ---------------------------------------------------------------------------
  function applyViewport(mode, width) {
    var wrap = document.getElementById('elementor-preview-responsive-wrapper');
    if (!wrap) return;
    wrap.style.transition = 'width .2s ease, height .2s ease';
    if (width) {
      wrap.style.width = width;
      wrap.style.margin = '0 auto';
    } else {
      wrap.style.width = '';
      wrap.style.margin = '';
    }
  }

  // ---------------------------------------------------------------------------
  // Nonce-based API (identical contract to the Bricks builder host)
  // ---------------------------------------------------------------------------
  function post(action, fields) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('_wpnonce', cfg.nonce);
    Object.keys(fields || {}).forEach(function (k) {
      if (k === '_files') return;
      var v = fields[k];
      fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
    });
    if (fields && fields._files) for (var i = 0; i < fields._files.length; i++) fd.append('attachments[]', fields._files[i]);
    return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }
  var api = {
    getComments: function () {
      return fetch(cfg.ajaxUrl + '?action=dxf_get_comments&post_id=' + cfg.postId + '&_wpnonce=' + cfg.nonce, { cache: 'no-store' })
        .then(function (r) { return r.json(); }).then(function (d) { return d.success ? d.data : []; });
    },
    getAllComments: function () {
      return fetch(cfg.ajaxUrl + '?action=dxf_get_all_builder_comments&_wpnonce=' + cfg.nonce, { cache: 'no-store' })
        .then(function (r) { return r.json(); }).then(function (d) { return d.success ? d.data : []; })
        .catch(function () { return []; });
    },
    addComment: function (payload) {
      payload = payload || {};
      payload.author_name = builderName || '';
      return post('dxf_add_comment', payload);
    },
    editComment: function (id, body) { return post('dxf_edit_comment', { id: String(id), body: body }); },
    resolveComment: function (id, status) {
      return post('dxf_resolve_comment', { id: id, status: status }).then(function (d) { return d.success; });
    },
    assignComment: function (id, assigneeId) { return post('dxf_assign_comment', { id: id, assignee_id: assigneeId }); },
    setCommentReview: function (id, reviewId) { return post('dxf_set_comment_review', { id: String(id), review_id: String(reviewId || 0) }); },
    summarize: function (postId) { return post('dxf_ai_summarize', { post_id: postId }); },
    updateAnchor: function (id, anchor) { return post('dxf_update_anchor', { id: String(id), anchor_data: JSON.stringify(anchor) }); },
    deleteComment: function (id) { return post('dxf_delete_comment', { id: String(id) }); },
    uploadScreenshot: function (dataUrl) {
      return post('dxf_upload_screenshot', { post_id: String(cfg.postId), screenshot: dataUrl })
        .then(function (d) { return (d.success && d.data && d.data.url) ? d.data.url : null; });
    },
    importToMedia: function (url) { return post('dxf_import_to_media', { url: url }); },
    unapprove: function (postId) { return post('dxf_unapprove_page', { post_id: postId }); },
    attachScreenshot: function (id, url) { return post('dxf_attach_screenshot', { id: String(id), screenshot_url: url }); },
    toggleReaction: function (id, reaction) {
      return post('dxf_toggle_reaction', { id: String(id), reaction: reaction, author_name: builderName || '' })
        .then(function (d) { return d.success ? d.data : null; });
    },
  };

  // ---------------------------------------------------------------------------
  // Floating toggle (FAB) — Elementor has no Bricks-style toolbar to dock into.
  // ---------------------------------------------------------------------------
  var PANEL_OPEN_KEY = 'dxf_elementor_panel_open';
  function mountToggle(opts) {
    if (document.getElementById('dxf-fab')) return;
    var fab = document.createElement('button');
    fab.id = 'dxf-fab';
    fab.type = 'button';
    fab.className = 'dxf-fab--bottom-right';
    fab.setAttribute('aria-label', t('elh.fab_aria', 'Feedback'));
    fab.innerHTML = opts.ICONS.comment + '<span>' + t('elh.fab_label', 'Comments') + '</span>';
    fab.addEventListener('click', opts.toggleComments);
    document.body.appendChild(fab);
    // Restore last open state (or force open via ?dxf_open=1).
    try {
      var forced = /[?&]dxf_open=1\b/.test(window.location.search);
      if (forced || localStorage.getItem(PANEL_OPEN_KEY) === '1') {
        setTimeout(opts.toggleComments, 250);
      }
    } catch (e) {}
  }
  function updateToggleActive(open) {
    try { localStorage.setItem(PANEL_OPEN_KEY, open ? '1' : '0'); } catch (e) {}
    var fab = document.getElementById('dxf-fab');
    if (fab) fab.classList.toggle('is-active', !!open);
  }

  // ---------------------------------------------------------------------------
  // One-time name prompt (shared-login attribution) — engine renders this when
  // identity.identified() is false. Mirrors the Bricks host gate.
  // ---------------------------------------------------------------------------
  function renderIdentityGate(body, onSuccess, opts) {
    opts = opts || {};
    var current = builderName || (cfg.currentUser || '');
    var esc = function (s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
    var intro = opts.changeNameOnly
      ? ''
      : '<p class="dxf-identity-intro">' + t('elh.identity_intro', 'You\'re commenting from this browser for the first time. Confirm the name to show on your comments — handy when your team shares one login.') + '</p>';
    body.innerHTML =
      '<div class="dxf-identity">' +
        intro +
        '<label class="dxf-identity-label">' + t('elh.identity_name_label', 'Your name') + '</label>' +
        '<input type="text" class="dxf-identity-input" id="dxf-id-name" value="' + esc(current) + '" placeholder="' + esc(t('elh.identity_name_placeholder', 'Jane Smith')) + '" autocomplete="name">' +
        '<p class="dxf-identity-error" id="dxf-id-error"></p>' +
        '<div class="dxf-identity-actions">' +
          (opts.changeNameOnly ? '<button type="button" class="dxf-btn dxf-btn-ghost" id="dxf-id-back">' + t('elh.identity_back', 'Back') + '</button>' : '') +
          '<button type="button" class="dxf-btn dxf-btn-primary" id="dxf-id-submit">' + (opts.changeNameOnly ? t('elh.identity_save', 'Save') : t('elh.identity_continue', 'Continue')) + '</button>' +
        '</div>' +
      '</div>';
    var input = body.querySelector('#dxf-id-name');
    var submit = function () {
      var n = input.value.trim();
      if (!n) { body.querySelector('#dxf-id-error').textContent = t('elh.identity_error_required', 'Please enter your name.'); return; }
      builderName = n;
      setCookie(NAME_COOKIE, n, 365);
      if (onSuccess) onSuccess();
    };
    body.querySelector('#dxf-id-submit').addEventListener('click', submit);
    var back = body.querySelector('#dxf-id-back');
    if (back) back.addEventListener('click', function () { if (typeof opts.onCancel === 'function') opts.onCancel(); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); submit(); }
      if (e.key === 'Escape' && typeof opts.onCancel === 'function') { e.preventDefault(); opts.onCancel(); }
    });
    input.focus(); input.select();
  }

  // ---------------------------------------------------------------------------
  // Editor marker — stamp a body class once Elementor's editor is present, so
  // our own CSS hooks can target the Elementor editor. NOTE: we deliberately do
  // NOT add `dxf-in-editor` — that class drives the Bricks builder's DOCKED
  // layout (which hides the floating FAB in favour of a toolbar toggle). The
  // Elementor host uses the floating-FAB model, identical to the front-end
  // reviewer overlay, so it wants the un-docked (frontend) layout from
  // review.css, not the Bricks dock.
  // ---------------------------------------------------------------------------
  function markEditor() {
    var attempts = 0;
    var t = setInterval(function () {
      attempts++;
      if (document.body && (document.getElementById('elementor-editor-wrapper') || getIframe())) {
        document.body.classList.add('dxf-elementor-editor');
        clearInterval(t);
      } else if (attempts >= MAX_POLLS) {
        clearInterval(t);
      }
    }, POLL_INTERVAL);
  }

  // ---------------------------------------------------------------------------
  // Native Elementor theming — read Elementor's live editor design tokens
  // (--e-a-*) and the editor font, and map them onto Dox Feedback's own theme
  // variables (--rv-*) so the panel adopts Elementor's exact palette, font and
  // light/dark mode. Reading at runtime (rather than hardcoding) keeps it in
  // sync with the user's UI-theme preference and survives Elementor updates.
  // ---------------------------------------------------------------------------
  function readToken(name) {
    var els = [document.documentElement, document.body,
               document.querySelector('.eps-app, #elementor-editor-wrapper, [class*="eps-theme"]')];
    for (var i = 0; i < els.length; i++) {
      if (!els[i]) continue;
      var v = getComputedStyle(els[i]).getPropertyValue(name);
      if (v && v.trim()) return v.trim();
    }
    return '';
  }
  // Relative luminance of a hex/rgb colour → decide light vs dark editor theme.
  function isDarkColor(c) {
    if (!c) return false;
    var r, g, b, m;
    if ((m = c.match(/^#([0-9a-f]{3})$/i)))  { r = parseInt(m[1][0]+m[1][0],16); g = parseInt(m[1][1]+m[1][1],16); b = parseInt(m[1][2]+m[1][2],16); }
    else if ((m = c.match(/^#([0-9a-f]{6})$/i))) { r = parseInt(m[1].slice(0,2),16); g = parseInt(m[1].slice(2,4),16); b = parseInt(m[1].slice(4,6),16); }
    else if ((m = c.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i))) { r = +m[1]; g = +m[2]; b = +m[3]; }
    else return false;
    return (0.2126 * r + 0.7152 * g + 0.0722 * b) < 128;
  }
  // The accent Elementor uses for active/primary UI (its magenta brand colour).
  function elementorAccent() {
    return readToken('--e-a-color-primary-bold') || readToken('--e-a-color-primary') || '';
  }
  function applyElementorTheme() {
    var root   = document.documentElement;
    var bg     = readToken('--e-a-bg-default');
    var txt    = readToken('--e-a-color-txt');
    var muted  = readToken('--e-a-color-txt-muted');
    var border = readToken('--e-a-border-color');
    var hover  = readToken('--e-a-bg-hover');
    var accent = elementorAccent();

    // Match Elementor's light/dark editor theme (so any var we don't map falls
    // back to the correct Dox Feedback light/dark palette).
    root.setAttribute('data-rv-theme', isDarkColor(bg) ? 'dark' : 'light');

    var set = function (k, v) { if (v) root.style.setProperty(k, v); };
    // Elementor's editor font.
    set('--rv-font-stack', '"Roboto", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif');
    // Surfaces: panel/modal = editor bg; cards/hover = the slightly-tinted hover bg.
    set('--rv-panel-bg', bg);
    set('--rv-modal',    bg);
    set('--rv-scene',    bg);
    set('--rv-surface',    hover || bg);
    set('--rv-surface-hi', hover || bg);
    set('--rv-hover',      hover);
    set('--rv-text',       txt);
    set('--rv-text-muted', muted);
    set('--rv-border',    border);
    set('--rv-border-hi', border);
    set('--rv-accent',    accent);
    // Legible text ON the accent. Elementor's accent is a DARK magenta in light
    // mode (#D004D4 → white text) but a LIGHT pink in dark mode (#f0abfc → needs
    // near-black text). Set it deterministically from the accent's luminance.
    if (accent) set('--rv-accent-text', isDarkColor(accent) ? '#ffffff' : '#111111');
    // Accent-tinted glow + soft fill so the FAB shadow / focus rings / pills
    // match the accent instead of the hardcoded default blue.
    var rgb = hexToRgb(accent);
    if (rgb) {
      set('--rv-accent-glow', 'rgba(' + rgb + ', .45)');
      set('--rv-accent-bg',   'rgba(' + rgb + ', .16)');
    }
  }
  function hexToRgb(hex) {
    if (!hex) return '';
    var m = /^#?([0-9a-f]{3})$/i.exec(hex);
    if (m) hex = m[1].replace(/(.)/g, '$1$1'); else { m = /^#?([0-9a-f]{6})$/i.exec(hex); if (!m) return ''; hex = m[1]; }
    return parseInt(hex.slice(0, 2), 16) + ', ' + parseInt(hex.slice(2, 4), 16) + ', ' + parseInt(hex.slice(4, 6), 16);
  }

  // ---------------------------------------------------------------------------
  // Boot
  // ---------------------------------------------------------------------------
  function boot() {
    markEditor();
    // Tint pins/cursor/primary actions with Elementor's accent before the engine
    // derives its own ACCENT from cfg.accent.
    var eAccent = elementorAccent();
    if (/^#[0-9a-fA-F]{3,6}$/.test(eAccent)) { ACCENT = eAccent; cfg.accent = eAccent; }
    DxfCommentEngine.ensureAccentContrast(document.documentElement);

    DxfCommentEngine.init({
      cfg: cfg,
      isBuilder: true,
      builderId: 'elementor', // force the Elementor anchor adapter
      brand: {
        accent: ACCENT,
        name: (cfg.brandName || t('elh.brand_name', 'Comments')),
        logo: (cfg.brandLogo || ''),
        color: '', textColor: '', isWhitelabel: false,
      },
      capabilities: {
        canAssign:        !!cfg.canAssignComments && cfg.showAssignPill !== false,
        canStatusSelect:  cfg.showStatusPill !== false,
        canResolve:       true,
        canDelete:        true,
        canDeleteOwnOnly: false,
        canViewport:      true,
        // The panel auto-matches Elementor's editor theme (light/dark), so the
        // manual light/dark toggle is redundant here — hide it.
        canToggleTheme:   false,
        // Editor users come to build; commenting is opt-in via the mode pill.
        defaultMode:      'browse',
        canReviewsPicker: true,
        canPickReview:    true,
        canScope:         true,
        canDeviceFilter:  true,
        canViewApprovals: true,
        canSummarize:     !!cfg.aiEnabled,
        canApprove:       false,
        canShareLink:     true,
        canFilterMine:    true,
        canDragPins:      true,
        canImportMedia:   !!cfg.canImportMedia,
        captureContext:   false,
        canAnnotate:      false,
        useCKey:          true,
        unreadAutoOpen:   true,
      },
      identity: {
        get name() { return builderName || (cfg.currentUser || ''); },
        email: '',
        id: (cfg.currentUserId || 0),
        isLoggedIn: true,
        identified: function () { return !!builderName; },
        requestIdentity: function () {},
      },
      renderIdentityGate: renderIdentityGate,
      api: api,
      getCanvasDoc:    getCanvasDoc,
      getCanvasWindow: getCanvasWindow,
      applyCommentModeStyle: applyCommentModeStyle,
      canvasClickToScreen:   canvasClickToScreen,
      applyViewport:   applyViewport,
      mountToggle:     mountToggle,
      updateToggleActive: updateToggleActive,
      onCanvasReady:   onCanvasReady,
      onCanvasResize:  onCanvasResize,
      defaultSidebarTop: function () { return 50; },
      scrollIsCanvas: false, // canvas scrolls inside the iframe, not the window
    });

    // Adopt Elementor's editor palette/font/theme. Re-apply shortly after in
    // case Elementor's design tokens or UI-theme class resolve after boot.
    applyElementorTheme();
    setTimeout(applyElementorTheme, 800);
    setTimeout(applyElementorTheme, 2500);

    // Drop the custom cursor when the editor window loses focus.
    function suspend() { applyCommentModeStyle(false); }
    function resume()  { applyCommentModeStyle(true); }
    window.addEventListener('blur',  suspend);
    window.addEventListener('focus', resume);
    document.addEventListener('visibilitychange', function () {
      document.hidden ? suspend() : resume();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}());
