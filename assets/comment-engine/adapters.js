/**
 * Dox Feedback — Builder anchor adapters
 *
 * The comment engine pins feedback to elements on a page. HOW an element is
 * identified, re-found after a reload, and deep-linked differs per page
 * builder. Each adapter encapsulates that, behind one contract, so the engine
 * stays builder-agnostic:
 *
 *   detect(doc)                  -> is this builder's output present?
 *   hasAnchorableContent(doc)    -> does the canvas have anchorable elements?
 *   closest(node, doc)           -> nearest anchorable element from a DOM node
 *   elementId(el)                -> stable native id (or '' if none)
 *   strategies(el, doc)          -> generic fallback anchor (css/xpath/text/bbox)
 *   resolve(anchor, doc)         -> Element | null  (native id first, then generic)
 *   deepLinkHash(anchor)         -> URL hash for cross-page navigation
 *   label(el)                    -> human-readable element label
 *
 * `GenericDomAdapter` is the universal floor — it works on ANY markup and is
 * always the last resort, both as the active adapter on non-builder pages and
 * as the resolution fallback WITHIN a builder when a native id goes stale.
 *
 * Adapters register into window.DxfAnchors. Free ships generic + bricks;
 * Pro/future phases register elementor + gutenberg into the same registry.
 */
