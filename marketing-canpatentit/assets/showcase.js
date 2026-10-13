(function () {
  'use strict';
  var grid = document.getElementById('showcase-grid');
  if (!grid) { return; }
  var countryEl = document.getElementById('country-filter');
  var domainEl = document.getElementById('domain-filter');
  var titleEl = document.getElementById('showcase-title');
  var pager = document.getElementById('showcase-pager');
  var PER_PAGE = 9;
  var COUNTRIES = ['India', 'USA', 'Others'];
  var items = [];
  var state = { country: '', domain: '', page: 1 };

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
  function safeImage(src) { return typeof src === 'string' && /^\/insights\/img\/[a-z0-9][a-z0-9._-]*$/i.test(src) ? src : ''; }

  function readUrl() {
    var q = new URLSearchParams(window.location.search);
    var c = q.get('country') || '', d = q.get('domain') || '', p = parseInt(q.get('page') || '1', 10);
    state.country = COUNTRIES.indexOf(c) >= 0 ? c : '';
    state.domain = d;
    state.page = p > 0 ? p : 1;
  }
  function urlFor(s) {
    var q = new URLSearchParams();
    if (s.country) { q.set('country', s.country); }
    if (s.domain) { q.set('domain', s.domain); }
    if (s.page > 1) { q.set('page', String(s.page)); }
    var qs = q.toString();
    return window.location.pathname + (qs ? '?' + qs : '');
  }
  function go(next, push) {
    state = { country: next.country, domain: next.domain, page: next.page };
    render();
    if (push && window.history && window.history.pushState) { window.history.pushState(state, '', urlFor(state)); }
    if (push) {
      var top = document.querySelector('.cpi-showcase');
      if (top) { top.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' }); }
    }
  }

  function filtered(country, domain) {
    return items.filter(function (p) { return (!country || p.country === country) && (!domain || p.domain === domain); });
  }

  function filterButton(label, active, count, onClick) {
    var li = el('li');
    var b = el('button', 'cpi-filter-btn' + (active ? ' is-active' : ''));
    b.type = 'button';
    if (active) { b.setAttribute('aria-current', 'true'); }
    b.appendChild(el('span', null, label));
    var tail = el('span', 'cpi-filter-tail');
    if (typeof count === 'number') { tail.appendChild(el('em', null, String(count))); }
    tail.appendChild(el('i', null, '→'));
    b.appendChild(tail);
    b.addEventListener('click', onClick);
    li.appendChild(b);
    return li;
  }

  // Maps a domain name to one of the icon classes (see .dom-* in pages.css); unknown domains get the generic icon.
  function domainClass(d) {
    var t = String(d || '').toLowerCase();
    var rules = [['artificial', 'ai'], [' ai', 'ai'], ['telecom', 'telecom'], ['electric', 'ev'], ['vehicle', 'ev'], ['health', 'health'], ['crypto', 'crypto'],
      ['3d', 'xr'], ['vr', 'xr'], ['blockchain', 'chain'], ['manufactur', 'factory'], ['business', 'bi'], ['network', 'network'], ['food', 'food'], ['beverage', 'food'],
      ['aerospace', 'aero'], ['aero', 'aero'], ['bio', 'bio'], ['polymer', 'polymer']];
    if (/^ai\b/.test(t)) { return 'dom-ai'; }
    for (var i = 0; i < rules.length; i++) { if (t.indexOf(rules[i][0]) >= 0) { return 'dom-' + rules[i][1]; } }
    return 'dom-default';
  }

  function card(p, eager) {
    var a = el('a', 'cpi-pcard');
    a.href = '/patent-showcase/' + encodeURIComponent(p.slug);
    var fig = el('div', 'cpi-pcard-fig');
    var src = safeImage(p.image);
    if (src) {
      var img = document.createElement('img');
      img.src = src; img.alt = p.image_alt || p.title; img.loading = eager ? 'eager' : 'lazy';
      fig.appendChild(img);
    } else {
      fig.classList.add('is-empty');
    }
    a.appendChild(fig);
    var body = el('div', 'cpi-pcard-body');
    var meta = el('div', 'cpi-pcard-meta');
    meta.appendChild(el('span', 'cpi-pcard-domain ' + domainClass(p.domain), p.domain));
    meta.appendChild(el('span', 'cpi-pcard-country', p.country));
    body.appendChild(meta);
    body.appendChild(el('h3', null, p.title));
    body.appendChild(el('span', 'cpi-pcard-id', p.id));
    body.appendChild(el('span', 'cpi-text-link', 'Know More'));
    a.appendChild(body);
    return a;
  }
  window.CPI_PATENT_CARD = card;

  function renderFilters() {
    countryEl.textContent = '';
    countryEl.appendChild(filterButton('All Countries', !state.country, undefined, function () { go({ country: '', domain: state.domain, page: 1 }, true); }));
    COUNTRIES.forEach(function (c) {
      countryEl.appendChild(filterButton(c, state.country === c, undefined, function () { go({ country: c, domain: state.domain, page: 1 }, true); }));
    });

    domainEl.textContent = '';
    var scope = filtered(state.country, '');
    domainEl.appendChild(filterButton('All Patent Showcase', !state.domain, scope.length, function () { go({ country: state.country, domain: '', page: 1 }, true); }));
    var counts = {};
    scope.forEach(function (p) { counts[p.domain] = (counts[p.domain] || 0) + 1; });
    Object.keys(counts).sort().forEach(function (d) {
      domainEl.appendChild(filterButton(d, state.domain === d, counts[d], function () { go({ country: state.country, domain: d, page: 1 }, true); }));
    });
    // A domain that has no patents under the chosen country still shows as the active filter.
    if (state.domain && !counts[state.domain]) { domainEl.appendChild(filterButton(state.domain, true, 0, function () {})); }
  }

  function pagerItem(label, p, opts) {
    var node = el(opts.disabled || opts.current ? 'span' : 'a', 'cpi-page' + (opts.current ? ' is-current' : '') + (opts.disabled ? ' is-disabled' : ''), label);
    if (opts.current) { node.setAttribute('aria-current', 'page'); }
    if (opts.aria) { node.setAttribute('aria-label', opts.aria); }
    if (!opts.disabled && !opts.current) {
      var next = { country: state.country, domain: state.domain, page: p };
      node.href = urlFor(next);
      node.addEventListener('click', function (ev) { ev.preventDefault(); go(next, true); });
    }
    return node;
  }
  function renderPager(total) {
    pager.textContent = '';
    if (total <= 1) { pager.hidden = true; return; }
    pager.hidden = false;
    pager.appendChild(pagerItem('‹ Prev', state.page - 1, { disabled: state.page === 1, aria: 'Previous page' }));
    var last = 0;
    for (var p = 1; p <= total; p++) {
      if (p === 1 || p === total || Math.abs(p - state.page) <= 1) {
        if (last && p - last > 1) { pager.appendChild(el('span', 'cpi-page-gap', '…')); }
        pager.appendChild(pagerItem(String(p), p, { current: p === state.page, aria: 'Page ' + p }));
        last = p;
      }
    }
    pager.appendChild(pagerItem('Next ›', state.page + 1, { disabled: state.page === total, aria: 'Next page' }));
  }

  function render() {
    var list = filtered(state.country, state.domain);
    var total = Math.max(1, Math.ceil(list.length / PER_PAGE));
    if (state.page > total) { state.page = total; }
    titleEl.textContent = 'Patents related to ' + (state.domain || 'all domains') + ' (' + (state.country || 'all countries') + ')';
    renderFilters();
    grid.textContent = '';
    if (!list.length) {
      grid.appendChild(el('p', 'cpi-fine', items.length ? 'No patents match these filters.' : 'No patents have been added yet. Check back soon.'));
      pager.hidden = true;
      return;
    }
    list.slice((state.page - 1) * PER_PAGE, state.page * PER_PAGE).forEach(function (p, i) { grid.appendChild(card(p, i < 3)); });
    renderPager(total);
  }

  window.addEventListener('popstate', function () { readUrl(); render(); });

  fetch('/patent-showcase/index.json', { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.ok ? r.json() : []; })
    .catch(function () { return []; })
    .then(function (data) { items = data; readUrl(); render(); });
})();
