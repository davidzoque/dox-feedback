/* Dox Feedback settings-screen helpers — dev-tools seeder, bug callout dismiss,
   telemetry opt-in. Nonces + ajax URL arrive via wp_localize_script as
   window.dxfSettings. Each block bails quietly when its UI is absent. */
(function () {
    var cfg = window.dxfSettings || {};
    var I18N = (window.dxfSettings && window.dxfSettings.i18n) || {};
    function t(k, fb){ var v = I18N[k]; return (v === undefined || v === null || v === '') ? fb : v; }

    // --- Dev tools: dummy-content seeder (non-production screens only) -------
    (function () {
        var result = document.getElementById('dxf-seed-result');
        if (!result) return;

        function runSeed(btn, action) {
            var postId = document.getElementById('dxf-seed-post').value;
            var count  = document.getElementById('dxf-seed-count').value;
            if (!postId) { result.textContent = t('set.seed_need_id', 'Enter a post or page ID first.'); result.style.color = '#b32d2e'; return; }
            btn.disabled = true; result.textContent = t('set.seeding', 'Seeding…'); result.style.color = '';
            var fd = new FormData();
            fd.append('action', action);
            fd.append('_wpnonce', cfg.seedNonce);
            fd.append('post_id', postId);
            fd.append('count', count);
            fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (d) {
                  btn.disabled = false;
                  if (d && d.success) {
                      var msg = (d.data && d.data.message) || t('set.seeded_count', 'Seeded %d comments.').replace('%d', (d.data && d.data.inserted));
                      if (d.data && d.data.url) {
                          result.innerHTML = '';
                          result.appendChild(document.createTextNode(msg + ' '));
                          var a = document.createElement('a');
                          a.href = d.data.url;
                          a.target = '_blank';
                          a.rel = 'noopener';
                          a.textContent = t('set.open_review', 'Open review →');
                          result.appendChild(a);
                      } else {
                          result.textContent = msg;
                      }
                      result.style.color = '#1f7a3c';
                  } else {
                      result.textContent = (d && d.data && d.data.message) || t('set.seed_failed', 'Seed failed.');
                      result.style.color = '#b32d2e';
                  }
              })
              .catch(function () { btn.disabled = false; result.textContent = t('set.network_error', 'Network error.'); result.style.color = '#b32d2e'; });
        }

        var btn       = document.getElementById('dxf-seed-btn');
        var reviewBtn = document.getElementById('dxf-seed-review-btn');
        if (btn)       btn.addEventListener('click',       function () { runSeed(btn,       'dxf_seed_dummy'); });
        if (reviewBtn) reviewBtn.addEventListener('click', function () { runSeed(reviewBtn, 'dxf_seed_dummy_review'); });
    })();

    // --- Dismissible "report a bug" callout ---------------------------------
    (function () {
        var btn = document.getElementById('dxf-bug-callout-dismiss');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('action', 'dxf_dismiss_bug_callout');
            fd.append('_wpnonce', cfg.bugNonce);
            fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
              .finally(function () { var el = document.getElementById('dxf-bug-callout'); if (el) el.remove(); });
        });
    })();

    // --- One-time telemetry opt-in banner ----------------------------------
    (function () {
        function post(optIn) {
            var fd = new FormData();
            fd.append('action', 'dxf_telemetry_optin');
            fd.append('_wpnonce', cfg.telemetryNonce);
            if (optIn) fd.append('opt_in', '1');
            fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
              .finally(function () {
                  var el = document.getElementById('dxf-telemetry-callout');
                  if (el) el.remove();
              });
        }
        var ok = document.getElementById('dxf-tel-allow');
        var no = document.getElementById('dxf-tel-decline');
        if (ok) ok.addEventListener('click', function () { post(true); });
        if (no) no.addEventListener('click', function () { post(false); });
    })();
})();
