(function () {
  'use strict';
  var art = document.querySelector('.cpi-patent');
  var grid = document.getElementById('rec-grid');
  var section = document.getElementById('recs');
  if (!art) { return; }
  initFigures();
  if (!grid || !section) { return; }
  var slug = art.getAttribute('data-slug');
  var domain = art.getAttribute('data-domain');
  var country = art.getAttribute('data-country');

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }

  // Figure strip: scroll arrows (only when it overflows) and click-to-enlarge.
  function initFigures() {
    var strip = document.querySelector('.cpi-figstrip');
    if (!strip) { return; }
    var wrap = document.createElement('div');
    wrap.className = 'cpi-figwrap';
    strip.parentNode.insertBefore(wrap, strip);
    wrap.appendChild(strip);
    var figs = Array.prototype.slice.call(strip.querySelectorAll('.cpi-fig'));

    function nav(dir, label) {
      var b = el('button', 'cpi-fig-nav ' + dir, dir === 'prev' ? '\u2039' : '\u203a');
      b.type = 'button'; b.setAttribute('aria-label', label);
      b.addEventListener('click', function () { strip.scrollBy({ left: (dir === 'prev' ? -1 : 1) * Math.max(240, strip.clientWidth * 0.8), behavior: 'smooth' }); });
      wrap.appendChild(b);
      return b;
    }
    var prev = nav('prev', 'Scroll figures left'), next = nav('next', 'Scroll figures right');
    function update() {
      var over = strip.scrollWidth > strip.clientWidth + 2;
      wrap.classList.toggle('is-overflowing', over);
      prev.disabled = strip.scrollLeft <= 2;
      next.disabled = strip.scrollLeft + strip.clientWidth >= strip.scrollWidth - 2;
    }
    strip.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    Array.prototype.forEach.call(strip.querySelectorAll('img'), function (im) { im.addEventListener('load', update); });
    update();
    // Arrow keys scroll the focused strip.
    strip.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { e.preventDefault(); next.click(); } else if (e.key === 'ArrowLeft') { e.preventDefault(); prev.click(); }
    });

    // Lightbox: click a figure to see it full size.
    if (typeof HTMLDialogElement === 'undefined') { return; }
    var dlg = document.createElement('dialog');
    dlg.className = 'cpi-lightbox';
    dlg.setAttribute('aria-label', 'Patent figure');
    var body = el('div', 'cpi-lightbox-body');
    var big = document.createElement('img');
    var cap = el('div', 'cpi-lightbox-cap');
    var close = el('button', 'cpi-lightbox-btn close', '\u00d7'); close.type = 'button'; close.setAttribute('aria-label', 'Close');
    var lp = el('button', 'cpi-lightbox-btn prev', '\u2039'); lp.type = 'button'; lp.setAttribute('aria-label', 'Previous figure');
    var ln = el('button', 'cpi-lightbox-btn next', '\u203a'); ln.type = 'button'; ln.setAttribute('aria-label', 'Next figure');
    [big, cap, close, lp, ln].forEach(function (n) { body.appendChild(n); });
    dlg.appendChild(body);
    document.body.appendChild(dlg);
    var idx = 0;
    function show(i) {
      idx = (i + figs.length) % figs.length;
      var im = figs[idx].querySelector('img');
      big.src = im.currentSrc || im.src; big.alt = im.alt;
      cap.textContent = figs[idx].querySelector('figcaption').textContent;
      lp.disabled = ln.disabled = figs.length < 2;
    }
    figs.forEach(function (f, i) {
      f.querySelector('.cpi-fig-open').addEventListener('click', function () { show(i); if (dlg.showModal) { dlg.showModal(); } });
    });
    close.addEventListener('click', function () { dlg.close(); });
    lp.addEventListener('click', function () { show(idx - 1); });
    ln.addEventListener('click', function () { show(idx + 1); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) { dlg.close(); } });
    dlg.addEventListener('keydown', function (e) { if (e.key === 'ArrowLeft') { show(idx - 1); } else if (e.key === 'ArrowRight') { show(idx + 1); } });
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

  // Recommended patents are text-only cards: domain, title, country badge and a View Patent button.
  function card(p) {
    var box = el('div', 'cpi-rcard');
    box.appendChild(el('p', 'cpi-rcard-domain ' + domainClass(p.domain), p.domain));
    box.appendChild(el('h3', null, p.title));
    var country = el('p', 'cpi-rcard-country');
    country.appendChild(el('span', 'cpi-globe'));
    country.appendChild(el('b', null, p.country));
    box.appendChild(country);
    var btn = el('a', 'cpi-rcard-btn', 'View Patent');
    btn.href = '/patent-showcase/' + encodeURIComponent(p.slug);
    btn.setAttribute('aria-label', 'View patent: ' + p.title);
    box.appendChild(btn);
    return box;
  }

  fetch('/patent-showcase/index.json', { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.ok ? r.json() : []; })
    .catch(function () { return []; })
    .then(function (items) {
      var others = items.filter(function (p) { return p.slug !== slug; });
      // Similar domain first, then the same country, then anything else; three at most.
      var score = function (p) { return (p.domain === domain ? 2 : 0) + (p.country === country ? 1 : 0); };
      others.sort(function (a, b) { return score(b) - score(a); });
      var picks = others.slice(0, 3);
      if (!picks.length) { return; }
      picks.forEach(function (p) { grid.appendChild(card(p)); });
      section.hidden = false;
    });
})();
