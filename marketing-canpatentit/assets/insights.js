(function () {
  'use strict';
  var list = document.getElementById('insights-list');
  if (!list) { return; }
  var bar = document.getElementById('insights-bar');
  var count = document.getElementById('insights-count');
  var pager = document.getElementById('insights-pager');
  var btnGrid = document.getElementById('view-grid');
  var btnList = document.getElementById('view-list');
  // Articles per page. Raise these (for example 9 and 6) once there are plenty of articles.
  var PER_PAGE = { grid: 3, list: 3 };
  var items = [];
  var view = 'grid';
  var page = 1;

  try { var saved = window.localStorage.getItem('cpi_insights_view'); if (saved === 'list' || saved === 'grid') { view = saved; } } catch (e) { /* storage blocked */ }

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
  // Only same-site, uploaded images may be shown (the server enforces this too).
  function safeImage(src) { return typeof src === 'string' && /^\/insights\/img\/[a-z0-9][a-z0-9._-]*$/i.test(src) ? src : ''; }
  function perPage() { return PER_PAGE[view]; }
  function totalPages() { return Math.max(1, Math.ceil(items.length / perPage())); }

  function pageFromUrl() {
    var m = /[?&]page=(\d+)/.exec(window.location.search);
    return m ? Math.max(1, parseInt(m[1], 10)) : 1;
  }
  function urlFor(p) { return p > 1 ? '?page=' + p : window.location.pathname; }

  function dateParts(label) {
    // "13 Oct 2026" -> badge shows the date on two lines for compactness
    return label || '';
  }

  function card(a, i) {
    var link = el('a', 'cpi-post');
    link.href = '/insights/' + encodeURIComponent(a.slug);

    var top = el('div', 'cpi-post-top');
    var mediaWrap = el('div', 'cpi-post-media');
    var src = safeImage(a.cover);
    if (src) {
      var img = document.createElement('img');
      img.src = src; img.alt = a.cover_alt || a.title; img.loading = i < 3 ? 'eager' : 'lazy';
      mediaWrap.appendChild(img);
    } else {
      mediaWrap.classList.add('is-empty');
    }
    top.appendChild(mediaWrap);
    if (a.date_label) { top.appendChild(el('time', 'cpi-post-date', dateParts(a.date_label))); }
    link.appendChild(top);

    var body = el('div', 'cpi-post-body');
    body.appendChild(el('h2', null, a.title));
    body.appendChild(el('p', null, a.excerpt || ''));
    var more = el('span', 'cpi-post-more');
    more.appendChild(document.createTextNode('Read More'));
    var arrow = el('span', 'cpi-post-arrow', '›'); arrow.setAttribute('aria-hidden', 'true');
    more.appendChild(arrow);
    body.appendChild(more);
    link.appendChild(body);
    return link;
  }

  function pagerItem(label, p, opts) {
    opts = opts || {};
    var a = el(opts.disabled || opts.current ? 'span' : 'a', 'cpi-page' + (opts.current ? ' is-current' : '') + (opts.disabled ? ' is-disabled' : ''), label);
    if (opts.current) { a.setAttribute('aria-current', 'page'); }
    if (opts.aria) { a.setAttribute('aria-label', opts.aria); }
    if (!opts.disabled && !opts.current) {
      a.href = urlFor(p);
      a.addEventListener('click', function (ev) { ev.preventDefault(); goTo(p, true); });
    }
    return a;
  }

  function renderPager() {
    var total = totalPages();
    pager.textContent = '';
    if (total <= 1) { pager.hidden = true; return; }
    pager.hidden = false;
    pager.appendChild(pagerItem('‹ Prev', page - 1, { disabled: page === 1, aria: 'Previous page' }));
    var shown = [], last = 0;
    for (var p = 1; p <= total; p++) {
      if (p === 1 || p === total || Math.abs(p - page) <= 1) { shown.push(p); }
    }
    shown.forEach(function (p) {
      if (last && p - last > 1) { pager.appendChild(el('span', 'cpi-page-gap', '…')); }
      pager.appendChild(pagerItem(String(p), p, { current: p === page, aria: 'Page ' + p }));
      last = p;
    });
    pager.appendChild(pagerItem('Next ›', page + 1, { disabled: page === total, aria: 'Next page' }));
  }

  function render() {
    var total = totalPages();
    if (page > total) { page = total; }
    list.textContent = '';
    list.className = 'cpi-post-grid' + (view === 'list' ? ' is-list' : '');
    if (!items.length) { bar.hidden = true; pager.hidden = true; list.appendChild(el('p', 'cpi-fine', 'No articles yet. Check back soon.')); return; }
    bar.hidden = false;
    var start = (page - 1) * perPage();
    var slice = items.slice(start, start + perPage());
    slice.forEach(function (a, i) { list.appendChild(card(a, i)); });
    count.textContent = 'Showing ' + (start + 1) + '–' + (start + slice.length) + ' of ' + items.length + (items.length === 1 ? ' article' : ' articles');
    btnGrid.setAttribute('aria-pressed', view === 'grid' ? 'true' : 'false');
    btnList.setAttribute('aria-pressed', view === 'list' ? 'true' : 'false');
    renderPager();
  }

  function goTo(p, push) {
    page = p;
    render();
    if (push && window.history && window.history.pushState) { window.history.pushState({ page: p }, '', urlFor(p)); }
    var top = document.querySelector('.cpi-insights-wrap');
    if (top && push) { top.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' }); }
  }

  function setView(next) {
    if (next === view) { return; }
    var firstIndex = (page - 1) * perPage();   // keep the same first article on screen
    view = next;
    try { window.localStorage.setItem('cpi_insights_view', view); } catch (e) { /* storage blocked */ }
    page = Math.floor(firstIndex / perPage()) + 1;
    render();
    if (window.history && window.history.replaceState) { window.history.replaceState({ page: page }, '', urlFor(page)); }
  }

  btnGrid.addEventListener('click', function () { setView('grid'); });
  btnList.addEventListener('click', function () { setView('list'); });
  window.addEventListener('popstate', function () { page = pageFromUrl(); render(); });

  fetch('/insights/index.json', { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.ok ? r.json() : []; })
    .catch(function () { return []; })
    .then(function (data) { items = data; page = pageFromUrl(); render(); });
})();
