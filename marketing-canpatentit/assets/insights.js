(function () {
  'use strict';
  var list = document.getElementById('insights-list');
  if (!list) { return; }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }

  // Only same-site, uploaded images may be shown (the server enforces this too).
  function safeImage(src) { return typeof src === 'string' && /^\/insights\/img\/[a-z0-9][a-z0-9._-]*$/i.test(src) ? src : ''; }

  fetch('/insights/index.json', { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.ok ? r.json() : []; })
    .catch(function () { return []; })
    .then(function (items) {
      list.textContent = '';
      list.className = 'cpi-news-grid';
      if (!items.length) { list.appendChild(el('p', 'cpi-fine', 'No articles yet. Check back soon.')); return; }
      items.forEach(function (a, i) {
        var card = el('a', 'cpi-news' + (i === 0 ? ' cpi-news-feature' : ''));
        card.href = '/insights/' + encodeURIComponent(a.slug);

        var media = el('div', 'cpi-news-media');
        var src = safeImage(a.cover);
        if (src) {
          var img = document.createElement('img');
          img.src = src; img.alt = a.cover_alt || a.title; img.loading = i === 0 ? 'eager' : 'lazy';
          media.appendChild(img);
        } else {
          media.classList.add('is-empty');
        }
        card.appendChild(media);

        var body = el('div', 'cpi-news-body');
        body.appendChild(el('time', null, a.date_label || ''));
        body.appendChild(el('h2', null, a.title));
        body.appendChild(el('p', null, a.excerpt || ''));
        body.appendChild(el('span', 'cpi-text-link', 'Read the full article'));
        card.appendChild(body);
        list.appendChild(card);
      });
    });
})();
