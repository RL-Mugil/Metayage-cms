(function () {
  'use strict';
  var list = document.getElementById('insights-list');
  if (!list) { return; }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
  fetch('/insights/index.json', { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.ok ? r.json() : []; })
    .catch(function () { return []; })
    .then(function (items) {
      list.textContent = '';
      if (!items.length) { list.appendChild(el('p', 'cpi-fine', 'No articles yet. Check back soon.')); return; }
      items.forEach(function (a) {
        var card = el('a', 'cpi-card'); card.href = '/insights/' + encodeURIComponent(a.slug);
        var t = el('time', null, a.date_label || ''); card.appendChild(t);
        card.appendChild(el('h3', null, a.title));
        card.appendChild(el('p', null, a.excerpt || ''));
        var more = el('span', 'cpi-text-link', 'Read article'); card.appendChild(more);
        list.appendChild(card);
      });
    });
})();
