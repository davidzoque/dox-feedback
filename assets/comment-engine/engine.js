/**
 * Dox Feedback — Shared comment engine
 *
 * One implementation, two contexts (builder + front-end review portal).
 * Adapters pass a `host` object that abstracts the parts that genuinely
 * differ between contexts (canvas DOM, auth, mount points, capabilities)
 * and the engine reads everything else from `host`.
 *
 * See docs/WAVE-B-HANDOFF.md for the contract.
 */
(function () {
  'use strict';

  window.DxfCommentEngine = {
    init: init,
    ensureAccentContrast: ensureAccentContrast,
    version: '1.0.0',
  };

  // Module-scope translation lookup for strings that live OUTSIDE init()
  // (e.g. the timeAgo helper). init() publishes its resolved i18n map onto
  // window.__dxfI18n so these can resolve too; falls back to the English
  // literal when no translation is supplied. Prefer t() inside init().
  function DXF_T(k, fb) {
    try { return (window.__dxfI18n && window.__dxfI18n[k]) || fb; }
    catch (e) { return fb; }
  }

  // ===========================================================================
  // Auto-contrast for the accent text colour.
  //
  // Bricks ships a `--builder-color-accent-inverse` token that defaults to
  // white. When the user picks a light primary colour (notably the default
  // yellow), white text on top is illegible. We resolve `--rv-accent` to an
  // actual RGB value via a probe element, compute the WCAG relative luminance,
  // and override `--rv-accent-text` to either #111 or #fff so contrast is
  // always legible — without exposing a separate setting to the user.
  //
  // Called once at host boot. Safe to call again if the accent ever changes.
  // ===========================================================================
  function ensureAccentContrast(rootEl) {
    rootEl = rootEl || document.documentElement;
    if (!document.body) return; // probe needs a parent; caller should retry.

    var probe = document.createElement('span');
    probe.style.cssText = 'color: var(--rv-accent); position: absolute; visibility: hidden; pointer-events: none;';
    document.body.appendChild(probe);
    var resolved = '';
    try { resolved = window.getComputedStyle(probe).color || ''; }
    catch (e) { resolved = ''; }
    document.body.removeChild(probe);

    var m = /rgba?\(\s*([\d.]+)\D+([\d.]+)\D+([\d.]+)/i.exec(resolved);
    if (!m) return;
    var r = +m[1], g = +m[2], b = +m[3];
    function ch(c) { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }
    var L = 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b);
    // Contrast ratio of white-on-accent. WCAG AA threshold for body text is 4.5.
    var whiteRatio = 1.05 / (L + 0.05);
    rootEl.style.setProperty('--rv-accent-text', whiteRatio < 4.5 ? '#111111' : '#ffffff');
  }

  // ===========================================================================
  function init(host) {
    var cfg     = host.cfg || {};
    var caps    = host.capabilities || {};
    var brand   = host.brand || {};
    var ACCENT  = (brand.accent && /^#[0-9a-fA-F]{3,6}$/.test(brand.accent)) ? brand.accent : '#ff8d27';

    // i18n: translations supplied from PHP via cfg.i18n. t(key, fallback)
    // returns the translation if present, else the English fallback so
    // behaviour is unchanged when no translation is supplied. Also published
    // to window.__dxfI18n so module-scope helpers (DXF_T) can resolve.
    var I18N = cfg.i18n || {};
    function t(k, fb) { var v = I18N[k]; return (v === undefined || v === null || v === '') ? fb : v; }
    window.__dxfI18n = I18N;

    // A read-only review (e.g. an add-on paused it): clients keep full read
    // access (list + pins); new comments and replies are suppressed in the UI
    // (and rejected server-side in ajax_add_comment as the real gate).
    var readOnly = !!(cfg.review && cfg.review.readOnly);

    var SEEN_KEY      = 'dxf_seen_' + cfg.postId;
    var POLL_INTERVAL = 300;
    var MAX_POLLS     = 40;

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------
    var state = {
      commentMode:        false,
      sidebarOpen:        false,
      filter:             'open',
      scope:              'page', // 'page' | 'all' (only meaningful when canScope)
      // Reviews filter: 'all' = show every comment, 'none' = comments not
      // attached to any Review (legacy + builder-direct), or a string Review
      // id matching c.review_id. Replaces the legacy per-post 'round' filter.
      reviewFilter:       'all',
      deviceFilter:       'all', // 'all' | 'desktop' | 'tablet' | 'mobile' — filters by captured anchor.context.breakpoint
      comments:           [],
      allComments:        [],
      pendingAnchor:      null,
      pendingScreenshot:  null,
      pendingShotDataUrl: null,
      pendingContext:     null,
      unreadCount:        0,
      expandedThreads:    Object.create(null), // map of comment id -> true (inline thread expanded)
      viewport:           'desktop',
    };

    // -------------------------------------------------------------------------
    // ICONS
    // -------------------------------------------------------------------------
    var ICONS = {
      comment:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="bricks-svg">' +
          '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>' +
          '<line x1="12" y1="9" x2="12" y2="13"/><line x1="10" y1="11" x2="14" y2="11"/>' +
        '</svg>',
      cursor:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="bricks-svg">' +
          '<path d="M3 3l7.07 16.97 2.51-7.39 7.39-2.51L3 3z"/><path d="M13 13l6 6"/>' +
        '</svg>',
      check:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<polyline points="20 6 9 17 4 12"/></svg>',
      reopen:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M2.5 2v6h6"/><path d="M2.5 8A9.5 9.5 0 1 1 4 15.5"/></svg>',
      close:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="bricks-svg">' +
          '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
      attach:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>',
      file:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>',
      vpDesktop:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
      vpTablet:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<rect x="4" y="2" width="16" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>',
      vpMobile:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<rect x="6" y="2" width="12" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>',
      trash:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
      pencil:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
      // ── Design-bundle icons ────────────────────────────────────────────
      commentIcon:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
      resolveCircle:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>',
      deviceIcon:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M20 3H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h7l-2 3h6l-2-3h7a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1z"/></svg>',
      userIcon:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><path d="M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/></svg>',
      chev:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' +
          '<polyline points="6 9 12 15 18 9"/></svg>',
      sun:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>',
      moon:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>',
      plus:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
    };

    // -------------------------------------------------------------------------
    // Theme system — default to OS preference; explicit override saved per-user.
    // Applied via `data-rv-theme` on documentElement so the modal, comment form,
    // lightbox, summary, and annotator all share the same palette.
    // -------------------------------------------------------------------------
    var THEME_KEY = 'dxf_theme';
    function osPrefersLight() {
      return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches);
    }
    // Per-user manual toggle (localStorage) wins, then the admin's modal-theme
    // setting, then a sensible default for the host context.
    function preferredTheme() {
      try { var saved = localStorage.getItem(THEME_KEY); if (saved === 'light' || saved === 'dark') return saved; }
      catch (e) {}
      var setting = cfg.modalTheme || 'follow_bricks';
      if (setting === 'dark')  return 'dark';
      if (setting === 'light') return 'light';
      if (setting === 'os')    return osPrefersLight() ? 'light' : 'dark';
      // 'follow_bricks': dark in the builder, OS preference everywhere else.
      if (host.isBuilder) return 'dark';
      return osPrefersLight() ? 'light' : 'dark';
    }
    function applyTheme(theme) {
      document.documentElement.setAttribute('data-rv-theme', theme);
    }
    function toggleTheme() {
      var current = document.documentElement.getAttribute('data-rv-theme') || preferredTheme();
      var next = current === 'light' ? 'dark' : 'light';
      try { localStorage.setItem(THEME_KEY, next); } catch (e) {}
      applyTheme(next);
      updateThemeToggle();
    }
    function updateThemeToggle() {
      var btn = document.querySelector('#dxf-sidebar .dxf-theme-toggle');
      if (!btn) return;
      var current = document.documentElement.getAttribute('data-rv-theme') || preferredTheme();
      btn.innerHTML = (current === 'light' ? ICONS.moon : ICONS.sun);
      btn.setAttribute('aria-label', current === 'light' ? t('theme.switchToDark', 'Switch to dark mode') : t('theme.switchToLight', 'Switch to light mode'));
    }

    // Dock-to-side: snap the floating panel to the right edge (full height, its
    // normal width) and back. Host-agnostic; shown when caps.canDockRight — used
    // in the Gutenberg editor, which has no builder dock of its own.
    var DOCKRIGHT_KEY = 'dxf_dockright';
    function isDockRight() {
      try { return localStorage.getItem(DOCKRIGHT_KEY) === '1'; } catch (e) { return false; }
    }
    function applyDockRight() {
      if (!caps.canDockRight) return;
      var sidebar = document.getElementById('dxf-sidebar');
      if (!sidebar) return;
      var on = isDockRight();
      sidebar.classList.toggle('dxf-dock-right', on);
      document.body.classList.toggle('dxf-dockright-on', on);
      var btn = sidebar.querySelector('.dxf-dockright-toggle');
      if (btn) { btn.classList.toggle('is-active', on); btn.setAttribute('aria-pressed', on ? 'true' : 'false'); }
    }
    function toggleDockRight() {
      var on = !isDockRight();
      try { localStorage.setItem(DOCKRIGHT_KEY, on ? '1' : '0'); } catch (e) {}
      var sidebar = document.getElementById('dxf-sidebar');
      applyDockRight();
      // Returning to float: drop the docked coords and re-place it sanely.
      if (sidebar && !on) {
        sidebar.style.left = ''; sidebar.style.top = '';
        initSidebarPosition(sidebar);
      }
    }

    applyTheme(preferredTheme());
    // Expose ACCENT (the brand colour the canvas pin uses) as a CSS var so
    // the in-card number badge can match it. We can't rely on --rv-accent
    // here because Bricks overrides that to its own primary in the builder
    // (often yellow), which is great for sidebar buttons but doesn't match
    // the blue pin overlay. The badge and the pin should always agree.
    try { document.documentElement.style.setProperty('--rv-pin-accent', ACCENT); } catch (e) {}

    // -------------------------------------------------------------------------
    // Helpers (shared across both contexts)
    // -------------------------------------------------------------------------
    function toInitials(name) {
      if (!name) return '?';
      return String(name).trim().split(/\s+/).map(function (w) { return w[0]; }).join('').slice(0, 2).toUpperCase();
    }
    var USER_PALETTE = ['#e11d48','#db2777','#9333ea','#7c3aed','#4f46e5','#2563eb',
                        '#0891b2','#0d9488','#059669','#16a34a','#ca8a04','#ea580c'];
    function userColor(comment) {
      // Key off the DISPLAYED name first so the colour matches who the reader
      // sees, and stays identical across builder + front-end (the public
      // endpoints strip author_email/author_id, so name is the only field
      // present in both contexts). Falls back to email/id for nameless rows.
      var key = String((comment && (comment.author_name || comment.author_email || comment.author_id)) || '?');
      var h = 0;
      for (var i = 0; i < key.length; i++) { h = (h * 31 + key.charCodeAt(i)) >>> 0; }
      return USER_PALETTE[h % USER_PALETTE.length];
    }
    function hexToRgba(hex, alpha) {
      var h = String(hex || '').replace('#', '');
      if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
      var r = parseInt(h.slice(0,2),16) || 0, g = parseInt(h.slice(2,4),16) || 0, b = parseInt(h.slice(4,6),16) || 0;
      return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
    }
    function escHtml(str) {
      var d = document.createElement('div'); d.textContent = String(str || ''); return d.innerHTML;
    }
    function escAttr(str) {
      return String(str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;')
        .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function parseAnchor(raw) {
      if (!raw) return null;
      if (typeof raw === 'object') return raw;
      try { return JSON.parse(raw); } catch (e) { return null; }
    }

    // -------------------------------------------------------------------------
    // Builder anchor adapters (multi-builder support).
    //
    // HOW an element is identified / re-found / deep-linked differs per page
    // builder. adapters.js registers them into window.DxfAnchors and we
    // route every anchor operation through the active adapter. If that script
    // is unavailable for any reason, FALLBACK_ADAPTER reproduces the original
    // Bricks-only behaviour byte-for-byte, so this refactor can never regress
    // a Bricks site.
    // -------------------------------------------------------------------------
    var FALLBACK_ADAPTER = {
      id: 'bricks',
      detect: function (doc) { return !!(doc && doc.querySelector && doc.querySelector('[id^="brxe-"]')); },
      hasAnchorableContent: function (doc) { return this.detect(doc); },
      closest: function (node) { return (node && node.closest) ? node.closest('[id^="brxe-"]') : null; },
      elementId: function (el) { return (el && el.id) ? el.id.replace('brxe-', '') : ''; },
      strategies: function () { return null; },
      resolve: function (a, doc) { return (a && a.element_id && doc) ? doc.getElementById('brxe-' + a.element_id) : null; },
      deepLinkHash: function (a) { var id = a && a.element_id ? a.element_id : (typeof a === 'string' ? a : ''); return id ? '#brxe-' + id : ''; },
      label: function () { return ''; }
    };
    var _adapterCache = null;
    function activeAdapter(doc) {
      if (_adapterCache) return _adapterCache;
      doc = doc || host.getCanvasDoc();
      var reg = window.DxfAnchors;
      var forced = host.builderId || (host.isBuilder ? 'bricks' : null);
      var a = reg ? reg.resolve(doc, forced) : null;
      if (!a) a = FALLBACK_ADAPTER;
      // Only cache once we resolved against a real canvas document.
      if (doc) _adapterCache = a;
      return a;
    }
    // Adapter for a specific anchor's builder (anchors carry a `builder` field;
    // legacy anchors without one fall through to the active page adapter, which
    // on an existing Bricks site is the Bricks adapter — preserving behaviour).
    function adapterFor(anchor, doc) {
      var reg = window.DxfAnchors;
      var bid = anchor && typeof anchor === 'object' ? anchor.builder : null;
      if (reg && bid) { var a = reg.get(bid); if (a) return a; }
      return activeAdapter(doc);
    }
    // Resolve { adapter, el } for a clicked/hovered node — per element, so mixed
    // pages (a theme in one builder wrapping content in another) anchor each pin
    // with the builder that owns the element. Editor hosts pin the choice.
    function matchEl(node, doc) {
      doc = doc || host.getCanvasDoc();
      var reg = window.DxfAnchors;
      var forced = host.builderId || (host.isBuilder ? 'bricks' : null);
      if (reg && reg.matchElement) return reg.matchElement(node, doc, forced);
      var a = activeAdapter(doc);
      return { adapter: a, el: (node && a.closest) ? a.closest(node, doc) : null };
    }
    // True when comment file-uploads are disabled for this review (e.g. the
    // public demo). NOTE: wp_localize_script casts boolean false to "" and true
    // to "1", so we must treat the falsy variants — not just === false — as off.
    // Absent (builder cfg) defaults to ON.
    function uploadsOff() {
      var v = cfg.allowUploads;
      return v === false || v === '' || v === '0' || v === 0;
    }
    function linkify(str) {
      // URL char-classes exclude quotes so URLs can't break out of href="".
      return escHtml(str).replace(/(https?:\/\/[^\s<"']+[^\s<.,;:!?)\]"'])/g, function (url) {
        return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a>';
      });
    }
    function formatFileName(name, maxBase) {
      maxBase = maxBase || 16;
      var dot = name.lastIndexOf('.');
      if (dot < 0) return name.length > maxBase + 1 ? name.slice(0, maxBase) + '…' : name;
      var ext = name.slice(dot), base = name.slice(0, dot);
      if (base.length <= maxBase) return name;
      return base.slice(0, maxBase) + '…' + ext;
    }
    function debounce(fn, wait) {
      var t; return function () { clearTimeout(t); t = setTimeout(fn, wait); };
    }
    function timeAgo(datetime) {
      if (!datetime) return '';
      var d = new Date(datetime.replace(' ', 'T') + 'Z'), diff = Math.floor((Date.now() - d.getTime()) / 1000);
      if (diff < 60)    return DXF_T('time.justNow', 'just now');
      if (diff < 3600)  return DXF_T('time.minutesAgo', '%dm ago').replace('%d', Math.floor(diff / 60));
      if (diff < 86400) return DXF_T('time.hoursAgo', '%dh ago').replace('%d', Math.floor(diff / 3600));
      return DXF_T('time.daysAgo', '%dd ago').replace('%d', Math.floor(diff / 86400));
    }
    function getCommentNumber(id) {
      var topLevel = state.comments.filter(function (c) { return !c.parent_id; });
      for (var i = 0; i < topLevel.length; i++) if (topLevel[i].id == id) return i + 1;
      return 0;
    }
    function getVisibleComments() {
      var source = state.scope === 'all' ? state.allComments : state.comments;
      return source
        .filter(function (c) { return !c.parent_id; })
        .filter(function (c) {
          if (state.reviewFilter === 'all')  return true;
          if (state.reviewFilter === 'none') return !c.review_id || +c.review_id === 0;
          return String(+c.review_id || 0) === String(state.reviewFilter);
        })
        .filter(function (c) {
          if (state.filter === 'open')     return c.status !== 'resolved';
          if (state.filter === 'resolved') return c.status === 'resolved';
          if (state.filter === 'mine')     return String(c.author_id) === String(cfg.currentUserId);
          return true;
        })
        .filter(function (c) {
          if (state.deviceFilter === 'all') return true;
          var a = parseAnchor(c.anchor_data);
          var bp = (a && a.context && a.context.breakpoint) ? String(a.context.breakpoint).toLowerCase() : '';
          return bp === state.deviceFilter;
        })
        .slice() // don't mutate state.comments
        .sort(function (a, b) {
          var pa = priorityRank(a);
          var pb = priorityRank(b);
          if (pa !== pb) return pa - pb;
          // Tie-break: newest first (created_at is "YYYY-MM-DD HH:MM:SS" — lexicographic desc works).
          return (b.created_at || '').localeCompare(a.created_at || '');
        });
    }

    // Auto-triage priority rank — lower = higher visual priority.
    // Untriaged comments sink to the bottom of each filter view.
    function priorityRank(c) {
      var a = parseAnchor(c.anchor_data);
      var p = a && a.triage && a.triage.priority;
      if (p === 'high')   return 0;
      if (p === 'medium') return 1;
      if (p === 'low')    return 2;
      return 3;
    }
    function canvasHasElements(doc) {
      return activeAdapter(doc).hasAnchorableContent(doc);
    }
    // Two-click delete confirm (Bricks-style): no browser dialog.
    function armDeleteButton(btn) {
      var prevTitle = btn.getAttribute('title') || t('comment.delete', 'Delete comment');
      btn.classList.add('is-armed');
      btn.setAttribute('title', t('comment.deleteConfirm', 'Click again to delete'));
      var disarm = function () {
        clearTimeout(btn._dxfArmTimer);
        btn.classList.remove('is-armed');
        btn.setAttribute('title', prevTitle);
        document.removeEventListener('mousedown', onOutside, true);
      };
      var onOutside = function (e) { if (e.target !== btn && !btn.contains(e.target)) disarm(); };
      btn._dxfArmTimer = setTimeout(disarm, 3000);
      document.addEventListener('mousedown', onOutside, true);
    }

    // -------------------------------------------------------------------------
    // Popover (custom dropdown for status/assignee/device/round)
    //
    // One popover at a time. Items: { id, label, dot?, avatar?, count?, selected }.
    // Outside-click + Escape close it. Stays positioned below the trigger;
    // shifts left/up if it would clip the viewport.
    // -------------------------------------------------------------------------
    var POPOVER_ID = 'dxf-popover';
    var popoverHandlers = null;
    var popoverTrigger  = null;
    function closePopover() {
      var existing = document.getElementById(POPOVER_ID);
      if (existing) existing.remove();
      if (popoverHandlers) {
        document.removeEventListener('mousedown', popoverHandlers.outside, true);
        document.removeEventListener('keydown',   popoverHandlers.esc,     true);
        window.removeEventListener('scroll',      popoverHandlers.scroll,  true);
        popoverHandlers = null;
      }
      popoverTrigger = null;
    }
    function openPopover(triggerEl, items, onSelect, opts) {
      opts = opts || {};
      // Toggle: clicking the same trigger that owns the open popover closes it.
      if (popoverTrigger === triggerEl) { closePopover(); return; }
      closePopover();
      popoverTrigger = triggerEl;
      var pop = document.createElement('div');
      pop.id = POPOVER_ID;
      pop.className = 'dxf-popover' + (opts.className ? ' ' + opts.className : '');
      pop.innerHTML = items.map(function (it, i) {
        var lead = '';
        if (it.dot) {
          lead = '<span class="dxf-popover-dot" style="background:' + escAttr(it.dot) + '"></span>';
        } else if (it.avatar) {
          lead = '<span class="dxf-popover-avatar" style="background:' + escAttr(it.avatar.bg) + '">' + escHtml(it.avatar.text) + '</span>';
        } else if (it.icon) {
          lead = '<span class="dxf-popover-glyph">' + it.icon + '</span>';
        }
        return (
          '<button type="button" class="dxf-popover-item' + (it.selected ? ' is-active' : '') + (it.action ? ' dxf-popover-item--action' : '') + '" data-i="' + i + '">' +
            lead +
            '<span class="dxf-popover-label">' + escHtml(it.label) + '</span>' +
            (it.count != null && it.count !== '' ? '<span class="dxf-popover-count">' + escHtml(String(it.count)) + '</span>' : '') +
            (it.selected ? '<span class="dxf-popover-check">✓</span>' : '') +
          '</button>'
        );
      }).join('');

      // Position the popover so it sits just below the trigger button.
      // z-index must beat BOTH stacking contexts we render in:
      //   - Builder: sidebar at --rv-z-sidebar (3000), form at 3500
      //   - Front-end: sidebar at 2147483610 (forced max-ish to clear page
      //     content + sticky headers). 99999 sat behind that, making the
      //     popover invisible — which read as "the device/round pills do
      //     nothing" to reviewers. Pin it just below the lightbox slot
      //     (2147483640) so we never occlude an open lightbox either.
      var r = triggerEl.getBoundingClientRect();
      pop.style.position = 'fixed';
      pop.style.top      = (r.bottom + 6) + 'px';
      pop.style.left     = r.left + 'px';
      pop.style.zIndex   = '2147483630';

      document.body.appendChild(pop);

      // After insertion, nudge left/up if it overflows the viewport.
      var pr = pop.getBoundingClientRect();
      if (pr.right > window.innerWidth - 8) pop.style.left = Math.max(8, window.innerWidth - pr.width - 8) + 'px';
      if (pr.bottom > window.innerHeight - 8) pop.style.top = Math.max(8, r.top - pr.height - 6) + 'px';

      pop.querySelectorAll('.dxf-popover-item').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          var item = items[+btn.dataset.i];
          onSelect(item);
          closePopover();
        });
      });

      // Outside-click / Escape / scroll-while-open all close.
      popoverHandlers = {
        outside: function (e) { if (!pop.contains(e.target) && e.target !== triggerEl && !triggerEl.contains(e.target)) closePopover(); },
        esc:     function (e) { if (e.key === 'Escape') closePopover(); },
        scroll:  function ()  { closePopover(); },
      };
      // Defer so the click that opened the popover doesn't immediately close it.
      setTimeout(function () {
        document.addEventListener('mousedown', popoverHandlers.outside, true);
        document.addEventListener('keydown',   popoverHandlers.esc,     true);
        window.addEventListener('scroll',      popoverHandlers.scroll,  true);
      }, 0);
    }

    // -------------------------------------------------------------------------
    // Unread tracking
    // -------------------------------------------------------------------------
    function getLastSeen() { return parseInt(localStorage.getItem(SEEN_KEY) || '0', 10); }
    function markAsSeen()  { localStorage.setItem(SEEN_KEY, String(Date.now())); }
    function computeUnread(comments) {
      var lastSeen = getLastSeen();
      if (!lastSeen) return 0;
      return comments.filter(function (c) {
        if (c.parent_id) return false;
        var ts = new Date(c.created_at.replace(' ', 'T') + 'Z').getTime();
        return ts > lastSeen && String(c.author_id) !== String(cfg.currentUserId);
      }).length;
    }
    function checkForUnread() {
      var count = computeUnread(state.comments);
      state.unreadCount = count;
      if (count > 0 && !state.sidebarOpen) openSidebar();
      if (!getLastSeen()) markAsSeen();
      updateOpenBadge();
    }
    function updateOpenBadge() {
      var source    = state.allComments.length ? state.allComments : state.comments;
      var openCount = source.filter(function (c) {
        return !(+c.parent_id) && c.status !== 'resolved';
      }).length;
      var badge = document.getElementById('dxf-open-badge');
      if (!badge) return;
      badge.textContent = openCount > 99 ? '99+' : String(openCount);
      badge.style.display = openCount > 0 ? '' : 'none';
    }

    // -------------------------------------------------------------------------
    // Comment mode
    // -------------------------------------------------------------------------
    function enableCommentMode() {
      // FE: gate on identity. Builder: identity is always present.
      if (!host.identity.identified()) { renderSidebarBody(); return; }
      // Approved pages are read-only — open the sidebar so reviewers can
      // still see what was discussed, but never enter click-to-pin mode.
      // The comment list itself shows an approved-state notice in place of
      // the comment cards (see renderCommentList).
      if (cfg.completed || readOnly) { state.commentMode = false; updateToggleActive(); return; }
      state.commentMode = true;

      // Bricks-preview dance (builder only).
      if (host.bricksPreview) host.bricksPreview.enable();

      var register = function () {
        var doc = host.getCanvasDoc();
        if (!doc || !doc.body) return;
        doc.body.classList.add('dxf-comment-mode');
        if (host.applyCommentModeStyle) host.applyCommentModeStyle(true);
        doc.addEventListener('click', onCanvasClick, true);
        bindCanvasHover();
      };

      var doc = host.getCanvasDoc();
      if (doc && (doc.readyState === 'complete' || doc === document)) {
        register();
      } else if (host.onCanvasReady) {
        host.onCanvasReady(register);
      } else {
        register();
      }

      updateToggleActive();
      showModePill();
    }

    function exitCommentPlacement() {
      state.commentMode = false;
      var doc = host.getCanvasDoc();
      if (doc && doc.body) {
        doc.body.classList.remove('dxf-comment-mode');
        if (host.applyCommentModeStyle) host.applyCommentModeStyle(false);
        doc.removeEventListener('click', onCanvasClick, true);
      }
      unbindCanvasHover();
      closeCommentForm();
      updateToggleActive();
      updateModePill();
    }

    function disableCommentMode() {
      exitCommentPlacement();
      if (host.bricksPreview) host.bricksPreview.disable();
    }

    function toggleCommentMode() {
      state.commentMode ? disableCommentMode() : enableCommentMode();
    }

    function onCanvasClick(e) {
      // Only genuine user clicks place pins. Some sites/plugins fire synthetic
      // click events on a timer (sliders, auto-rotating tabs, BricksForge
      // interactions, accessibility helpers). Those arrive with isTrusted=false
      // and clientX/clientY=0, which would drop the comment form in the
      // top-left corner every few seconds. Ignore anything not user-generated.
      if (e.isTrusted === false) return;
      // Never treat WP admin-bar clicks as pin placements — let them navigate.
      if (e.target && e.target.closest && e.target.closest('#dxf-pin-layer,#wpadminbar,[id^="dxf-"]')) return;
      e.preventDefault();
      e.stopPropagation();

      var screen   = host.canvasClickToScreen ? host.canvasClickToScreen(e) : { x: e.clientX, y: e.clientY };
      var canvas   = host.getCanvasDoc();
      var match    = e.target ? matchEl(e.target, canvas) : { adapter: activeAdapter(canvas), el: null };
      var adapter  = match.adapter;
      var el       = match.el;
      var anchor = {
        builder: adapter.id,
        element_id: el ? adapter.elementId(el) : '',
        css_selector: '',
        viewport_x: 0, viewport_y: 0,
        offset_x: 0, offset_y: 0,
        doc_x: e.pageX, doc_y: e.pageY,
        strategies: el ? adapter.strategies(el, canvas) : null,
      };
      if (el) {
        var r = el.getBoundingClientRect();
        if (r.width)  anchor.offset_x = (e.clientX - r.left) / r.width;
        if (r.height) anchor.offset_y = (e.clientY - r.top)  / r.height;
      }
      state.pendingAnchor  = anchor;
      if (caps.captureContext) state.pendingContext = buildContext();

      // Drop a placeholder pin at the click point and keep it visible for
      // the lifetime of the open form. Previously this pin only existed
      // for the screenshot-capture window (~1s) and then disappeared,
      // which reviewers read as "the pin failed". closeCommentForm() +
      // the form-submit success path are responsible for removing it.
      addPendingPin(anchor);

      captureScreenshot();
      showCommentForm(screen.x, screen.y);
    }

    // Visible pending-pin shown at the click point until the form closes
    // or the comment is saved (at which point the real pin renders from
    // server data). One at a time — repeated clicks replace the prior pin.
    function addPendingPin(anchor) {
      removePendingPin();
      var doc = host.getCanvasDoc();
      if (!doc || !doc.body || !anchor || anchor.doc_x == null) return;
      var pin = doc.createElement('div');
      pin.id = 'dxf-pending-pin';
      pin.style.cssText =
        'position:absolute;left:' + (anchor.doc_x || 0) + 'px;top:' + (anchor.doc_y || 0) + 'px;' +
        'width:22px;height:22px;background:' + ACCENT + ';border:3px solid #fff;border-radius:50%;' +
        'transform:translate(-50%,-50%);z-index:2147483001;pointer-events:none;' +
        'box-shadow:0 2px 8px rgba(0,0,0,.45);' +
        'animation:dxf-pendingPulse 1.4s ease-in-out infinite;';
      doc.body.appendChild(pin);
      // Inject keyframes once (idempotent — id-checked).
      if (!doc.getElementById('dxf-pending-pin-style')) {
        var s = doc.createElement('style');
        s.id = 'dxf-pending-pin-style';
        s.textContent = '@keyframes dxf-pendingPulse{0%,100%{transform:translate(-50%,-50%) scale(1);}50%{transform:translate(-50%,-50%) scale(1.15);}}';
        (doc.head || doc.documentElement).appendChild(s);
      }
    }
    function removePendingPin() {
      var doc = host.getCanvasDoc();
      if (!doc) return;
      var pin = doc.getElementById('dxf-pending-pin');
      if (pin && pin.parentNode) pin.parentNode.removeChild(pin);
    }

    // -------------------------------------------------------------------------
    // Context (browser/OS/viewport/JS errors) — FE captures, builder displays
    // -------------------------------------------------------------------------
    var errorLog = [];
    if (caps.captureContext) {
      window.addEventListener('error', function (e) {
        if (errorLog.length >= 20) errorLog.shift();
        errorLog.push({
          msg:  String((e && e.message) || 'Script error'),
          src:  String((e && e.filename) || ''),
          line: (e && e.lineno) || 0,
        });
      });
    }
    function detectOS(ua) {
      if (/Windows NT 10/.test(ua))           return 'Windows';
      if (/Windows/.test(ua))                 return 'Windows';
      if (/Android/.test(ua))                 return 'Android';
      if (/(iPhone|iPad|iPod)/.test(ua))      return 'iOS';
      if (/Mac OS X/.test(ua))                return 'macOS';
      if (/CrOS/.test(ua))                    return 'ChromeOS';
      if (/Linux/.test(ua))                   return 'Linux';
      return '';
    }
    function detectBrowser(ua) {
      if (/Edg\//.test(ua))         return 'Edge';
      if (/OPR\/|Opera/.test(ua))   return 'Opera';
      if (/Firefox\//.test(ua))     return 'Firefox';
      if (/Chrome\//.test(ua))      return 'Chrome';
      if (/Safari\//.test(ua))      return 'Safari';
      return '';
    }
    function buildContext() {
      var w  = window.innerWidth, h = window.innerHeight;
      var bp = w <= 480 ? 'Mobile' : (w <= 1024 ? 'Tablet' : 'Desktop');
      var ua = navigator.userAgent || '';
      return {
        ua: ua, os: detectOS(ua), browser: detectBrowser(ua),
        viewport: w + '×' + h, breakpoint: bp,
        dpr: window.devicePixelRatio || 1,
        url: location.href, errors: errorLog.slice(-5),
      };
    }

    // -------------------------------------------------------------------------
    // Screenshot capture
    // -------------------------------------------------------------------------
    // Capture library = snapDOM (MIT, bundled in assets/vendor/). Unlike
    // html2canvas it serializes the DOM through SVG foreignObject so the
    // BROWSER's own engine does layout — grid/flex gaps, webfonts, filters
    // and shadow DOM render correctly by construction, at devicePixelRatio.
    // When the canvas doc is the viewport-emulation iframe we inject the
    // script INTO that document (never copy the function across windows —
    // its internal document/window refs must match the captured tree).
    function ensureCaptureLib(win, doc) {
      return new Promise(function (resolve) {
        if (win && typeof win.snapdom === 'function') { resolve(win.snapdom); return; }
        if (win === window && typeof window.snapdom === 'function') { resolve(window.snapdom); return; }
        if (!cfg.captureLibUrl || !doc) { resolve(null); return; }
        var sc = doc.createElement('script');
        sc.src = cfg.captureLibUrl;
        sc.onload  = function () { resolve(win.snapdom || null); };
        sc.onerror = function () { resolve(null); };
        (doc.head || doc.documentElement).appendChild(sc);
      });
    }

    function captureScreenshot() {
      var doc = host.getCanvasDoc();
      var win = host.getCanvasWindow();
      state.pendingShotDataUrl = null;
      if (!doc || !doc.body || !win) {
        state.pendingScreenshot = Promise.resolve(null);
        setShotStatus('fail');
        return;
      }

      var pinLayer = doc.getElementById('dxf-pin-layer');
      if (pinLayer) pinLayer.style.visibility = 'hidden';

      // Temp dot at the click point.
      var tempPin = null;
      var anchor  = state.pendingAnchor;
      if (anchor && (anchor.doc_x != null || anchor.doc_y != null)) {
        tempPin = doc.createElement('div');
        tempPin.style.cssText =
          'position:absolute;left:' + (anchor.doc_x || 0) + 'px;top:' + (anchor.doc_y || 0) + 'px;' +
          'width:20px;height:20px;background:' + ACCENT + ';border:3px solid #fff;border-radius:50%;' +
          'transform:translate(-50%,-50%);z-index:2147483001;pointer-events:none;' +
          'box-shadow:0 2px 6px rgba(0,0,0,.5);';
        doc.body.appendChild(tempPin);
      }

      // Rasterize the current frame of each visible <video> into a temporary
      // overlay <img> — SVG-foreignObject capture cannot paint video frames,
      // so hero/background videos otherwise come out blank. Cross-origin
      // streams taint the canvas (toDataURL throws) and are skipped.
      var videoOverlays = [];
      try {
        var vids = doc.querySelectorAll('video');
        for (var vi = 0; vi < vids.length; vi++) {
          var v  = vids[vi];
          var vr = v.getBoundingClientRect();
          if (!vr.width || !vr.height) continue;
          try {
            var vc = doc.createElement('canvas');
            vc.width  = v.videoWidth  || Math.round(vr.width);
            vc.height = v.videoHeight || Math.round(vr.height);
            vc.getContext('2d').drawImage(v, 0, 0, vc.width, vc.height);
            var ov = doc.createElement('img');
            ov.src = vc.toDataURL('image/jpeg', 0.85);
            ov.style.cssText =
              'position:absolute;left:' + (vr.left + (win.scrollX || 0)) + 'px;top:' + (vr.top + (win.scrollY || 0)) + 'px;' +
              'width:' + vr.width + 'px;height:' + vr.height + 'px;object-fit:cover;' +
              'z-index:2147482999;pointer-events:none;';
            doc.body.appendChild(ov);
            videoOverlays.push(ov);
          } catch (ve) { /* tainted or not ready — capture the video as-is */ }
        }
      } catch (e) {}

      var restore = function () {
        if (pinLayer) pinLayer.style.visibility = '';
        if (tempPin && tempPin.parentNode) tempPin.parentNode.removeChild(tempPin);
        for (var oi = 0; oi < videoOverlays.length; oi++) {
          if (videoOverlays[oi].parentNode) videoOverlays[oi].parentNode.removeChild(videoOverlays[oi]);
        }
      };

      setShotStatus('capturing');
      // Capture strategy:
      //  1) Wait for the page's web fonts to finish loading, then let snapDOM
      //     embed them so the SVG-foreignObject raster matches the page.
      //  2) Render the WHOLE document body at devicePixelRatio (capped so the
      //     output canvas stays inside browser canvas limits — Safari's area
      //     limit is the binding one on long pages).
      //  3) Post-crop the full canvas to a viewport-sized window centred on
      //     the click point, so the temp pin (positioned at doc_x/doc_y) sits
      //     at the centre of the resulting screenshot.
      state.pendingScreenshot = ensureCaptureLib(win, doc).then(function (snap) {
        if (typeof snap !== 'function') { restore(); return null; }
        var fontsReady = (doc.fonts && doc.fonts.ready && typeof doc.fonts.ready.then === 'function')
          ? doc.fonts.ready : Promise.resolve();
        var docEl = doc.documentElement;
        var docW  = Math.max(docEl.scrollWidth,  docEl.clientWidth,  doc.body.scrollWidth);
        var docH  = Math.max(docEl.scrollHeight, docEl.clientHeight, doc.body.scrollHeight);
        // Hard cap so very long pages don't blow past browser canvas limits.
        var MAX = 12000;
        if (docH > MAX) docH = MAX;
        if (docW > MAX) docW = MAX;
        // Retina-quality capture, throttled so width×height×dpr² stays under
        // a conservative ~33M-pixel canvas area (iOS Safari rejects above
        // ~16M; desktop browsers above ~64-268M — long pages already risked
        // this pre-snapDOM, so keep the same envelope at dpr 1 worst-case).
        var dpr = Math.min(win.devicePixelRatio || 1, 2);
        var area = docW * docH;
        if (area * dpr * dpr > 33000000) {
          dpr = Math.max(1, Math.sqrt(33000000 / area));
        }
        // Warm snapDOM's resource cache (webfonts, stylesheets, images)
        // before capturing — without it, font-faces routinely miss the
        // capture and text rasterizes in a fallback font at wrong metrics
        // (the "obscene font sizing" failure mode). preCache is exported
        // globally by the snapdom build alongside window.snapdom.
        var pre = (win && win.preCache) || window.preCache || null;
        var warm = pre ? Promise.resolve(pre(doc.body)).catch(function () {}) : Promise.resolve();
        return fontsReady.then(function () { return warm; }).then(function () {
          // Capture doc.body (NOT documentElement — a root-element capture
          // came out with no text at all on real pages; snapDOM inlines
          // COMPUTED styles so rem sizing is already resolved at body level).
          return snap.toCanvas(doc.body, {
            // Accuracy over speed: `fast` skips idle waits that let font
            // embedding settle; capture is already async/non-blocking for
            // the reviewer (deferred-shot submit flow).
            fast: false,
            backgroundColor: '#ffffff',
            dpr: dpr,
            scale: 1,
            embedFonts: true,
            // Hide all Dox Feedback UI (pins, sidebar, FAB) from the capture; the
            // temp click-point dot has no id so it stays visible by design.
            exclude: ['[id^="dxf-"]'],
            excludeMode: 'hide',
          });
        }).then(function (full) {
          restore();
          if (!full) return null;
          // Crop to a viewport-sized window centred on the click point.
          // The full canvas is dpr× the CSS-pixel document — scale the crop
          // rect to canvas pixels and keep the output at capture density.
          var ratio = docW > 0 ? (full.width / docW) : 1;
          var vw = win.innerWidth, vh = win.innerHeight;
          var cw = Math.min(Math.round(vw * ratio), full.width);
          var ch = Math.min(Math.round(vh * ratio), full.height);
          var cx = ((anchor && anchor.doc_x != null) ? anchor.doc_x : (win.scrollX + vw / 2)) * ratio;
          var cy = ((anchor && anchor.doc_y != null) ? anchor.doc_y : (win.scrollY + vh / 2)) * ratio;
          // Clamp so the crop stays inside the rendered canvas.
          var x = Math.max(0, Math.min(full.width  - cw, cx - cw / 2));
          var y = Math.max(0, Math.min(full.height - ch, cy - ch / 2));
          var out = document.createElement('canvas');
          out.width  = cw;
          out.height = ch;
          var ctx = out.getContext('2d');
          ctx.fillStyle = '#ffffff';
          ctx.fillRect(0, 0, cw, ch);
          try { ctx.drawImage(full, x, y, cw, ch, 0, 0, cw, ch); }
          catch (e) { return null; }
          try { return out.toDataURL('image/jpeg', 0.9); }
          catch (e) { return null; }
        });
      }).then(function (dataUrl) {
        state.pendingShotDataUrl = dataUrl || null;
        if (!dataUrl) { setShotStatus('fail'); return null; }
        return host.api.uploadScreenshot(dataUrl).then(function (url) {
          if (!url) setShotStatus('fail');
          return url;
        });
      }).catch(function () { restore(); setShotStatus('fail'); return null; });
    }

    function setShotStatus(stateName) {
      var el = document.querySelector('#dxf-comment-form .dxf-form-shot');
      if (!el) return;
      if (stateName === 'fail') {
        el.textContent = t('shot.unavailable', '⚠ Screenshot unavailable');
        el.className   = 'dxf-form-shot dxf-form-shot--fail';
      } else if (stateName === 'annotated') {
        el.textContent = t('shot.annotated', '✓ Screenshot annotated');
        el.className   = 'dxf-form-shot dxf-form-shot--ok';
      } else if (stateName === 'pending') {
        el.textContent = t('shot.preparing', 'Screenshot still preparing…');
        el.className   = 'dxf-form-shot';
      } else {
        el.textContent = '';
        el.className   = 'dxf-form-shot';
      }
    }

    // -------------------------------------------------------------------------
    // Comment form popover
    // -------------------------------------------------------------------------
    function showCommentForm(x, y) {
      var form = document.getElementById('dxf-comment-form');
      if (!form) form = createCommentForm();

      form._pendingFiles = [];
      form.querySelector('.dxf-form-textarea').value    = '';
      form.querySelector('.dxf-form-error').textContent = '';
      form.querySelector('.dxf-btn-submit').disabled    = false;
      form.querySelector('.dxf-btn-submit').textContent = t('form.addComment', 'Add comment');
      // File input is absent when uploads are disabled for the review (e.g. the
      // public demo). Guard it — touching .value on null threw here and aborted
      // the rest of showCommentForm, leaving the form unplaced + unfocused.
      var _fileInput = form.querySelector('.dxf-file-input');
      if (_fileInput) _fileInput.value = '';
      renderPills(form);

      // Position clamp: on phones the CSS @media block forces left/right/
      // width so the inline left/top can't push the form off-screen
      // (dragging isn't viable with touch on a narrow viewport). Desktop
      // falls through to the classic anchor-near-click placement.
      var isNarrow = window.matchMedia && window.matchMedia('(max-width: 600px)').matches;
      if (isNarrow) {
        form.style.left = '';
        form.style.top  = '12px';
      } else {
        var W = 300, H = 220;
        var maxLeft = Math.max(0, window.innerWidth  - W - 16);
        var maxTop  = Math.max(0, window.innerHeight - H - 16);
        form.style.left = Math.max(0, Math.min(x + 14, maxLeft)) + 'px';
        form.style.top  = Math.max(0, Math.min(y + 14, maxTop))  + 'px';
      }
      form.classList.remove('hidden');
      form.querySelector('.dxf-form-textarea').focus();
    }

    function closeCommentForm() {
      var form = document.getElementById('dxf-comment-form');
      if (form) { form.classList.add('hidden'); form._pendingFiles = []; }
      state.pendingAnchor = null;
      // Also clear the pending-pin marker — covers both the cancel path
      // (form dismissed by Esc / close X / outside-click) and the
      // submit-success path (which routes through here too). The real
      // pin is rendered from server data by refreshComments() shortly
      // after, so there's no visual gap on success either.
      removePendingPin();
    }

    function createCommentForm() {
      var form = document.createElement('div');
      form.id = 'dxf-comment-form';
      form._pendingFiles = [];

      form.innerHTML =
        '<div class="dxf-form-header">' +
          '<span>' + escHtml(t('form.addComment', 'Add comment')) + '</span>' +
          '<button class="dxf-form-close" type="button" aria-label="' + escAttr(t('action.close', 'Close')) + '">&#x2715;</button>' +
        '</div>' +
        '<textarea class="dxf-form-textarea" placeholder="' + escAttr(t('form.placeholder', 'Leave a comment… (Enter to send, Shift+Enter for a new line)')) + '" rows="3"></textarea>' +
        '<p class="dxf-form-error"></p>' +
        '<div class="dxf-form-shot"></div>' +
        '<div class="dxf-form-attach">' +
          (uploadsOff() ? '' :
            '<input type="file" class="dxf-file-input" multiple hidden>' +
            '<button type="button" class="dxf-attach-btn">' + ICONS.attach + escHtml(t('form.attachFiles', 'Attach files')) + '</button>') +
          (caps.canAnnotate
            ? '<button type="button" class="dxf-annot-btn">&#9998; ' + escHtml(t('form.annotateShot', 'Annotate screenshot')) + '</button>'
            : '') +
          '<div class="dxf-file-list"></div>' +
        '</div>' +
        '<div class="dxf-form-actions">' +
          '<button class="dxf-btn dxf-btn-ghost dxf-btn-cancel" type="button">' + escHtml(t('action.cancel', 'Cancel')) + '</button>' +
          '<button class="dxf-btn dxf-btn-primary dxf-btn-submit" type="button">' + escHtml(t('form.addComment', 'Add comment')) + '</button>' +
        '</div>';

      form.querySelector('.dxf-form-close').addEventListener('click', closeCommentForm);
      form.querySelector('.dxf-btn-cancel').addEventListener('click', closeCommentForm);
      form.querySelector('.dxf-btn-submit').addEventListener('click', submitComment);
      form.querySelector('.dxf-form-textarea').addEventListener('keydown', function (e) {
        // Enter submits; Shift+Enter inserts a newline.
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submitComment(); }
        if (e.key === 'Escape') closeCommentForm();
      });

      var fileInput = form.querySelector('.dxf-file-input');
      var attachBtn = form.querySelector('.dxf-attach-btn');
      if (attachBtn && fileInput) {
        attachBtn.addEventListener('click', function () { fileInput.click(); });
        fileInput.addEventListener('change', function () {
          for (var i = 0; i < fileInput.files.length; i++) form._pendingFiles.push(fileInput.files[i]);
          fileInput.value = '';
          renderPills(form);
        });
      }

      if (caps.canAnnotate) {
        var annotBtn = form.querySelector('.dxf-annot-btn');
        if (annotBtn) annotBtn.addEventListener('click', function () {
          if (state.pendingShotDataUrl) openAnnotator(state.pendingShotDataUrl);
          else setShotStatus('pending');
        });
      }

      document.body.appendChild(form);
      setupFormDrag(form);
      return form;
    }

    // Make the floating comment form draggable by its header. Mirrors the
    // sidebar drag pattern: mousedown on the header (excluding buttons +
    // the textarea resize handle) → captures viewport position → mousemove
    // updates left/top → mouseup releases. Position is clamped to the
    // viewport so the form can't be dragged off-screen.
    function setupFormDrag(form) {
      var header = form.querySelector('.dxf-form-header');
      if (!header) return;
      var dragging = false, moved = false, sx = 0, sy = 0, sl = 0, st = 0, capId = null;

      header.addEventListener('pointerdown', function (e) {
        // Skip drag when the click target is a control (close X button).
        if (e.button !== 0 || (e.target.closest && e.target.closest('button'))) return;
        dragging = true; moved = false;
        sx = e.clientX; sy = e.clientY;
        // Start position = the inline left/top set by showCommentForm. The form
        // is position:fixed, so these already equal the rendered viewport coords
        // — and reading them avoids a Firefox edge case where a transient
        // getBoundingClientRect() returns {0,0} mid-layout and the first move
        // would fling the form to the top-left. Only fall back to the measured
        // rect if inline is genuinely unset; never default to 0.
        sl = parseFloat(form.style.left);
        st = parseFloat(form.style.top);
        if (isNaN(sl) || isNaN(st)) {
          var r = form.getBoundingClientRect();
          sl = r.left; st = r.top;
        }
        // Capture the pointer on the handle so move/up keep firing even when the
        // cursor outruns the modal or crosses into the canvas iframe — without
        // capture the drag is "dropped" mid-move.
        try { header.setPointerCapture(e.pointerId); capId = e.pointerId; } catch (err) {}
        e.preventDefault();
      });

      header.addEventListener('pointermove', function (e) {
        if (!dragging) return;
        var dx = e.clientX - sx, dy = e.clientY - sy;
        // Ignore sub-threshold jitter so a plain click never repositions the form.
        if (!moved && Math.abs(dx) < 4 && Math.abs(dy) < 4) return;
        if (!moved) { moved = true; header.classList.add('is-dragging'); }
        var w = form.offsetWidth, h = form.offsetHeight;
        form.style.left = Math.max(0, Math.min(window.innerWidth  - w, sl + dx)) + 'px';
        form.style.top  = Math.max(0, Math.min(window.innerHeight - h, st + dy)) + 'px';
      });

      function endFormDrag() {
        if (!dragging) return;
        dragging = false;
        header.classList.remove('is-dragging');
        try { if (capId != null) header.releasePointerCapture(capId); } catch (err) {}
        capId = null;
      }
      header.addEventListener('pointerup', endFormDrag);
      header.addEventListener('pointercancel', endFormDrag);

      // Defensive: when the textarea's vertical-resize handle (or late content
      // like a loading screenshot) grows the form past the bottom of the
      // viewport, nudge it UP by exactly the overflow so the action buttons stay
      // reachable. Previously this slammed `top` toward the top of the screen on
      // any size change, which read as the modal "randomly jumping". Skips while
      // hidden or transiently 0-sized, and never touches `left`.
      if ('ResizeObserver' in window) {
        var ro = new ResizeObserver(function () {
          if (form.classList.contains('hidden')) return;
          var r = form.getBoundingClientRect();
          if (!r.height) return;
          var overflow = r.bottom - (window.innerHeight - 8);
          if (overflow > 0) {
            var curTop = parseFloat(form.style.top);
            if (isNaN(curTop)) curTop = r.top;
            form.style.top = Math.max(8, curTop - overflow) + 'px';
          }
        });
        ro.observe(form);
      }
    }

    function renderPills(form) {
      var list = form.querySelector('.dxf-file-list');
      if (!list) return;
      var files = form._pendingFiles || [];
      if (!files.length) { list.innerHTML = ''; return; }
      list.innerHTML = files.map(function (f, i) {
        return '<div class="dxf-file-pill">' +
          '<span title="' + escAttr(f.name) + '">' + escHtml(formatFileName(f.name)) + '</span>' +
          '<button type="button" class="dxf-file-remove" data-idx="' + i + '" aria-label="' + escAttr(t('file.remove', 'Remove file')) + '">&times;</button>' +
        '</div>';
      }).join('');
      list.querySelectorAll('.dxf-file-remove').forEach(function (btn) {
        btn.addEventListener('click', function () {
          form._pendingFiles.splice(parseInt(btn.dataset.idx, 10), 1);
          renderPills(form);
        });
      });
    }

    function submitComment() {
      var form    = document.getElementById('dxf-comment-form');
      var body    = form.querySelector('.dxf-form-textarea').value.trim();
      var errorEl = form.querySelector('.dxf-form-error');
      var btn     = form.querySelector('.dxf-btn-submit');

      if (!body) { errorEl.textContent = t('form.emptyError', 'Please enter a comment.'); return; }
      btn.disabled = true; btn.textContent = t('state.saving', 'Saving…'); errorEl.textContent = '';

      var anchor = state.pendingAnchor || {};
      var files  = form._pendingFiles && form._pendingFiles.length ? form._pendingFiles : null;

      // Optimistic insert — drop the comment into the sidebar immediately (as
      // "Sending…") so the reviewer sees it registered right away instead of
      // staring at "No open comments" during the save round-trip (which can run
      // ~1-2s while the auto-screenshot is captured). On success
      // refreshComments() reconciles it with the saved row; on failure
      // dropOptimistic() removes it and the form keeps the text for a retry.
      var optimisticId = 'dxf-tmp-' + Date.now();
      state.comments.push({
        id:           optimisticId,
        _pending:     true,
        body:         body,
        status:       'open',
        parent_id:    0,
        post_id:      cfg.postId,
        anchor_data:  anchor,
        author_name:  (host.identity && host.identity.name)  || '',
        author_email: (host.identity && host.identity.email) || '',
        author_id:    cfg.currentUserId || 0,
        created_at:   new Date().toISOString().slice(0, 19).replace('T', ' '),
        reactions:    [],
        assignee_id:  0,
        review_id:    (cfg.review && cfg.review.reviewId) || 0,
      });
      if (!state.sidebarOpen) openSidebar();
      renderCommentList();
      renderPins();
      updateOpenBadge();

      function dropOptimistic() {
        state.comments = state.comments.filter(function (c) { return c.id !== optimisticId; });
        renderCommentList();
        renderPins();
        updateOpenBadge();
      }

      // Don't make the reviewer wait for capture+upload: give the screenshot
      // a short grace window, then submit WITHOUT it and attach it to the new
      // comment once the upload lands (dxf_attach_screenshot fills the
      // empty slot; the live-poll/refresh picks it up). Capture the promise
      // now — state.pendingScreenshot is reset by the next pin click.
      var shotPromise   = state.pendingScreenshot;
      var SHOT_GRACE_MS = 1200;
      var SHOT_PENDING  = '__dxf_shot_pending__';
      var shotRace = new Promise(function (resolve) {
        var settled = false;
        var t = setTimeout(function () { if (!settled) { settled = true; resolve(SHOT_PENDING); } }, SHOT_GRACE_MS);
        Promise.resolve(shotPromise).then(function (u) {
          if (!settled) { settled = true; clearTimeout(t); resolve(u); }
        }).catch(function () {
          if (!settled) { settled = true; clearTimeout(t); resolve(null); }
        });
      });

      shotRace.then(function (screenshotUrl) {
        var late    = screenshotUrl === SHOT_PENDING;
        var payload = {
          post_id: cfg.postId,
          element_id: anchor.element_id || '',
          body: body,
          anchor_data: anchor,
          screenshot_url: (!late && screenshotUrl) ? screenshotUrl : '',
          _files: files,
        };
        if (state.pendingContext) payload.context = state.pendingContext;
        return host.api.addComment(payload).then(function (result) {
          return { result: result, late: late };
        });
      }).then(function (wrap) {
        var result = wrap.result;
        btn.disabled = false; btn.textContent = t('form.addComment', 'Add comment');
        if (result.success) {
          var newId = result.data && result.data.id;
          if (wrap.late && newId && shotPromise && host.api.attachScreenshot) {
            Promise.resolve(shotPromise).then(function (lateUrl) {
              if (!lateUrl) return;
              host.api.attachScreenshot(newId, lateUrl).then(function () { refreshComments(); });
            }).catch(function () {});
          }
          closeCommentForm();
          if (!state.sidebarOpen) openSidebar();
          refreshComments();
        } else {
          dropOptimistic();
          errorEl.textContent = (result.data && result.data.message) || t('error.generic', 'Something went wrong.');
        }
      }).catch(function () {
        dropOptimistic();
        btn.disabled = false; btn.textContent = t('form.addComment', 'Add comment');
        errorEl.textContent = t('error.network', 'Network error. Please try again.');
      });
    }

    // -------------------------------------------------------------------------
    // Annotator (FE-only feature, gated by canAnnotate)
    // -------------------------------------------------------------------------
    function openAnnotator(dataUrl) {
      if (!dataUrl || document.getElementById('dxf-annotator')) return;
      var overlay = document.createElement('div');
      overlay.id = 'dxf-annotator';
      overlay.innerHTML =
        '<div class="dxf-annot-inner">' +
          '<div class="dxf-annot-toolbar">' +
            '<span class="dxf-annot-title">' + escHtml(t('annot.title', 'Draw on the screenshot')) + '</span>' +
            '<span class="dxf-annot-tools">' +
              '<button type="button" class="dxf-btn dxf-btn-ghost dxf-annot-clear">' + escHtml(t('annot.clear', 'Clear')) + '</button>' +
              '<button type="button" class="dxf-btn dxf-btn-ghost dxf-annot-cancel">' + escHtml(t('action.cancel', 'Cancel')) + '</button>' +
              '<button type="button" class="dxf-btn dxf-btn-primary dxf-annot-save">' + escHtml(t('action.save', 'Save')) + '</button>' +
            '</span>' +
          '</div>' +
          '<div class="dxf-annot-stage"><canvas class="dxf-annot-canvas"></canvas></div>' +
        '</div>';
      document.body.appendChild(overlay);

      var canvas = overlay.querySelector('.dxf-annot-canvas');
      var ctx    = canvas.getContext('2d');
      var img    = new Image();
      img.onload = function () { canvas.width = img.naturalWidth; canvas.height = img.naturalHeight; ctx.drawImage(img, 0, 0); };
      img.src = dataUrl;

      var drawing = false;
      function at(e) {
        var r = canvas.getBoundingClientRect();
        return { x: (e.clientX - r.left) * (canvas.width / r.width), y: (e.clientY - r.top) * (canvas.height / r.height) };
      }
      function start(e) { drawing = true; var p = at(e); ctx.strokeStyle = '#ff3b30'; ctx.lineWidth = Math.max(3, canvas.width / 400); ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
      function move(e) { if (!drawing) return; var p = at(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }
      function end() { drawing = false; }
      canvas.addEventListener('mousedown', start);
      canvas.addEventListener('mousemove', move);
      window.addEventListener('mouseup', end);
      function teardown() { window.removeEventListener('mouseup', end); overlay.remove(); }

      overlay.querySelector('.dxf-annot-clear').addEventListener('click', function () { ctx.clearRect(0, 0, canvas.width, canvas.height); ctx.drawImage(img, 0, 0); });
      overlay.querySelector('.dxf-annot-cancel').addEventListener('click', teardown);
      overlay.querySelector('.dxf-annot-save').addEventListener('click', function () {
        var btn = overlay.querySelector('.dxf-annot-save');
        btn.disabled = true; btn.textContent = t('state.saving', 'Saving…');
        var out;
        try { out = canvas.toDataURL('image/jpeg', 0.85); } catch (e) { out = null; }
        if (!out) { teardown(); return; }
        host.api.uploadScreenshot(out).then(function (url) {
          if (url) { state.pendingScreenshot = Promise.resolve(url); state.pendingShotDataUrl = out; setShotStatus('annotated'); }
          teardown();
        }).catch(teardown);
      });
    }

    // -------------------------------------------------------------------------
    // Sidebar
    // -------------------------------------------------------------------------
    function renderSidebar() {
      if (document.getElementById('dxf-sidebar')) return;

      // Brand logo: render both light + dark variants when available and let
      // CSS swap them based on data-rv-theme so the logo follows the theme
      // toggle. Falls back to a single image when the host only passes the
      // legacy `brand.logo` field, or to the comment-icon SVG (currentColor
      // auto-themes) when there's no whitelabel logo at all.
      var logoDark  = brand.logoDark  || brand.logo;
      var logoLight = brand.logoLight || brand.logo;
      var titleInner;
      if (brand.isWhitelabel && (logoDark || logoLight)) {
        titleInner = '';
        if (logoDark) {
          titleInner += '<img class="dxf-brand-logo dxf-brand-logo--dark" src="' + escAttr(logoDark) +
                        '" alt="' + escAttr(brand.name || '') + '">';
        }
        if (logoLight) {
          titleInner += '<img class="dxf-brand-logo dxf-brand-logo--light" src="' + escAttr(logoLight) +
                        '" alt="' + escAttr(brand.name || '') + '">';
        }
      } else {
        titleInner = ICONS.commentIcon + '<span class="dxf-sidebar-name">' +
                     escHtml(brand.name || t('sidebar.brand', 'Comments')) + '</span>';
      }

      var sidebar = document.createElement('div');
      sidebar.id = 'dxf-sidebar';
      // Lenis: prevent smooth-scroll libs from hijacking wheel/touch in the modal.
      sidebar.setAttribute('data-lenis-prevent', '');
      sidebar.setAttribute('data-lenis-prevent-wheel', '');
      sidebar.setAttribute('data-lenis-prevent-touch', '');

      // ── Tabs (This page / Everything) ──
      // ── Scope tabs: This page / Everything (full-width tab row) ──
      var scopeBar = caps.canScope
        ? '<div class="dxf-scope-bar">' +
            '<button class="dxf-scope-btn active" data-scope="page">' + escHtml(t('scope.thisPage', 'This page')) + ' <span class="dxf-scope-count" data-scope-count="page"></span></button>' +
            '<button class="dxf-scope-btn" data-scope="all">' + escHtml(t('scope.everything', 'Everything')) + ' <span class="dxf-scope-count" data-scope-count="all"></span></button>' +
          '</div>'
        : '';

      // ── Tools row: device + Reviews picker + the Resolved toggle (the old
      //    Open/Resolved/Mine/All pill row is gone — one switch covers the
      //    real use case: default hides resolved, toggle shows everything). ──
      var devSel = caps.canDeviceFilter
        ? '<button type="button" class="dxf-dropdown dxf-device-drop" data-pill="device">' +
            '<span class="dxf-dropdown-icon">' + ICONS.deviceIcon + '</span>' +
            '<span class="dxf-dropdown-label">' + escHtml(t('device.all', 'All devices')) + '</span>' +
            '<span class="dxf-dropdown-chev">' + ICONS.chev + '</span>' +
          '</button>'
        : '';
      var reviewContainer = caps.canReviewsPicker
        ? '<div class="dxf-review-bar" id="dxf-review-bar"></div>'
        : '';
      var resolvedToggle =
        '<label class="dxf-resolved-toggle" title="' + escAttr(t('resolved.toggleHint', 'Include resolved comments in the list')) + '">' +
          '<input type="checkbox" class="dxf-resolved-check"' + (state.filter && state.filter !== 'open' ? ' checked' : '') + '>' +
          '<span>' + escHtml(t('resolved', 'Resolved')) + '</span>' +
        '</label>';
      var toolsRow = '<div class="dxf-tools-row">' + devSel + reviewContainer + resolvedToggle + '</div>';

      // ── AI summarize — a compact header icon, not a full-width bar (the
      //    old bar cost an entire row of vertical space in the docked panel).
      var aiBtn = caps.canSummarize
        ? '<button type="button" class="dxf-ai-summarize dxf-ai-headbtn" aria-label="' + escAttr(t('ai.summarize', 'Summarize feedback')) + '" title="' + escAttr(t('ai.summarizeTitle', 'Summarize feedback (AI)')) + '">&#10024;</button>'
        : '';

      sidebar.innerHTML =
        '<div class="dxf-sidebar-header">' +
          '<div class="dxf-sidebar-title">' + titleInner + '</div>' +
          '<div class="dxf-sidebar-actions">' +
            aiBtn +
            (caps.canDockRight ?
              '<button class="dxf-dockright-toggle" type="button" aria-label="' + escAttr(t('dock.toSide', 'Dock to side')) + '" title="' + escAttr(t('dock.toSideFloat', 'Dock to side / float')) + '">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                  '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="15" y1="3" x2="15" y2="21"/>' +
                '</svg></button>' : '') +
            (caps.canToggleTheme === false ? '' :
              '<button class="dxf-theme-toggle" type="button" aria-label="' + escAttr(t('theme.toggle', 'Toggle theme')) + '" title="' + escAttr(t('theme.toggleTitle', 'Toggle light / dark')) + '"></button>') +
            '<button class="dxf-sidebar-close" aria-label="' + escAttr(t('action.close', 'Close')) + '">' + ICONS.close + '</button>' +
          '</div>' +
        '</div>' +
        scopeBar + toolsRow +
        '<div class="dxf-sidebar-body" id="dxf-sidebar-body"></div>' +
        '<div class="dxf-sidebar-footer" id="dxf-sidebar-footer"></div>';

      sidebar.querySelector('.dxf-sidebar-close').addEventListener('click', function () {
        closeSidebar();
        disableCommentMode();
      });
      var themeToggleBtn = sidebar.querySelector('.dxf-theme-toggle');
      if (themeToggleBtn) themeToggleBtn.addEventListener('click', toggleTheme);
      var dockRightBtn = sidebar.querySelector('.dxf-dockright-toggle');
      if (dockRightBtn) dockRightBtn.addEventListener('click', toggleDockRight);
      applyDockRight(); // restore docked/floating state on (re)render
      sidebar.querySelectorAll('.dxf-scope-btn').forEach(function (btn) {
        btn.addEventListener('click', function () { setScope(btn.dataset.scope); });
      });
      var resolvedCheck = sidebar.querySelector('.dxf-resolved-check');
      if (resolvedCheck) {
        resolvedCheck.addEventListener('change', function () {
          setFilter(resolvedCheck.checked ? 'all' : 'open');
        });
      }
      var sumBtn = sidebar.querySelector('.dxf-ai-summarize');
      if (sumBtn) sumBtn.addEventListener('click', openSummary);

      // Pre-populate the toggle's SVG using the local sidebar reference. The
      // shared updateThemeToggle() does a document.querySelector against
      // '#dxf-sidebar .dxf-theme-toggle', which doesn't resolve until
      // the sidebar is appended to the body — and that happens at the bottom
      // of this function. Setting the innerHTML here ensures the icon paints
      // on the very first render (previously it was empty until the user
      // clicked the button, which is what triggered the re-render).
      var themeBtn = sidebar.querySelector('.dxf-theme-toggle');
      if (themeBtn) {
        var currentTheme = document.documentElement.getAttribute('data-rv-theme') || preferredTheme();
        themeBtn.innerHTML = (currentTheme === 'light' ? ICONS.moon : ICONS.sun);
        themeBtn.setAttribute('aria-label', currentTheme === 'light' ? t('theme.switchToDark', 'Switch to dark mode') : t('theme.switchToLight', 'Switch to light mode'));
      }

      // Delegated handler — opens the custom popover for any [data-pill]
      // trigger (status / assignee / device / round). One popover at a time;
      // outside-click / Escape / scroll close it. See openPopover().
      sidebar.addEventListener('click', function (e) {
        var pill = e.target.closest('[data-pill]');
        if (!pill || !sidebar.contains(pill)) return;
        e.stopPropagation();
        var kind = pill.dataset.pill;
        if (kind === 'status') openStatusPopover(pill);
        else if (kind === 'assign') openAssignPopover(pill);
        else if (kind === 'device') openDevicePopover(pill);
        else if (kind === 'review') openReviewPopover(pill);
        else if (kind === 'review-switch') openReviewSwitchPopover(pill);
        else if (kind === 'comment-review') openCommentReviewPopover(pill);
      });

      // Delegated delete handler (works across re-renders, no rebinding).
      sidebar.addEventListener('click', function (e) {
        var del = e.target.closest('.dxf-delete-btn');
        if (!del) return;
        e.stopPropagation();
        var id = +del.dataset.id;
        if (!id) return;
        if (!del.classList.contains('is-armed')) { armDeleteButton(del); return; }
        clearTimeout(del._dxfArmTimer);
        del.classList.remove('is-armed');
        del.disabled = true;
        // Busy feedback while the AJAX round-trip runs — without it the
        // confirmed click looks like it did nothing for a second or two.
        var prevHtml = del.innerHTML;
        del.classList.add('is-busy');
        del.innerHTML = '<span class="dxf-spinner"></span>';
        var restoreBtn = function () {
          del.disabled = false;
          del.classList.remove('is-busy');
          del.innerHTML = prevHtml;
        };
        host.api.deleteComment(id).then(function (res) {
          if (res && res.success) {
            if (state.expandedThreads[id]) delete state.expandedThreads[id];
            refreshComments();
          } else {
            restoreBtn();
          }
        }).catch(restoreBtn);
      });

      document.body.appendChild(sidebar);
      initSidebarPosition(sidebar);
      setupSidebarDrag(sidebar);
    }

    function initSidebarPosition(sidebar) {
      var saved = null;
      try { saved = JSON.parse(localStorage.getItem('dxf_sidebar_pos')); } catch (e) {}

      // Measure the actual rendered size — the CSS sidebar width changed from
      // 320 → 420 when the modal redesign landed, and a hardcoded 320 here
      // meant the right edge slipped off-screen and the clamp couldn't catch
      // it. Read the live width/height instead so we stay in sync with CSS.
      var rect     = sidebar.getBoundingClientRect();
      var sidebarW = rect.width  || 420;
      var sidebarH = rect.height || Math.min(window.innerHeight * 0.9, 600);
      var margin   = 16;

      // Default placement: anchor just above the FAB at bottom-right. The FAB
      // is fixed at bottom:24 / right:24 with a ~48px height, so leaving a
      // ~80px gap floats the modal cleanly above it. In the builder there's
      // no FAB — fall back to host.defaultSidebarTop() (top-anchored).
      var fab      = document.getElementById('dxf-fab');
      var defaultL = window.innerWidth - sidebarW - margin;
      var defaultT;
      if (fab) {
        var fabRect = fab.getBoundingClientRect();
        var gap     = 16;
        defaultT    = Math.max(margin, fabRect.top - sidebarH - gap);
      } else if (host.defaultSidebarTop) {
        defaultT = host.defaultSidebarTop();
      } else {
        defaultT = 80;
      }

      var x = (saved && typeof saved.x === 'number') ? saved.x : defaultL;
      var y = (saved && typeof saved.y === 'number') ? saved.y : defaultT;
      // Clamp with the real width so the modal can't be pushed off either edge.
      x = Math.max(margin, Math.min(window.innerWidth  - sidebarW - margin, x));
      y = Math.max(margin, Math.min(window.innerHeight - Math.min(sidebarH, 120) - margin, y));
      sidebar.style.left = x + 'px';
      sidebar.style.top  = y + 'px';
    }

    function setupSidebarDrag(sidebar) {
      var header = sidebar.querySelector('.dxf-sidebar-header');
      if (!header) return;
      var isDragging = false, startMouseX, startMouseY, startLeft, startTop, capId = null;
      header.addEventListener('pointerdown', function (e) {
        if (e.button !== 0 || (e.target.closest && e.target.closest('button'))) return;
        if (sidebar.classList.contains('dxf-dock-right')) return; // docked = not draggable
        isDragging = true; startMouseX = e.clientX; startMouseY = e.clientY;
        startLeft = parseFloat(sidebar.style.left) || 0; startTop = parseFloat(sidebar.style.top) || 0;
        header.style.cursor = 'grabbing';
        // Pointer capture so a fast drag (or one that crosses the canvas iframe)
        // keeps tracking instead of being dropped.
        try { header.setPointerCapture(e.pointerId); capId = e.pointerId; } catch (err) {}
        e.preventDefault();
      });
      header.addEventListener('pointermove', function (e) {
        if (!isDragging) return;
        var nl = Math.max(0, Math.min(window.innerWidth  - sidebar.offsetWidth,  startLeft + e.clientX - startMouseX));
        var nt = Math.max(0, Math.min(window.innerHeight - sidebar.offsetHeight, startTop  + e.clientY - startMouseY));
        sidebar.style.left = nl + 'px'; sidebar.style.top = nt + 'px';
      });
      function endSidebarDrag() {
        if (!isDragging) return;
        isDragging = false; header.style.cursor = '';
        try { if (capId != null) header.releasePointerCapture(capId); } catch (err) {}
        capId = null;
        try { localStorage.setItem('dxf_sidebar_pos', JSON.stringify({ x: parseFloat(sidebar.style.left), y: parseFloat(sidebar.style.top) })); } catch (e) {}
      }
      header.addEventListener('pointerup', endSidebarDrag);
      header.addEventListener('pointercancel', endSidebarDrag);
    }

    function openSidebar() {
      state.sidebarOpen = true;
      var sidebar = document.getElementById('dxf-sidebar');
      if (sidebar) sidebar.classList.add('dxf-sidebar--open');
      renderSidebarBody();
      renderFooter();
      updateToggleActive();
      markAsSeen(); state.unreadCount = 0;
      updateReviewBar();
      renderPins();
      startPinTracking();
      showModePill();
    }

    function closeSidebar() {
      state.sidebarOpen = false;
      var sidebar = document.getElementById('dxf-sidebar');
      if (sidebar) sidebar.classList.remove('dxf-sidebar--open');
      updateToggleActive();
      stopPinTracking();
      renderPins();
      hideModePill();
    }

    function toggleSidebar() { state.sidebarOpen ? closeSidebar() : openSidebar(); }

    // -------------------------------------------------------------------------
    // Cursor-mode toggle — a floating bottom-centre pill that lets the user
    // switch between BROWSE/EDIT (clicks pass through: scroll, navigate, edit in
    // the builder; pins stay visible & clickable) and COMMENT (crosshair +
    // click-to-pin). Decouples "is the panel open" from "am I placing pins", so
    // reviewers can navigate and builder users can keep editing without the
    // overlay hijacking every click. In Bricks, COMMENT auto-enters Preview and
    // BROWSE exits it (enable/disableCommentMode already drive bricksPreview).
    // -------------------------------------------------------------------------
    function modePillAllowed() {
      return caps.canModeToggle !== false && !cfg.completed && !readOnly && host.identity.identified();
    }
    function mountModePill() {
      if (document.getElementById('dxf-mode-pill')) return;
      var pill = document.createElement('div');
      pill.id = 'dxf-mode-pill';
      pill.setAttribute('role', 'group');
      pill.setAttribute('aria-label', t('mode.cursor', 'Cursor mode'));
      pill.innerHTML =
        '<button type="button" class="dxf-mode-btn" data-mode="browse" aria-pressed="true">'  + ICONS.cursor  + '<span>' + escHtml(t('mode.browse', 'Browse')) + '</span></button>' +
        '<button type="button" class="dxf-mode-btn" data-mode="comment" aria-pressed="false">' + ICONS.comment + '<span>' + escHtml(t('mode.comment', 'Comment')) + '</span></button>';
      pill.querySelector('[data-mode="browse"]').addEventListener('click', function () {
        if (state.commentMode) disableCommentMode(); else updateModePill();
      });
      pill.querySelector('[data-mode="comment"]').addEventListener('click', function () {
        if (!state.commentMode) enableCommentMode(); else updateModePill();
      });
      document.body.appendChild(pill);
    }
    function showModePill() {
      if (!modePillAllowed()) return;
      mountModePill();
      var p = document.getElementById('dxf-mode-pill');
      if (p) p.classList.add('is-visible');
      updateModePill();
    }
    function hideModePill() {
      var p = document.getElementById('dxf-mode-pill');
      if (p) p.classList.remove('is-visible');
    }
    function updateModePill() {
      var p = document.getElementById('dxf-mode-pill');
      if (!p) return;
      var on = !!state.commentMode;
      p.classList.toggle('mode-comment', on);
      var b = p.querySelector('[data-mode="browse"]'), c = p.querySelector('[data-mode="comment"]');
      if (b) { b.classList.toggle('is-active', !on); b.setAttribute('aria-pressed', String(!on)); }
      if (c) { c.classList.toggle('is-active', on);  c.setAttribute('aria-pressed', String(on)); }
    }

    // toggleComments — opens/closes the panel. On open it applies the host's
    // default cursor mode: reviewers default to COMMENT (they came to give
    // feedback), builder/editor users default to BROWSE/EDIT (they came to
    // build). The floating mode pill (above) flips it thereafter.
    function toggleComments() {
      if (state.sidebarOpen) {
        closeSidebar();
        if (state.commentMode) disableCommentMode();
        return;
      }
      openSidebar();
      var defaultMode = caps.defaultMode || (host.bricksPreview ? 'browse' : 'comment');
      if (defaultMode === 'comment') {
        enableCommentMode();
      } else {
        // Browse/edit: ensure we're not in placement mode, just show the pill.
        if (state.commentMode) exitCommentPlacement();
        showModePill();
      }
    }

    // Sidebar body: identity gate (FE) or comment list.
    function renderSidebarBody() {
      var body = document.getElementById('dxf-sidebar-body');
      if (!body) return;
      if (!host.identity.identified() && host.renderIdentityGate) {
        toggleFilterBars(false);
        host.renderIdentityGate(body, function () {
          renderSidebarBody();
          renderFooter();
          enableCommentMode();
        });
        return;
      }
      toggleFilterBars(true);
      body.innerHTML = '<div class="dxf-comment-list" id="dxf-comment-list"></div>';
      renderCommentList();
    }

    function toggleFilterBars(show) {
      var sidebar = document.getElementById('dxf-sidebar');
      if (!sidebar) return;
      ['.dxf-scope-bar', '.dxf-sidebar-filters', '.dxf-viewport-bar',
       '.dxf-review-bar', '.dxf-ai-bar', '.dxf-tools-row'].forEach(function (sel) {
        var el = sidebar.querySelector(sel);
        if (el) el.style.display = show ? '' : 'none';
      });
    }

    // Footer: identity strip + approve (FE only, gated by canApprove)
    // OR the read-only "Page approved by X" banner in the builder.
    function renderFooter() {
      var footer = document.getElementById('dxf-sidebar-footer');
      if (!footer) return;

      // Builder view: show the latest approval (read-only) with a Revert button.
      if (caps.canViewApprovals && cfg.approvedBy) {
        renderApprovalBanner(footer);
        return;
      }

      if (!host.identity.identified()) { footer.innerHTML = ''; return; }

      // Approve is for approvers (FE only); "I'm done reviewing" is for ANY
      // active reviewer. The builder shows its read-only banner above instead.
      var showApprove = !!caps.canApprove && !caps.canViewApprovals;
      var showDone    = !!caps.canMarkDone;
      if (!showApprove && !showDone) { footer.innerHTML = ''; return; }

      // ── Approve section (approvers only) ──
      // Approval is gated on every comment being resolved. Counting the
      // page-scoped comment set (state.comments) is enough — `scope` only
      // affects which comments are *shown*, not which exist on this page.
      // Replies inherit their parent's resolved state, so we only count
      // top-level threads (parent_id === null).
      var approveDisabled = false;
      var approval = '';
      if (showApprove) {
        var unresolvedCount = 0;
        if (Array.isArray(state.comments)) {
          for (var i = 0; i < state.comments.length; i++) {
            var c = state.comments[i];
            if (!c.parent_id && c.status !== 'resolved') unresolvedCount++;
          }
        }
        approveDisabled = !cfg.completed && unresolvedCount > 0;
        if (cfg.completed) {
          approval = '<div class="dxf-approved-state">' + ICONS.check + '<span>' + escHtml(t('approve.done', 'Page approved')) + '</span></div>';
        } else if (approveDisabled) {
          // The "why disabled" reason now lives in a hover tooltip on the
          // button itself rather than as inline text above it.
          var blockTip = t('approve.blockedTip', 'Resolve every open comment before approving — %d still open.').replace('%d', unresolvedCount);
          approval = '<button type="button" class="dxf-btn dxf-btn-ghost dxf-btn-full is-disabled" id="dxf-mark-complete" ' +
            'aria-disabled="true" title="' + escAttr(blockTip) + '">' + escHtml(t('approve.mark', 'Mark page as approved')) + '</button>';
        } else {
          approval = '<button type="button" class="dxf-btn dxf-btn-ghost dxf-btn-full" id="dxf-mark-complete">' + escHtml(t('approve.mark', 'Mark page as approved')) + '</button>';
        }
      }

      // ── Review-completion section ──
      // Multi-page reviews: this button ("Request these changes") flips a per-page server state
      // and returns to the dashboard (no email — they notify once from there).
      // Single-page reviews: the button IS the finish action → optional note +
      // notify the developer immediately.
      var rv = cfg.review || {};
      var isMultiPage = !!(rv.pages && rv.pages.length > 1 && rv.landingUrl);
      var doneBtn = '';
      if (showDone) {
        if (isMultiPage) {
          var thisReviewed = false;
          for (var p = 0; p < rv.pages.length; p++) {
            if ((rv.pages[p].id | 0) === (cfg.postId | 0)) { thisReviewed = !!rv.pages[p].reviewed; break; }
          }
          doneBtn = thisReviewed
            ? '<button type="button" class="dxf-btn dxf-btn-ghost dxf-btn-full dxf-reviewed-toggle is-reviewed" id="dxf-unreview">' + ICONS.check + '<span>' + escHtml(t('review.reviewedUndo', 'Changes requested — undo')) + '</span></button>'
            : '<button type="button" class="dxf-btn dxf-btn-ghost dxf-btn-full dxf-done-btn" id="dxf-mark-reviewed">' + escHtml(t('review.markReviewed', 'Request these changes')) + '</button>';
        } else {
          doneBtn = '<button type="button" class="dxf-btn dxf-btn-ghost dxf-btn-full dxf-done-btn" id="dxf-finish-review">' + escHtml(t('review.finishNotify', 'Finish & notify developer')) + '</button>';
        }
      }

      var identityStrip = host.identity.isLoggedIn
        ? ''
        : '<div class="dxf-identity-strip">' +
            escHtml(t('identity.commentingAs', 'Commenting as')) + ' <strong>' + escHtml(host.identity.name) + '</strong> ' +
            '<button type="button" class="dxf-link-btn" id="dxf-change-identity">' + escHtml(t('identity.change', 'Change')) + '</button>' +
          '</div>';

      // Done + Approve sit side-by-side.
      footer.innerHTML = identityStrip +
        '<div class="dxf-footer-actions">' + doneBtn + approval + '</div>';

      // Multi-page: mark this page reviewed → back to the dashboard.
      var markRevBtn = footer.querySelector('#dxf-mark-reviewed');
      if (markRevBtn) markRevBtn.addEventListener('click', function () {
        markRevBtn.disabled = true;
        markRevBtn.textContent = t('state.saving', 'Saving…');
        host.api.markReviewed(true).then(function (res) {
          if (res && res.dashboardUrl) { window.location.href = res.dashboardUrl; }
          else { renderFooter(); }
        }).catch(function () {
          markRevBtn.disabled = false;
          markRevBtn.textContent = t('review.markReviewed', 'Request these changes');
        });
      });

      // Multi-page: undo "reviewed" (stay on the page).
      var unrevBtn = footer.querySelector('#dxf-unreview');
      if (unrevBtn) unrevBtn.addEventListener('click', function () {
        unrevBtn.disabled = true;
        host.api.markReviewed(false).then(function () {
          if (rv.pages) {
            for (var q = 0; q < rv.pages.length; q++) {
              if ((rv.pages[q].id | 0) === (cfg.postId | 0)) { rv.pages[q].reviewed = false; }
            }
          }
          renderFooter();
        }).catch(function () { unrevBtn.disabled = false; });
      });

      // Single-page: finish → optional note → notify.
      var finishBtn = footer.querySelector('#dxf-finish-review');
      if (finishBtn) finishBtn.addEventListener('click', function () {
        var actions = footer.querySelector('.dxf-footer-actions');
        if (!actions) return;
        actions.innerHTML =
          '<div class="dxf-finish-note">' +
            '<textarea id="dxf-finish-note-text" rows="2" placeholder="' + escAttr(t('finish.notePlaceholder', 'Add a note for your developer (optional)…')) + '"></textarea>' +
            '<div class="dxf-finish-note-actions">' +
              '<button type="button" class="dxf-btn dxf-btn-ghost" id="dxf-finish-cancel">' + escHtml(t('action.cancel', 'Cancel')) + '</button>' +
              '<button type="button" class="dxf-btn dxf-btn-primary" id="dxf-finish-send">' + escHtml(t('finish.send', 'Send to developer')) + '</button>' +
            '</div>' +
          '</div>';
        var ta = footer.querySelector('#dxf-finish-note-text');
        if (ta) ta.focus();
        var cancelBtn = footer.querySelector('#dxf-finish-cancel');
        if (cancelBtn) cancelBtn.addEventListener('click', renderFooter);
        var sendBtn = footer.querySelector('#dxf-finish-send');
        if (sendBtn) sendBtn.addEventListener('click', function () {
          var note = ta ? ta.value : '';
          sendBtn.disabled = true;
          sendBtn.textContent = t('state.sending', 'Sending…');
          host.api.reviewDone(note).then(function () {
            var a = footer.querySelector('.dxf-footer-actions');
            if (a) a.innerHTML = '<div class="dxf-done-state">' + ICONS.check + '<span>' + escHtml(t('finish.notified', 'Developer notified')) + '</span></div>';
          }).catch(function () {
            sendBtn.disabled = false;
            sendBtn.textContent = t('finish.send', 'Send to developer');
          });
        });
      });

      var changeBtn = footer.querySelector('#dxf-change-identity');
      if (changeBtn) changeBtn.addEventListener('click', function () {
        // Change-name flow: replace the sidebar body with a name-only form
        // and pause comment-mode while it's open. Email is preserved (the
        // user is just relabelling themselves), and a Back button restores
        // the previous view without saving. Falls back to the legacy full
        // clear-and-re-gate path if the host hasn't implemented the
        // changeNameOnly option (e.g. older review.js builds).
        if (!host.renderIdentityGate) return;
        var body = document.getElementById('dxf-sidebar-body');
        if (!body) return;
        var wasCommentMode = state.commentMode;
        disableCommentMode();
        toggleFilterBars(false);
        // Blank the footer so the identity strip + Approve button don't
        // sit beneath the change form (would be confusing — implies you can
        // approve mid-rename).
        var footerEl = document.getElementById('dxf-sidebar-footer');
        if (footerEl) footerEl.innerHTML = '';
        host.renderIdentityGate(body, function () {
          // Saved — back to the normal comment list + footer.
          renderSidebarBody();
          renderFooter();
          if (wasCommentMode) enableCommentMode();
        }, {
          changeNameOnly: true,
          onCancel: function () {
            renderSidebarBody();
            renderFooter();
            if (wasCommentMode) enableCommentMode();
          },
        });
      });

      // Approve only wires up when enabled; the disabled state explains itself
      // via the hover tooltip on the button (no inline hint anymore).
      if (!cfg.completed && !approveDisabled) {
        var btn = footer.querySelector('#dxf-mark-complete');
        if (btn) btn.addEventListener('click', showApprovalConfirm);
      }
    }

    function showApprovalConfirm() {
      var footer = document.getElementById('dxf-sidebar-footer');
      var hostEl = footer && footer.querySelector('#dxf-mark-complete');
      if (!hostEl) return;
      var box = document.createElement('div');
      box.className = 'dxf-approve-confirm';
      box.innerHTML =
        '<label class="dxf-approve-auth"><input type="checkbox" id="dxf-approve-auth"> ' +
          escHtml(t('approve.authority', 'I confirm I have the authority to approve this page.')) + '</label>' +
        '<p class="dxf-approve-note">' + escHtml(t('approve.recordNote', 'Your name, email, and the date & time will be recorded as a record of this approval.')) + '</p>' +
        '<div class="dxf-approve-actions">' +
          '<button type="button" class="dxf-btn dxf-btn-ghost" id="dxf-approve-cancel">' + escHtml(t('action.cancel', 'Cancel')) + '</button>' +
          '<button type="button" class="dxf-btn dxf-btn-primary" id="dxf-approve-go" disabled>' + escHtml(t('approve.button', 'Approve page')) + '</button>' +
        '</div>';
      hostEl.replaceWith(box);

      var chk = box.querySelector('#dxf-approve-auth');
      var go  = box.querySelector('#dxf-approve-go');
      chk.addEventListener('change', function () { go.disabled = !chk.checked; });
      box.querySelector('#dxf-approve-cancel').addEventListener('click', renderFooter);
      go.addEventListener('click', function () {
        if (!chk.checked) return;
        go.disabled = true; go.textContent = t('state.saving', 'Saving…');
        host.api.markComplete().then(function (d) {
          if (d.success) {
            cfg.completed = true;
            // Keep the in-memory pages list in sync with what the server just
            // wrote, so the bottom-left pages-nav and any other UI reading
            // cfg.review.pages flips to "Approved" without a page refresh.
            if (cfg.review && Array.isArray(cfg.review.pages)) {
              for (var i = 0; i < cfg.review.pages.length; i++) {
                if (+cfg.review.pages[i].id === +cfg.postId) {
                  cfg.review.pages[i].status = 'approved';
                  break;
                }
              }
            }
            if (typeof host.onApproved === 'function') {
              try { host.onApproved(cfg.postId); } catch (e) {}
            }
            renderFooter();
          } else {
            go.disabled = false; go.textContent = t('approve.button', 'Approve page');
          }
        }).catch(function () { go.disabled = false; go.textContent = t('approve.button', 'Approve page'); });
      });
    }

    // Builder-only: read-only banner showing who approved the page and when,
    // with a Revert button to take it back to "unapproved" (e.g. when new
    // comments come in that change the verdict).
    function renderApprovalBanner(footer) {
      var a = cfg.approvedBy || {};
      var when = a.approved_at ? timeAgo(a.approved_at) : '';
      footer.innerHTML =
        '<div class="dxf-approval-banner">' +
          '<div class="dxf-approval-banner-head">' +
            '<span class="dxf-approval-banner-icon">' + ICONS.check + '</span>' +
            '<div class="dxf-approval-banner-body">' +
              '<div class="dxf-approval-banner-title">' + escHtml(t('approve.bannerTitle', 'Page approved by %s').replace('%s', a.name || t('role.reviewer', 'Reviewer'))) + '</div>' +
              '<div class="dxf-approval-banner-meta">' +
                (a.email ? escHtml(a.email) : '') +
                (when ? (a.email ? ' · ' : '') + escHtml(when) : '') +
              '</div>' +
            '</div>' +
          '</div>' +
          '<button type="button" class="dxf-btn dxf-btn-ghost dxf-approval-revert">' + escHtml(t('approve.revert', 'Revert approval')) + '</button>' +
        '</div>';
      var rev = footer.querySelector('.dxf-approval-revert');
      if (rev) rev.addEventListener('click', function () {
        if (!host.api.unapprove) { return; }
        if (!confirm(t('approve.revertConfirm', 'Revert this page back to unapproved? The original approval record is removed.'))) return;
        rev.disabled = true; rev.textContent = t('approve.reverting', 'Reverting…');
        host.api.unapprove(cfg.postId).then(function (res) {
          if (res && res.success) { cfg.approvedBy = null; renderFooter(); }
          else { rev.disabled = false; rev.textContent = t('approve.revert', 'Revert approval'); }
        }).catch(function () { rev.disabled = false; rev.textContent = t('approve.revert', 'Revert approval'); });
      });
    }

    // -------------------------------------------------------------------------
    // Comment list + card
    // -------------------------------------------------------------------------
    // Status metadata used by the foot pill + readout. Internal status keys
    // (open/in_progress/resolved) preserved; display labels are i18n-overridable.
    var STATUS_META = {
      open:        { label: t('open',       'Open'),      dot: '#22c55e' },
      in_progress: { label: t('inProgress', 'In review'), dot: '#f59e0b' },
      resolved:    { label: t('resolved',   'Resolved'),  dot: '#94a3b8' },
    };

    // ── Graceful comment-card reconciliation ────────────────────────────────
    // The list used to be rebuilt wholesale (`list.innerHTML = …`) on every
    // poll / resolve / delete, so even unchanged cards were destroyed and
    // recreated — everything "popped". Instead we keep existing card nodes in
    // place and animate only genuine changes: new cards fade/scale in, removed
    // cards fade out, surviving cards slide to their new positions (FLIP), and
    // resolve becomes a CSS class transition on the node that stays. Cards
    // whose rendered content is unchanged (ignoring drifting relative
    // timestamps) are left completely untouched.
    var REDUCE_MOTION = (function () {
      try { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); }
      catch (e) { return false; }
    })();
    var CARD_MOVE_EASE = 'cubic-bezier(.22,.61,.36,1)';

    // Relative timestamps drift every poll; strip them so an otherwise
    // unchanged card isn't needlessly re-rendered.
    function cardSig(html) {
      return html.replace(/(class="dxf-time">)[^<]*/g, '$1');
    }
    function cardHtmlToNode(html) {
      var t = document.createElement('div');
      t.innerHTML = html;
      return t.firstElementChild;
    }
    function clearCardTransform(node) {
      node.addEventListener('transitionend', function te(ev) {
        if (ev.target !== node || ev.propertyName !== 'transform') return;
        node.style.transition = '';
        node.style.transform  = '';
        node.removeEventListener('transitionend', te);
      });
    }
    function morphCard(node, html, sig) {
      var fresh = cardHtmlToNode(html);
      // Keep the live node (preserves its bound card-level handlers and any
      // in-progress FLIP transform) but swap class + contents. The class
      // change lets is-resolved / is-in-progress transition via CSS.
      if (node.className !== fresh.className) node.className = fresh.className;
      node.innerHTML = fresh.innerHTML;
      node._rvSig = sig;
    }
    function animateCardOut(node) {
      if (node._rvExiting) return;
      node._rvExiting = true;
      if (REDUCE_MOTION) { if (node.parentNode) node.parentNode.removeChild(node); return; }
      node.classList.remove('dxf-card-enter'); // clean fade from full opacity
      // Pin the card in place (out of flow) so siblings reflow to their final
      // positions immediately — the FLIP pass then slides them — while this
      // card fades out on top. Geometry must be read by the caller BEFORE any
      // exits are pinned (see reconcileCardList).
      node.style.position      = 'absolute';
      node.style.top           = node._rvExitTop + 'px';
      node.style.left          = node._rvExitLeft + 'px';
      node.style.width         = node._rvExitW + 'px';
      node.style.height        = node._rvExitH + 'px';
      node.style.margin        = '0';
      node.style.zIndex        = '0';
      node.style.pointerEvents = 'none';
      requestAnimationFrame(function () { node.classList.add('dxf-card-exit'); });
      var done = false;
      var finish = function () { if (done) return; done = true; if (node.parentNode) node.parentNode.removeChild(node); };
      node.addEventListener('transitionend', function (ev) { if (ev.target === node) finish(); });
      setTimeout(finish, 500); // safety net if transitionend doesn't fire
    }

    // descriptors: ordered [{ id, html, sig }]
    function reconcileCardList(list, descriptors) {
      var existing = {};
      Array.prototype.forEach.call(list.children, function (node) {
        if (node.classList && node.classList.contains('dxf-comment') && !node._rvExiting) {
          existing[node.getAttribute('data-id')] = node;
        }
      });
      var want = {};
      descriptors.forEach(function (d) { want[d.id] = true; });

      // FIRST: record survivor positions for FLIP.
      var firstTops = {};
      if (!REDUCE_MOTION) {
        Object.keys(existing).forEach(function (id) {
          firstTops[id] = existing[id].getBoundingClientRect().top;
        });
      }

      // EXITS — capture ALL geometries before pinning any (pinning one shifts
      // the offsets of the rest), then animate them out.
      var exits = Object.keys(existing).filter(function (id) { return !want[id]; });
      exits.forEach(function (id) {
        var n = existing[id];
        n._rvExitTop = n.offsetTop; n._rvExitLeft = n.offsetLeft;
        n._rvExitW = n.offsetWidth; n._rvExitH = n.offsetHeight;
      });
      exits.forEach(function (id) { animateCardOut(existing[id]); delete existing[id]; });

      // ENTER / KEEP / MORPH / ORDER — walk wanted order.
      var entered = [];
      var cursor  = null;
      descriptors.forEach(function (d) {
        var node = existing[d.id];
        if (node) {
          if (node._rvSig !== d.sig) morphCard(node, d.html, d.sig);
        } else {
          node = cardHtmlToNode(d.html);
          node._rvSig = d.sig;
          entered.push(node);
        }
        var ref = cursor ? cursor.nextSibling : list.firstChild;
        if (ref !== node) list.insertBefore(node, ref);
        cursor = node;
      });

      if (REDUCE_MOTION) return;

      // LAST + INVERT + PLAY for survivors that moved.
      Object.keys(existing).forEach(function (id) {
        var node = existing[id];
        var dy = firstTops[id] - node.getBoundingClientRect().top;
        if (Math.abs(dy) < 1) return;
        node.style.transition = 'none';
        node.style.transform  = 'translateY(' + dy + 'px)';
        requestAnimationFrame(function () {
          node.style.transition = 'transform .32s ' + CARD_MOVE_EASE;
          node.style.transform  = '';
        });
        clearCardTransform(node);
      });

      // Enter animation for brand-new cards. A CSS @keyframes (fill-mode both)
      // rather than a class-swap, so a card re-rendered mid-animation can never
      // get stranded at opacity 0 — the keyframe always resolves to visible.
      entered.forEach(function (node) {
        node.classList.add('dxf-card-enter');
        node.addEventListener('animationend', function ae(ev) {
          if (ev.target !== node) return;
          node.classList.remove('dxf-card-enter');
          node.removeEventListener('animationend', ae);
        });
      });
    }

    function renderCommentList() {
      var list = document.getElementById('dxf-comment-list');
      if (!list) return;
      updateScopeCounts();
      updateFilterCounts();

      // Approved pages: clear the comment list and show a read-only banner
      // in its place. We render this for reviewers (not the builder Review
      // tab — `canViewApprovals` covers that read-only view) so the FAB
      // can still open the sidebar without misleading them into thinking
      // they can leave new comments. Pins on the page itself remain (so
      // they can still see WHERE the prior feedback landed) but clicks
      // don't open the new-comment form — enableCommentMode is gated on
      // cfg.completed.
      if (cfg.completed && !caps.canViewApprovals) {
        toggleFilterBars(false);
        list.innerHTML =
          '<div class="dxf-approved-empty">' +
            '<div class="dxf-approved-empty-icon">' + ICONS.check + '</div>' +
            '<p class="dxf-approved-empty-title">' + escHtml(t('approvedEmpty.title', 'This page has been approved')) + '</p>' +
            '<p class="dxf-approved-empty-body">' + escHtml(t('approvedEmpty.body', 'New comments are closed because this page has been marked as approved. If something\'s changed, ask the team to re-open the review.')) + '</p>' +
          '</div>';
        return;
      }

      var filtered    = getVisibleComments();
      var canvasDoc   = host.getCanvasDoc();
      var replySource = state.scope === 'all' ? state.allComments : state.comments;

      var descriptors = filtered.map(function (comment) {
        var replies = replySource.filter(function (c) { return String(c.parent_id) === String(comment.id); });
        var html = commentCardHtml(comment, replies, { canvasDoc: canvasDoc });
        return { id: String(comment.id), html: html, sig: cardSig(html) };
      });

      // Reconcile cards in place (animated). The empty-state message is a
      // sibling <p> the reconciler leaves alone — we add/remove it here.
      reconcileCardList(list, descriptors);

      var emptyEl = list.querySelector('.dxf-empty');
      if (!descriptors.length) {
        if (!emptyEl) { emptyEl = document.createElement('p'); emptyEl.className = 'dxf-empty'; list.appendChild(emptyEl); }
        // "No <filter> comments<suffix>." — the filter word (open/resolved/…)
        // and the optional " site-wide" suffix are each translatable, then
        // composed into the sentence template so word order stays localizable.
        var emptyFilter = t('empty.filter.' + state.filter, state.filter);
        var emptySuffix = state.scope === 'all' ? t('empty.siteWideSuffix', ' site-wide') : '';
        emptyEl.textContent = t('empty.noComments', 'No %s comments%s.')
          .replace('%s', emptyFilter).replace('%s', emptySuffix);
      } else if (emptyEl) {
        emptyEl.parentNode.removeChild(emptyEl);
      }

      bindCardHandlers(list);
    }

    function renderStatusPill(comment) {
      var s = STATUS_META[comment.status] || STATUS_META.open;
      return (
        '<button type="button" class="dxf-pill dxf-pill--status" data-pill="status" data-id="' + comment.id + '" title="' + escAttr(t('pill.status', 'Status')) + '">' +
          '<span class="dxf-pill-dot" style="background:' + s.dot + '"></span>' +
          '<span class="dxf-pill-label">' + escHtml(s.label) + '</span>' +
          '<span class="dxf-pill-chev">' + ICONS.chev + '</span>' +
        '</button>'
      );
    }

    function renderStatusReadout(comment) {
      var s = STATUS_META[comment.status] || STATUS_META.open;
      return (
        '<span class="dxf-pill dxf-pill--status is-static">' +
          '<span class="dxf-pill-dot" style="background:' + s.dot + '"></span>' +
          '<span class="dxf-pill-label">' + escHtml(s.label) + '</span>' +
        '</span>'
      );
    }

    function renderAssignPill(comment) {
      var members = cfg.assignees || [];
      var assignedId = comment.assignee_id || 0;
      var assigned = null;
      for (var i = 0; i < members.length; i++) {
        if (String(members[i].id) === String(assignedId)) { assigned = members[i]; break; }
      }
      var leading = assigned
        ? '<span class="dxf-pill-avatar" style="background:' + escAttr(userColor({ author_name: assigned.name, author_email: assigned.email || '' })) + '">' + toInitials(assigned.name) + '</span>'
        : '<span class="dxf-pill-glyph">' + ICONS.userIcon + '</span>';
      return (
        // Unassigned pills are an ACTION, not information — they hover-reveal
        // (CSS) so "Unassigned" isn't stamped on every card. Assigned ones
        // stay visible.
        '<button type="button" class="dxf-pill dxf-pill--assign' + (assigned ? '' : ' is-unassigned') + '" data-pill="assign" data-id="' + comment.id + '" title="' + escAttr(t('pill.assignee', 'Assignee')) + '">' +
          leading +
          '<span class="dxf-pill-label">' + escHtml(assigned ? assigned.name : t('unassigned', 'Unassigned')) + '</span>' +
          '<span class="dxf-pill-chev">' + ICONS.chev + '</span>' +
        '</button>'
      );
    }

    // One-tap emoji reactions. Counts come from the server payload
    // (comment.reactions = [{reaction, count, mine}]); identities never do.
    // Hidden entirely when the host has no toggleReaction api (older host
    // adapter) — never render a dead control.
    // The emoji glyphs are supplied by CSS `content` (keyed off data-e), NOT
    // text nodes — WordPress's twemoji script rewrites emoji TEXT into <img>
    // tags, which broke native system-emoji rendering. Pseudo-element content
    // is invisible to it.
    var REACTION_KEYS = ['thumbs_up', 'heart', 'check', 'eyes'];
    function reactionEmojiSpan(key) {
      return '<span class="dxf-react-emoji" data-e="' + key + '"></span>';
    }
    function renderReactions(comment) {
      if (!host.api.toggleReaction) return '';
      var byKey = {};
      (comment.reactions || []).forEach(function (r) { byKey[r.reaction] = r; });
      // Read-only reviews: show existing counts as static chips, no buttons.
      if (readOnly) {
        var statics = REACTION_KEYS.filter(function (k) { return byKey[k]; }).map(function (k) {
          return '<span class="dxf-react is-static">' +
            reactionEmojiSpan(k) +
            '<span class="dxf-react-count">' + Number(byKey[k].count) + '</span></span>';
        }).join('');
        return statics ? '<div class="dxf-reactions">' + statics + '</div>' : '';
      }
      var chips = REACTION_KEYS.map(function (k) {
        var r     = byKey[k];
        var count = r ? Number(r.count) : 0;
        var mine  = !!(r && r.mine);
        return '<button type="button" class="dxf-react' + (mine ? ' is-mine' : '') + (count ? ' has-count' : '') + '"' +
          ' data-id="' + comment.id + '" data-reaction="' + k + '"' +
          ' aria-pressed="' + (mine ? 'true' : 'false') + '" title="' + escAttr(t('react', 'React')) + '">' +
          reactionEmojiSpan(k) +
          (count ? '<span class="dxf-react-count">' + count + '</span>' : '') +
        '</button>';
      }).join('');
      return '<div class="dxf-reactions">' + chips + '</div>';
    }

    function renderThreadPanel(comment, replies) {
      // When the review is read-only, keep replies visible but
      // drop the reply composer — new comments are blocked server-side anyway.
      var composer = readOnly
        ? ''
        : '<div class="dxf-inline-reply" data-parent="' + comment.id + '">' +
            '<textarea class="dxf-inline-reply-text" placeholder="' + escAttr(t('reply.placeholder', 'Write a reply… (Enter to send)')) + '" rows="2"></textarea>' +
            '<div class="dxf-inline-reply-files"></div>' +
            '<div class="dxf-inline-reply-actions">' +
              (uploadsOff() ? '' :
                '<input type="file" multiple hidden class="dxf-inline-reply-file">' +
                '<button type="button" class="dxf-inline-reply-attach" title="' + escAttr(t('form.attachFiles', 'Attach files')) + '" aria-label="' + escAttr(t('form.attachFiles', 'Attach files')) + '">' + ICONS.attach + '</button>') +
              '<button type="button" class="dxf-btn dxf-btn-ghost dxf-inline-reply-cancel" data-id="' + comment.id + '">' + escHtml(t('action.cancel', 'Cancel')) + '</button>' +
              '<button type="button" class="dxf-btn dxf-btn-primary dxf-inline-reply-submit" data-id="' + comment.id + '">' + escHtml(t('reply', 'Reply')) + '</button>' +
            '</div>' +
          '</div>';
      return (
        '<div class="dxf-thread-panel">' +
          (replies.length ? renderReplies(replies) : '') +
          composer +
        '</div>'
      );
    }

    function commentCardHtml(comment, replies, opts) {
      opts = opts || {};
      var canvasDoc    = opts.canvasDoc || host.getCanvasDoc();
      var isResolved   = comment.status === 'resolved';
      var isInProgress = comment.status === 'in_progress';
      var num          = getCommentNumber(comment.id);
      var anchor       = parseAnchor(comment.anchor_data);
      var uc           = userColor(comment);
      var isSamePage   = String(comment.post_id) === String(cfg.postId);
      var canNavigate  = !isSamePage && comment.post_url;
      var orphAdapter  = adapterFor(anchor, canvasDoc);
      var orphaned     = isSamePage && !!(anchor && (anchor.element_id || anchor.strategies)) && orphAdapter.hasAnchorableContent(canvasDoc) && !orphAdapter.resolve(anchor, canvasDoc);
      var elId         = (anchor && anchor.element_id) || '';
      var bpRaw        = (anchor && anchor.context && anchor.context.breakpoint) ? String(anchor.context.breakpoint).toLowerCase() : '';
      var bp           = (bpRaw === 'desktop' || bpRaw === 'tablet' || bpRaw === 'mobile') ? bpRaw : '';
      var expanded     = !!state.expandedThreads[comment.id];
      var isOwn        = !!(host.identity.name && comment.author_name === host.identity.name);
      var showDelete   = caps.canDelete && (!caps.canDeleteOwnOnly || isOwn);
      var canEdit      = !readOnly && isOwn && !!(host.api && host.api.editComment);
      var fallback     = (caps.canApprove ? t('role.reviewer', 'Reviewer') : t('role.teamMember', 'Team member'));
      var authorDisp   = comment.author_name || fallback;
      var triageHot    = !!(anchor && anchor.triage &&
                            /^(high|urgent|critical)$/i.test(String(anchor.triage.priority || '')));

      var shot = (anchor && anchor.screenshot)
        ? '<button type="button" class="dxf-shot-btn" data-shot="' + escAttr(anchor.screenshot) + '" title="' + escAttr(t('shot.view', 'View screenshot')) + '">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
              '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>' +
              '<circle cx="12" cy="13" r="4"/>' +
            '</svg>' +
            '<span class="dxf-shot-btn-label">' + escHtml(t('shot.view', 'View screenshot')) + '</span>' +
          '</button>'
        : '';
      var pageTag = (state.scope === 'all' && comment.post_title)
        ? (canNavigate
            ? '<a class="dxf-page-tag dxf-page-tag--nav" href="' + escAttr(comment.post_url) + '" title="' + escAttr(t('page.openInBuilder', 'Open in builder')) + '">' + escHtml(comment.post_title) + ' ↗</a>'
            : '<span class="dxf-page-tag">' + escHtml(comment.post_title) + '</span>')
        : '';

      // Resolve moved to an explicit text button in the footer (next to
      // Reply) — the hover-revealed circle icon was too hidden/ambiguous.
      var actions =
        '<div class="dxf-comment-actions">' +
          (canEdit
            ? '<button class="dxf-edit-btn" data-id="' + comment.id + '" title="' + escAttr(t('comment.edit', 'Edit comment')) + '" aria-label="' + escAttr(t('comment.edit', 'Edit comment')) + '">' + ICONS.pencil + '</button>'
            : '') +
          (showDelete
            ? '<button class="dxf-delete-btn" data-id="' + comment.id + '" title="' + escAttr(t('comment.delete', 'Delete comment')) + '" aria-label="' + escAttr(t('comment.delete', 'Delete comment')) + '">' + ICONS.trash + '</button>'
            : '') +
        '</div>';
      var numPillStatus = isResolved ? ' is-resolved' : (isInProgress ? ' is-progress' : '');
      var numPill = num ? '<span class="dxf-num-pill' + numPillStatus + '" title="' + escAttr(t('comment.numberTitle', 'Comment #%d').replace('%d', num)) + '">' + num + '</span>' : '';

      // Per-comment "Assign to Review" — only shown when this comment isn't
      // attached to any Review AND the viewer can pick reviews (editors).
      // Lives to the LEFT of the number badge as a small pill button.
      // Hidden when there are no Reviews to assign to — a picker with no
      // options is just noise on the card.
      var unassignedToReview = caps.canPickReview && !(+comment.review_id) &&
                               !!(cfg.reviews && cfg.reviews.length);
      var assignReviewBtn = unassignedToReview
        ? '<button type="button" class="dxf-comment-review-pick" data-pill="comment-review" data-id="' + comment.id + '" title="' + escAttr(t('review.assignTo', 'Assign to a Review')) + '" aria-label="' + escAttr(t('review.assignTo', 'Assign to a Review')) + '">' +
            ICONS.commentIcon +
            '<span class="dxf-comment-review-pick-label">' + escHtml(t('review.assign', 'Assign')) + '</span>' +
          '</button>'
        : '';

      return (
        '<div class="dxf-comment' +
            (isResolved ? ' is-resolved' : '') +
            (isInProgress ? ' is-in-progress' : '') +
            (expanded ? ' is-expanded' : '') +
            (replies.length ? ' has-replies' : '') +
            (comment._pending ? ' is-pending' : '') +
            ' dxf-comment--clickable' +
          '" data-id="' + comment.id + '"' +
          ' data-el="' + escAttr(elId) + '" data-samepage="' + (isSamePage ? '1' : '0') + '" data-post-url="' + escAttr(comment.post_url || '') + '"' +
          ' data-breakpoint="' + escAttr(bp) + '"' +
          ' style="--rv-user:' + escAttr(uc) + '">' +
          pageTag +
          // ── Top tag row: triage (left) + "Element removed" (right). A lone
          //    orphaned tag (no triage) left-aligns via the flex container.
          //    Triage badges only render for High/Urgent — "Other · Medium"
          //    on every card was pure noise; the AI summary keeps the full
          //    classification. ──
          ((triageHot || orphaned)
            ? '<div class="dxf-comment-toprow">' +
                (triageHot ? renderTriage(anchor.triage) : '') +
                (orphaned ? '<span class="dxf-orphaned-tag">' + escHtml(t('orphaned', 'Element removed')) + '</span>' : '') +
              '</div>'
            : '') +
          // ── Header ──
          '<div class="dxf-comment-head">' +
            '<span class="dxf-avatar" style="background:' + escAttr(uc) + '">' + toInitials(authorDisp) + '</span>' +
            '<div class="dxf-comment-meta">' +
              '<span class="dxf-author">' + escHtml(authorDisp) + '</span>' +
              '<span class="dxf-time">' + (comment._pending ? escHtml(t('state.sending', 'Sending…')) : timeAgo(comment.created_at)) + '</span>' +
            '</div>' +
            actions + assignReviewBtn + numPill +
          '</div>' +
          // ── Body ──
          (comment.body ? '<p class="dxf-comment-body">' + linkify(comment.body) + '</p>' : '') +
          // ── Screenshot button — opens lightbox on click ──
          (shot ? '<div class="dxf-shot-wrap">' + shot + '</div>' : '') +
          // ── Context + attachments. The browser/viewport context line is
          //    team-facing debugging metadata — hidden on the public reviewer
          //    surface (caps.showCardContext === false) where clients never
          //    act on it; the data is still captured and visible in-builder. ──
          (caps.showCardContext !== false && anchor && anchor.context ? '<div class="dxf-comment-pad">' + renderContext(anchor.context) + '</div>' : '') +
          (anchor && anchor.attachments && anchor.attachments.length ? '<div class="dxf-comment-pad">' + renderAttachments(anchor.attachments) + '</div>' : '') +
          // ── Emoji reactions (one-tap ack) ──
          renderReactions(comment) +
          // ── Reply peek (latest reply preview when collapsed) — sits at the
          //    bottom of the card, directly above the footer, so the preview
          //    reads as the lead-in to the Reply control rather than floating
          //    mid-card between attachments and reactions. ──
          (replies.length && !expanded ? renderReplyPeek(replies, comment.id) : '') +
          // ── Footer ──
          '<div class="dxf-comment-foot">' +
            (caps.canStatusSelect ? renderStatusPill(comment) : renderStatusReadout(comment)) +
            (caps.canAssign ? renderAssignPill(comment) : '') +
            '<div class="dxf-comment-foot-right">' +
              // Single Reply control: carries the thread count as a badge so
              // there's no separate "X replies" pill. Click toggles the
              // thread (and focuses the reply box when opening). Read-only
              // surfaces keep a count-only toggle since they can't reply.
              (readOnly
                ? (replies.length
                    ? '<button class="dxf-thread-toggle' + (expanded ? ' is-active' : '') + '" data-id="' + comment.id + '" title="' + escAttr(expanded ? t('thread.hide', 'Hide thread') : t('thread.show', 'Show thread')) + '">' + ICONS.commentIcon + '<span>' + escHtml((replies.length === 1 ? t('thread.replyOne', '%d reply') : t('thread.replyMany', '%d replies')).replace('%d', replies.length)) + '</span></button>'
                    : '')
                : '<button class="dxf-reply-open' + (expanded ? ' is-active' : '') + '" data-id="' + comment.id + '" title="' + escAttr(expanded ? t('thread.hide', 'Hide thread') : (replies.length ? t('thread.showReply', 'Show thread & reply') : t('reply', 'Reply'))) + '">' + escHtml(t('reply', 'Reply')) +
                    (replies.length ? '<span class="dxf-reply-count">' + replies.length + '</span>' : '') +
                  '</button>') +
              (caps.canResolve
                ? '<button class="dxf-resolve-btn dxf-resolve-text' + (isResolved ? ' is-resolved' : '') + '" data-id="' + comment.id + '" data-status="' + comment.status + '" title="' + escAttr(isResolved ? t('resolve.reopen', 'Reopen this comment') : t('resolve.mark', 'Mark as resolved')) + '" aria-label="' + escAttr(isResolved ? t('resolve.reopen', 'Reopen this comment') : t('resolve.mark', 'Mark as resolved')) + '">' + ICONS.check + '</button>'
                : '') +
            '</div>' +
          '</div>' +
          // ── Inline thread panel ──
          (expanded ? renderThreadPanel(comment, replies) : '') +
        '</div>'
      );
    }

    function toggleThread(id, opts) {
      opts = opts || {};
      var idStr = String(id);
      if (state.expandedThreads[idStr] && !opts.forceOpen) {
        delete state.expandedThreads[idStr];
      } else {
        state.expandedThreads[idStr] = true;
      }
      renderCommentList();
      if (opts.focusReply) {
        var ta = document.querySelector('.dxf-comment[data-id="' + idStr + '"] .dxf-inline-reply-text');
        if (ta) ta.focus();
      }
    }

    // Inline-edit a comment's body text in place (own comments only — the
    // edit button is gated by canEdit in commentCardHtml). Replaces the body
    // <p> with a textarea + Save/Cancel; on save, POSTs then refreshes.
    function startCommentEdit(id) {
      var card = document.querySelector('.dxf-comment[data-id="' + id + '"]');
      if (!card || card.querySelector('.dxf-edit-form')) return;
      var comment = findCommentById(id);
      if (!comment) return;

      var bodyEl = card.querySelector('.dxf-comment-body');
      var form   = document.createElement('div');
      form.className = 'dxf-edit-form';
      form.innerHTML =
        '<textarea class="dxf-edit-text" rows="3"></textarea>' +
        '<div class="dxf-edit-actions">' +
          '<button type="button" class="dxf-btn dxf-btn-ghost dxf-edit-cancel">' + escHtml(t('action.cancel', 'Cancel')) + '</button>' +
          '<button type="button" class="dxf-btn dxf-btn-primary dxf-edit-save">' + escHtml(t('action.save', 'Save')) + '</button>' +
        '</div>';
      // Keep clicks inside the editor from bubbling to the card (locate/scroll).
      form.addEventListener('click', function (e) { e.stopPropagation(); });

      if (bodyEl) {
        bodyEl.style.display = 'none';
        bodyEl.parentNode.insertBefore(form, bodyEl.nextSibling);
      } else {
        var head = card.querySelector('.dxf-comment-head');
        if (head) head.parentNode.insertBefore(form, head.nextSibling);
        else card.appendChild(form);
      }

      var ta     = form.querySelector('.dxf-edit-text');
      var save   = form.querySelector('.dxf-edit-save');
      var cancel = form.querySelector('.dxf-edit-cancel');
      ta.value = comment.body || '';
      ta.focus();
      ta.setSelectionRange(ta.value.length, ta.value.length);

      var cleanup = function () { form.remove(); if (bodyEl) bodyEl.style.display = ''; };
      var doSave  = function () {
        var val = ta.value.trim();
        if (!val) { ta.focus(); return; }
        if (val === (comment.body || '')) { cleanup(); return; }
        save.disabled = true; save.textContent = t('state.saving', 'Saving…');
        host.api.editComment(id, val).then(function (r) {
          if (r && r.success) {
            comment.body = val;
            for (var i = 0; i < state.allComments.length; i++) {
              if (String(state.allComments[i].id) === String(id)) { state.allComments[i].body = val; break; }
            }
            renderCommentList();
          } else {
            save.disabled = false; save.textContent = t('action.save', 'Save');
          }
        }).catch(function () { save.disabled = false; save.textContent = t('action.save', 'Save'); });
      };
      save.addEventListener('click', doSave);
      cancel.addEventListener('click', cleanup);
      ta.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); doSave(); }
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cleanup(); }
      });
    }

    function bindCardHandlers(list) {
      // Whole-card click → scroll the canvas to the comment's pin (not the
      // element centre) and flash the element. Also resize the canvas to
      // the commenter's breakpoint when one was captured.
      list.querySelectorAll('.dxf-comment--clickable').forEach(function (card) {
        if (card._rvBound) return; card._rvBound = true;
        card.addEventListener('click', function (e) {
          if (e.target.closest('button, select, a, input, textarea, label, .dxf-comment-foot, .dxf-thread-panel, .dxf-shot, .dxf-shot-btn, .dxf-reply-peek, .dxf-comment-actions')) return;
          var commentId = card.getAttribute('data-id');
          var bp        = card.getAttribute('data-breakpoint');
          var samePage  = card.getAttribute('data-samepage') === '1';
          if (bp && (bp === 'desktop' || bp === 'tablet' || bp === 'mobile')) {
            setViewport(bp);
          }
          if (samePage) {
            // Let the viewport resize settle (240ms) before scrolling.
            setTimeout(function () { locateComment(commentId); }, bp ? 260 : 0);
          } else {
            var url = card.getAttribute('data-post-url');
            if (url) {
              var cmt  = findCommentById(card.getAttribute('data-id'));
              var a    = cmt ? parseAnchor(cmt.anchor_data) : null;
              var hash = a ? adapterFor(a).deepLinkHash(a) : '';
              window.location.href = url + (hash || '');
            }
          }
        });
        // Hover a card → outline the element it points at on the canvas, so
        // it's clear which part of the page each comment refers to.
        card.addEventListener('mouseenter', function () {
          highlightCommentTarget(findCommentById(card.getAttribute('data-id')));
        });
        card.addEventListener('mouseleave', function () { clearHoverHighlight(); });
      });

      // Edit own comment — swap the body for an inline editor.
      list.querySelectorAll('.dxf-edit-btn').forEach(function (btn) {
        if (btn._rvBound) return; btn._rvBound = true;
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          startCommentEdit(btn.dataset.id);
        });
      });

      list.querySelectorAll('.dxf-resolve-btn').forEach(function (btn) {
        if (btn._rvBound) return; btn._rvBound = true;
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          var id        = parseInt(btn.dataset.id, 10);
          var newStatus = btn.dataset.status === 'resolved' ? 'open' : 'resolved';
          updateLocalComment(id, { status: newStatus });
          renderCommentList(); renderPins(); updateOpenBadge();
          host.api.resolveComment(id, newStatus).then(function () { refreshComments(); });
        });
      });

      // Emoji reactions — optimistic toggle, then reconcile with the server's
      // aggregate (covers concurrent reactions from other reviewers).
      list.querySelectorAll('.dxf-react:not(.is-static)').forEach(function (btn) {
        if (btn._rvBound) return; btn._rvBound = true;
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          if (!host.api.toggleReaction) return;
          var id  = parseInt(btn.dataset.id, 10);
          var key = btn.dataset.reaction;
          var c   = findCommentById(id);
          if (c) {
            var rs  = c.reactions = (c.reactions || []).slice();
            var hit = null;
            for (var i = 0; i < rs.length; i++) { if (rs[i].reaction === key) { hit = rs[i]; break; } }
            if (hit && hit.mine) {
              hit.mine  = false;
              hit.count = Math.max(0, Number(hit.count) - 1);
              if (!hit.count) rs.splice(rs.indexOf(hit), 1);
            } else if (hit) {
              hit.mine = true; hit.count = Number(hit.count) + 1;
            } else {
              rs.push({ reaction: key, count: 1, mine: true });
            }
            renderCommentList();
          }
          host.api.toggleReaction(id, key).then(function (res) {
            var cc = findCommentById(id);
            if (cc && res && res.reactions) { cc.reactions = res.reactions; renderCommentList(); }
          }).catch(function () {});
        });
      });

      // Thread expand / collapse.
      list.querySelectorAll('.dxf-thread-toggle').forEach(function (btn) {
        if (btn._rvBound) return; btn._rvBound = true;
        btn.addEventListener('click', function (e) { e.stopPropagation(); toggleThread(btn.dataset.id); });
      });
      list.querySelectorAll('.dxf-reply-open').forEach(function (btn) {
        if (btn._rvBound) return; btn._rvBound = true;
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          // Reply doubles as the thread toggle now: open + focus when
          // collapsed, plain collapse when the thread is already showing.
          if (state.expandedThreads[String(btn.dataset.id)]) {
            toggleThread(btn.dataset.id);
          } else {
            toggleThread(btn.dataset.id, { forceOpen: true, focusReply: true });
          }
        });
      });

      // Inline reply (textarea + attach + submit inside expanded thread).
      list.querySelectorAll('.dxf-inline-reply').forEach(function (form) {
        if (form._rvBound) return; form._rvBound = true;
        var parentId  = parseInt(form.getAttribute('data-parent'), 10);
        var ta        = form.querySelector('.dxf-inline-reply-text');
        var cancel    = form.querySelector('.dxf-inline-reply-cancel');
        var submit    = form.querySelector('.dxf-inline-reply-submit');
        var attachBtn = form.querySelector('.dxf-inline-reply-attach');
        var fileInput = form.querySelector('.dxf-inline-reply-file');
        var pillsHost = form.querySelector('.dxf-inline-reply-files');
        form._pendingFiles = form._pendingFiles || [];

        function renderPills() {
          if (!pillsHost) return;
          if (!form._pendingFiles.length) { pillsHost.innerHTML = ''; return; }
          pillsHost.innerHTML = form._pendingFiles.map(function (f, i) {
            return '<div class="dxf-file-pill">' +
              '<span title="' + escAttr(f.name) + '">' + escHtml(formatFileName(f.name)) + '</span>' +
              '<button type="button" class="dxf-file-remove" data-idx="' + i + '" aria-label="' + escAttr(t('file.remove', 'Remove file')) + '">&times;</button>' +
            '</div>';
          }).join('');
          pillsHost.querySelectorAll('.dxf-file-remove').forEach(function (rb) {
            rb.addEventListener('click', function () {
              form._pendingFiles.splice(parseInt(rb.dataset.idx, 10), 1);
              renderPills();
            });
          });
        }
        if (attachBtn) attachBtn.addEventListener('click', function () { fileInput.click(); });
        if (fileInput) fileInput.addEventListener('change', function () {
          for (var i = 0; i < fileInput.files.length; i++) form._pendingFiles.push(fileInput.files[i]);
          fileInput.value = '';
          renderPills();
        });

        var doSubmit = function () {
          var body = ta.value.trim();
          if (!body && !form._pendingFiles.length) return;
          submit.disabled = true; submit.textContent = t('state.saving', 'Saving…');
          host.api.addComment({
            post_id: cfg.postId, element_id: '', parent_id: parentId,
            body: body, anchor_data: {},
            _files: form._pendingFiles.length ? form._pendingFiles : null,
          }).then(function (r) {
            if (r && r.success) {
              ta.value = '';
              form._pendingFiles = [];
              refreshComments();
            } else {
              submit.disabled = false; submit.textContent = t('reply', 'Reply');
            }
          }).catch(function () { submit.disabled = false; submit.textContent = t('reply', 'Reply'); });
        };
        submit.addEventListener('click', doSubmit);
        cancel.addEventListener('click', function () {
          ta.value = '';
          form._pendingFiles = [];
          toggleThread(parentId); // collapses if expanded
        });
        ta.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSubmit(); }
          if (e.key === 'Escape') { ta.value = ''; form._pendingFiles = []; toggleThread(parentId); }
        });
        ta.addEventListener('input', function () {
          submit.disabled = !(ta.value.trim() || form._pendingFiles.length);
        });
        submit.disabled = !(ta.value.trim() || form._pendingFiles.length);
      });

      list.querySelectorAll('.dxf-shot').forEach(function (a) {
        if (a._rvBound) return; a._rvBound = true;
        a.addEventListener('click', function (ev) { ev.preventDefault(); openLightbox(a.getAttribute('data-shot')); });
      });

      // "View screenshot" button — opens the lightbox directly. The inline
      // thumbnail was distracting in the card, so the screenshot now only
      // exists at full-size, on-demand.
      list.querySelectorAll('.dxf-shot-btn').forEach(function (btn) {
        if (btn._rvBound) return; btn._rvBound = true;
        btn.addEventListener('click', function (ev) {
          ev.stopPropagation();
          openLightbox(btn.getAttribute('data-shot'));
        });
      });

      // Reply peek — clicking the latest-reply summary expands the thread.
      list.querySelectorAll('.dxf-reply-peek').forEach(function (btn) {
        if (btn._rvBound) return; btn._rvBound = true;
        btn.addEventListener('click', function (ev) {
          ev.stopPropagation();
          toggleThread(btn.dataset.id, { forceOpen: true });
        });
      });

      if (caps.canImportMedia) {
        list.querySelectorAll('.dxf-file-media').forEach(function (btn) {
          if (btn._rvBound) return; btn._rvBound = true;
          btn.addEventListener('click', function () {
            btn.disabled = true; btn.textContent = t('state.adding', 'Adding…');
            host.api.importToMedia(btn.dataset.url).then(function (res) {
              btn.textContent = (res && res.success) ? t('media.added', '✓ Added') : t('state.failed', 'Failed');
              if (!res || !res.success) btn.disabled = false;
            }).catch(function () { btn.disabled = false; btn.textContent = t('state.failed', 'Failed'); });
          });
        });
      }

      // Status / assignee pills open custom popovers — handled by the
      // delegated [data-pill] listener attached to the sidebar root.
      // Delete is handled by the delegated sidebar handler.
    }

    // ── Popover openers (status / assignee / device / round) ──────────────
    function findCommentById(id) {
      var source = state.scope === 'all' ? state.allComments : state.comments;
      for (var i = 0; i < source.length; i++) if (String(source[i].id) === String(id)) return source[i];
      return null;
    }
    // Optimistic local mutation — apply to BOTH scope arrays so the change is
    // reflected immediately whichever scope is active. Without this, a resolve
    // in "Everything" scope wouldn't apply until the server round-trip,
    // causing the card to churn (exit → re-enter) when the data lands.
    function updateLocalComment(id, patch) {
      [state.comments, state.allComments].forEach(function (arr) {
        if (!arr) return;
        for (var i = 0; i < arr.length; i++) {
          if (String(arr[i].id) === String(id)) { for (var k in patch) arr[i][k] = patch[k]; break; }
        }
      });
    }
    function openStatusPopover(trigger) {
      var id = parseInt(trigger.dataset.id, 10);
      var comment = findCommentById(id);
      if (!comment) return;
      var items = Object.keys(STATUS_META).map(function (k) {
        var m = STATUS_META[k];
        return { id: k, label: m.label, dot: m.dot, selected: comment.status === k };
      });
      openPopover(trigger, items, function (item) {
        updateLocalComment(id, { status: item.id });
        renderCommentList(); renderPins(); updateOpenBadge();
        host.api.resolveComment(id, item.id).then(function () { refreshComments(); });
      });
    }
    function openAssignPopover(trigger) {
      var id = parseInt(trigger.dataset.id, 10);
      var comment = findCommentById(id);
      if (!comment) return;
      var members = cfg.assignees || [];
      var assignedId = comment.assignee_id || 0;
      var items = [{ id: 0, label: t('unassigned', 'Unassigned'), icon: ICONS.userIcon, selected: !assignedId }];
      members.forEach(function (u) {
        items.push({
          id: u.id,
          label: u.name,
          avatar: { bg: userColor({ author_name: u.name, author_email: u.email || '' }), text: toInitials(u.name) },
          selected: String(u.id) === String(assignedId),
        });
      });
      openPopover(trigger, items, function (item) {
        var aid = parseInt(item.id, 10) || 0;
        updateLocalComment(id, { assignee_id: aid || null });
        renderCommentList();
        host.api.assignComment(id, aid);
      });
    }
    function deviceCounts() {
      var source = state.scope === 'all' ? state.allComments : state.comments;
      var top = source.filter(function (c) { return !c.parent_id; });
      var counts = { all: top.length, desktop: 0, tablet: 0, mobile: 0 };
      top.forEach(function (c) {
        var a = parseAnchor(c.anchor_data);
        var bp = (a && a.context && a.context.breakpoint) ? String(a.context.breakpoint).toLowerCase() : '';
        if (counts[bp] != null) counts[bp]++;
      });
      return counts;
    }
    // FE reviewer review-switcher — lists the reviews this viewer can reach and
    // navigates to the chosen one's landing URL (re-bootstraps the session).
    function openReviewSwitchPopover(trigger) {
      var list = (cfg.review && cfg.review.switchable) || [];
      var items = list.map(function (s) {
        return { id: s.slug, label: s.name || t('review.untitled', '(untitled review)'), selected: !!s.current, _url: s.landingUrl };
      });
      openPopover(trigger, items, function (item) {
        if (item._url && !item.selected) { window.location.href = item._url; }
      });
    }
    function openDevicePopover(trigger) {
      var counts = deviceCounts();
      var defs = [
        { id: 'all',     label: t('device.all',     'All devices') },
        { id: 'desktop', label: t('device.desktop', 'Desktop')     },
        { id: 'tablet',  label: t('device.tablet',  'Tablet')      },
        { id: 'mobile',  label: t('device.mobile',  'Mobile')      },
      ];
      var items = defs.map(function (d) {
        return { id: d.id, label: d.label, count: counts[d.id] || 0, selected: state.deviceFilter === d.id };
      });
      openPopover(trigger, items, function (item) { setDeviceFilter(item.id); });
    }
    function reviewFilterLabel() {
      if (state.reviewFilter === 'all')  return t('review.all', 'All Reviews');
      if (state.reviewFilter === 'none') return t('review.outside', 'Outside any Review');
      var list = cfg.reviews || [];
      for (var i = 0; i < list.length; i++) {
        if (String(list[i].id) === String(state.reviewFilter)) return list[i].name || t('review.numbered', 'Review #%d').replace('%d', list[i].id);
      }
      return t('review.all', 'All Reviews');
    }
    function reviewCounts() {
      // Counts of top-level comments per Review id (string keys), plus
      // 'all' (total) and 'none' (unattached). Matches deviceCounts()
      // semantics so the popover renders consistent secondary counts.
      var source = state.scope === 'all' ? state.allComments : state.comments;
      var top    = source.filter(function (c) { return !c.parent_id; });
      var counts = { all: top.length, none: 0 };
      top.forEach(function (c) {
        var rid = +c.review_id || 0;
        if (!rid) { counts.none++; return; }
        var k = String(rid);
        counts[k] = (counts[k] || 0) + 1;
      });
      return counts;
    }
    function openReviewPopover(trigger) {
      var counts = reviewCounts();
      var items = [{ id: 'all', label: t('review.all', 'All Reviews'), count: counts.all || 0, selected: state.reviewFilter === 'all' }];
      var list  = cfg.reviews || [];
      for (var i = 0; i < list.length; i++) {
        var id = String(list[i].id);
        items.push({
          id: id,
          label: list[i].name || t('review.numbered', 'Review #%d').replace('%d', list[i].id),
          count: counts[id] || 0,
          selected: String(state.reviewFilter) === id,
        });
      }
      items.push({ id: 'none', label: t('review.outside', 'Outside any Review'), count: counts.none || 0, selected: state.reviewFilter === 'none' });
      // "New Review" lives at the bottom of this dropdown (it used to be a
      // separate "+" button, which crowded the row and read ambiguously).
      if (cfg.newReviewUrl) {
        items.push({ id: '__new__', label: t('review.new', '+ New Review'), action: true, selected: false });
      }
      openPopover(trigger, items, function (item) {
        if (item.id === '__new__') {
          window.open(cfg.newReviewUrl, '_blank', 'noopener');
          return;
        }
        state.reviewFilter = item.id;
        updateReviewLabel();
        renderCommentList(); renderPins();
      });
    }
    function updateReviewLabel() {
      var trig = document.querySelector('#dxf-sidebar .dxf-review-drop .dxf-dropdown-label');
      if (!trig) return;
      trig.textContent = reviewFilterLabel();
    }

    // Per-comment review picker — opened by the small "Assign" pill next to
    // the number badge on comments that aren't attached to any Review yet.
    // Surfaces every active/draft Review; the server rejects assignment to
    // a Review whose scope doesn't cover this comment's page, and we toast
    // that case rather than guess at scope client-side.
    function openCommentReviewPopover(trigger) {
      if (!host.api || !host.api.setCommentReview) return;
      var commentId = +trigger.dataset.id;
      if (!commentId) return;
      var list = cfg.reviews || [];
      var items = [];
      for (var i = 0; i < list.length; i++) {
        items.push({
          id: String(list[i].id),
          label: list[i].name || t('review.numbered', 'Review #%d').replace('%d', list[i].id),
          selected: false,
        });
      }
      if (!items.length) {
        items.push({ id: '__none__', label: t('review.noneActive', 'No active reviews'), selected: false });
      }
      openPopover(trigger, items, function (item) {
        if (item.id === '__none__') return;
        host.api.setCommentReview(commentId, item.id).then(function (res) {
          if (res && res.success) {
            refreshComments();
          } else {
            var msg = (res && res.data && res.data.message) || t('review.assignFailed', 'Could not assign comment.');
            try { window.alert(msg); } catch (e) {}
          }
        });
      });
    }

    // Newest-reply summary shown above the foot when the thread is collapsed.
    // Click expands the thread (handled in bindCardHandlers via .dxf-reply-peek).
    function renderReplyPeek(replies, parentId) {
      if (!replies || !replies.length) return '';
      var latest = replies.slice().sort(function (a, b) {
        return (a.created_at || '') < (b.created_at || '') ? 1 : -1;
      })[0];
      if (!latest) return '';
      var ruc  = userColor(latest);
      var name = escHtml(latest.author_name || (caps.canApprove ? t('role.reviewer', 'Reviewer') : t('role.teamMember', 'Team member')));
      var body = String(latest.body || '').replace(/\s+/g, ' ').trim();
      if (body.length > 110) body = body.slice(0, 107) + '…';
      var n    = replies.length;
      var more = n > 1 ? '<span class="dxf-reply-peek-more">' + escHtml(t('reply.peekMore', '+%d more').replace('%d', (n - 1))) + '</span>' : '';
      return '<button type="button" class="dxf-reply-peek" data-id="' + parentId + '" title="' + escAttr(t('thread.show', 'Show thread')) + '"' +
        ' style="--rv-reply-user:' + escAttr(ruc) + '">' +
        '<span class="dxf-avatar dxf-avatar--sm" style="background:' + escAttr(ruc) + '">' + toInitials(latest.author_name) + '</span>' +
        '<span class="dxf-reply-peek-text">' +
          '<strong>' + name + '</strong> ' +
          '<span class="dxf-reply-peek-body">' + escHtml(body) + '</span>' +
        '</span>' +
        '<span class="dxf-reply-peek-meta"><span class="dxf-time">' + timeAgo(latest.created_at) + '</span>' + more + '</span>' +
      '</button>';
    }

    function renderReplies(replies) {
      return '<div class="dxf-replies">' +
        replies.map(function (r) {
          var anchor = parseAnchor(r.anchor_data);
          var ruc    = userColor(r);
          return '<div class="dxf-reply">' +
            '<span class="dxf-avatar dxf-avatar--sm" style="background:' + escAttr(ruc) + '">' + toInitials(r.author_name) + '</span>' +
            '<div><strong style="color:' + escAttr(ruc) + '">' + escHtml(r.author_name || (caps.canApprove ? t('role.reviewer', 'Reviewer') : t('role.teamMember', 'Team member'))) + '</strong>' +
            '<span class="dxf-time">' + timeAgo(r.created_at) + '</span>' +
            '<p>' + linkify(r.body) + '</p>' +
            (anchor ? renderAttachments(anchor.attachments) : '') +
            '</div></div>';
        }).join('') + '</div>';
    }

    function renderContext(ctx) {
      if (!ctx) return '';
      var parts = [];
      if (ctx.breakpoint) parts.push(ctx.breakpoint);
      if (ctx.viewport)   parts.push(ctx.viewport);
      if (ctx.os)         parts.push(ctx.os);
      if (ctx.browser)    parts.push(ctx.browser);
      if (!ctx.os && ctx.platform) parts.push(ctx.platform); // back-compat
      var meta = parts.join(' · ');
      var errs = (ctx.errors && ctx.errors.length)
        ? '<span class="dxf-ctx-err" title="' + escAttr(ctx.errors.map(function (e) {
              return (e.msg || '') + (e.src ? ' (' + e.src + ':' + (e.line || 0) + ')' : '');
            }).join('\n')) + '">' + escHtml((ctx.errors.length === 1 ? t('error.jsOne', '⚠ %d JS error') : t('error.jsMany', '⚠ %d JS errors')).replace('%d', ctx.errors.length)) + '</span>'
        : '';
      if (!meta && !errs) return '';
      return '<div class="dxf-ctx" title="' + escAttr(ctx.ua || '') + '">' +
        (meta ? '<span>' + escHtml(meta) + '</span>' : '') + errs + '</div>';
    }

    function renderAttachments(list) {
      if (!list || !list.length) return '';
      return '<div class="dxf-attachments">' + list.map(function (a) {
        if (!a || !a.url) return '';
        if (/^image\//.test(a.mime || '')) {
          return '<a class="dxf-shot dxf-attach-img" href="' + escAttr(a.url) + '" data-shot="' + escAttr(a.url) + '" title="' + escAttr(a.name || t('attach.image', 'Image')) + '">' +
            '<img src="' + escAttr(a.url) + '" alt="' + escAttr(a.name || t('attach.attachment', 'Attachment')) + '" loading="lazy"></a>';
        }
        return '<div class="dxf-file">' +
          '<a class="dxf-file-link" href="' + escAttr(a.url) + '" target="_blank" rel="noopener noreferrer">' +
            ICONS.file + '<span>' + escHtml(a.name || t('attach.file', 'File')) + '</span>' +
          '</a>' +
          (caps.canImportMedia ? '<button type="button" class="dxf-file-media" data-url="' + escAttr(a.url) + '" title="' + escAttr(t('media.addToLibrary', 'Add to Media Library')) + '">' + escHtml(t('media.addShort', '+ Media')) + '</button>' : '') +
        '</div>';
      }).join('') + '</div>';
    }

    function renderTriage(tr) {
      if (!tr || !tr.type) return '';
      var p = tr.priority || 'medium';
      return '<span class="dxf-triage dxf-triage--' + escAttr(p) + '">' + escHtml(tr.type) + ' &middot; ' + escHtml(p) + '</span>';
    }

    function showInlineReplyForm(triggerBtn, parentId) {
      document.querySelectorAll('.dxf-inline-reply').forEach(function (el) { el.remove(); });
      var form = document.createElement('div');
      form._pendingFiles = [];
      form.className = 'dxf-inline-reply';
      form.innerHTML =
        '<textarea placeholder="' + escAttr(t('reply.placeholder', 'Write a reply… (Enter to send)')) + '" rows="2"></textarea>' +
        '<div class="dxf-inline-reply-actions">' +
          (uploadsOff() ? '' :
            '<input type="file" class="dxf-file-input" multiple hidden>' +
            '<button type="button" class="dxf-btn dxf-btn-ghost dxf-attach-btn">' + ICONS.attach + '</button>') +
          '<button class="dxf-btn dxf-btn-ghost dxf-cancel-reply">' + escHtml(t('action.cancel', 'Cancel')) + '</button>' +
          '<button class="dxf-btn dxf-btn-primary dxf-submit-reply">' + escHtml(t('reply', 'Reply')) + '</button>' +
        '</div>' +
        '<div class="dxf-file-list"></div>';
      triggerBtn.after(form);
      form.querySelector('textarea').focus();

      var fileInput = form.querySelector('.dxf-file-input');
      var attachBtn = form.querySelector('.dxf-attach-btn');
      if (attachBtn && fileInput) {
        attachBtn.addEventListener('click', function () { fileInput.click(); });
        fileInput.addEventListener('change', function () {
          for (var i = 0; i < fileInput.files.length; i++) form._pendingFiles.push(fileInput.files[i]);
          fileInput.value = '';
          renderPills(form);
        });
      }
      form.querySelector('.dxf-cancel-reply').addEventListener('click', function () { form.remove(); });

      var doSubmit = function () {
        var body = form.querySelector('textarea').value.trim();
        if (!body) return;
        var btn = form.querySelector('.dxf-submit-reply');
        btn.disabled = true; btn.textContent = t('state.saving', 'Saving…');
        host.api.addComment({
          post_id: cfg.postId, element_id: '', parent_id: parentId, body: body, anchor_data: {},
          _files: form._pendingFiles && form._pendingFiles.length ? form._pendingFiles : null,
        }).then(function (r) {
          if (r.success) { form.remove(); refreshComments(); }
          else { btn.disabled = false; btn.textContent = t('reply', 'Reply'); }
        });
      };
      form.querySelector('.dxf-submit-reply').addEventListener('click', doSubmit);
      form.querySelector('textarea').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSubmit(); }
        if (e.key === 'Escape') form.remove();
      });
    }

    // -------------------------------------------------------------------------
    // Scope counters
    // -------------------------------------------------------------------------
    function updateScopeCounts() {
      var sidebar = document.getElementById('dxf-sidebar');
      if (!sidebar) return;
      var openTop = function (c) { return !c.parent_id && c.status !== 'resolved'; };
      var counts = {
        page: state.comments.filter(openTop).length,
        all:  state.allComments.filter(openTop).length,
      };
      sidebar.querySelectorAll('.dxf-scope-count').forEach(function (el) {
        var n = counts[el.getAttribute('data-scope-count')] || 0;
        el.textContent = n ? String(n) : '';
      });
    }

    // Counters next to the Open/Resolved/Mine/All status pills (design row 1).
    // Counts ignore the device filter (pills always reflect the underlying scope).
    function updateFilterCounts() {
      var sidebar = document.getElementById('dxf-sidebar');
      if (!sidebar) return;
      var source = state.scope === 'all' ? state.allComments : state.comments;
      var top    = source.filter(function (c) { return !c.parent_id; });
      var counts = {
        open:     top.filter(function (c) { return c.status !== 'resolved'; }).length,
        resolved: top.filter(function (c) { return c.status === 'resolved'; }).length,
        mine:     top.filter(function (c) { return String(c.author_id) === String(cfg.currentUserId); }).length,
        all:      top.length,
      };
      sidebar.querySelectorAll('.dxf-filter-count').forEach(function (el) {
        var k = el.getAttribute('data-filter-count');
        var n = counts[k] || 0;
        el.textContent = n ? String(n) : '';
      });
    }

    // -------------------------------------------------------------------------
    // Pins
    // -------------------------------------------------------------------------
    // The pin number sits on the accent-coloured teardrop, so its colour must
    // contrast with the accent — white on a dark accent (Bricks blue), but
    // near-black on a light accent (e.g. Elementor's light-pink dark-mode
    // magenta). Reuse the same WCAG luminance test as ensureAccentContrast.
    var PIN_TEXT = (function (hex) {
      var m = /^#?([0-9a-f]{3})$/i.exec(hex);
      if (m) hex = m[1].replace(/(.)/g, '$1$1'); else { m = /^#?([0-9a-f]{6})$/i.exec(hex); hex = m ? m[1] : '2563eb'; }
      var r = parseInt(hex.slice(0, 2), 16), g = parseInt(hex.slice(2, 4), 16), b = parseInt(hex.slice(4, 6), 16);
      function ch(c) { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }
      var L = 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b);
      return (1.05 / (L + 0.05)) < 4.5 ? '#111111' : '#ffffff';
    })(ACCENT);
    var PIN_STYLE =
      '#dxf-pin-layer{position:absolute;left:0;top:0;width:0;height:0;overflow:visible;' +
        'z-index:2147483000;pointer-events:none}' +
      '.dxf-pin{position:absolute;width:26px;height:26px;padding:0;border:0;background:none;' +
        'cursor:grab;transform:translate(-50%,-100%);pointer-events:auto;}' +
      '.dxf-pin.is-dragging{cursor:grabbing;}' +
      '.dxf-pin i{display:flex;align-items:center;justify-content:center;width:26px;height:26px;' +
        'border-radius:50% 50% 50% 0;background:' + ACCENT + ';border:2px solid #fff;' +
        'box-shadow:0 2px 7px rgba(0,0,0,.45);transform:rotate(-45deg);transition:transform .1s;}' +
      '.dxf-pin:hover i{transform:rotate(-45deg) scale(1.12);}' +
      '.dxf-pin b{transform:rotate(45deg);color:' + PIN_TEXT + ';font:700 12px/1 -apple-system,system-ui,sans-serif;font-style:normal;}' +
      '.dxf-pin.is-resolved i{background:#3fbf6e;opacity:.55;}' +
      '.dxf-pin.is-progress i{background:#d97706;}';

    function injectPinStyles(doc) {
      if (doc.getElementById('dxf-pin-style')) return;
      var s = doc.createElement('style');
      s.id = 'dxf-pin-style';
      s.textContent = PIN_STYLE;
      (doc.head || doc.documentElement).appendChild(s);
    }

    function getPinLayer() {
      var doc = host.getCanvasDoc();
      if (!doc || !doc.body) return null;
      injectPinStyles(doc);
      var layer = doc.getElementById('dxf-pin-layer');
      if (!layer) {
        layer = doc.createElement('div');
        layer.id = 'dxf-pin-layer';
        doc.body.appendChild(layer);
      }
      return layer;
    }

    function computePinPosition(comment, doc, win) {
      var a = parseAnchor(comment.anchor_data);
      if (!a) return null;
      var sx = win ? (win.scrollX || 0) : 0;
      var sy = win ? (win.scrollY || 0) : 0;
      var el = adapterFor(a, doc).resolve(a, doc);
      if (el) {
        var r = el.getBoundingClientRect();
        return { x: r.left + sx + (a.offset_x || 0) * r.width,
                 y: r.top  + sy + (a.offset_y || 0) * r.height };
      }
      if (a.doc_x || a.doc_y) return { x: a.doc_x, y: a.doc_y };
      return null;
    }

    // Pins reflect THIS page's comments (filtered by active status), regardless
    // of the page/everything scope used for the list.
    function visiblePinComments() {
      return state.comments.filter(function (c) { return !c.parent_id; }).filter(function (c) {
        if (state.filter === 'open')     return c.status !== 'resolved';
        if (state.filter === 'resolved') return c.status === 'resolved';
        if (state.filter === 'mine')     return String(c.author_id) === String(cfg.currentUserId);
        return true;
      });
    }

    function renderPins() {
      var doc   = host.getCanvasDoc();
      var layer = getPinLayer();
      if (!doc || !layer) return;
      if (!state.sidebarOpen) { layer.innerHTML = ''; return; }
      var win = host.getCanvasWindow();
      layer.innerHTML = '';

      visiblePinComments().forEach(function (c) {
        var pos = computePinPosition(c, doc, win);
        if (!pos) return;

        var pin = doc.createElement('button');
        pin.type = 'button';
        pin.className = 'dxf-pin' + (c.status === 'resolved' ? ' is-resolved' : (c.status === 'in_progress' ? ' is-progress' : ''));
        pin.setAttribute('data-id', c.id);
        pin.style.left = pos.x + 'px';
        pin.style.top  = pos.y + 'px';
        pin.innerHTML  = '<i><b>' + getCommentNumber(c.id) + '</b></i>';

        if (caps.canDragPins) {
          (function (comment, pinEl) {
            var dragMoved = false, startX, startY, startLeft, startTop;
            pinEl.addEventListener('mousedown', function (e) {
              if (e.button !== 0) return;
              e.preventDefault(); e.stopPropagation();
              dragMoved = false;
              startX = e.clientX; startY = e.clientY;
              startLeft = parseFloat(pinEl.style.left); startTop = parseFloat(pinEl.style.top);

              function onMove(me) {
                var dx = me.clientX - startX, dy = me.clientY - startY;
                if (!dragMoved && (Math.abs(dx) > 5 || Math.abs(dy) > 5)) { dragMoved = true; pinEl.classList.add('is-dragging'); }
                if (dragMoved) { pinEl.style.left = (startLeft + dx) + 'px'; pinEl.style.top = (startTop + dy) + 'px'; }
              }
              function onUp(ue) {
                doc.removeEventListener('mousemove', onMove, true);
                doc.removeEventListener('mouseup',   onUp,   true);
                pinEl.classList.remove('is-dragging');
                if (!dragMoved) { focusComment(comment.id); return; }

                var newDocX = parseFloat(pinEl.style.left), newDocY = parseFloat(pinEl.style.top);

                pinEl.style.pointerEvents = 'none';
                var elUnder = doc.elementFromPoint(ue.clientX, ue.clientY);
                pinEl.style.pointerEvents = '';
                var rMatch   = elUnder ? matchEl(elUnder, doc) : { adapter: activeAdapter(doc), el: null };
                var rAdapter = rMatch.adapter;
                var target   = rMatch.el;
                var newAnchor = { builder: rAdapter.id, element_id: '', offset_x: 0, offset_y: 0, doc_x: newDocX, doc_y: newDocY, strategies: null };
                if (target) {
                  var rr = target.getBoundingClientRect();
                  newAnchor.element_id = rAdapter.elementId(target);
                  newAnchor.strategies = rAdapter.strategies(target, doc);
                  if (rr.width)  newAnchor.offset_x = (ue.clientX - rr.left) / rr.width;
                  if (rr.height) newAnchor.offset_y = (ue.clientY - rr.top)  / rr.height;
                }
                for (var j = 0; j < state.comments.length; j++) {
                  if (state.comments[j].id == comment.id) {
                    var existing = parseAnchor(state.comments[j].anchor_data) || {};
                    existing.builder    = newAnchor.builder;
                    existing.element_id = newAnchor.element_id;
                    existing.strategies = newAnchor.strategies;
                    existing.offset_x   = newAnchor.offset_x;
                    existing.offset_y   = newAnchor.offset_y;
                    existing.doc_x      = newAnchor.doc_x;
                    existing.doc_y      = newAnchor.doc_y;
                    state.comments[j].anchor_data = existing;
                    break;
                  }
                }
                renderPins();
                host.api.updateAnchor(comment.id, newAnchor).then(function (res) {
                  if (!res || !res.success) refreshComments();
                }).catch(function () { refreshComments(); });
              }
              doc.addEventListener('mousemove', onMove, true);
              doc.addEventListener('mouseup',   onUp,   true);
            });
          }(c, pin));
        } else {
          pin.addEventListener('click', function () { focusComment(c.id); });
        }

        layer.appendChild(pin);
      });
    }

    // Lightweight per-frame pin tracking. renderPins() does a full teardown
    // (used when the SET of pins changes); this only nudges the left/top of
    // pins already on screen so they stay glued to their anchored element when
    // the element MOVES for reasons that don't fire scroll/resize — GSAP/scroll
    // entrance animations, lazy-loaded images reflowing the page, smooth-scroll
    // (Lenis), webfont swaps, etc. This is the common cause of "the pin drifted
    // off where I placed it" on animated marketing pages (e.g. the demo).
    function repositionPins() {
      var doc = host.getCanvasDoc();
      if (!doc) return;
      var layer = doc.getElementById('dxf-pin-layer');
      if (!layer || !layer.children.length) return;
      var win = host.getCanvasWindow();
      for (var i = 0; i < layer.children.length; i++) {
        var pin = layer.children[i];
        if (pin.classList && pin.classList.contains('is-dragging')) continue;
        var c = null, id = pin.getAttribute('data-id');
        for (var j = 0; j < state.comments.length; j++) {
          if (String(state.comments[j].id) === String(id)) { c = state.comments[j]; break; }
        }
        if (!c) continue;
        var a = parseAnchor(c.anchor_data);
        if (!a || !a.element_id) continue; // doc-coordinate pins don't move
        var pos = computePinPosition(c, doc, win);
        if (!pos) continue;
        var nl = pos.x + 'px', nt = pos.y + 'px';
        if (pin.style.left !== nl) pin.style.left = nl;
        if (pin.style.top  !== nt) pin.style.top  = nt;
      }
    }

    // rAF loop that keeps pins tracking their elements while the panel is open.
    // Front-end reviewer surface only (host.scrollIsCanvas) — the builder canvas
    // is static and perf-sensitive, so it keeps the existing scroll/resize
    // re-render instead. Self-exits when the sidebar closes; guarded against
    // double-starting.
    var pinTracking = false;
    function startPinTracking() {
      if (pinTracking || !host.scrollIsCanvas) return;
      pinTracking = true;
      var win = host.getCanvasWindow() || window;
      var raf = (win.requestAnimationFrame || window.requestAnimationFrame).bind(win);
      function tick() {
        if (!pinTracking || !state.sidebarOpen) { pinTracking = false; return; }
        repositionPins();
        raf(tick);
      }
      raf(tick);
    }
    function stopPinTracking() { pinTracking = false; }

    // Inject the flash style + add the flash class to an element.
    function flashElement(el, doc) {
      if (!el || !doc) return;
      if (!doc.getElementById('dxf-locate-style')) {
        var s = doc.createElement('style');
        s.id = 'dxf-locate-style';
        s.textContent =
          '.dxf-locate-flash{outline:3px solid ' + ACCENT + '!important;outline-offset:2px!important;' +
          'animation:dxf-locate 1.6s ease-out!important;}' +
          '@keyframes dxf-locate{0%{box-shadow:0 0 0 0 ' + hexToRgba(ACCENT, 0.5) + '}' +
          '70%{box-shadow:0 0 0 16px ' + hexToRgba(ACCENT, 0) + '}100%{box-shadow:0 0 0 0 ' + hexToRgba(ACCENT, 0) + '}}';
        (doc.head || doc.documentElement).appendChild(s);
      }
      el.classList.add('dxf-locate-flash');
      setTimeout(function () { el.classList.remove('dxf-locate-flash'); }, 1600);
    }

    // -------------------------------------------------------------------------
    // Element hover highlight (BugSmash-style) — a persistent accent outline on
    // the element you're about to comment on (canvas hover while placing) or the
    // element a sidebar comment card points at (card hover). Distinct from the
    // one-shot locate flash above: no animation, toggled on/off by hover.
    // -------------------------------------------------------------------------
    var hoverEl = null; // currently outlined element (in the canvas doc)
    function ensureHoverStyle(doc) {
      if (!doc || doc.getElementById('dxf-hover-style')) return;
      var s = doc.createElement('style');
      s.id = 'dxf-hover-style';
      s.textContent =
        '.dxf-hover-outline{outline:2px solid ' + ACCENT + '!important;outline-offset:2px!important;' +
        'box-shadow:0 0 0 4px ' + hexToRgba(ACCENT, 0.18) + '!important;transition:outline-color .08s ease!important;}';
      (doc.head || doc.documentElement).appendChild(s);
    }
    function setHoverHighlight(el, doc) {
      doc = doc || host.getCanvasDoc();
      if (!doc) return;
      if (hoverEl === el) return;
      clearHoverHighlight(doc);
      if (!el) return;
      ensureHoverStyle(doc);
      el.classList.add('dxf-hover-outline');
      hoverEl = el;
    }
    function clearHoverHighlight(doc) {
      doc = doc || host.getCanvasDoc();
      if (hoverEl) {
        try { hoverEl.classList.remove('dxf-hover-outline'); } catch (e) {}
        hoverEl = null;
      }
    }
    function highlightCommentTarget(comment) {
      if (!comment) { clearHoverHighlight(); return; }
      if (String(comment.post_id) !== String(cfg.postId)) { clearHoverHighlight(); return; }
      var anchor = parseAnchor(comment.anchor_data);
      var doc    = host.getCanvasDoc();
      if (!anchor || (!anchor.element_id && !anchor.strategies) || !doc) { clearHoverHighlight(doc); return; }
      var el = adapterFor(anchor, doc).resolve(anchor, doc);
      setHoverHighlight(el, doc);
    }

    // While in click-to-pin mode, outline whatever element is under the cursor
    // so it's unmistakable what a click will attach the comment to. Resolve via
    // elementFromPoint at the exact cursor coords (the TOPMOST painted node)
    // rather than the mouseover target — the active builder adapter then maps
    // that node to the most specific anchorable element under the pointer, so
    // nested elements highlight correctly instead of a parent.
    function onCanvasHover(e) {
      var doc  = host.getCanvasDoc();
      var node = (doc && doc.elementFromPoint) ? doc.elementFromPoint(e.clientX, e.clientY) : e.target;
      // Don't frame/crosshair our own overlay UI or the WP admin bar — those
      // aren't commentable (matches onCanvasClick's early-return). Clearing the
      // highlight here also removes any stale outline as the cursor leaves
      // content and enters chrome.
      if (node && node.closest && node.closest('#wpadminbar, [id^="dxf-"]')) {
        clearHoverHighlight(doc);
        return;
      }
      var el = node ? matchEl(node, doc).el : null;
      setHoverHighlight(el, doc);
    }
    function bindCanvasHoverMove() {
      var doc = host.getCanvasDoc();
      if (!doc || doc.__dxfHoverMove) return;
      doc.__dxfHoverMove = true;
      // mousemove (rAF-throttled) keeps the highlight tracking the deepest
      // element even when moving within one element's bounds.
      var ticking = false, lastEvt = null;
      doc.__dxfHoverMoveFn = function (e) {
        lastEvt = e;
        if (ticking) return;
        ticking = true;
        (doc.defaultView || window).requestAnimationFrame(function () {
          ticking = false;
          if (lastEvt) onCanvasHover(lastEvt);
        });
      };
      doc.addEventListener('mousemove', doc.__dxfHoverMoveFn, true);
    }
    function bindCanvasHover() {
      var doc = host.getCanvasDoc();
      if (!doc || doc.__dxfHoverBound) return;
      doc.__dxfHoverBound = true;
      doc.addEventListener('mouseover', onCanvasHover, true);
      bindCanvasHoverMove();
    }
    function unbindCanvasHover() {
      var doc = host.getCanvasDoc();
      if (doc && doc.__dxfHoverBound) {
        doc.removeEventListener('mouseover', onCanvasHover, true);
        if (doc.__dxfHoverMoveFn) doc.removeEventListener('mousemove', doc.__dxfHoverMoveFn, true);
        doc.__dxfHoverBound = false;
        doc.__dxfHoverMove = false;
      }
      clearHoverHighlight(doc);
    }

    // Scroll the canvas so the comment's PIN (not the element's centre) is
    // visible, then flash the underlying element. Centering on the element
    // misses the pin badly when the element is a full-width section.
    function locateComment(commentId) {
      var comment = findCommentById(commentId);
      if (!comment) return;
      var anchor = parseAnchor(comment.anchor_data);
      if (!anchor) return;
      var doc = host.getCanvasDoc();
      var win = host.getCanvasWindow();
      if (!doc || !win) return;

      var pos = computePinPosition(comment, doc, win);
      var el  = adapterFor(anchor, doc).resolve(anchor, doc);

      if (pos) {
        var targetTop = Math.max(0, pos.y - win.innerHeight * 0.33);
        var targetLeft = Math.max(0, pos.x - win.innerWidth * 0.5);
        try { win.scrollTo({ top: targetTop, left: targetLeft, behavior: 'smooth' }); }
        catch (e) { win.scrollTo(targetLeft, targetTop); }
      } else if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      if (el) flashElement(el, doc);
    }

    // Back-compat for any caller that still references locateElement(elementId).
    // Internally we find the matching comment and route through locateComment().
    function locateElement(elementId) {
      if (!elementId) return;
      var source = state.scope === 'all' ? state.allComments : state.comments;
      for (var i = 0; i < source.length; i++) {
        var a = parseAnchor(source[i].anchor_data);
        if (a && a.element_id === elementId) { locateComment(source[i].id); return; }
      }
    }

    function focusComment(id) {
      if (!state.sidebarOpen) openSidebar();
      // No-op: thread panels expand inline now (no detail-view swap to undo).
      var card = document.querySelector('.dxf-comment[data-id="' + id + '"]');
      if (!card) { setFilter('all'); card = document.querySelector('.dxf-comment[data-id="' + id + '"]'); }
      if (card) {
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        card.classList.add('dxf-flash');
        setTimeout(function () { card.classList.remove('dxf-flash'); }, 1400);
      }
    }

    // -------------------------------------------------------------------------
    // Filters / scope / viewport / rounds
    // -------------------------------------------------------------------------
    function setFilter(filter) {
      state.filter = filter;
      var sidebar = document.getElementById('dxf-sidebar');
      if (sidebar) sidebar.querySelectorAll('.dxf-filter-btn').forEach(function (b) {
        b.classList.toggle('active', b.dataset.filter === filter);
      });
      renderCommentList();
      renderPins();
    }

    function setScope(scope) {
      state.scope = scope;
      var sidebar = document.getElementById('dxf-sidebar');
      if (sidebar) sidebar.querySelectorAll('.dxf-scope-btn').forEach(function (b) {
        b.classList.toggle('active', b.dataset.scope === scope);
      });
      if (scope === 'all' && !state.allComments.length && host.api.getAllComments) {
        host.api.getAllComments().then(function (all) {
          state.allComments = all;
          updateOpenBadge();
          renderCommentList();
          updateReviewBar();
        }).catch(function () { renderCommentList(); });
      } else {
        renderCommentList();
        updateReviewBar();
      }
    }

    function setViewport(mode) {
      var WIDTHS = { desktop: '', tablet: '768px', mobile: '390px' };
      if (!(mode in WIDTHS)) return;
      state.viewport = mode;
      if (host.applyViewport) host.applyViewport(mode, WIDTHS[mode]);

      var sidebar = document.getElementById('dxf-sidebar');
      if (sidebar) sidebar.querySelectorAll('.dxf-vp-btn').forEach(function (b) {
        b.classList.toggle('active', b.dataset.vp === mode);
      });
      setTimeout(function () { renderPins(); }, 240);
    }

    function setDeviceFilter(value) {
      state.deviceFilter = value;
      var trig = document.querySelector('#dxf-sidebar .dxf-device-drop .dxf-dropdown-label');
      if (trig) {
        var labelMap = { all: t('device.all', 'All devices'), desktop: t('device.desktop', 'Desktop'), tablet: t('device.tablet', 'Tablet'), mobile: t('device.mobile', 'Mobile') };
        trig.textContent = labelMap[value] || t('device.all', 'All devices');
      }
      // Picking a specific device also resizes the canvas to match (so the
      // layout matches what the commenter saw). 'all' restores desktop width.
      if (value === 'desktop' || value === 'tablet' || value === 'mobile') {
        setViewport(value);
      } else if (value === 'all' && state.viewport !== 'desktop') {
        setViewport('desktop');
      }
      renderCommentList();
      renderPins();
    }

    function updateReviewBar() {
      if (!caps.canReviewsPicker) return;
      var bar = document.getElementById('dxf-review-bar');
      if (!bar) return;

      // Reviewers (FE) see the Review's name as a static label — they're
      // inside one Review and don't pick across others. The dropdown is
      // builder-only. When there's no Review context at all the bar
      // collapses (handled by :empty in builder.css).
      if (caps.canPickReview === false) {
        var rv = cfg.review || {};
        var reviewName = rv.name ? String(rv.name) : '';
        // When the viewer can reach more than one review (a logged-in admin, or
        // an email reviewer on several reviews), offer a switcher instead of a
        // static label. Switching navigates to the chosen review's landing URL,
        // which re-bootstraps the session — no extra endpoint needed.
        var switchable = (rv.switchable && rv.switchable.length > 1) ? rv.switchable : null;
        if (switchable) {
          // Same custom dropdown component as the "All devices" filter beside it
          // (data-pill → openPopover), so the two pills are visually identical.
          // The popover lists the viewer's reviews; selecting one navigates.
          bar.innerHTML =
            '<button type="button" class="dxf-dropdown dxf-review-drop" data-pill="review-switch">' +
              '<span class="dxf-dropdown-label">' + escHtml(reviewName || t('review.untitled', '(untitled review)')) + '</span>' +
              '<span class="dxf-dropdown-chev">' + ICONS.chev + '</span>' +
            '</button>';
        } else if (reviewName) {
          bar.innerHTML = '<span class="dxf-review-label" title="' + escAttr(reviewName) + '">' +
                            escHtml(reviewName) +
                          '</span>';
        } else {
          bar.innerHTML = '';
        }
        return;
      }

      var label = reviewFilterLabel();
      // "New Review" is the last item inside the dropdown — no standalone button.
      bar.innerHTML =
        '<button type="button" class="dxf-dropdown dxf-review-drop" data-pill="review">' +
          '<span class="dxf-dropdown-label">' + escHtml(label) + '</span>' +
          '<span class="dxf-dropdown-chev">' + ICONS.chev + '</span>' +
        '</button>';
    }

    // -------------------------------------------------------------------------
    // AI summary
    // -------------------------------------------------------------------------
    // The summary is cached against a fingerprint of the page's comments —
    // reopening it with nothing changed costs zero API calls (BYO-key users
    // pay per token; don't burn their money re-summarizing identical input).
    var summaryCacheSig = null, summaryCacheData = null;
    function summarySig() {
      return state.comments.map(function (c) {
        return c.id + ':' + (c.updated_at || '') + ':' + (c.status || '');
      }).join('|');
    }
    function openSummary() {
      if (!caps.canSummarize) return;
      var sig = summarySig();
      if (summaryCacheData && summaryCacheSig === sig) {
        renderSummaryModal(summaryCacheData);
        return;
      }
      var btn = document.querySelector('.dxf-ai-summarize');
      if (btn) { btn.disabled = true; btn.innerHTML = '<span class="dxf-spinner"></span>'; }
      var restore = function () {
        if (btn) { btn.disabled = false; btn.innerHTML = '&#10024;'; }
      };
      host.api.summarize(cfg.postId).then(function (res) {
        restore();
        if (res && res.success) {
          summaryCacheSig = sig;
          summaryCacheData = res.data;
          renderSummaryModal(res.data);
        } else {
          renderSummaryModal({ error: (res && res.data && res.data.message) || t('ai.summarizeFailed', 'Could not summarize.') });
        }
      }).catch(function () {
        restore();
        renderSummaryModal({ error: t('error.network', 'Network error. Please try again.') });
      });
    }

    function renderSummaryModal(data) {
      var existing = document.getElementById('dxf-summary');
      if (existing) existing.remove();
      var inner;
      if (data.error) {
        inner =
          '<p class="dxf-summary-overview">' +
            '<strong>' + escHtml(t('ai.failedTitle', 'Summarize failed.')) + '</strong> ' + escHtml(data.error) +
          '</p>' +
          '<p class="dxf-summary-overview" style="opacity:.65;font-size:12px;">' +
            escHtml(t('ai.modelHint', 'If the message names a model id, the AI provider rejected it — update the model under Dox Feedback → AI.')) +
          '</p>';
      } else {
        var themes = (data.themes || []).map(function (t) {
          return '<div class="dxf-theme dxf-theme--' + escAttr(t.priority || 'medium') + '">' +
            '<div class="dxf-theme-head"><strong>' + escHtml(t.title) + '</strong>' +
            '<span class="dxf-theme-meta">' + escHtml(t.priority || '') + (t.count ? ' &middot; ' + (+t.count) : '') + '</span></div>' +
            (t.summary ? '<p>' + escHtml(t.summary) + '</p>' : '') +
          '</div>';
        }).join('');
        inner = (data.overview ? '<p class="dxf-summary-overview">' + escHtml(data.overview) + '</p>' : '') +
          '<div class="dxf-summary-themes">' + themes + '</div>';
      }
      var modal = document.createElement('div');
      modal.id = 'dxf-summary';
      modal.innerHTML =
        '<div class="dxf-summary-inner">' +
          '<div class="dxf-summary-head"><span>' + escHtml(t('ai.summaryTitle', 'Feedback summary')) + '</span>' +
            '<button class="dxf-summary-close" aria-label="' + escAttr(t('action.close', 'Close')) + '">' + ICONS.close + '</button></div>' +
          inner +
        '</div>';
      modal.addEventListener('click', function (e) { if (e.target === modal) modal.remove(); });
      modal.querySelector('.dxf-summary-close').addEventListener('click', function () { modal.remove(); });
      document.body.appendChild(modal);
    }

    // -------------------------------------------------------------------------
    // Lightbox
    // -------------------------------------------------------------------------
    function openLightbox(url) {
      if (!url) return;
      var lb = document.getElementById('dxf-lightbox');
      if (!lb) {
        lb = document.createElement('div');
        lb.id = 'dxf-lightbox';
        lb.setAttribute('data-lenis-prevent', '');
        var inner = '<div class="dxf-lightbox-inner"><img alt="' + escAttr(t('shot.label', 'Screenshot')) + '">';
        if (caps.canImportMedia) {
          inner += '<div class="dxf-lightbox-bar"><button type="button" class="dxf-btn dxf-btn-primary dxf-lb-media">' + escHtml(t('media.addToLibrary', 'Add to Media Library')) + '</button></div>';
        }
        inner += '</div>';
        lb.innerHTML = inner;
        lb.addEventListener('click', function (e) { if (e.target === lb) lb.classList.add('hidden'); });
        var mediaBtn = lb.querySelector('.dxf-lb-media');
        if (mediaBtn) {
          mediaBtn.addEventListener('click', function () {
            var current = lb.querySelector('img').src;
            mediaBtn.disabled = true; mediaBtn.textContent = t('state.adding', 'Adding…');
            host.api.importToMedia(current).then(function (res) {
              if (res && res.success) { mediaBtn.textContent = t('media.addedToLibrary', '✓ Added to Media Library'); }
              else { mediaBtn.disabled = false; mediaBtn.textContent = (res && res.data && res.data.message) || t('media.failedRetry', 'Failed — try again'); }
            }).catch(function () { mediaBtn.disabled = false; mediaBtn.textContent = t('media.failedRetry', 'Failed — try again'); });
          });
        }
        document.body.appendChild(lb);
      }
      lb.querySelector('img').src = url;
      var btn = lb.querySelector('.dxf-lb-media');
      if (btn) { btn.disabled = false; btn.textContent = t('media.addToLibrary', 'Add to Media Library'); }
      lb.classList.remove('hidden');
    }

    // -------------------------------------------------------------------------
    // Toggle state (button active class etc.)
    // -------------------------------------------------------------------------
    function updateToggleActive() {
      if (host.updateToggleActive) host.updateToggleActive(state.sidebarOpen);
    }

    // -------------------------------------------------------------------------
    // Data refresh
    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // Live updates — poll the same full-list endpoint on an interval and
    // re-render only when something actually changed (add / edit / status /
    // delete / pin move all bump the signature). Re-rendering is skipped while
    // the user is composing so we never wipe an in-progress comment or reply.
    // -------------------------------------------------------------------------
    var POLL_LIVE_MS = 10000;
    var lastSig = '';
    function commentsSignature(list) {
      return (list || []).map(function (c) {
        var a = c.anchor_data;
        var aLen = typeof a === 'string' ? a.length : (a ? JSON.stringify(a).length : 0);
        return c.id + ':' + c.status + ':' + (c.updated_at || '') + ':' + (c.body ? c.body.length : 0) + ':' + (c.parent_id || 0) + ':' + aLen;
      }).join('|');
    }
    function isComposing() {
      var f = document.getElementById('dxf-comment-form');
      if (f && !f.classList.contains('hidden')) return true;
      if (document.querySelector('.dxf-inline-reply, .dxf-edit-form')) return true;
      var ae = document.activeElement;
      if (ae && ae.closest && ae.closest('#dxf-sidebar') &&
          (ae.tagName === 'TEXTAREA' || ae.tagName === 'INPUT')) return true;
      return false;
    }
    function pollComments() {
      if (typeof document !== 'undefined' && document.hidden) return;
      if (isComposing()) return;
      host.api.getComments().then(function (comments) {
        comments = comments || [];
        var sig = commentsSignature(comments);
        if (sig === lastSig) return;
        lastSig = sig;
        state.comments = comments;
        renderCommentList();
        renderPins();
        updateOpenBadge();
        updateReviewBar();
        updateScopeCounts();
        renderFooter();
      }).catch(function () {});
      // Builder cross-page badge — keep the all-scope set fresh too.
      if (caps.canScope && host.api.getAllComments) {
        host.api.getAllComments().then(function (all) {
          state.allComments = all || [];
          updateOpenBadge();
          updateScopeCounts();
          if (state.scope === 'all' && !isComposing()) { renderCommentList(); updateReviewBar(); }
        }).catch(function () {});
      }
    }

    function refreshComments() {
      return host.api.getComments().then(function (comments) {
        state.comments = comments || [];
        lastSig = commentsSignature(state.comments);
        renderCommentList();
        renderPins();
        updateOpenBadge();
        updateReviewBar();
        updateScopeCounts();
        // Re-render the footer so the "Mark page as approved" button
        // enables / disables in step with the current open-comment count.
        // Without this, resolving the last open comment doesn't unlock the
        // approve button until the page is reloaded.
        renderFooter();
        // Cross-page background fetch (builder only, gated by canScope).
        if (caps.canScope && host.api.getAllComments) {
          host.api.getAllComments().then(function (all) {
            state.allComments = all || [];
            updateOpenBadge();
            updateScopeCounts();
            if (state.scope === 'all') { renderCommentList(); updateReviewBar(); }
          }).catch(function () {});
        }
      });
    }

    // -------------------------------------------------------------------------
    // Keyboard shortcuts
    // -------------------------------------------------------------------------
    function bindKeys() {
      document.addEventListener('keydown', function (e) {
        if (e.target && typeof e.target.matches === 'function' &&
            e.target.matches('input,textarea,select,[contenteditable]')) return;
        // Plain "c" toggles the overlay, but never when a modifier is held —
        // Cmd/Ctrl+C is copy (Bricks copies the selected element with it), and
        // Alt+C / Win+C belong to the OS/browser. Let those pass through.
        // Plain "c": when the panel is open, flip the cursor mode
        // (browse ⇄ comment); when closed, open the panel.
        if (caps.useCKey && (e.key === 'c' || e.key === 'C') &&
            !e.metaKey && !e.ctrlKey && !e.altKey) {
          if (state.sidebarOpen) { state.commentMode ? disableCommentMode() : enableCommentMode(); }
          else toggleComments();
        }
        if (e.key === 'Escape') {
          var f = document.getElementById('dxf-comment-form');
          if (f && !f.classList.contains('hidden')) { closeCommentForm(); return; }
          if (state.commentMode || state.sidebarOpen) {
            closeSidebar();
            disableCommentMode();
          }
        }
      });
    }

    // -------------------------------------------------------------------------
    // Window listeners (resize + scroll for pins, keep sidebar in viewport)
    // -------------------------------------------------------------------------
    window.addEventListener('resize', debounce(function () {
      renderPins();
      var sidebar = document.getElementById('dxf-sidebar');
      if (!sidebar) return;
      var left = parseFloat(sidebar.style.left) || 0, top = parseFloat(sidebar.style.top) || 0;
      sidebar.style.left = Math.max(0, Math.min(window.innerWidth  - sidebar.offsetWidth, left)) + 'px';
      sidebar.style.top  = Math.max(0, Math.min(window.innerHeight - 100,                 top))  + 'px';
    }, 120), { passive: true });

    // Front-end only: re-position pins when the page scrolls, and once more
    // after late-loading assets (images/fonts) settle the layout — a common
    // source of pins drifting below where they were placed.
    if (host.scrollIsCanvas) {
      window.addEventListener('scroll', debounce(renderPins, 60), { passive: true });
      window.addEventListener('load', function () { renderPins(); }, { once: true });
    }

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------
    renderSidebar();

    if (host.mountToggle) {
      host.mountToggle({
        toggleComments: toggleComments,
        toggleSidebar:  toggleSidebar,
        ICONS:          ICONS,
        getUnreadCount: function () { return state.unreadCount; },
      });
    }

    bindKeys();

    refreshComments().then(function () { if (caps.unreadAutoOpen) checkForUnread(); });

    // Live updates: poll for changes so new/edited/resolved comments and moved
    // pins appear without a manual refresh (skipped while composing / tab hidden).
    setInterval(pollComments, POLL_LIVE_MS);

    // Canvas hookup — wait for iframe (builder) or just render now (FE).
    if (host.onCanvasReady) {
      host.onCanvasReady(function () { renderPins(); if (state.sidebarOpen) renderCommentList(); });
    }
    if (host.onCanvasResize) {
      host.onCanvasResize(debounce(renderPins, 200));
    }

    // Bricks preview observer — keeps comment-placement in sync with Preview
    // state. The sidebar is independent and stays where the user put it:
    // docked in editor, floating in preview. Only pin placement is gated
    // by Preview (canvas clicks in editor mode hit Bricks UI, not pins).
    if (host.bricksPreview && host.bricksPreview.observe) {
      host.bricksPreview.observe({
        onEnter: function () {
          if (state.sidebarOpen && !state.commentMode) enableCommentMode();
        },
        onExit: function () {
          if (state.commentMode) exitCommentPlacement();
        },
      });
    }

    // Expose a small API for the adapter (rarely needed).
    return {
      state:               state,
      refreshComments:     refreshComments,
      renderPins:          renderPins,
      renderCommentList:   renderCommentList,
      openSidebar:         openSidebar,
      closeSidebar:        closeSidebar,
      enableCommentMode:   enableCommentMode,
      disableCommentMode:  disableCommentMode,
      exitCommentPlacement: exitCommentPlacement,
    };
  }
}());
