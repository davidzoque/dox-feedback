/**
 * Dox Feedback — Builder host adapter
 *
 * Thin shim over the shared comment engine. Provides:
 *  - canvas = the Bricks iframe document
 *  - nonce-based API
 *  - Bricks toolbar mount + preview-mode dance
 *  - viewport switcher that resizes the iframe element
 *  - full Pro/Agency capability set
 *
 * All rendering, state, and behaviour lives in assets/comment-engine/engine.js.
 */
(function () {
  'use strict';

  var cfg = window.dxfComments;
  if (!cfg || !cfg.postId) return;
  if (!window.DxfCommentEngine) { console.warn('Dox Feedback: comment engine missing'); return; }

  // ---------------------------------------------------------------------------
  // i18n — translations are supplied at window.dxfComments.i18n (shared across
  // the plugin's JS files). Keys here are prefixed `bld.` to avoid collisions.
  // t(key, fallback) returns the fallback whenever no translation is present,
  // so behaviour is unchanged when the site ships no translations.
  // ---------------------------------------------------------------------------
  var I18N = (cfg && cfg.i18n) || (window.dxfComments && window.dxfComments.i18n) || {};
  function t(k, fb){ var v = I18N[k]; return (v === undefined || v === null || v === '') ? fb : v; }

  var ACCENT = (cfg.accent && /^#[0-9a-fA-F]{3,6}$/.test(cfg.accent)) ? cfg.accent : '#ff8d27';
  var POLL_INTERVAL = 300;
  var MAX_POLLS     = 40;

  // ---------------------------------------------------------------------------
  // Per-browser identity (agencies often share one WP login across a team).
  // The claimed name is remembered in a cookie so each teammate's comments are
  // attributed to them; absent a cookie, the engine prompts once via the
  // identity gate below, then we stamp the name onto every comment they post.
  // ---------------------------------------------------------------------------
  var BUILDER_NAME_COOKIE = 'dxf_identity_name';
  function getCookie(name) {
    var m = document.cookie.match('(?:^|; )' + name.replace(/([.$?*|{}()\[\]\\\/\+^])/g, '\\$1') + '=([^;]*)');
    return m ? decodeURIComponent(m[1]) : '';
  }
  function setCookie(name, val, days) {
    var d = new Date();
    d.setTime(d.getTime() + days * 86400000);
    document.cookie = name + '=' + encodeURIComponent(val) + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
  }
  var builderName = getCookie(BUILDER_NAME_COOKIE) || '';

  // ---------------------------------------------------------------------------
  // Canvas iframe
  // ---------------------------------------------------------------------------
  function getIframe() { return document.getElementById('bricks-builder-iframe'); }
  function getCanvasDoc() {
    var f = getIframe();
    if (!f) return null;
    try { return f.contentDocument || (f.contentWindow && f.contentWindow.document); }
    catch (e) { return null; }
  }
  function getCanvasWindow() { var f = getIframe(); return f ? f.contentWindow : null; }

  // The comment-mode cursor: custom SVG injected into the canvas iframe doc.
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
      // Scope the comment cursor to Bricks elements so the reviewer only
      // sees the crosshair when they're actually over something they can
      // anchor a comment to. user-select stays disabled on body+* so drag
      // selections don't fight click-to-pin.
      s.textContent =
        'body.dxf-comment-mode, body.dxf-comment-mode * { user-select: none !important; }' +
        'body.dxf-comment-mode [id^="brxe-"], body.dxf-comment-mode [id^="brxe-"] * {' +
          'cursor: url("data:image/svg+xml,' + commentCursor() + '") 7 24, crosshair !important;' +
        '}';
      (doc.head || doc.documentElement).appendChild(s);
    } else if (el) { el.remove(); }
  }

  // Canvas click coordinates → screen coordinates (accounts for iframe offset).
  function canvasClickToScreen(e) {
    var iframe = getIframe();
    var offset = iframe ? iframe.getBoundingClientRect() : { left: 0, top: 0 };
    return { x: e.clientX + offset.left, y: e.clientY + offset.top };
  }

  // ---------------------------------------------------------------------------
  // Bricks preview observer (engine listens for exit)
  // ---------------------------------------------------------------------------
  var bricksPreviewToggled = false;
  function isBricksPreviewActive() {
    var tb = document.getElementById('bricks-toolbar');
    return tb ? tb.classList.contains('is-previewing') : false;
  }
  var bricksPreview = {
    isActive: isBricksPreviewActive,
    enable: function (cb) {
      if (isBricksPreviewActive()) { if (cb) cb(); return; }
      var btn = document.querySelector('#bricks-toolbar li.preview');
      if (!btn) { if (cb) cb(); return; }
      bricksPreviewToggled = true;
      btn.click();
      var attempts = 0;
      var t = setInterval(function () {
        attempts++;
        if (isBricksPreviewActive() || attempts >= 20) {
          clearInterval(t);
          if (cb) cb();
        }
      }, 50);
    },
    disable: function () {
      if (!bricksPreviewToggled) return;
      bricksPreviewToggled = false;
      if (!isBricksPreviewActive()) return;
      var btn = document.querySelector('#bricks-toolbar li.preview');
      if (btn) btn.click();
    },
    // Accepts either a plain `onExit` function (legacy) or an
    // `{ onEnter, onExit }` object. The body-class mirror still tracks
    // Preview state so builder.css can switch sidebar layout (docked in
    // editor, floating in preview) and hide overlay UI that only makes
    // sense over a rendered preview (FAB, pin layer, pending pin).
    observe: function (handlers) {
      var onEnter = (handlers && handlers.onEnter) ? handlers.onEnter : function () {};
      var onExit  = (handlers && typeof handlers === 'function') ? handlers
                  : (handlers && handlers.onExit) ? handlers.onExit
                  : function () {};
      var tb = document.getElementById('bricks-toolbar');
      if (!tb || tb.__dxfPreviewObs) return;
      tb.__dxfPreviewObs = true;

      function syncBodyClass() {
        document.body.classList.toggle('dxf-bricks-preview', tb.classList.contains('is-previewing'));
      }
      syncBodyClass();

      var wasInPreview = tb.classList.contains('is-previewing');
      new MutationObserver(function () {
        var inPreview = tb.classList.contains('is-previewing');
        syncBodyClass();
        if (!wasInPreview && inPreview) { onEnter(); }
        if (wasInPreview && !inPreview) { bricksPreviewToggled = false; onExit(); }
        wasInPreview = inPreview;
      }).observe(tb, { attributes: true, attributeFilter: ['class'] });
    },
  };

  // ---------------------------------------------------------------------------
  // Editor marker — stamp body.dxf-in-editor once Bricks's root element
  // exists. builder.css keys ALL editor-only layout (hide FAB/pins, docked
  // sidebar) off this class; it replaced `body:has(#bricks-app)`, which
  // silently failed to match on some Bricks/browser combinations and left
  // the panel floating. Poll briefly — Bricks mounts its Vue app async.
  // ---------------------------------------------------------------------------
  // Selector covers Bricks across versions: #bricks-app (older builds),
  // .brx-body (the 2.x builder shell wrapper), #bricks-toolbar (belt and
  // braces — it has survived every redesign so far).
  var EDITOR_ROOT_SEL = '#bricks-app, .brx-body, #bricks-toolbar';
  (function markEditor() {
    var tries = 0;
    var t = setInterval(function () {
      tries++;
      if (document.querySelector(EDITOR_ROOT_SEL)) {
        document.body.classList.add('dxf-in-editor');
        clearInterval(t);
      } else if (tries >= 40) {
        clearInterval(t);
        // Diagnostic: if this fires, Bricks renamed/moved its root elements on
        // this install and ALL editor-docking is disabled — report the Bricks
        // version when filing this.
        console.warn('[Dox Feedback] Bricks editor root not found after 12s (tried ' + EDITOR_ROOT_SEL + ') — comments panel docking disabled.');
      }
    }, 300);
    // Background tabs throttle the poll above hard (timers run ~1/min after a
    // few minutes hidden) — re-check the moment the tab becomes visible so a
    // builder opened in a background tab still docks instantly on focus.
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && document.querySelector(EDITOR_ROOT_SEL)) {
        document.body.classList.add('dxf-in-editor');
      }
    });
  })();

  // ---------------------------------------------------------------------------
  // API (nonce-only)
  // ---------------------------------------------------------------------------
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
      var fd = new FormData();
      fd.append('action', 'dxf_add_comment');
      fd.append('_wpnonce', cfg.nonce);
      // Per-browser claimed name (shared-login attribution). Empty falls back
      // to the WP display name server-side.
      fd.append('author_name', builderName || '');
      Object.keys(payload).forEach(function (k) {
        if (k === '_files') return;
        var v = payload[k];
        fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
      });
      if (payload._files) for (var i = 0; i < payload._files.length; i++) fd.append('attachments[]', payload._files[i]);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    editComment: function (id, body) {
      var fd = new FormData();
      fd.append('action', 'dxf_edit_comment'); fd.append('_wpnonce', cfg.nonce);
      fd.append('id', String(id)); fd.append('body', body);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    resolveComment: function (id, status) {
      var fd = new FormData();
      fd.append('action', 'dxf_resolve_comment'); fd.append('_wpnonce', cfg.nonce);
      fd.append('id', id); fd.append('status', status);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) { return d.success; });
    },
    assignComment: function (id, assigneeId) {
      var fd = new FormData();
      fd.append('action', 'dxf_assign_comment'); fd.append('_wpnonce', cfg.nonce);
      fd.append('id', id); fd.append('assignee_id', assigneeId);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    setCommentReview: function (id, reviewId) {
      var fd = new FormData();
      fd.append('action', 'dxf_set_comment_review'); fd.append('_wpnonce', cfg.nonce);
      fd.append('id', String(id)); fd.append('review_id', String(reviewId || 0));
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    summarize: function (postId) {
      var fd = new FormData();
      fd.append('action', 'dxf_ai_summarize'); fd.append('_wpnonce', cfg.nonce); fd.append('post_id', postId);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    updateAnchor: function (id, anchor) {
      var fd = new FormData();
      fd.append('action', 'dxf_update_anchor'); fd.append('_wpnonce', cfg.nonce);
      fd.append('id', String(id)); fd.append('anchor_data', JSON.stringify(anchor));
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    deleteComment: function (id) {
      var fd = new FormData();
      fd.append('action', 'dxf_delete_comment'); fd.append('_wpnonce', cfg.nonce); fd.append('id', String(id));
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    uploadScreenshot: function (dataUrl) {
      var fd = new FormData();
      fd.append('action', 'dxf_upload_screenshot'); fd.append('_wpnonce', cfg.nonce);
      fd.append('post_id', String(cfg.postId)); fd.append('screenshot', dataUrl);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); })
        .then(function (d) { return (d.success && d.data && d.data.url) ? d.data.url : null; });
    },
    importToMedia: function (url) {
      var fd = new FormData();
      fd.append('action', 'dxf_import_to_media'); fd.append('_wpnonce', cfg.nonce); fd.append('url', url);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    unapprove: function (postId) {
      var fd = new FormData();
      fd.append('action', 'dxf_unapprove_page'); fd.append('_wpnonce', cfg.nonce); fd.append('post_id', postId);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    attachScreenshot: function (id, url) {
      var fd = new FormData();
      fd.append('action', 'dxf_attach_screenshot'); fd.append('_wpnonce', cfg.nonce);
      fd.append('id', String(id)); fd.append('screenshot_url', url);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    toggleReaction: function (id, reaction) {
      var fd = new FormData();
      fd.append('action', 'dxf_toggle_reaction'); fd.append('_wpnonce', cfg.nonce);
      fd.append('id', String(id)); fd.append('reaction', reaction);
      // Display attribution only — the server keys logged-in reactions on the
      // WP user id regardless of the claimed name.
      fd.append('author_name', builderName || '');
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); })
        .then(function (d) { return d.success ? d.data : null; });
    },
  };

  // ---------------------------------------------------------------------------
  // Toolbar mount (Bricks-toolbar <li> with badge + open count)
  // ---------------------------------------------------------------------------
  var toggleEl = null;
  // Remember whether the panel was open so refreshes / page hops inside the
  // builder restore it (position, size and dock mode are already persisted).
  var PANEL_OPEN_KEY = 'dxf_builder_panel_open';
  function restorePanelOpen(toggleComments) {
    try {
      // ?dxf_open=1 (e.g. the "open comments" link in the admin-bar
      // quick-review popout) force-opens the panel regardless of how it was
      // left last session.
      var forced = /[?&]dxf_open=1\b/.test(window.location.search);
      if (forced || localStorage.getItem(PANEL_OPEN_KEY) === '1') {
        // Small delay: let the engine finish mounting before toggling.
        setTimeout(toggleComments, 200);
      }
    } catch (e) {}
  }
  function mountToggle(opts) {
    var attempts = 0;
    var poll = setInterval(function () {
      attempts++;
      var previewBtn = document.querySelector('#bricks-toolbar li.preview');
      var undoBtn    = document.querySelector('#bricks-toolbar ul.group-wrapper.end li.undo');
      var anchor     = previewBtn || undoBtn;
      if (!anchor && attempts < MAX_POLLS) return;
      clearInterval(poll);
      if (document.getElementById('dxf-tb-sidebar')) {
        toggleEl = document.getElementById('dxf-tb-sidebar');
        restorePanelOpen(opts.toggleComments);
        return;
      }

      var li = document.createElement('li');
      li.id = 'dxf-tb-sidebar';
      li.setAttribute('data-balloon', t('bld.comments', 'Comments'));
      li.setAttribute('data-balloon-pos', 'bottom');
      li.setAttribute('tabindex', '0');
      li.setAttribute('role', 'button');
      li.setAttribute('aria-pressed', 'false');
      li.innerHTML = opts.ICONS.comment + '<span class="dxf-open-badge" id="dxf-open-badge" style="display:none"></span>';
      li.addEventListener('click', opts.toggleComments);
      li.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); opts.toggleComments(); }
      });

      if (previewBtn && previewBtn.parentNode) {
        previewBtn.parentNode.insertBefore(li, previewBtn.nextSibling);
      } else if (undoBtn && undoBtn.parentNode) {
        undoBtn.parentNode.insertBefore(li, undoBtn);
      } else {
        (document.getElementById('bricks-toolbar') || document.body).appendChild(li);
      }
      toggleEl = li;
      restorePanelOpen(opts.toggleComments);
    }, POLL_INTERVAL);
  }
  function updateToggleActive(open) {
    try { localStorage.setItem(PANEL_OPEN_KEY, open ? '1' : '0'); } catch (e) {}
    if (!toggleEl) return;
    toggleEl.classList.toggle('dxf-tb-active', !!open);
    toggleEl.setAttribute('aria-pressed', open ? 'true' : 'false');
  }

  // ---------------------------------------------------------------------------
  // Canvas-ready / canvas-resize hooks
  // ---------------------------------------------------------------------------
  var canvasReadyCbs = [];
  function onCanvasReady(cb) {
    canvasReadyCbs.push(cb);
    var ifr = getIframe();
    if (ifr && !ifr.__dxfLoadHook) {
      ifr.__dxfLoadHook = true;
      ifr.addEventListener('load', function () {
        setTimeout(function () { canvasReadyCbs.forEach(function (f) { try { f(); } catch (e) {} }); }, 350);
      });
    }
    // If canvas already loaded, fire immediately.
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
  // Viewport switcher resizes the Bricks iframe element width
  // ---------------------------------------------------------------------------
  function applyViewport(mode, width) {
    var iframe = getIframe();
    if (!iframe) return;
    iframe.style.transition = 'width .2s ease';
    if (width) {
      iframe.style.width = width;
      iframe.style.maxWidth = '100%';
      iframe.style.margin = '0 auto';
      iframe.style.display = 'block';
    } else {
      iframe.style.width = '';
      iframe.style.margin = '';
    }
  }

  // ---------------------------------------------------------------------------
  // One-time name prompt (shared-login attribution). The engine calls this
  // when identity.identified() is false; on save we store the cookie + name so
  // it's never asked again on this browser. Supports the engine's
  // changeNameOnly flow (the "change name" link in the sidebar footer).
  // ---------------------------------------------------------------------------
  function renderIdentityGate(body, onSuccess, opts) {
    opts = opts || {};
    var current = builderName || (cfg.currentUser || '');
    var esc = function (s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
    var intro = opts.changeNameOnly
      ? ''
      : '<p class="dxf-identity-intro">' + esc(t('bld.identityIntro', "You're commenting from this browser for the first time. Confirm the name to show on your comments — handy when your team shares one login.")) + '</p>';
    body.innerHTML =
      '<div class="dxf-identity">' +
        intro +
        '<label class="dxf-identity-label">' + esc(t('bld.yourName', 'Your name')) + '</label>' +
        '<input type="text" class="dxf-identity-input" id="dxf-id-name" value="' + esc(current) + '" placeholder="' + esc(t('bld.namePlaceholder', 'Jane Smith')) + '" autocomplete="name">' +
        '<p class="dxf-identity-error" id="dxf-id-error"></p>' +
        '<div class="dxf-identity-actions">' +
          (opts.changeNameOnly ? '<button type="button" class="dxf-btn dxf-btn-ghost" id="dxf-id-back">' + esc(t('bld.back', 'Back')) + '</button>' : '') +
          '<button type="button" class="dxf-btn dxf-btn-primary" id="dxf-id-submit">' + esc(opts.changeNameOnly ? t('bld.save', 'Save') : t('bld.continue', 'Continue')) + '</button>' +
        '</div>' +
      '</div>';
    var input = body.querySelector('#dxf-id-name');
    var submit = function () {
      var n = input.value.trim();
      if (!n) { body.querySelector('#dxf-id-error').textContent = t('bld.nameRequired', 'Please enter your name.'); return; }
      builderName = n;
      setCookie(BUILDER_NAME_COOKIE, n, 365);
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
  // Docked-panel layout — "partner" the Dox Feedback panel with the Bricks Structure
  // panel: dock into the same column, stacked ABOVE it, and push the Structure
  // panel down to share the column. The bottom handle is the divider between
  // the two (drag = give each more/less height); the inner (canvas-facing)
  // handle resizes the column width for BOTH. Auto-detects the Structure side
  // (left or right) — it's configurable per Bricks instance. Falls back to a
  // plain left full-height dock when no Structure panel is present. Everything
  // is reverted when the panel closes or Preview mode is entered, so Bricks'
  // own layout is never left in a half-modified state.
  // ---------------------------------------------------------------------------
  var DOCK_W_KEY = 'dxf_dock_w';
  var DOCK_H_KEY = 'dxf_dock_h';
  function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }
  function dockToolbarBottom() {
    var tb = document.getElementById('bricks-toolbar');
    return tb ? Math.round(tb.getBoundingClientRect().bottom) : 33;
  }
  function structurePanel() {
    var el = document.querySelector('#bricks-structure');
    if (!el) return null;
    // Don't use offsetParent to test visibility: it's null for BOTH hidden
    // elements AND position:fixed ones, and Bricks pins the Structure panel
    // fixed — which made this reject the panel and silently degrade to the
    // full-height dock. Test real geometry + computed visibility instead
    // (display:none collapses the rect to 0×0; visibility:hidden doesn't,
    // hence the explicit computed-style check).
    var r = el.getBoundingClientRect();
    if (r.width < 80 || r.height < 80) return null;
    var cs = window.getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden') return null;
    return el;
  }
  function enhanceDock() {
    var attempts = 0;
    var poll = setInterval(function () {
      attempts++;
      var sidebar = document.getElementById('dxf-sidebar');
      if (!sidebar && attempts < MAX_POLLS) return;
      clearInterval(poll);
      if (!sidebar || sidebar.__dxfDockEnhanced) return;
      sidebar.__dxfDockEnhanced = true;

      sidebar.classList.add('dxf-dock-resizable');
      var hx = document.createElement('div');
      hx.className = 'dxf-dock-resize dxf-dock-resize-x';
      hx.setAttribute('title', t('bld.resizeWidth', 'Drag to resize width'));
      var hy = document.createElement('div');
      hy.className = 'dxf-dock-resize dxf-dock-resize-y';
      hy.setAttribute('title', t('bld.resizeHeight', 'Drag to resize height (divider)'));
      sidebar.appendChild(hx);
      sidebar.appendChild(hy);

      var structRef = null, structOrigMT = 0;
      var rightAligned = (function () { try { return localStorage.getItem('dxf_dock_right') === '1'; } catch (e) { return false; } })();
      var desired = null, structObs = null, rafPending = false, lastCol = null, canvasPad = null;
      function savedW(def) { var v = parseInt(localStorage.getItem(DOCK_W_KEY) || '', 10); return v || def; }
      function savedH(def) { var v = parseInt(localStorage.getItem(DOCK_H_KEY) || '', 10); return v || def; }
      function inEditorDock() {
        return !!document.querySelector(EDITOR_ROOT_SEL) &&
               document.body && !document.body.classList.contains('dxf-bricks-preview') &&
               sidebar.classList.contains('dxf-sidebar--open') &&
               !sidebar.classList.contains('dxf-float');
      }
      function revertStructure() {
        if (structObs) { structObs.disconnect(); structObs = null; }
        if (structRef) {
          // Remove ONLY the properties we own — never restore the whole
          // style attribute. Bricks keeps live state inline (display:none
          // while hidden, width from its own resizer); clobbering it
          // resurrects a panel the user just closed and the two systems
          // oscillate.
          structRef.style.removeProperty('margin-top');
          structRef.style.removeProperty('height');
          structRef.style.removeProperty('max-height');
        }
        structRef = null; structOrigMT = 0; desired = null;
      }

      // When the Structure panel is closed, Bricks reclaims its column and
      // the canvas widens UNDER our dock — so we reserve the column ourselves
      // with a margin on the canvas wrapper. Never used while Structure is
      // visible (Bricks reserves the space then).
      function reserveCanvas(px, alignRight) {
        var el = document.querySelector('#bricks-builder-iframe-wrapper, #bricks-preview');
        if (!el) return;
        var prop = alignRight ? 'marginRight' : 'marginLeft';
        if (canvasPad && (canvasPad.el !== el || canvasPad.prop !== prop)) releaseCanvas();
        if (!canvasPad) canvasPad = { el: el, prop: prop, orig: el.style[prop] || '' };
        el.style[prop] = px + 'px';
      }
      function releaseCanvas() {
        if (!canvasPad) return;
        canvasPad.el.style[canvasPad.prop] = canvasPad.orig;
        canvasPad = null;
      }

      // Has Bricks rewritten the vertical push we applied? We only ever set
      // marginTop/height — position, width and visibility stay Bricks-owned,
      // so its own resizer and show/hide toggle are never fought.
      function structDrifted(struct) {
        if (!desired) return false;
        var s = struct.style;
        var ne = function (prop, val) { return Math.abs((parseFloat(s[prop]) || 0) - val) > 1.5; };
        return ne('marginTop', desired.mt) || ne('height', desired.h);
      }
      function attachStructObserver(struct) {
        if (structObs) structObs.disconnect();
        structObs = new MutationObserver(function () {
          if (!structRef) return;
          // React to the panel being removed, hidden (display:none toggles a
          // style/class mutation), or our push being rewritten — layout()
          // re-resolves and either re-stacks or expands us to full height.
          if (!structRef.isConnected || structurePanel() !== structRef || structDrifted(structRef)) {
            scheduleLayout();
            return;
          }
          // Follow Bricks' own column changes (its native width-resizer).
          if (lastCol) {
            var rr = structRef.getBoundingClientRect();
            if (Math.abs(rr.left - lastCol.left) > 1.5 || Math.abs(rr.width - lastCol.w) > 1.5) {
              scheduleLayout();
            }
          }
        });
        structObs.observe(struct, { attributes: true, attributeFilter: ['style', 'class'] });
        // Removal happens on the parent's childList, not the node itself.
        if (struct.parentNode) structObs.observe(struct.parentNode, { childList: true });
      }

      // Push the Structure panel DOWN below our panel — marginTop + height
      // ONLY. Its position, width and visibility stay 100% Bricks-owned, so:
      // the canvas keeps reserving the column (no overlap), Bricks' native
      // width-resizer keeps working, and its show/hide toggle isn't fought.
      function pushStructureDown(struct, rvH, avail) {
        if (structRef !== struct) {
          revertStructure();
          structRef = struct;
          // Bricks 2.x clears its toolbar with the panel's own top margin —
          // our push is ADDED to it so the panels meet with no ghost gap.
          structOrigMT = parseFloat(window.getComputedStyle(struct).marginTop) || 0;
          attachStructObserver(struct);
        }
        var mt = structOrigMT + rvH;
        var h  = avail - rvH;
        struct.style.marginTop = mt + 'px';
        struct.style.height    = h + 'px';
        struct.style.maxHeight = h + 'px';
        desired = { mt: mt, h: h };
      }

      // Change signature of the last APPLIED layout — all DOM writes are
      // gated on it. Observers fire often while Bricks edits; a layout pass
      // that changes nothing must cost two rect reads and zero writes, or we
      // thrash Bricks' own render loop and the whole builder crawls.
      var lastSig = '';
      function layout() {
        if (!inEditorDock()) {
          revertStructure(); releaseCanvas(); lastCol = null; lastSig = '';
          return;
        }
        var top   = dockToolbarBottom();
        var avail = window.innerHeight - top;

        var struct = structurePanel();
        if (!struct) {
          // No Structure panel (hidden/closed) → fill the full edge height,
          // staying in whichever column we last stacked in. Bricks reclaims
          // the panel's space when it closes, so WE reserve the column on
          // the canvas wrapper — the dock must never overlap the canvas.
          var w0  = clamp(savedW(340), 240, 720);
          var sig = 'N,' + top + ',' + w0 + ',' + rightAligned + ',' + window.innerWidth;
          revertStructure();
          lastCol = null;
          if (sig === lastSig) return;
          lastSig = sig;
          sidebar.style.setProperty('--rv-dock-top', top + 'px');
          sidebar.style.setProperty('--rv-dock-left', rightAligned ? (window.innerWidth - w0) + 'px' : '0px');
          sidebar.style.setProperty('--rv-dock-w', w0 + 'px');
          sidebar.style.removeProperty('--rv-dock-h');
          sidebar.classList.toggle('dxf-dock-x-inner-left', rightAligned);
          sidebar.classList.remove('dxf-dock-stacked');
          reserveCanvas(w0, rightAligned);
          return;
        }

        var r = struct.getBoundingClientRect();
        var ra = (window.innerWidth - r.right) < 8;
        // FOLLOW the column Bricks lays out (use Bricks' own resizer for
        // width) — we only own the vertical split between the two panels.
        var colW    = Math.round(r.width);
        var colLeft = Math.round(r.left);
        var rvH     = clamp(savedH(Math.round(avail * 0.6)), 220, avail - 160);
        lastCol     = { left: colLeft, w: colW };

        var sig = 'S,' + top + ',' + colLeft + ',' + colW + ',' + rvH + ',' + ra;
        var structNeedsPush = (structRef !== struct) || structDrifted(struct);
        if (sig === lastSig && !structNeedsPush) return;

        if (sig !== lastSig) {
          lastSig = sig;
          // Structure visible → Bricks reserves the column; never pad canvas.
          releaseCanvas();
          if (ra !== rightAligned) {
            rightAligned = ra;
            try { localStorage.setItem('dxf_dock_right', ra ? '1' : '0'); } catch (e) {}
          }
          sidebar.style.setProperty('--rv-dock-top', top + 'px');
          sidebar.style.setProperty('--rv-dock-left', colLeft + 'px');
          sidebar.style.setProperty('--rv-dock-w', colW + 'px');
          sidebar.style.setProperty('--rv-dock-h', rvH + 'px');
          sidebar.classList.toggle('dxf-dock-x-inner-left', ra);
          sidebar.classList.add('dxf-dock-stacked');
        }
        pushStructureDown(struct, rvH, avail);
      }

      function startResize(axis, e) {
        e.preventDefault(); e.stopPropagation();
        if (!inEditorDock()) return;
        // Width is Bricks-owned while stacked (use Bricks' panel resizer);
        // our x-handle only operates in the no-Structure full-height mode.
        if (axis === 'x' && sidebar.classList.contains('dxf-dock-stacked')) return;
        var startX = e.clientX, startY = e.clientY;
        var startW = sidebar.offsetWidth, startH = sidebar.offsetHeight;
        var top    = dockToolbarBottom();
        var avail  = window.innerHeight - top;
        var struct = structurePanel();
        var iframe = getIframe();
        if (iframe) iframe.style.pointerEvents = 'none';
        sidebar.classList.add('dxf-dock-dragging');

        function onMove(me) {
          if (axis === 'x') {
            // No-Structure mode only: width grows toward the canvas
            // (leftward when right-aligned); keep the canvas reservation
            // in lockstep so the dock never overlaps it.
            var delta = rightAligned ? (startX - me.clientX) : (me.clientX - startX);
            var w = clamp(startW + delta, 240, 720);
            var colLeft = rightAligned ? (window.innerWidth - w) : (parseFloat(sidebar.style.getPropertyValue('--rv-dock-left')) || 0);
            sidebar.style.setProperty('--rv-dock-w', w + 'px');
            sidebar.style.setProperty('--rv-dock-left', colLeft + 'px');
            reserveCanvas(w, rightAligned);
          } else {
            var h = clamp(startH + (me.clientY - startY), 220, avail - 160);
            sidebar.style.setProperty('--rv-dock-h', h + 'px');
            if (struct) {
              struct.style.marginTop = (structOrigMT + h) + 'px';
              struct.style.height    = (avail - h) + 'px';
              struct.style.maxHeight = (avail - h) + 'px';
            }
          }
        }
        function onUp() {
          document.removeEventListener('mousemove', onMove, true);
          document.removeEventListener('mouseup', onUp, true);
          if (iframe) iframe.style.pointerEvents = '';
          sidebar.classList.remove('dxf-dock-dragging');
          var w = parseInt(sidebar.style.getPropertyValue('--rv-dock-w'), 10);
          var h = parseInt(sidebar.style.getPropertyValue('--rv-dock-h'), 10);
          if (w) localStorage.setItem(DOCK_W_KEY, String(w));
          if (h) localStorage.setItem(DOCK_H_KEY, String(h));
        }
        document.addEventListener('mousemove', onMove, true);
        document.addEventListener('mouseup', onUp, true);
      }
      hx.addEventListener('mousedown', function (e) { startResize('x', e); });
      hy.addEventListener('mousedown', function (e) { startResize('y', e); });

      // Dock / float toggle in the sidebar header (editor only — CSS hides it
      // elsewhere). Default is DOCKED; the choice persists per-browser.
      var DOCK_MODE_KEY = 'dxf_dock_mode';
      var dockBtn = null;
      function applyDockMode(mode) {
        var floating = mode === 'float';
        sidebar.classList.toggle('dxf-float', floating);
        if (dockBtn) {
          dockBtn.classList.toggle('is-active', !floating);
          dockBtn.setAttribute('title', floating ? t('bld.dockPanel', 'Dock panel') : t('bld.floatPanel', 'Float panel'));
        }
        if (floating) { revertStructure(); releaseCanvas(); }
        scheduleLayout();
      }
      var themeBtn = sidebar.querySelector('.dxf-theme-toggle');
      if (themeBtn && themeBtn.parentNode) {
        dockBtn = document.createElement('button');
        dockBtn.type = 'button';
        dockBtn.className = 'dxf-dock-toggle';
        dockBtn.setAttribute('aria-label', t('bld.dockOrFloat', 'Dock or float the panel'));
        dockBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="9" y1="4" x2="9" y2="20"/></svg>';
        themeBtn.parentNode.insertBefore(dockBtn, themeBtn);
        dockBtn.addEventListener('click', function () {
          var next = sidebar.classList.contains('dxf-float') ? 'dock' : 'float';
          try { localStorage.setItem(DOCK_MODE_KEY, next); } catch (e) {}
          applyDockMode(next);
        });
      }
      var savedMode = 'dock';
      try { savedMode = localStorage.getItem(DOCK_MODE_KEY) || 'dock'; } catch (e) {}
      applyDockMode(savedMode);

      // Coalesce re-layout requests into one per frame, and never run mid-drag
      // (the drag handlers own the geometry while the user is dragging).
      function scheduleLayout() {
        if (rafPending || sidebar.classList.contains('dxf-dock-dragging')) return;
        rafPending = true;
        var run = function () {
          if (!rafPending) return;
          rafPending = false;
          layout();
        };
        window.requestAnimationFrame(run);
        // rAF never fires while the tab is hidden — without this fallback a
        // queued layout wedges rafPending and every later trigger bails.
        // Long delay in the foreground: it's a no-op backstop there (rAF wins
        // and the run-once guard eats it), so don't add timer churn.
        setTimeout(run, document.hidden ? 250 : 1500);
      }

      // (Re)apply when the panel opens/closes, Preview toggles, the window
      // resizes, OR Bricks rebuilds/moves the Structure panel. We always revert
      // Bricks' panel cleanly when we step aside (close / preview).
      layout();
      new MutationObserver(scheduleLayout).observe(sidebar, { attributes: true, attributeFilter: ['class'] });
      // Body CLASS observer: only the two classes we key layout off matter.
      // Bricks mutates body classes during normal editing — filter before
      // scheduling or we re-layout on every interaction. (The old extra
      // body CHILDLIST observer is gone for the same reason: with no
      // #bricks-app, it watched document.body children, which Bricks 2.x
      // churns constantly — that alone made the whole builder crawl.
      // Structure add/removal is covered by structObs + the 2s re-assert.)
      var lastBodyState = '';
      if (document.body) {
        new MutationObserver(function () {
          var s = (document.body.classList.contains('dxf-bricks-preview') ? 'p' : '') +
                  (document.body.classList.contains('dxf-in-editor') ? 'e' : '');
          if (s !== lastBodyState) { lastBodyState = s; scheduleLayout(); }
        }).observe(document.body, { attributes: true, attributeFilter: ['class'] });
      }
      window.addEventListener('resize', scheduleLayout, { passive: true });
      window.addEventListener('beforeunload', function () { revertStructure(); releaseCanvas(); });

      // Belt-and-braces: a low-frequency re-assert in case an observer misses a
      // mutation (e.g. Bricks swaps the node without a detectable attribute
      // change). Cheap, idempotent, and skipped while dragging or stepped aside.
      setInterval(function () {
        if (!inEditorDock()) return;
        var struct = structurePanel();
        if (struct && (structRef !== struct || structDrifted(struct))) scheduleLayout();
        // Structure vanished since we stacked → reflow to full height.
        else if (!struct && structRef) scheduleLayout();
        // Column moved/resized via Bricks' own resizer → follow it.
        else if (struct && lastCol) {
          var rr = struct.getBoundingClientRect();
          if (Math.abs(rr.left - lastCol.left) > 1.5 || Math.abs(rr.width - lastCol.w) > 1.5) scheduleLayout();
        }
      }, 2000);
    }, POLL_INTERVAL);
  }

  // ---------------------------------------------------------------------------
  // Boot
  // ---------------------------------------------------------------------------
  function boot() {
    // Override Bricks' accent-inverse with a WCAG-correct text colour so light
    // primaries (notably the default yellow) don't end up with illegible white
    // text on pills, the AI summarise button, toolbar active state, etc.
    DxfCommentEngine.ensureAccentContrast(document.documentElement);

    DxfCommentEngine.init({
      cfg: cfg,
      isBuilder: true,
      brand: {
        accent:       ACCENT,
        name:         (cfg.brandName || t('bld.comments', 'Comments')),
        logo:         (cfg.brandLogo || ''),
        color:        '',
        textColor:    '',
        isWhitelabel: false,
      },
      capabilities: {
        // Assignment is a Pro-only endpoint — offered only when available,
        // AND only when the site hasn't hidden the pill (Settings → Comments).
        canAssign:        !!cfg.canAssignComments && cfg.showAssignPill !== false,
        // Comment status (open / in progress / resolved) is a built-in feature:
        // the endpoint ships in Free and works for any editor. Hiding the
        // dropdown (Settings → Comments) falls back to the read-only chip.
        canStatusSelect:  cfg.showStatusPill !== false,
        canResolve:       true,
        canDelete:        true,    // editors can delete any comment (server-checked)
        canDeleteOwnOnly: false,
        canViewport:      true,
        // Reviews picker replaces the legacy per-post Rounds dropdown.
        // Free + Pro both get the picker; the underlying Review filtering
        // is universal. canPickReview gives the builder the dropdown +
        // "New Review" link; FE leaves it false so reviewers see the
        // active Review name as a static label.
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
        captureContext:   false,    // builder shows context; FE captures it
        canAnnotate:      false,
        useCKey:          true,
        unreadAutoOpen:   true,
        // Builder users come to build; commenting is opt-in via the mode pill.
        defaultMode:      'browse',
      },
      identity: {
        get name()   { return builderName || (cfg.currentUser || ''); },
        email:       '',
        id:          (cfg.currentUserId || 0),
        isLoggedIn:  true,
        // "Identified" once this browser has a claimed name. Until then the
        // engine shows the one-time name prompt (renderIdentityGate below).
        identified:  function () { return !!builderName; },
        requestIdentity: function () {},
      },
      renderIdentityGate: renderIdentityGate,
      api: api,
      getCanvasDoc:    getCanvasDoc,
      getCanvasWindow: getCanvasWindow,
      applyCommentModeStyle: applyCommentModeStyle,
      canvasClickToScreen:   canvasClickToScreen,
      applyViewport:   applyViewport,
      bricksPreview:   bricksPreview,
      mountToggle:     mountToggle,
      updateToggleActive: updateToggleActive,
      onCanvasReady:   onCanvasReady,
      onCanvasResize:  onCanvasResize,
      defaultSidebarTop: function () {
        var tb = document.getElementById('bricks-toolbar');
        return tb ? tb.getBoundingClientRect().bottom + 10 : 58;
      },
      scrollIsCanvas: false, // canvas scrolls inside the iframe, not window
    });

    // Builder-only: drop the custom cursor when the window loses focus.
    function suspend() { applyCommentModeStyle(false); }
    function resume()  { applyCommentModeStyle(true); }
    window.addEventListener('blur',  suspend);
    window.addEventListener('focus', resume);
    document.addEventListener('visibilitychange', function () {
      document.hidden ? suspend() : resume();
    });

    // Make the docked panel resizable (width + height) so it can stack with
    // the Bricks Structure panel instead of covering the full edge.
    enhanceDock();

    // Wait for the canvas iframe to be available, then trigger an initial pin render.
    var tries = 0;
    var pinPoll = setInterval(function () {
      tries++;
      if (getCanvasDoc()) { /* engine onCanvasReady will fire */ }
      if (getCanvasDoc() || tries >= MAX_POLLS) clearInterval(pinPoll);
    }, POLL_INTERVAL);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}());
