/*!
 * TLDers UI: makes price lists interactive (shared by the PHP script and the WordPress plugin).
 * Progressive enhancement: pages work without it; with it, lists sort instantly, switch between
 * list and grid, filter as you type, collapse long lists, and search results load live.
 *
 * Markup contract (server-rendered):
 *   [data-tlders-list="offers|tlds"]          a list; optional data-limit="10"
 *     [data-sort="register|renew|transfer|name"]  sort controls (links or buttons)
 *     [data-view="list|grid"]                 view toggles (start hidden)
 *     input[data-filter]                      filter box (starts hidden)
 *     [data-items] > [data-item]              items with data-name, data-register,
 *                                             data-renew, data-transfer (numbers or "")
 *       [data-bar]                            price bar, resized to the chosen sort
 *     [data-more]  [data-empty]               "show all" button, "no match" note (hidden)
 *   form[data-tlders-live][data-endpoint]     live search; results go in the element
 *                                             referenced by data-target (a CSS selector).
 *                                             The endpoint returns HTML, or JSON {"html": "..."}
 */
(function () {
  'use strict';

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function store(key, value) {
    try {
      if (value === undefined) return window.localStorage.getItem(key);
      window.localStorage.setItem(key, value);
    } catch (e) { /* storage blocked: preferences just aren't remembered */ }
    return null;
  }

  function num(el, key) {
    var v = el.getAttribute('data-' + key);
    return v === null || v === '' ? null : parseFloat(v);
  }

  // Animate items from their old to their new positions (FLIP).
  function flip(items, mutate) {
    if (reduceMotion) { mutate(); return; }
    var before = items.map(function (el) { return el.getBoundingClientRect(); });
    mutate();
    items.forEach(function (el, i) {
      var after = el.getBoundingClientRect();
      var dx = before[i].left - after.left, dy = before[i].top - after.top;
      if (!dx && !dy) return;
      el.style.transition = 'none';
      el.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
      requestAnimationFrame(function () {
        requestAnimationFrame(function () {
          el.style.transition = 'transform .32s cubic-bezier(.2,.7,.2,1)';
          el.style.transform = '';
        });
      });
    });
  }

  function initList(root) {
    if (root.__tldersReady) return;
    root.__tldersReady = true;

    var box = root.querySelector('[data-items]');
    if (!box) return;
    var items = Array.prototype.slice.call(box.querySelectorAll('[data-item]'));
    var limit = parseInt(root.getAttribute('data-limit') || '0', 10);
    var kind = root.getAttribute('data-tlders-list');
    var filterInput = root.querySelector('input[data-filter]');
    var more = root.querySelector('[data-more]');
    var empty = root.querySelector('[data-empty]');
    var sortControls = Array.prototype.slice.call(root.querySelectorAll('[data-sort]'));
    var viewControls = Array.prototype.slice.call(root.querySelectorAll('[data-view]'));
    var expanded = false;
    var activeControl = sortControls.filter(function (c) {
      return c.getAttribute('aria-pressed') === 'true' || c.classList.contains('is-active');
    })[0] || sortControls[0];
    var current = activeControl ? activeControl.getAttribute('data-sort') : 'register';

    function compare(a, b) {
      if (current === 'name') return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
      var x = num(a, current), y = num(b, current);
      if (x === y) return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
      if (x === null) return 1;
      if (y === null) return -1;
      return x - y;
    }

    function updateBars() {
      if (current === 'name') return;
      var max = 0;
      items.forEach(function (el) { var v = num(el, current); if (v !== null && v > max) max = v; });
      items.forEach(function (el) {
        var bar = el.querySelector('[data-bar]');
        if (!bar) return;
        var v = num(el, current);
        bar.style.width = (max > 0 && v !== null ? Math.max(4, Math.round(100 * v / max)) : 0) + '%';
      });
    }

    function apply() {
      var q = filterInput ? filterInput.value.trim().toLowerCase().replace(/^\./, '') : '';
      var shown = 0;
      items.forEach(function (el) {
        var match = !q || el.getAttribute('data-name').toLowerCase().indexOf(q) !== -1;
        var withinLimit = q || expanded || !limit || shown < limit;
        el.hidden = !(match && withinLimit);
        if (match) shown++;
      });
      var matches = items.filter(function (el) {
        return !q || el.getAttribute('data-name').toLowerCase().indexOf(q) !== -1;
      }).length;
      if (more) {
        more.hidden = !!q || expanded || !limit || matches <= limit;
        var count = more.querySelector('[data-count]');
        if (count) count.textContent = String(matches);
      }
      if (empty) empty.hidden = matches > 0;
    }

    function sortBy(key, animate) {
      current = key;
      sortControls.forEach(function (c) {
        var on = c.getAttribute('data-sort') === key;
        c.classList.toggle('is-active', on);
        c.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      root.setAttribute('data-sorted-by', key);
      var sorted = items.slice().sort(compare);
      var doIt = function () { sorted.forEach(function (el) { box.appendChild(el); }); items = sorted; apply(); updateBars(); };
      if (animate) flip(items.filter(function (el) { return !el.hidden; }), doIt); else doIt();
    }

    function setView(view, save) {
      root.classList.toggle('is-grid', view === 'grid');
      viewControls.forEach(function (c) {
        var on = c.getAttribute('data-view') === view;
        c.classList.toggle('is-active', on);
        c.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      if (save) store('tlders-view-' + kind, view);
    }

    sortControls.forEach(function (c) {
      c.addEventListener('click', function (e) {
        e.preventDefault(); // links are the no-JS fallback
        sortBy(c.getAttribute('data-sort'), true);
      });
    });
    viewControls.forEach(function (c) {
      c.hidden = false;
      c.addEventListener('click', function () { setView(c.getAttribute('data-view'), true); });
    });
    if (filterInput) {
      filterInput.hidden = false;
      filterInput.addEventListener('input', apply);
    }
    if (more) {
      more.addEventListener('click', function () { expanded = true; apply(); });
    }
    var toolbarExtras = root.querySelectorAll('[data-js-only]');
    Array.prototype.forEach.call(toolbarExtras, function (el) { el.hidden = false; });

    var savedView = store('tlders-view-' + kind);
    if (savedView === 'grid' || savedView === 'list') setView(savedView, false);
    sortBy(current, false);
  }

  function initLive(form) {
    if (form.__tldersReady) return;
    form.__tldersReady = true;
    var input = form.querySelector('input[type="search"], input[name="q"], input[name="tlders_q"]');
    var target = document.querySelector(form.getAttribute('data-target'));
    var endpoint = form.getAttribute('data-endpoint');
    var param = input && input.getAttribute('name');
    if (!input || !target || !endpoint) return;
    var timer, controller, last = '';

    function looksSearchable(v) {
      v = v.trim();
      if (v.length < 2) return false;
      return /^\.?[a-z0-9-]+(\.[a-z0-9-]{2,})*$/i.test(v.replace(/^https?:\/\//i, '').replace(/^www\./i, '').replace(/\/.*$/, ''));
    }

    function run(v) {
      if (v === last) return;
      last = v;
      if (controller) controller.abort();
      controller = window.AbortController ? new AbortController() : null;
      form.classList.add('is-loading');
      var url = endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + encodeURIComponent(param) + '=' + encodeURIComponent(v);
      fetch(url, { headers: { Accept: 'text/html' }, signal: controller ? controller.signal : undefined, credentials: 'same-origin' })
        .then(function (r) {
          if (!(r.ok || r.status === 404 || r.status === 400 || r.status === 403)) return Promise.reject(r);
          // The PHP script answers with HTML; WordPress's REST API with {"html": "..."}.
          return (r.headers.get('content-type') || '').indexOf('json') !== -1
            ? r.json().then(function (j) { return j && j.html ? j.html : ''; })
            : r.text();
        })
        .then(function (html) {
          target.innerHTML = html;
          target.hidden = false;
          init(target);
          if (window.history && history.replaceState) {
            var u = new URL(window.location.href);
            u.searchParams.set(param, v);
            history.replaceState(null, '', u.toString());
          }
        })
        .catch(function () { /* aborted or offline: keep what's shown */ })
        .then(function () { form.classList.remove('is-loading'); });
    }

    input.addEventListener('input', function () {
      clearTimeout(timer);
      var v = input.value.trim();
      if (!looksSearchable(v)) return;
      timer = setTimeout(function () { run(v); }, 450);
    });
    form.addEventListener('submit', function (e) {
      var v = input.value.trim();
      if (!looksSearchable(v)) return; // let the browser show its own validation
      e.preventDefault();
      clearTimeout(timer);
      last = '';
      run(v);
    });
  }

  function init(scope) {
    scope = scope || document;
    Array.prototype.forEach.call(scope.querySelectorAll('[data-tlders-list]'), initList);
    Array.prototype.forEach.call(scope.querySelectorAll('form[data-tlders-live]'), initLive);
  }

  window.TLDersUI = { init: init };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(); });
  else init();
})();
