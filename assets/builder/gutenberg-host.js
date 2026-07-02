/**
 * Dox Feedback — Gutenberg (block editor) host adapter
 *
 * Thin shim over the shared comment engine for the WordPress block editor,
 * mirroring the Bricks/Elementor hosts:
 *  - canvas    = the block editor's content document. Modern WP iframes it
 *                (iframe[name="editor-canvas"]); we fall back to the main
 *                document for non-iframed setups.
 *  - anchoring = forced to the Gutenberg adapter (generic cascade — blocks have
 *                no stable persistent id) via builderId.
 *  - api       = nonce-based admin-ajax (identical contract to the other hosts)
 *  - toggle    = a floating "Comments" button (block editor has no toolbar slot
 *                we want to hijack, so we use the front-end FAB model)
 *  - theme     = adopts WordPress's admin accent (--wp-admin-theme-color)
 *
 * All rendering/state/behaviour lives in assets/comment-engine/engine.js;
 * element identification lives in assets/comment-engine/adapters.js.
 */
(function () {
  'use strict';

  var cfg = window.dxfComments;
  if (!cfg || !cfg.postId) return;
  if (!window.DxfCommentEngine) { console.warn('Dox Feedback: comment engine missing'); return; }

  var ACCENT = (cfg.accent && /^#[0-9a-fA-F]{3,6}$/.test(cfg.accent)) ? cfg.accent : '#ff8d27';
  var POLL_INTERVAL = 300;
  var MAX_POLLS     = 80; // the block editor + its iframe can take a while to mount.

  // ---------------------------------------------------------------------------
  // Per-browser identity (shared/agency logins) — same model as the other hosts.
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
  // Canvas — the block editor content document (iframed in modern WP, inline in
  // older / non-block-theme setups).
  // ---------------------------------------------------------------------------
  function getIframe() { return document.querySelector('iframe[name="editor-canvas"]'); }
  function getCanvasDoc() {
    var f = getIframe();
    if (f) {
      try { return f.contentDocument || (f.contentWindow && f.contentWindow.document); }
      catch (e) { return null; }
    }
    // Non-iframed editor (or before the canvas iframe has mounted): blocks
    // render in the main document.
    return document;
  }
  function getCanvasWindow() {
    var f = getIframe();
    if (f) return f.contentWindow;
    return window;
  }
  var canvasIsIframe = function () { return !!getIframe(); };

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
        'body.dxf-comment-mode [data-block], body.dxf-comment-mode [class*="wp-block-"] {' +
          'cursor: url("data:image/svg+xml,' + commentCursor() + '") 7 24, crosshair !important;' +
        '}';
      (doc.head || doc.documentElement).appendChild(s);
    } else if (el) { el.remove(); }
  }

  // Canvas click coords → screen coords. When the canvas is iframed the sidebar/
  // form live in the top editor window, so add the iframe's offset; inline
  // (non-iframed) editors share the coordinate space already.
  function canvasClickToScreen(e) {
    if (!canvasIsIframe()) return { x: e.clientX, y: e.clientY };
    var iframe = getIframe();
    var offset = iframe ? iframe.getBoundingClientRect() : { left: 0, top: 0 };
    return { x: e.clientX + offset.left, y: e.clientY + offset.top };
  }

  // ---------------------------------------------------------------------------
  // Canvas-ready / resize hooks
  // ---------------------------------------------------------------------------
  var canvasReadyCbs = [];
  function fireReady() { canvasReadyCbs.forEach(function (f) { try { f(); } catch (e) {} }); }
  function onCanvasReady(cb) {
    canvasReadyCbs.push(cb);
    var ifr = getIframe();
    if (ifr && !ifr.__dxfLoadHook) {
      ifr.__dxfLoadHook = true;
      ifr.addEventListener('load', function () { setTimeout(fireReady, 300); });
    }
    var doc = getCanvasDoc();
    if (doc && (doc.readyState === 'complete' || doc === document)) setTimeout(cb, 0);
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
  // Nonce-based API (identical contract to the Bricks/Elementor hosts)
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
  // Floating toggle (FAB)
  // ---------------------------------------------------------------------------
  var PANEL_OPEN_KEY = 'dxf_gutenberg_panel_open';
  function mountToggle(opts) {
    if (document.getElementById('dxf-fab')) return;
    var fab = document.createElement('button');
    fab.id = 'dxf-fab';
    fab.type = 'button';
    fab.className = 'dxf-fab--bottom-right';
    fab.setAttribute('aria-label', 'Comments');
    fab.innerHTML = opts.ICONS.comment + '<span>Comments</span>';
    fab.addEventListener('click', opts.toggleComments);
    document.body.appendChild(fab);
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
    reflectToolbarToggle(open); // keep the top-bar button in sync (Esc/C/etc.)
  }

  // ---------------------------------------------------------------------------
  // One-time name prompt (shared-login attribution).
  // ---------------------------------------------------------------------------
  function renderIdentityGate(body, onSuccess, opts) {
    opts = opts || {};
    var current = builderName || (cfg.currentUser || '');
    var esc = function (s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
    var intro = opts.changeNameOnly
      ? ''
      : '<p class="dxf-identity-intro">You\'re commenting from this browser for the first time. Confirm the name to show on your comments — handy when your team shares one login.</p>';
    body.innerHTML =
      '<div class="dxf-identity">' +
        intro +
        '<label class="dxf-identity-label">Your name</label>' +
        '<input type="text" class="dxf-identity-input" id="dxf-id-name" value="' + esc(current) + '" placeholder="Jane Smith" autocomplete="name">' +
        '<p class="dxf-identity-error" id="dxf-id-error"></p>' +
        '<div class="dxf-identity-actions">' +
          (opts.changeNameOnly ? '<button type="button" class="dxf-btn dxf-btn-ghost" id="dxf-id-back">Back</button>' : '') +
          '<button type="button" class="dxf-btn dxf-btn-primary" id="dxf-id-submit">' + (opts.changeNameOnly ? 'Save' : 'Continue') + '</button>' +
        '</div>' +
      '</div>';
    var input = body.querySelector('#dxf-id-name');
    var submit = function () {
      var n = input.value.trim();
      if (!n) { body.querySelector('#dxf-id-error').textContent = 'Please enter your name.'; return; }
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
  // Native WordPress theming — adopt the admin accent (--wp-admin-theme-color)
  // and match light/dark to the editor surface.
  // ---------------------------------------------------------------------------
  function readToken(name) {
    var els = [document.documentElement, document.body];
    for (var i = 0; i < els.length; i++) {
      if (!els[i]) continue;
      var v = getComputedStyle(els[i]).getPropertyValue(name);
      if (v && v.trim()) return v.trim();
    }
    return '';
  }
  function isDarkColor(c) {
    if (!c) return false;
    var r, g, b, m;
    if ((m = c.match(/^#([0-9a-f]{3})$/i)))  { r = parseInt(m[1][0]+m[1][0],16); g = parseInt(m[1][1]+m[1][1],16); b = parseInt(m[1][2]+m[1][2],16); }
    else if ((m = c.match(/^#([0-9a-f]{6})$/i))) { r = parseInt(m[1].slice(0,2),16); g = parseInt(m[1].slice(2,4),16); b = parseInt(m[1].slice(4,6),16); }
    else if ((m = c.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i))) { r = +m[1]; g = +m[2]; b = +m[3]; }
    else return false;
    return (0.2126 * r + 0.7152 * g + 0.0722 * b) < 128;
  }
  function hexToRgb(hex) {
    if (!hex) return '';
    var m = /^#?([0-9a-f]{3})$/i.exec(hex);
    if (m) hex = m[1].replace(/(.)/g, '$1$1'); else { m = /^#?([0-9a-f]{6})$/i.exec(hex); if (!m) return ''; hex = m[1]; }
    return parseInt(hex.slice(0, 2), 16) + ', ' + parseInt(hex.slice(2, 4), 16) + ', ' + parseInt(hex.slice(4, 6), 16);
  }
  function applyWpTheme() {
    var root = document.documentElement;
    var accent = readToken('--wp-admin-theme-color') || '#007cba';
    // The WP admin chrome follows the user's colour scheme; match light/dark to
    // the admin body background so unmapped Dox Feedback vars use the right palette.
    var bg = '';
    try { bg = getComputedStyle(document.body).backgroundColor; } catch (e) {}
    root.setAttribute('data-rv-theme', isDarkColor(bg) ? 'dark' : 'light');

    var set = function (k, v) { if (v) root.style.setProperty(k, v); };
    set('--rv-accent', accent);
    if (accent) set('--rv-accent-text', isDarkColor(accent) ? '#ffffff' : '#111111');
    var rgb = hexToRgb(accent);
    if (rgb) {
      set('--rv-accent-glow', 'rgba(' + rgb + ', .4)');
      set('--rv-accent-bg',   'rgba(' + rgb + ', .14)');
    }
    if (DxfCommentEngine.ensureAccentContrast) DxfCommentEngine.ensureAccentContrast(root);
  }

  function markEditor() {
    var attempts = 0;
    var t = setInterval(function () {
      attempts++;
      if (document.body && (document.querySelector('.block-editor') || getIframe())) {
        document.body.classList.add('dxf-gutenberg-editor');
        clearInterval(t);
      } else if (attempts >= MAX_POLLS) {
        clearInterval(t);
      }
    }, POLL_INTERVAL);
  }

  // ---------------------------------------------------------------------------
  // Top-bar toggle — a "Comments" button injected into the editor header next to
  // Gutenberg's own controls (Preview/Settings), opening the FULL floating panel
  // (the narrow docked PluginSidebar wrapped/cut off the rich panel). Re-injected
  // via MutationObserver because the header is React-managed. Once it's present,
  // the floating FAB is hidden (this button replaces it); if injection ever
  // fails, the FAB stays as a fallback.
  // ---------------------------------------------------------------------------
  var TOGGLE_ID = 'dxf-gb-toggle';
  function toolbarToggleEl() { return document.getElementById(TOGGLE_ID); }
  function reflectToolbarToggle(open) {
    var b = toolbarToggleEl();
    if (b) { b.classList.toggle('is-pressed', !!open); b.setAttribute('aria-pressed', open ? 'true' : 'false'); }
  }
  function setupToolbarToggle(engineApi) {
    if (!engineApi) return;
    function makeButton() {
      var b = document.createElement('button');
      b.id = TOGGLE_ID;
      b.type = 'button';
      b.className = 'components-button dxf-gb-toggle';
      b.setAttribute('aria-label', 'Comments');
      b.setAttribute('aria-pressed', 'false');
      b.innerHTML =
        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>' +
        '</svg><span class="dxf-gb-toggle-label">Comments</span>';
      b.addEventListener('click', function () {
        if (engineApi.state && engineApi.state.sidebarOpen) engineApi.closeSidebar();
        else engineApi.openSidebar();
        reflectToolbarToggle(engineApi.state && engineApi.state.sidebarOpen);
      });
      return b;
    }
    function inject() {
      if (toolbarToggleEl()) return true;
      // The right-hand controls cluster in the editor header, across WP versions.
      var slot = document.querySelector('.editor-header__settings, .edit-post-header__settings');
      if (!slot) return false;
      slot.insertBefore(makeButton(), slot.firstChild);
      reflectToolbarToggle(engineApi.state && engineApi.state.sidebarOpen);
      document.body.classList.add('dxf-gb-has-toolbar'); // hides the FAB via CSS
      return true;
    }
    var tries = 0;
    var poll = setInterval(function () {
      tries++;
      if (inject() || tries >= MAX_POLLS) clearInterval(poll);
    }, POLL_INTERVAL);
    // The header re-renders (device switches, etc.) — keep the button present.
    setTimeout(function () {
      var header = document.querySelector('.edit-post-header, .editor-header');
      if (!header) return;
      new MutationObserver(function () {
        if (!toolbarToggleEl()) inject();
      }).observe(header, { childList: true, subtree: true });
    }, 1200);
  }

  // ---------------------------------------------------------------------------
  // Boot
  // ---------------------------------------------------------------------------
  function boot() {
    markEditor();
    var wpAccent = readToken('--wp-admin-theme-color');
    if (/^#[0-9a-fA-F]{3,6}$/.test(wpAccent)) { ACCENT = wpAccent; cfg.accent = wpAccent; }
    DxfCommentEngine.ensureAccentContrast(document.documentElement);

    var engineApi = DxfCommentEngine.init({
      cfg: cfg,
      isBuilder: true,
      builderId: 'gutenberg',
      brand: {
        accent: ACCENT,
        name: (cfg.brandName || 'Comments'),
        logo: (cfg.brandLogo || ''),
        color: '', textColor: '', isWhitelabel: false,
      },
      capabilities: {
        canAssign:        !!cfg.canAssignComments && cfg.showAssignPill !== false,
        canStatusSelect:  cfg.showStatusPill !== false,
        canResolve:       true,
        canDelete:        true,
        canDeleteOwnOnly: false,
        // The block editor owns device preview; don't fight it from here.
        canViewport:      false,
        // Auto-matches the WP admin theme, so the manual light/dark toggle is
        // redundant — hide it.
        canToggleTheme:   false,
        // Editor users come to build; commenting is opt-in via the mode pill.
        defaultMode:      'browse',
        // Offer a dock-to-side toggle in the panel header (no builder dock here).
        canDockRight:     true,
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
      mountToggle:     mountToggle,
      updateToggleActive: updateToggleActive,
      onCanvasReady:   onCanvasReady,
      onCanvasResize:  onCanvasResize,
      defaultSidebarTop: function () { return 80; },
      scrollIsCanvas: false, // canvas scrolls inside the iframe, not the window
    });

    applyWpTheme();
    setTimeout(applyWpTheme, 800);
    setTimeout(applyWpTheme, 2500);

    // A "Comments" button in the editor top bar (next to Preview/Settings) that
    // opens the full floating panel.
    setupToolbarToggle(engineApi);

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