(function () {
  'use strict';

  // ---------------------------------------------------------------------------
  // Generic DOM strategies — builder-independent. A captured anchor stores a
  // cascade; resolution tries each in priority order and returns the best
  // surviving match, tolerant of DOM drift (reordered siblings, edited text).
  // ---------------------------------------------------------------------------
  var OWN = /^(dxf-|wp-admin-bar|wpadminbar)/;

  function isOwnNode(el) {
    return !!(el && el.id && OWN.test(el.id));
  }

  // A CSS path of tag + :nth-of-type, stopping at the nearest id or <body>.
  function cssPath(el, doc) {
    if (!el || el.nodeType !== 1) return '';
    var parts = [];
    var node = el;
    while (node && node.nodeType === 1 && node !== doc.body && parts.length < 12) {
      if (node.id && !OWN.test(node.id)) {
        parts.unshift('#' + cssEscape(node.id));
        break;
      }
      var tag = node.tagName.toLowerCase();
      var idx = 1, sib = node;
      while ((sib = sib.previousElementSibling)) {
        if (sib.tagName === node.tagName) idx++;
      }
      parts.unshift(tag + ':nth-of-type(' + idx + ')');
      node = node.parentElement;
    }
    return parts.join(' > ');
  }

  function cssEscape(s) {
    if (window.CSS && CSS.escape) return CSS.escape(s);
    return String(s).replace(/([^\w-])/g, '\\$1');
  }

  function xpath(el, doc) {
    if (!el || el.nodeType !== 1) return '';
    var parts = [];
    var node = el;
    while (node && node.nodeType === 1 && node !== doc.documentElement && parts.length < 16) {
      var tag = node.tagName.toLowerCase();
      var idx = 1, sib = node;
      while ((sib = sib.previousElementSibling)) {
        if (sib.tagName === node.tagName) idx++;
      }
      parts.unshift(tag + '[' + idx + ']');
      node = node.parentElement;
    }
    return parts.length ? '/' + parts.join('/') : '';
  }

  function textFp(el) {
    if (!el) return '';
    var t = (el.textContent || '').replace(/\s+/g, ' ').trim();
    return t.slice(0, 80);
  }

  function bboxRatio(el, doc) {
    try {
      var r = el.getBoundingClientRect();
      var win = doc.defaultView || window;
      var sw = (doc.documentElement && doc.documentElement.scrollWidth) || win.innerWidth || 1;
      var sh = (doc.documentElement && doc.documentElement.scrollHeight) || win.innerHeight || 1;
      var sx = win.scrollX || 0, sy = win.scrollY || 0;
      return {
        x: (r.left + sx) / sw, y: (r.top + sy) / sh,
        w: r.width / sw, h: r.height / sh
      };
    } catch (e) { return null; }
  }

  function buildStrategies(el, doc) {
    if (!el || el.nodeType !== 1) return null;
    var idx = 1, sib = el;
    while ((sib = sib.previousElementSibling)) {
      if (sib.tagName === el.tagName) idx++;
    }
    return {
      css_path: cssPath(el, doc),
      xpath: xpath(el, doc),
      nth_of_type: { tag: el.tagName.toLowerCase(), index: idx },
      text_fp: textFp(el),
      bbox_ratio: bboxRatio(el, doc)
    };
  }

  function bySelector(sel, doc) {
    if (!sel) return null;
    var el;
    try { el = doc.querySelector(sel); } catch (e) { return null; }
    return (el && !isOwnNode(el)) ? el : null;
  }

  function byXpath(xp, doc) {
    if (!xp || !doc.evaluate) return null;
    var el;
    try {
      var x = doc.evaluate(xp, doc, null, 9 /* FIRST_ORDERED_NODE */, null);
      el = x && x.singleNodeValue;
    } catch (e) { return null; }
    return (el && el.nodeType === 1 && !isOwnNode(el)) ? el : null;
  }

  function byText(tag, fp, doc) {
    if (!tag || !fp) return null;
    try {
      var pool = doc.getElementsByTagName(tag);
      for (var i = 0; i < pool.length; i++) {
        if (!isOwnNode(pool[i]) && textFp(pool[i]) === fp) return pool[i];
      }
    } catch (e) {}
    return null;
  }

  // Resolve a stored strategy cascade to the best surviving element, tolerant
  // of DOM drift. Positional selectors (css_path / xpath) can silently match a
  // DIFFERENT but valid element after siblings are inserted/reordered, so when
  // we have a text fingerprint we prefer a candidate whose text still matches,
  // and fall back to a pure text scan, before trusting raw position.
  function resolveStrategies(s, doc) {
    if (!s || !doc) return null;
    var wantText = s.text_fp || '';
    var wantTag  = (s.nth_of_type && s.nth_of_type.tag) || '';
    var byCss    = bySelector(s.css_path, doc);
    var byXp     = byXpath(s.xpath, doc);

    if (wantText) {
      // 1. Positional candidate whose text still matches — highest confidence.
      if (byCss && textFp(byCss) === wantText) return byCss;
      if (byXp  && textFp(byXp)  === wantText) return byXp;
      // 2. Pure text match among same-tag elements (recovers from reorder).
      var t = byText(wantTag, wantText, doc);
      if (t) return t;
      // 3. No text confirmation anywhere — the text was likely edited; fall
      //    back to the positional selector as a best guess.
      return byCss || byXp || null;
    }
    // No text fingerprint captured — positional only.
    return byCss || byXp || null;
  }

  function labelFor(el) {
    if (!el || el.nodeType !== 1) return '';
    var tag = el.tagName.toLowerCase();
    var role = el.getAttribute && el.getAttribute('role');
    var txt = (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 40);
    var kind = ({
      a: 'Link', button: 'Button', img: 'Image', h1: 'Heading', h2: 'Heading',
      h3: 'Heading', h4: 'Heading', p: 'Text', ul: 'List', ol: 'List',
      input: 'Field', textarea: 'Field', select: 'Field', section: 'Section'
    })[tag] || (role ? (role.charAt(0).toUpperCase() + role.slice(1)) : 'Element');
    return txt ? (kind + ': ' + txt) : kind;
  }

  // ---------------------------------------------------------------------------
  // Generic adapter — the universal floor.
  // ---------------------------------------------------------------------------
  var generic = {
    id: 'generic',
    detect: function () { return true; },
    hasAnchorableContent: function (doc) {
      return !!(doc && doc.body && doc.body.children && doc.body.children.length);
    },
    closest: function (node) {
      if (!node || node.nodeType !== 1) return null;
      if (isOwnNode(node)) return null;
      // Walk up out of any of our own overlay nodes.
      var n = node;
      while (n && n.nodeType === 1 && isOwnNode(n)) n = n.parentElement;
      return n && n.nodeType === 1 ? n : null;
    },
    elementId: function () { return ''; },
    strategies: function (el, doc) { return buildStrategies(el, doc); },
    resolve: function (anchor, doc) { return resolveStrategies(anchor && anchor.strategies, doc); },
    deepLinkHash: function () { return ''; },
    label: labelFor
  };

  // ---------------------------------------------------------------------------
  // Bricks adapter — native ids (brxe-*), with the generic cascade as fallback.
  // ---------------------------------------------------------------------------
  var bricks = {
    id: 'bricks',
    detect: function (doc) {
      return !!(doc && doc.querySelector && doc.querySelector('[id^="brxe-"]'));
    },
    hasAnchorableContent: function (doc) { return this.detect(doc); },
    closest: function (node) {
      return (node && node.closest) ? node.closest('[id^="brxe-"]') : null;
    },
    elementId: function (el) {
      return (el && el.id) ? el.id.replace('brxe-', '') : '';
    },
    strategies: function (el, doc) { return buildStrategies(el, doc); },
    resolve: function (anchor, doc) {
      if (!anchor || !doc) return null;
      if (anchor.element_id) {
        var el = doc.getElementById('brxe-' + anchor.element_id);
        if (el) return el;
      }
      // Native id stale — fall back to the generic cascade if we stored one.
      return resolveStrategies(anchor.strategies, doc);
    },
    deepLinkHash: function (anchor) {
      var id = anchor && anchor.element_id ? anchor.element_id
             : (typeof anchor === 'string' ? anchor : '');
      return id ? '#brxe-' + id : '';
    },
    label: labelFor
  };

  // ---------------------------------------------------------------------------
  // Elementor adapter — native ids via data-id on .elementor-element, with the
  // generic cascade as fallback. Elementor doesn't set DOM ids on elements by
  // default, so there's no reliable in-page hash for cross-page deep-links;
  // navigation just opens the page (same as the generic floor).
  // ---------------------------------------------------------------------------
  var elementor = {
    id: 'elementor',
    detect: function (doc) {
      return !!(doc && doc.querySelector && (
        doc.querySelector('.elementor-element[data-id]') ||
        doc.querySelector('[data-elementor-type]')
      ));
    },
    hasAnchorableContent: function (doc) {
      return !!(doc && doc.querySelector && doc.querySelector('.elementor-element[data-id]'));
    },
    closest: function (node) {
      return (node && node.closest) ? node.closest('.elementor-element[data-id]') : null;
    },
    elementId: function (el) {
      return (el && el.getAttribute) ? (el.getAttribute('data-id') || '') : '';
    },
    strategies: function (el, doc) { return buildStrategies(el, doc); },
    resolve: function (anchor, doc) {
      if (!anchor || !doc) return null;
      if (anchor.element_id) {
        var sel = '.elementor-element[data-id="' + cssEscape(anchor.element_id) + '"]';
        var el;
        try { el = doc.querySelector(sel); } catch (e) { el = null; }
        if (el) return el;
      }
      return resolveStrategies(anchor.strategies, doc);
    },
    deepLinkHash: function () { return ''; },
    label: function (el) {
      if (el && el.getAttribute) {
        var wt = el.getAttribute('data-widget_type');
        if (wt) {
          var kind = wt.split('.')[0].replace(/[-_]/g, ' ');
          return kind.charAt(0).toUpperCase() + kind.slice(1);
        }
        var et = el.getAttribute('data-element_type');
        if (et) return et.charAt(0).toUpperCase() + et.slice(1);
      }
      return labelFor(el);
    }
  };

  // ---------------------------------------------------------------------------
  // Gutenberg adapter — the block editor has no STABLE per-block id (clientId is
  // editor-only + regenerates each load; the frontend has none at all), so we
  // anchor purely with the generic cascade. Detection + a block-aware label give
  // it a native identity; the editor host maps a resolved node → its live
  // [data-block] clientId to select the block.
  // ---------------------------------------------------------------------------
  var gutenberg = {
    id: 'gutenberg',
    detect: function (doc) {
      return !!(doc && doc.querySelector && (
        doc.querySelector('[data-block]') ||          // editor canvas (clientId wrapper)
        doc.querySelector('[class*="wp-block-"]')     // rendered frontend
      ));
    },
    hasAnchorableContent: function (doc) {
      return this.detect(doc);
    },
    closest: function (node) {
      if (!node || !node.closest) return null;
      return node.closest('[data-block]') || node.closest('[class*="wp-block-"]');
    },
    elementId: function () { return ''; },            // no stable id → use strategies
    strategies: function (el, doc) { return buildStrategies(el, doc); },
    resolve: function (anchor, doc) { return resolveStrategies(anchor && anchor.strategies, doc); },
    deepLinkHash: function () { return ''; },
    label: function (el) {
      if (el && el.getAttribute) {
        var dt = el.getAttribute('data-type');        // editor: "core/heading"
        if (dt) { var n = dt.split('/').pop().replace(/[-_]/g, ' '); return n.charAt(0).toUpperCase() + n.slice(1); }
        var cls = (el.className || '').match(/wp-block-([a-z0-9-]+)/); // frontend
        if (cls) { var k = cls[1].replace(/-/g, ' '); return k.charAt(0).toUpperCase() + k.slice(1); }
      }
      return labelFor(el);
    }
  };

  // ---------------------------------------------------------------------------
  // Registry. Builder adapters are tried in registration order; detection is
  // mostly mutually exclusive, and generic is always the floor. (On a mixed
  // page — e.g. a Bricks theme wrapping Gutenberg content — the first match
  // wins; the generic cascade still anchors elements the active adapter's
  // closest() doesn't recognise.)
  // ---------------------------------------------------------------------------
  var registry = [bricks, elementor, gutenberg];

  window.DxfAnchors = {
    generic: generic,
    register: function (adapter) {
      if (adapter && adapter.id) registry.unshift(adapter);
    },
    get: function (id) {
      if (id === 'generic') return generic;
      for (var i = 0; i < registry.length; i++) {
        if (registry[i].id === id) return registry[i];
      }
      return null;
    },
    // Pick the active adapter for a canvas. `forcedId` lets an editor host
    // (e.g. the Bricks builder) pin the choice; otherwise we sniff the DOM.
    resolve: function (doc, forcedId) {
      // An explicit choice from an editor host is authoritative; if we don't
      // have that adapter, drop to the generic floor rather than sniffing.
      if (forcedId) {
        return this.get(forcedId) || generic;
      }
      for (var i = 0; i < registry.length; i++) {
        try { if (registry[i].detect(doc)) return registry[i]; } catch (e) {}
      }
      return generic;
    },
    // Resolve the best adapter + anchorable element for a CLICKED node. On a
    // mixed page (e.g. a Bricks theme wrapping Gutenberg content) the page-level
    // active adapter is too coarse — a Gutenberg block has no brxe- ancestor, so
    // the Bricks adapter's closest() returns null and we'd capture nothing.
    // Trying each builder's closest() in turn tags every pin with the builder
    // that actually owns the element, and falls back to the generic cascade so
    // ANY element is anchorable. An editor host pins the choice via forcedId.
    matchElement: function (node, doc, forcedId) {
      if (forcedId) {
        var fa = this.get(forcedId) || generic;
        return { adapter: fa, el: fa.closest(node, doc) };
      }
      for (var i = 0; i < registry.length; i++) {
        try {
          var el = registry[i].closest(node, doc);
          if (el) return { adapter: registry[i], el: el };
        } catch (e) {}
      }
      return { adapter: generic, el: generic.closest(node, doc) };
    },
    // Shared helpers exposed for future adapters that want the generic cascade.
    _strategies: buildStrategies,
    _resolveStrategies: resolveStrategies,
    _label: labelFor
  };
})();
