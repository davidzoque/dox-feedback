/**
 * Dox Feedback — Front-end (client review) host adapter
 *
 * Thin shim over the shared comment engine. Provides:
 *  - canvas = the live page itself (no iframe)
 *  - token + author API auth
 *  - FAB mount
 *  - guest identity gate (logged-in users skip it)
 *  - client capability set (resolve, delete-own, approve, viewport)
 *  - approval flow (cfg.completed + api.markComplete)
 *  - data-lenis-prevent applied to engine UI via global stylesheet load order
 *
 * All rendering, state, and behaviour lives in assets/comment-engine/engine.js.
 */
(function () {
  'use strict';

  var cfg = window.dxfReview;
  if (!cfg) return;

  var ACCENT = (cfg.accent && /^#[0-9a-fA-F]{3,6}$/.test(cfg.accent)) ? cfg.accent : '#ff8d27';

  // Two-mode boot:
  //   - Full review-mode: token + postId + engine available → render the
  //     overlay (FAB, comment-mode, identity gate, pins, sidebar) AND the
  //     chrome (nav panel).
  //   - Chrome-only: cfg.review present but no token → review session is
  //     active but the current URL isn't in scope (or the engine is
  //     intentionally not loaded). Render just the nav panel + off-scope
  //     banner so the reviewer can navigate back to a real review page.
  var hasEngine = !!window.DxfCommentEngine;
  var fullMode  = !!(cfg.token && cfg.postId && hasEngine);
  if (!fullMode && !(cfg.review && cfg.review.slug)) return;
  if (cfg.token && cfg.postId && !hasEngine) {
    console.warn('Dox Feedback: comment engine missing');
    // Fall through — chrome may still mount if cfg.review present.
  }

  // ---------------------------------------------------------------------------
  // Guest identity (seeded from WP user when logged in)
  //
  // Seed order, highest priority first:
  //   1. sessionStorage (something the reviewer already typed this session)
  //   2. logged-in WP user (current_user_can('edit_posts'), e.g. agency)
  //   3. email-restricted member identity (cfg.memberEmail/Name set when
  //      review.mode = 'email' and the DXF_Review_Auth cookie validates
  //      server-side) — pre-fills the gate so the reviewer doesn't have to
  //      type the email they were invited with, and seeds the name with the
  //      email's local-part as a sane default they can edit.
  // ---------------------------------------------------------------------------
  function emailLocalPart(addr) {
    var s = String(addr || '').trim();
    var at = s.indexOf('@');
    return at > 0 ? s.slice(0, at) : '';
  }
  function titlecase(s) {
    return String(s || '')
      .split(/[._-]+/).filter(Boolean)
      .map(function (w) { return w.charAt(0).toUpperCase() + w.slice(1); })
      .join(' ');
  }
  var seedName  = cfg.memberName || titlecase(emailLocalPart(cfg.memberEmail || ''));
  var seedEmail = cfg.memberEmail || '';

  // Per-browser claimed name — set by the builder's one-time prompt for
  // shared/agency logins (same cookie the builder host reads). Honouring it
  // here keeps front-end comments attributed to the same person instead of
  // falling back to the WP account's display name. Logged-in users only:
  // guests go through the identity gate and must never inherit a teammate's
  // name from a shared machine.
  function getCookie(name) {
    var m = document.cookie.match('(?:^|; )' + name.replace(/([.$?*|{}()\[\]\\\/\+^])/g, '\\$1') + '=([^;]*)');
    return m ? decodeURIComponent(m[1]) : '';
  }
  function setCookie(name, value, days) {
    var exp = new Date(Date.now() + days * 864e5).toUTCString();
    document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + exp + '; path=/; SameSite=Lax';
  }
  // Per-browser claimed identity — only honoured for logged-in users (guests on
  // a shared machine must never inherit a teammate's name from a cookie).
  var isLoggedIn   = !!(cfg.currentUserId && +cfg.currentUserId > 0);
  var claimedName  = isLoggedIn ? getCookie('dxf_identity_name')  : '';
  var claimedEmail = isLoggedIn ? getCookie('dxf_identity_email') : '';

  var guest = {
    // Seed (and so pre-fill the gate) from: this session → this device's claimed
    // identity → the WP account → an email-invite seed. The seed is only a
    // PRE-FILL; it doesn't count as confirmed (see identified()).
    name:  sessionStorage.getItem('dxf_name')  || claimedName  || (cfg.currentUser || '') || seedName,
    email: sessionStorage.getItem('dxf_email') || claimedEmail || (cfg.currentUserEmail || '') || seedEmail,
    isLoggedIn: isLoggedIn,
    // "Identified" requires an explicit per-device confirmation — even logged-in
    // users must confirm their name/email once on a new computer rather than
    // silently commenting under their WP account's display name. A device counts
    // as confirmed when they've passed the gate this session (sessionStorage),
    // carry the claimed-identity cookie from a prior confirm, or are a
    // pre-authenticated email-restricted member (magic link already proved who
    // they are). The name check guards the empty-submission re-open loop.
    identified: function () {
      var confirmed = !!sessionStorage.getItem('dxf_name') || !!claimedName || !!cfg.memberEmail;
      return confirmed && !!this.name;
    },
    save:  function (n, e) {
      this.name = n; this.email = e;
      sessionStorage.setItem('dxf_name', n); sessionStorage.setItem('dxf_email', e);
      // Logged-in users: remember on this device so they don't re-confirm every
      // session. Guests stay session-only — never persist identity for someone
      // on a potentially shared public machine.
      if (this.isLoggedIn) {
        claimedName = n; claimedEmail = e;
        setCookie('dxf_identity_name', n, 365);
        setCookie('dxf_identity_email', e, 365);
      }
      // Let the agency's "client opened" receipt name this reviewer, even if
      // their first open was logged anonymously (before the gate).
      fireReviewBeacon('dxf_review_identified', true);
    },
    clear: function () {
      this.name = ''; this.email = '';
      sessionStorage.removeItem('dxf_name'); sessionStorage.removeItem('dxf_email');
    },
  };

  // ---------------------------------------------------------------------------
  // API — token + author on every call
  // ---------------------------------------------------------------------------
  var api = {
    getComments: function () {
      // Identity hints personalise the reactions "mine" flags server-side;
      // they are never used for authorisation (the token is).
      return fetch(cfg.ajaxUrl + '?action=dxf_get_public_comments&post_id=' + cfg.postId +
        '&token=' + encodeURIComponent(cfg.token) +
        '&author_name=' + encodeURIComponent(guest.name || '') +
        '&author_email=' + encodeURIComponent(guest.email || ''), { cache: 'no-store' })
        .then(function (r) { return r.json(); }).then(function (d) { return d.success ? d.data : []; });
    },
    getAllComments: function () {
      return fetch(cfg.ajaxUrl + '?action=dxf_get_public_all_comments&token=' + encodeURIComponent(cfg.token) +
        '&author_name=' + encodeURIComponent(guest.name || '') +
        '&author_email=' + encodeURIComponent(guest.email || ''),
        { cache: 'no-store' }).then(function (r) { return r.json(); })
        .then(function (d) { return d.success ? d.data : []; }).catch(function () { return []; });
    },
    addComment: function (payload) {
      var fd = new FormData();
      fd.append('action', 'dxf_add_comment');
      fd.append('_wpnonce', cfg.nonce);
      fd.append('review_token', cfg.token);
      fd.append('author_name', guest.name);
      fd.append('author_email', guest.email);
      Object.keys(payload).forEach(function (k) {
        if (k === '_files') return;
        var v = payload[k];
        fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
      });
      if (payload._files) for (var i = 0; i < payload._files.length; i++) fd.append('attachments[]', payload._files[i]);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    resolveComment: function (id, status) {
      var fd = new FormData();
      fd.append('action', 'dxf_resolve_comment'); fd.append('_wpnonce', cfg.nonce);
      fd.append('id', id); fd.append('status', status); fd.append('review_token', cfg.token);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) { return d.success; });
    },
    updateAnchor: function (id, anchor) {
      var fd = new FormData();
      fd.append('action', 'dxf_update_anchor'); fd.append('_wpnonce', cfg.nonce);
      fd.append('id', String(id)); fd.append('anchor_data', JSON.stringify(anchor));
      fd.append('review_token', cfg.token);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    uploadScreenshot: function (dataUrl) {
      var fd = new FormData();
      fd.append('action', 'dxf_upload_screenshot'); fd.append('_wpnonce', cfg.nonce);
      fd.append('post_id', String(cfg.postId)); fd.append('screenshot', dataUrl);
      fd.append('review_token', cfg.token);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); })
        .then(function (d) { return (d.success && d.data && d.data.url) ? d.data.url : null; });
    },
    deleteComment: function (id) {
      var fd = new FormData();
      fd.append('action', 'dxf_delete_comment'); fd.append('_wpnonce', cfg.nonce);
      fd.append('id', String(id)); fd.append('review_token', cfg.token);
      fd.append('author_name', guest.name); fd.append('author_email', guest.email);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    editComment: function (id, body) {
      var fd = new FormData();
      fd.append('action', 'dxf_edit_comment'); fd.append('_wpnonce', cfg.nonce);
      fd.append('id', String(id)); fd.append('body', body);
      fd.append('review_token', cfg.token);
      fd.append('author_name', guest.name); fd.append('author_email', guest.email);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    markComplete: function () {
      var fd = new FormData();
      fd.append('action', 'dxf_mark_review_complete'); fd.append('_wpnonce', cfg.nonce);
      fd.append('token', cfg.token); fd.append('post_id', String(cfg.postId));
      fd.append('author_name', guest.name); fd.append('author_email', guest.email);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    // Per-page "Mark as reviewed" (multi-page) — toggles the page's Reviewed
    // state. Resolves to {dashboardUrl, reviewed} so the caller can redirect.
    markReviewed: function (reviewed) {
      var fd = new FormData();
      fd.append('action', 'dxf_mark_reviewed'); fd.append('_wpnonce', cfg.nonce);
      fd.append('token', cfg.token); fd.append('post_id', String(cfg.postId));
      fd.append('reviewed', reviewed ? '1' : '0');
      fd.append('author_name', guest.name); fd.append('author_email', guest.email);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) { return (d && d.success) ? d.data : null; });
    },
    // Finish & notify the developer (single-page reviews) — optional note.
    // Resolves to the success boolean.
    reviewDone: function (note) {
      var fd = new FormData();
      fd.append('action', 'dxf_review_done'); fd.append('_wpnonce', cfg.nonce);
      fd.append('token', cfg.token); fd.append('post_id', String(cfg.postId));
      fd.append('author_name', guest.name); fd.append('author_email', guest.email);
      fd.append('note', note || '');
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); }).then(function (d) { return !!(d && d.success); });
    },
    attachScreenshot: function (id, url) {
      var fd = new FormData();
      fd.append('action', 'dxf_attach_screenshot'); fd.append('_wpnonce', cfg.nonce);
      fd.append('id', String(id)); fd.append('screenshot_url', url);
      fd.append('review_token', cfg.token);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    toggleReaction: function (id, reaction) {
      var fd = new FormData();
      fd.append('action', 'dxf_toggle_reaction'); fd.append('_wpnonce', cfg.nonce);
      fd.append('id', String(id)); fd.append('reaction', reaction);
      fd.append('review_token', cfg.token);
      fd.append('author_name', guest.name); fd.append('author_email', guest.email);
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); })
        .then(function (d) { return d.success ? d.data : null; });
    },
  };

  // ---------------------------------------------------------------------------
  // FAB mount + apply-brand
  // ---------------------------------------------------------------------------
  var toggleComments;
  // Remember the reviewer's "I closed it" decision for this browser session,
  // so the modal doesn't pop back open on every nav after they hide it. Key
  // is scoped by review slug so different reviews don't share state.
  var SIDEBAR_DISMISS_KEY = 'dxf_sidebar_dismissed_' + (cfg.review && cfg.review.slug ? cfg.review.slug : 'default');
  function isSidebarDismissed() {
    try { return sessionStorage.getItem(SIDEBAR_DISMISS_KEY) === '1'; } catch (e) { return false; }
  }
  function setSidebarDismissed(dismissed) {
    try {
      if (dismissed) sessionStorage.setItem(SIDEBAR_DISMISS_KEY, '1');
      else sessionStorage.removeItem(SIDEBAR_DISMISS_KEY);
    } catch (e) {}
  }
  // First-visit coachmark: point new reviewers at the Feedback FAB instead of
  // force-opening the sidebar over the page they came to review. Shown until
  // the reviewer clicks the FAB (or hits the tip's X) — tracked in
  // localStorage so it never reappears on later visits. Ported from the demo
  // mu-plugin popout, generalised to all four FAB corners.
  var FAB_TIP_KEY = 'dxf_fab_tip_done';
  var mountedFabPos = 'bottom-right';
  function fabTipDone() {
    try { return localStorage.getItem(FAB_TIP_KEY) === '1'; } catch (e) { return true; }
  }
  function setFabTipDone() {
    try { localStorage.setItem(FAB_TIP_KEY, '1'); } catch (e) {}
    var tip = document.getElementById('dxf-fab-tip');
    if (tip) tip.remove();
  }
  function showFabTip() {
    if (fabTipDone() || document.getElementById('dxf-fab-tip')) return;
    // The doxstudio.com demo mu-plugin mounts its own popout — don't double up.
    if (document.body.classList.contains('dxf-demo-onboarding-active')) return;
    // Pointless once the sidebar is already open (e.g. filter-forced open).
    var sb = document.getElementById('dxf-sidebar');
    if (sb && sb.classList.contains('dxf-sidebar--open')) return;
    var i18n = cfg.i18n || {};
    var tip = document.createElement('div');
    tip.id = 'dxf-fab-tip';
    tip.className = 'dxf-fab-tip--' + mountedFabPos;
    tip.setAttribute('role', 'status');
    var msg = document.createElement('span');
    msg.className = 'dxf-fab-tip-msg';
    msg.textContent = i18n.fabTip || 'Click the Feedback button to pin comments anywhere on this page.';
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'dxf-fab-tip-close';
    close.setAttribute('aria-label', i18n.fabTipClose || 'Dismiss');
    close.innerHTML = '&times;';
    // Explicit dismissal counts as "got it" — never show again.
    close.addEventListener('click', setFabTipDone);
    tip.appendChild(msg);
    tip.appendChild(close);
    document.body.appendChild(tip);
    // Showing it once is enough. Persist "done" as soon as it's shown so it
    // never re-prompts on subsequent page navigations within the review — the
    // previous behaviour re-showed it on every page, which felt naggy.
    try { localStorage.setItem(FAB_TIP_KEY, '1'); } catch (e) {}
    // Auto-fade after 12s.
    setTimeout(function () {
      if (!tip.parentNode) return;
      tip.classList.add('dxf-fab-tip--hide');
      setTimeout(function () { if (tip.parentNode) tip.remove(); }, 400);
    }, 12000);
  }
  function mountToggle(opts) {
    toggleComments = opts.toggleComments;
    var fab = document.createElement('button');
    fab.id = 'dxf-fab';
    fab.type = 'button';
    // Admin-chosen corner (cfg.fabPosition); fall back to bottom-right and
    // guard against an unexpected value so we never emit a junk class.
    var fabPos = cfg.fabPosition || 'bottom-right';
    if (['bottom-right', 'bottom-left', 'top-right', 'top-left'].indexOf(fabPos) === -1) fabPos = 'bottom-right';
    mountedFabPos = fabPos;
    fab.className = 'dxf-fab--' + fabPos;
    fab.setAttribute('aria-label', 'Feedback');
    fab.innerHTML = opts.ICONS.comment + '<span>Feedback</span>';
    fab.addEventListener('click', function () {
      // FAB click counts as an explicit "open" — clear the dismissed flag.
      setSidebarDismissed(false);
      // The reviewer found the button — retire the first-visit tooltip.
      setFabTipDone();
      opts.toggleComments();
    });
    document.body.appendChild(fab);
  }
  function updateToggleActive(open) {
    var fab = document.getElementById('dxf-fab');
    if (fab) fab.classList.toggle('is-active', !!open);
    // When the reviewer closes the sidebar (via X, Esc, or C), record it so
    // we don't auto-open on the next page nav. When they open it, clear the
    // flag so subsequent nav re-opens.
    setSidebarDismissed(!open);
  }

  function applyBrand() {
    var root = document.documentElement;
    root.style.setProperty('--rv-accent', ACCENT);
    var b = cfg.brand || {};
    if (b.enabled) {
      if (b.color)     root.style.setProperty('--rv-accent', b.color);
      if (b.textColor) root.style.setProperty('--rv-accent-text', b.textColor);
    }
    // If no explicit textColor was supplied, derive a legible one from the
    // accent so light brand colours don't render white-on-yellow.
    if (!b.textColor && window.DxfCommentEngine && DxfCommentEngine.ensureAccentContrast) {
      DxfCommentEngine.ensureAccentContrast(root);
    }
  }

  // ---------------------------------------------------------------------------
  // Identity gate (rendered by engine when not identified)
  // ---------------------------------------------------------------------------
  function escAttr(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function escHtml(str) {
    var d = document.createElement('div'); d.textContent = String(str || ''); return d.innerHTML;
  }
  function renderIdentityGate(body, onSuccess, opts) {
    opts = opts || {};
    // Pre-fill from the email-restricted invite when available — the
    // reviewer's email is already known server-side so they shouldn't
    // have to retype it. Name is suggested from the email's local-part
    // (e.g. "alice.smith" → "Alice Smith") so they only need to confirm
    // or edit, not start from blank.
    var hasInvite      = !!cfg.memberEmail;
    var isChangingName = !!opts.changeNameOnly;
    var nameVal        = guest.name  || '';
    var emailVal       = guest.email || '';

    // Change-name flow: name-only form, no intro, with a Back button. The
    // user already has a verified identity; they're just relabelling
    // themselves (e.g. typo fix). Email stays exactly as it is — we never
    // want to silently break the link between an existing comment author
    // and their email-of-record.
    if (isChangingName) {
      body.innerHTML =
        '<div class="dxf-identity">' +
          '<label class="dxf-identity-label">Your name</label>' +
          '<input type="text" class="dxf-identity-input" id="dxf-id-name" ' +
            'value="' + escAttr(nameVal) + '" placeholder="Jane Smith" autocomplete="name">' +
          '<p class="dxf-identity-error" id="dxf-id-error"></p>' +
          '<div class="dxf-identity-actions">' +
            '<button type="button" class="dxf-btn dxf-btn-ghost" id="dxf-id-back">Back</button>' +
            '<button type="button" class="dxf-btn dxf-btn-primary" id="dxf-id-submit">Save</button>' +
          '</div>' +
        '</div>';

      var changeNameInput = body.querySelector('#dxf-id-name');
      var changeSaveBtn   = body.querySelector('#dxf-id-submit');
      var changeBackBtn   = body.querySelector('#dxf-id-back');
      var changeSubmit = function () {
        var name = changeNameInput.value.trim();
        var err  = body.querySelector('#dxf-id-error');
        if (!name) { err.textContent = 'Please enter your name.'; return; }
        // Save name; preserve existing email.
        guest.save(name, emailVal);
        onSuccess();
      };
      changeSaveBtn.addEventListener('click', changeSubmit);
      changeBackBtn.addEventListener('click', function () {
        if (typeof opts.onCancel === 'function') opts.onCancel();
      });
      changeNameInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter')  { e.preventDefault(); changeSubmit(); }
        if (e.key === 'Escape') { e.preventDefault(); if (typeof opts.onCancel === 'function') opts.onCancel(); }
      });
      changeNameInput.focus();
      changeNameInput.select();
      return;
    }

    // First-time identity gate: name (required) + email (optional unless
    // the reviewer arrived via a magic-link invite, in which case the email
    // is already known and pre-filled readonly). For "anyone with link"
    // reviews we never contact the reviewer at the address they enter —
    // it's only shown alongside their name in the site owner's notification
    // emails — so we don't gate on it.
    var emailReadonly = hasInvite ? ' readonly title="From your invitation"' : '';
    var emailLabel    = hasInvite
      ? 'Your email'
      : 'Your email <span class="dxf-identity-optional">(optional)</span>';
    var emailNote     = hasInvite
      ? '<p class="dxf-identity-note">We\'ll send replies to this address — it\'s the one your invite was sent to.</p>'
      : '';
    var intro = hasInvite
      ? ''
      : '<p class="dxf-identity-intro">Before leaving feedback on <strong>' + escHtml(cfg.pageTitle) + '</strong>, tell us who you are.</p>';

    body.innerHTML =
      '<div class="dxf-identity">' +
        intro +
        '<label class="dxf-identity-label">Your name</label>' +
        '<input type="text" class="dxf-identity-input" id="dxf-id-name" ' +
          'value="' + escAttr(nameVal) + '" placeholder="Jane Smith" autocomplete="name">' +
        '<label class="dxf-identity-label">' + emailLabel + '</label>' +
        '<input type="email" class="dxf-identity-input" id="dxf-id-email" ' +
          'value="' + escAttr(emailVal) + '" placeholder="jane@example.com" autocomplete="email"' + emailReadonly + '>' +
        emailNote +
        '<p class="dxf-identity-error" id="dxf-id-error"></p>' +
        '<button type="button" class="dxf-btn dxf-btn-primary dxf-btn-full" id="dxf-id-submit">' +
          (hasInvite ? 'Continue as ' + (escHtml(nameVal) || 'this reviewer') : 'Continue') +
        '</button>' +
      '</div>';

    var nameInput = body.querySelector('#dxf-id-name');
    var btn       = body.querySelector('#dxf-id-submit');
    if (hasInvite) {
      // Live-update the CTA so it always reflects the editable name.
      nameInput.addEventListener('input', function () {
        var n = nameInput.value.trim();
        btn.textContent = 'Continue as ' + (n || 'this reviewer');
      });
    }

    var submit = function () {
      var name  = nameInput.value.trim();
      var email = body.querySelector('#dxf-id-email').value.trim();
      var err   = body.querySelector('#dxf-id-error');
      if (!name) { err.textContent = 'Please enter your name.'; return; }
      // Email is optional. If they did type something, it needs to look like
      // an email — empty is fine, a typo'd address is not (it'd render
      // garbage in the site owner's notification email).
      if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        err.textContent = 'Please enter a valid email address, or leave it blank.';
        return;
      }
      guest.save(name, email);
      // Neutral signal that a reviewer just identified themselves with an email
      // (a lead). The doxstudio.com demo site listens for this to fire a GA4
      // generate_lead event; on customer sites nothing listens, so it's a no-op.
      // No analytics/GA coupling lives in the plugin itself.
      if (email) {
        try {
          document.dispatchEvent(new CustomEvent('dxf:lead', { detail: { method: 'review-identity' } }));
        } catch (e) {}
      }
      onSuccess();
    };
    btn.addEventListener('click', submit);
    body.querySelector('#dxf-id-email').addEventListener('keydown', function (e) { if (e.key === 'Enter') submit(); });
    // If we have a usable seed name, focus the CTA so a single tap continues.
    if (hasInvite && nameVal) { btn.focus(); } else { nameInput.focus(); }
  }

  // ---------------------------------------------------------------------------
  // Brand info forwarded to engine
  // ---------------------------------------------------------------------------
  var brand = (function () {
    var b = cfg.brand || {};
    return {
      accent:       ACCENT,
      name:         (cfg.agencyName || 'Comments'),
      logo:         (b.enabled && b.logo) ? b.logo : '',
      logoDark:     (b.enabled && b.logoDark)  ? b.logoDark  : ((b.enabled && b.logo) ? b.logo : ''),
      logoLight:    (b.enabled && b.logoLight) ? b.logoLight : ((b.enabled && b.logo) ? b.logo : ''),
      color:        b.color || '',
      textColor:    b.textColor || '',
      isWhitelabel: !!b.enabled,
    };
  }());

  // ---------------------------------------------------------------------------
  // Page-nav chrome — bottom-left floating panel + off-scope banner
  //
  // Renders independently of the comment engine so it works on both:
  //   - in-scope pages where the full review overlay is active, and
  //   - off-scope pages where the engine is intentionally not loaded
  //     (token absent, cfg.review present).
  //
  // State (collapsed/expanded) persists via localStorage so the panel
  // remembers the reviewer's preference across page navigations.
  // ---------------------------------------------------------------------------
  var NAV_KEY = 'dxf_nav_collapsed';

  function pagesNavBadge(status) {
    var i18n = (cfg.i18n || {});
    if (status === 'approved')  return '<span class="rv-nav-badge is-done">' + escHtml(i18n.statusDone   || 'Approved') + '</span>';
    if (status === 'in_review') return '<span class="rv-nav-badge is-active">' + escHtml(i18n.statusReview || 'In review') + '</span>';
    return '<span class="rv-nav-badge is-todo">' + escHtml(i18n.statusTodo || 'To do') + '</span>';
  }

  function renderPagesNav() {
    var rv = cfg.review || {};
    if (!rv.slug || !rv.pages || !rv.pages.length) return;
    // Single-page review → there's nowhere to navigate to. The nav panel
    // would just be a one-row chip cluttering the corner. Drop any stale
    // node and bail.
    if (rv.pages.length <= 1) {
      var stale = document.getElementById('dxf-pages-nav');
      if (stale) stale.remove();
      return;
    }
    // Idempotent: if the panel is already in the DOM, drop the previous
    // node so the caller can re-render with refreshed status badges
    // (e.g. after the reviewer approves the current page).
    var existing = document.getElementById('dxf-pages-nav');
    if (existing) existing.remove();

    // Default-collapse on phones — the expanded pages-nav otherwise sits on
    // top of the page content and the Feedback FAB. Reviewer can still
    // expand it; their choice persists via NAV_KEY in localStorage.
    var collapsed = false;
    try {
      var stored = localStorage.getItem(NAV_KEY);
      if (stored === '1') collapsed = true;
      else if (stored == null && window.matchMedia && window.matchMedia('(max-width: 600px)').matches) collapsed = true;
    } catch (e) {}

    var rows = rv.pages.map(function (p) {
      var isCurrent = (p.id === cfg.postId);
      return ''
        + '<a class="rv-nav-row' + (isCurrent ? ' is-current' : '') + '" href="' + escAttr(p.url) + '">'
        +   '<span class="rv-nav-title">' + escHtml(p.title) + '</span>'
        +   pagesNavBadge(p.status)
        + '</a>';
    }).join('');

    var i18n = cfg.i18n || {};
    var el = document.createElement('aside');
    el.id = 'dxf-pages-nav';
    el.className = 'rv-nav' + (collapsed ? ' is-collapsed' : '');
    el.setAttribute('aria-label', i18n.navTitle || 'Pages in this review');
    // Lenis: prevent smooth-scroll libs from hijacking wheel/touch inside the
    // pages-nav. Mirrors what the sidebar already does — without this, when a
    // theme runs Lenis (common on Bricks builds) the inner page list looks
    // frozen because the wheel events get captured by the global scroller.
    el.setAttribute('data-lenis-prevent', '');
    el.setAttribute('data-lenis-prevent-wheel', '');
    el.setAttribute('data-lenis-prevent-touch', '');
    el.innerHTML =
      '<button type="button" class="rv-nav-header" aria-expanded="' + (collapsed ? 'false' : 'true') + '">' +
        '<span class="rv-nav-header-title">' +
          (rv.name ? escHtml(rv.name) : escHtml(i18n.navTitle || 'Pages in this review')) +
          '<span class="rv-nav-count">' + rv.pages.length + '</span>' +
        '</span>' +
        '<span class="rv-nav-chev" aria-hidden="true">' +
          '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>' +
        '</span>' +
      '</button>' +
      '<div class="rv-nav-body">' +
        '<div class="rv-nav-list">' + rows + '</div>' +
        (rv.landingUrl
          ? '<a class="rv-nav-dashboard" href="' + escAttr(rv.landingUrl) + '">' + escHtml(i18n.navAllPages || 'All pages') + ' →</a>'
          : '') +
      '</div>';

    el.querySelector('.rv-nav-header').addEventListener('click', function (e) {
      e.preventDefault();
      var nowCollapsed = !el.classList.contains('is-collapsed');
      el.classList.toggle('is-collapsed', nowCollapsed);
      el.querySelector('.rv-nav-header').setAttribute('aria-expanded', nowCollapsed ? 'false' : 'true');
      try { localStorage.setItem(NAV_KEY, nowCollapsed ? '1' : '0'); } catch (e2) {}
    });

    document.body.appendChild(el);
  }

  function renderOffScopeBanner() {
    var rv = cfg.review || {};
    if (!rv.slug || !rv.isOffScope) return;
    if (document.getElementById('dxf-off-scope')) return;
    var i18n = cfg.i18n || {};
    var el = document.createElement('div');
    el.id = 'dxf-off-scope';
    el.className = 'rv-off-scope';
    el.innerHTML =
      '<span class="rv-off-scope-msg">' + escHtml(i18n.offScopeMsg || 'This page isn\'t part of the review.') + '</span>' +
      '<span class="rv-off-scope-actions">' +
        (rv.landingUrl
          ? '<a class="rv-off-scope-back" href="' + escAttr(rv.landingUrl) + '">' + escHtml(i18n.offScopeBack || 'Back to dashboard') + ' →</a>'
          : '') +
        '<button type="button" class="rv-off-scope-exit">' + escHtml(i18n.offScopeExit || 'Exit review mode') + '</button>' +
      '</span>';
    document.body.insertBefore(el, document.body.firstChild);

    // Exit button: clear the per-page token + session cookies server-side,
    // then hard-reload so this page renders without any review chrome.
    var exitBtn = el.querySelector('.rv-off-scope-exit');
    if (exitBtn) {
      exitBtn.addEventListener('click', function () {
        exitBtn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'dxf_review_exit');
        fd.append('_ajax_nonce', cfg.nonce || '');
        fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function () { window.location.reload(); })
          .catch(function () {
            // Even if the AJAX failed, force a reload — best-effort clear.
            window.location.reload();
          });
      });
    }
  }

  function renderReadOnlyBanner() {
    var rv = cfg.review || {};
    if (!rv.readOnly) return;
    if (document.getElementById('dxf-read-only')) return;
    var i18n = cfg.i18n || {};
    var el = document.createElement('div');
    el.id = 'dxf-read-only';
    el.className = 'rv-off-scope rv-read-only';
    el.innerHTML =
      '<span class="rv-off-scope-msg">' +
        escHtml(i18n.readOnlyMsg || 'This review is read-only — you can still read all the feedback here.') +
      '</span>';
    document.body.insertBefore(el, document.body.firstChild);
  }

  function mountChrome() {
    applyBrand();
    renderOffScopeBanner();
    renderReadOnlyBanner();
    renderPagesNav();
  }

  // ---------------------------------------------------------------------------
  // Viewport emulation
  //
  // The viewport switcher needs to actually fire CSS media queries — setting
  // `html.style.maxWidth` only narrows the visual box; the page keeps
  // rendering at the real window width, so `(max-width: 767px)` rules don't
  // apply and the preview lies about what the reviewer sees on a phone.
  //
  // Approach: when the reviewer picks tablet/mobile we mount a same-origin
  // iframe that loads the *same URL* with `?dxf_no_chrome=1` appended.
  // PHP sees that flag in class-review-mode.php and skips enqueueing the
  // overlay assets so the iframe renders the bare page — no nested FAB,
  // sidebar, or pages-nav. The iframe's intrinsic width drives real media
  // queries inside its document. Our overlay UI stays on the parent body,
  // floating above the iframe via z-index.
  //
  // The shared comment engine is iframe-agnostic — it pulls the canvas via
  // `host.getCanvasDoc()` / `host.getCanvasWindow()` on every read. We
  // make those accessors dynamic so they return the iframe's contentDoc
  // when emulation is on, and the live `document` when it's off. Pins,
  // comment-mode clicks, and screenshots all flow through that adapter
  // unchanged.
  // ---------------------------------------------------------------------------
  var vp = {
    mode:    'document',   // 'document' | 'iframe'
    iframe:  null,
    wrapper: null,
    readyCbs:  [],
    resizeCbs: [],
  };
  var engineApi = null;    // Captured from DxfCommentEngine.init() return.

  function vpDebounce(fn, ms) {
    var t;
    return function () { clearTimeout(t); t = setTimeout(fn, ms); };
  }

  function vpIsIframeMode() { return vp.mode === 'iframe' && !!vp.iframe; }

  function vpCanvasDoc() {
    if (!vpIsIframeMode()) return document;
    try { return vp.iframe.contentDocument || vp.iframe.contentWindow.document; }
    catch (e) { return document; }
  }
  function vpCanvasWindow() {
    if (!vpIsIframeMode()) return window;
    try { return vp.iframe.contentWindow; }
    catch (e) { return window; }
  }

  function vpFireReady()  { vp.readyCbs.forEach(function (f)  { try { f(); } catch (e) {} }); }
  function vpFireResize() { vp.resizeCbs.forEach(function (f) { try { f(); } catch (e) {} }); }

  // Engine subscribes once at boot. In document-mode the doc is already
  // there, so we fire immediately. In iframe-mode the iframe's load handler
  // fires the same callback set after the contentDoc is ready.
  function vpOnCanvasReady(cb) {
    vp.readyCbs.push(cb);
    if (!vpIsIframeMode()) setTimeout(cb, 0);
  }
  function vpOnCanvasResize(cb) {
    vp.resizeCbs.push(cb);
  }

  function vpBuildIframeSrc() {
    var u;
    try { u = new URL(location.href); }
    catch (e) { return location.href + (location.search ? '&' : '?') + 'dxf_no_chrome=1'; }
    u.searchParams.set('dxf_no_chrome', '1');
    return u.toString();
  }

  // Inject the comment-mode crosshair cursor into the iframe doc. Only
  // needed in iframe-mode — the parent page already has these styles
  // from review.css when it's the canvas itself.
  function vpApplyCommentModeStyle(on) {
    if (!vpIsIframeMode()) return;
    var doc = vpCanvasDoc();
    if (!doc) return;
    var id = 'dxf-canvas-style';
    var el = doc.getElementById(id);
    if (on) {
      if (el) return;
      var fill = encodeURIComponent(ACCENT);
      var cursor =
        "%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='30'%20height='30'%20viewBox='0%200%2030%2030'%3E" +
        "%3Cpath%20d='M5%203%20h20%20a2%202%200%200%201%202%202%20v12%20a2%202%200%200%201%20-2%202%20h-13%20l-5%205%20v-5%20h-2%20a2%202%200%200%201%20-2%20-2%20v-12%20a2%202%200%200%201%202%20-2%20z'%20fill='" + fill + "'%20stroke='%23ffffff'%20stroke-width='2'%20stroke-linejoin='round'/%3E" +
        "%3Cline%20x1='11'%20y1='11'%20x2='19'%20y2='11'%20stroke='%23ffffff'%20stroke-width='2'%20stroke-linecap='round'/%3E" +
        "%3Cline%20x1='15'%20y1='7'%20x2='15'%20y2='15'%20stroke='%23ffffff'%20stroke-width='2'%20stroke-linecap='round'/%3E" +
        "%3C/svg%3E";
      var s = doc.createElement('style');
      s.id = id;
      s.textContent =
        'body.dxf-comment-mode, body.dxf-comment-mode * {' +
          'cursor: url("data:image/svg+xml,' + cursor + '") 7 24, crosshair !important;' +
          'user-select: none !important;' +
        '}';
      (doc.head || doc.documentElement).appendChild(s);
    } else if (el) {
      el.remove();
    }
  }

  // Click coords inside the iframe need an offset added so the parent-level
  // comment form (position:fixed on parent body) lands next to the click.
  // In document-mode the click is already in parent coords.
  function vpCanvasClickToScreen(e) {
    if (!vpIsIframeMode()) return { x: e.clientX, y: e.clientY };
    var r = vp.iframe.getBoundingClientRect();
    return { x: e.clientX + r.left, y: e.clientY + r.top };
  }

  // Comment mode is rebound across canvas swaps. The engine wires its
  // onCanvasClick listener to whichever doc getCanvasDoc() returns at the
  // moment enableCommentMode() runs — once the canvas swaps, that listener
  // is stranded on the old doc and the new one receives nothing. We
  // bracket each swap with exit/re-enable so the listener follows.
  function vpWasCommentMode() {
    return !!(engineApi && engineApi.state && engineApi.state.commentMode);
  }
  function vpSuspendCommentMode() {
    var was = vpWasCommentMode();
    if (was && engineApi.exitCommentPlacement) engineApi.exitCommentPlacement();
    return was;
  }
  function vpResumeCommentMode(was) {
    if (was && engineApi && engineApi.enableCommentMode) engineApi.enableCommentMode();
  }

  function vpSetupEmulator(width) {
    var wasCommentMode = vpSuspendCommentMode();
    var firstMount     = !vp.iframe;

    if (!vp.wrapper) {
      var wrap = document.createElement('div');
      wrap.id = 'dxf-vp-wrapper';
      document.body.appendChild(wrap);
      vp.wrapper = wrap;
    }
    if (firstMount) {
      var ifr = document.createElement('iframe');
      ifr.id = 'dxf-vp-iframe';
      ifr.setAttribute('title', 'Page preview');
      ifr.src = vpBuildIframeSrc();
      ifr.addEventListener('load', function () {
        // Pin layer lives inside the canvas doc — scrolling the iframe
        // moves it relative to the iframe viewport, so renderPins must
        // re-run on iframe-internal scroll. The engine binds parent
        // window-scroll already (scrollIsCanvas: true), but that fires
        // a no-op in iframe mode because the parent doesn't scroll.
        try {
          var win = ifr.contentWindow;
          if (win && !win.__dxfVpHooks) {
            win.__dxfVpHooks = true;
            win.addEventListener('scroll', vpDebounce(function () {
              if (engineApi && engineApi.renderPins) engineApi.renderPins();
            }, 60), { passive: true });
            // Resizing the iframe element (mobile → tablet, or window
            // resize while emulating) fires `resize` on its contentWindow.
            // Forward to the engine's onCanvasResize-registered handler.
            win.addEventListener('resize', vpDebounce(vpFireResize, 120), { passive: true });
          }
        } catch (e) {}
        vpFireReady();
        if (engineApi && engineApi.renderPins) engineApi.renderPins();
        // Re-arm click-to-pin against the iframe's document.
        vpResumeCommentMode(wasCommentMode);
      });
      vp.wrapper.appendChild(ifr);
      vp.iframe = ifr;
    }
    vp.iframe.style.width = width;
    vp.mode = 'iframe';
    document.documentElement.classList.add('dxf-vp-emulating');
    // The old document-mode pin layer is stranded in parent body. Drop it
    // so it doesn't show through (the parent body is now hidden behind the
    // iframe wrapper, but its contents are still in the DOM tree).
    var staleLayer = document.getElementById('dxf-pin-layer');
    if (staleLayer && staleLayer.parentNode) staleLayer.parentNode.removeChild(staleLayer);

    // Already-mounted iframe (e.g. mobile → tablet): the contentDoc didn't
    // change so no fresh load event is coming. Rebind synchronously here
    // since exitCommentPlacement() above unbound the listener.
    if (!firstMount) vpResumeCommentMode(wasCommentMode);
  }

  function vpTeardownEmulator() {
    var wasCommentMode = vpSuspendCommentMode();
    if (vp.iframe && vp.iframe.parentNode) vp.iframe.parentNode.removeChild(vp.iframe);
    if (vp.wrapper && vp.wrapper.parentNode) vp.wrapper.parentNode.removeChild(vp.wrapper);
    vp.iframe = null;
    vp.wrapper = null;
    vp.mode = 'document';
    document.documentElement.classList.remove('dxf-vp-emulating');
    // Re-render pins against the live page now that getCanvasDoc()
    // resolves back to `document`.
    if (engineApi && engineApi.renderPins) engineApi.renderPins();
    // Re-arm click-to-pin against the parent document.
    vpResumeCommentMode(wasCommentMode);
  }

  function vpApplyViewport(mode, width) {
    if (!width || mode === 'desktop') vpTeardownEmulator();
    else                              vpSetupEmulator(width);
  }

  // Parent window resize is the only resize signal we get when no iframe
  // is up (document-mode). When the iframe is up, the iframe element
  // resizing with the window naturally generates a contentWindow resize
  // event too — the engine's onCanvasResize handler picks it up.
  window.addEventListener('resize', vpDebounce(vpFireResize, 120), { passive: true });

  // ---------------------------------------------------------------------------
  // Boot
  // ---------------------------------------------------------------------------
  function boot() {
    // Chrome always mounts first — it works in both full-mode and
    // chrome-only off-scope mode.
    mountChrome();

    // Off-scope (no token) → chrome only, no engine init.
    if (!fullMode) return;

    engineApi = DxfCommentEngine.init({
      cfg: cfg,
      isBuilder: false,
      brand: brand,
      capabilities: {
        canAssign:        false,
        canStatusSelect:  false,
        // Browser/viewport context is team-facing debugging metadata —
        // captured here, shown only in the builder.
        showCardContext:  false,
        canResolve:       true,
        canDelete:        true,
        canDeleteOwnOnly: true,    // FE: only delete one's own comments
        // Viewport switcher: tablet/mobile mount a same-origin iframe so
        // CSS media queries actually fire at the simulated width. See the
        // "Viewport emulation" block above this boot function.
        canViewport:      true,
        // Reviewers always see the Review name as a static label (no
        // cross-Review picking). canReviewsPicker keeps the bar present;
        // canPickReview=false renders the static label instead of the
        // dropdown.
        canReviewsPicker: true,
        canPickReview:    false,
        canScope:         false,
        canDeviceFilter:  true,
        canViewApprovals: false,
        canSummarize:     false,
        // Role-aware approve gate. For email-mode reviews the server
        // sends cfg.review.canApprove based on the member's role
        // (approver + lead → true; viewer + reviewer → false). Public-
        // link reviews (no member) fall through to `true` so the legacy
        // "anyone with the link can approve" behaviour is preserved.
        canApprove:       (cfg.review && typeof cfg.review.canApprove === 'boolean')
                              ? cfg.review.canApprove
                              : true,
        // "I'm done reviewing" is available to any active reviewer (not just
        // approvers); only an explicitly read-only review hides it.
        canMarkDone:      !(cfg.review && cfg.review.readOnly),
        canShareLink:     false,
        canFilterMine:    false,
        canDragPins:      true,
        canImportMedia:   false,
        captureContext:   true,    // FE captures browser/OS/errors with each comment
        canAnnotate:      true,
        // C-key toggles the sidebar + comment-mode. bindKeys() already
        // ignores presses inside inputs/textareas/contenteditable, so this
        // doesn't fight with typing a comment.
        useCKey:          true,
        unreadAutoOpen:   false,
        // Reviewers came to give feedback — default straight into comment mode.
        defaultMode:      'comment',
      },
      identity: {
        get name()       { return guest.name;  },
        get email()      { return guest.email; },
        id:              (cfg.currentUserId || 0),
        get isLoggedIn() { return guest.isLoggedIn; },
        identified:      function () { return guest.identified(); },
        clear:           function () { guest.clear(); },
      },
      api: api,
      // Canvas accessors are dynamic — they return the live page when in
      // document-mode and the iframe's contentDoc/contentWindow when the
      // viewport switcher has emulation active.
      getCanvasDoc:    vpCanvasDoc,
      getCanvasWindow: vpCanvasWindow,
      mountToggle:     mountToggle,
      updateToggleActive: updateToggleActive,
      renderIdentityGate: renderIdentityGate,
      // scrollIsCanvas binds parent-window scroll → renderPins. Correct for
      // document-mode (the page IS the canvas). In iframe-mode the parent
      // doesn't scroll; the iframe-internal scroll handler installed in
      // vpSetupEmulator() re-renders pins instead.
      scrollIsCanvas:  true,
      applyViewport:         vpApplyViewport,
      applyCommentModeStyle: vpApplyCommentModeStyle,
      canvasClickToScreen:   vpCanvasClickToScreen,
      // onCanvasReady/onCanvasResize let the engine subscribe to canvas
      // lifecycle events once. In document-mode the ready callback fires
      // immediately (DOM already loaded). In iframe-mode it re-fires on
      // each iframe load (when the reviewer enters emulation).
      onCanvasReady:   vpOnCanvasReady,
      onCanvasResize:  vpOnCanvasResize,
      // Called by the engine after a successful markComplete(). The engine
      // has already flipped cfg.review.pages[i].status = 'approved' for the
      // current page; we just need to redraw the pages-nav so the badge
      // updates without a page refresh. The dashboard re-renders correctly
      // on its own (fresh PHP load), now that the server propagates the
      // approval to every open Review containing this post.
      onApproved: function () {
        renderPagesNav();
      },
    });

    // The sidebar no longer force-opens on load (since 1.0.7) — a first-visit
    // tooltip points at the Feedback FAB instead. The old behaviour can be
    // restored per-site via the `dxf_review_auto_open_sidebar` PHP filter,
    // honoured here ONLY on an explicit `true` — legacy single-page-token
    // sessions ship `review: []` and must never auto-open. Narrow (phone)
    // viewports get neither: an auto-popping modal or callout obscures the
    // page they're trying to review.
    var isNarrow = window.matchMedia && window.matchMedia('(max-width: 600px)').matches;
    var forceOpen = !!(cfg.review && cfg.review.autoOpenSidebar === true);
    if (toggleComments && forceOpen && !isSidebarDismissed() && !isNarrow) {
      toggleComments();
    } else if (!isNarrow) {
      // Small delay so the FAB has mounted/painted and the demo mu-plugin
      // (if present) has had time to claim the coachmark slot first.
      setTimeout(showFabTip, 900);
    }

    // Read-receipt beacon — tell the server a real browser opened this review.
    // We fire from JS (not a server-side log on the link hit) so email link
    // scanners, which fetch the URL but never run JS, don't fabricate a view.
    // The server ignores staff opens and resolves the review from the session.
    // Identity is sent when already known (a returning reviewer) so the receipt
    // can name them; first-time opens are anonymous until the gate is completed.
    fireReviewBeacon('dxf_review_seen', true);
  }

  // Fire-and-forget POST beacon to a Dox Feedback review endpoint. When withIdentity
  // is set, includes the reviewer's claimed name/email (empty until the gate
  // is completed). keepalive lets it survive an immediate navigation.
  function fireReviewBeacon(action, withIdentity) {
    try {
      var fd = new FormData();
      fd.append('action', action);
      fd.append('review_token', cfg.token);
      fd.append('post_id', String(cfg.postId));
      if (withIdentity) {
        fd.append('author_name',  guest.name  || '');
        fd.append('author_email', guest.email || '');
      }
      fetch(cfg.ajaxUrl, { method: 'POST', body: fd, keepalive: true }).catch(function () {});
    } catch (e) {}
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}());
