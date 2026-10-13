(function () {
  'use strict';
  var art = document.querySelector('.cpi-patent');
  var grid = document.getElementById('rec-grid');
  var section = document.getElementById('recs');
  if (!art || !grid || !section) { return; }
  var slug = art.getAttribute('data-slug');
  var domain = art.getAttribute('data-domain');
  var country = art.getAttribute('data-country');

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
  function safeImage(src) { return typeof src === 'string' && /^\/insights\/img\/[a-z0-9][a-z0-9._-]*$/i.test(src) ? src : ''; }

  function card(p) {
    var a = el('a', 'cpi-pcard');
    a.href = '/patent-showcase/' + encodeURIComponent(p.slug);
    var fig = el('div', 'cpi-pcard-fig');
    var src = safeImage(p.image);
    if (src) { var img = document.createElement('img'); img.src = src; img.alt = p.image_alt || p.title; img.loading = 'lazy'; fig.appendChild(img); } else { fig.classList.add('is-empty'); }
    a.appendChild(fig);
    var body = el('div', 'cpi-pcard-body');
    var meta = el('div', 'cpi-pcard-meta');
    meta.appendChild(el('span', 'cpi-pcard-domain', p.domain));
    meta.appendChild(el('span', 'cpi-pcard-country', p.country));
    body.appendChild(meta);
    body.appendChild(el('h3', null, p.title));
    body.appendChild(el('span', 'cpi-pcard-id', p.id));
    body.appendChild(el('span', 'cpi-text-link', 'View Patent'));
    a.appendChild(body);
    return a;
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
