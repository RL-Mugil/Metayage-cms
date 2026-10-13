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

  // Recommended patents are text-only cards: domain, title, country badge and a View Patent button.
  function card(p) {
    var box = el('div', 'cpi-rcard');
    box.appendChild(el('p', 'cpi-rcard-domain', p.domain));
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
