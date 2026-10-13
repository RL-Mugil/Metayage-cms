(function () {
  'use strict';
  var api = document.querySelector('meta[name="api-base"]').getAttribute('content');
  var token = null;
  try { token = window.sessionStorage.getItem('cpi_admin_token'); } catch (e) { token = null; }
  if (!token) { window.location.replace('/login'); return; }

  function $(id) { return document.getElementById(id); }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = String(text); }
    return n;
  }
  function signOut(callApi) {
    var done = function () {
      try { window.sessionStorage.removeItem('cpi_admin_token'); window.sessionStorage.removeItem('cpi_admin_name'); } catch (e) { /* ignore */ }
      window.location.replace('/login');
    };
    if (!callApi) { done(); return; }
    call('POST', '/cpi-admin/logout').then(done, done);
  }
  function call(method, path, body) {
    return fetch(api + path, {
      method: method,
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (res) {
      if (res.status === 401 || res.status === 403) { signOut(false); throw new Error('Session ended'); }
      if (!res.ok) { throw new Error('Request failed (' + res.status + ')'); }
      return res.json();
    });
  }

  // Server timestamps are UTC; show IST.
  function ist(iso) {
    return new Date(iso).toLocaleString('en-GB', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true }) + ' IST';
  }

  window.ADM = { call: call, el: el, ist: ist, $: $ };
  try { $('who').textContent = window.sessionStorage.getItem('cpi_admin_name') || ''; } catch (e) { /* ignore */ }
  $('logout').addEventListener('click', function () { signOut(true); });
  $('refresh').addEventListener('click', loadAll);
  ['f-source', 'f-status', 'f-mail', 'f-type'].forEach(function (id) { $(id).addEventListener('change', loadRows); });

  function renderOverview(o) {
    var cards = $('cards');
    cards.textContent = '';
    [['New', o.totals.new, o.totals.new > 0], ['Last 7 days', o.totals.last_7_days], ['All time', o.totals.all],
     ['CanPatentIt', o.totals.canpatentit], ['MyIPStrategy', o.totals.myipstrategy], ['Self-checks', o.totals.quiz], ['Upcoming bookings', o.totals.bookings_upcoming, o.totals.bookings_upcoming > 0], ['Articles live', o.totals.articles_published],
     ['Email failed', o.totals.mail_failed, o.totals.mail_failed > 0, true]
    ].forEach(function (c) {
      var d = el('div', 'adm-card' + (c[2] ? (c[3] ? ' bad' : ' hot') : ''));
      d.appendChild(el('span', 'adm-card-n', c[1]));
      d.appendChild(el('span', 'adm-card-l', c[0]));
      cards.appendChild(d);
    });

    var chart = $('chart');
    chart.textContent = '';
    var max = Math.max.apply(null, o.per_day.map(function (d) { return d.count; }).concat([1]));
    o.per_day.forEach(function (d) {
      var col = el('div', 'adm-bar-col');
      var bar = el('div', 'adm-bar-fill');
      bar.style.height = Math.max(2, Math.round((d.count / max) * 100)) + '%';
      bar.title = d.date + ': ' + d.count;
      col.appendChild(el('span', 'adm-bar-n', d.count));
      col.appendChild(bar);
      col.appendChild(el('span', 'adm-bar-d', d.date.slice(8)));
      chart.appendChild(col);
    });

    var h = $('health');
    h.textContent = '';
    function item(ok, text) { var li = el('li', ok ? 'ok' : 'warn'); li.appendChild(el('b', null, ok ? 'OK' : 'CHECK')); li.appendChild(document.createTextNode(' ' + text)); h.appendChild(li); }
    item(!!o.health.recipient, o.health.recipient ? 'Enquiries are emailed to ' + o.health.recipient : 'No recipient configured: enquiries are saved here but not emailed');
    item(o.health.mailer && o.health.mailer !== 'log' && o.health.mailer !== 'array', 'Mail transport: ' + o.health.mailer);
    item(o.health.turnstile, o.health.turnstile ? 'Bot check (Turnstile) is enabled' : 'Bot check (Turnstile) is not enabled; honeypot and rate limits are active');
    item(o.totals.mail_failed === 0, o.totals.mail_failed === 0 ? 'No failed email deliveries' : o.totals.mail_failed + ' enquiries failed to email: read them below');
  }

  function renderRows(rows) {
    var body = $('rows');
    body.textContent = '';
    $('empty').hidden = rows.length > 0;
    rows.forEach(function (r) {
      var tr = el('tr');
      tr.appendChild(el('td', 'nowrap', ist(r.created_at)));
      tr.appendChild(el('td', null, r.source === 'canpatentit' ? 'CanPatentIt' : 'MyIPStrategy'));

      var from = el('td');
      from.appendChild(el('strong', null, r.name));
      from.appendChild(el('br'));
      var a = el('a', null, r.email);
      a.href = 'mailto:' + encodeURIComponent(r.email);
      from.appendChild(a);
      if (r.organization) { from.appendChild(el('br')); from.appendChild(el('span', 'adm-muted', r.organization)); }
      tr.appendChild(from);

      var det = el('td', 'adm-msg');
      var meta = [r.type !== 'enquiry' ? r.type.toUpperCase() : '', r.stage, r.domain].filter(Boolean).join(' · ');
      if (meta) { det.appendChild(el('span', 'adm-muted', meta)); det.appendChild(el('br')); }
      det.appendChild(document.createTextNode(r.message));
      tr.appendChild(det);

      var mail = el('td');
      mail.appendChild(el('span', 'adm-pill ' + (r.mail_status === 'sent' ? 'ok' : 'warn'), r.mail_status));
      if (r.mail_error) { mail.title = r.mail_error; }
      tr.appendChild(mail);

      var st = el('td');
      var btn = el('button', 'adm-btn', r.status === 'new' ? 'Mark handled' : 'Reopen');
      btn.type = 'button';
      btn.addEventListener('click', function () {
        btn.disabled = true;
        call('PATCH', '/cpi-admin/enquiries/' + r.id, { status: r.status === 'new' ? 'handled' : 'new' }).then(loadAll, function (e) { $('page-error').textContent = e.message; btn.disabled = false; });
      });
      st.appendChild(el('span', 'adm-pill ' + (r.status === 'new' ? 'hot' : ''), r.status));
      st.appendChild(el('br'));
      st.appendChild(btn);
      tr.appendChild(st);
      body.appendChild(tr);
    });
  }

  function loadRows() {
    var q = [];
    [['source', 'f-source'], ['status', 'f-status'], ['mail', 'f-mail'], ['type', 'f-type']].forEach(function (p) { var v = $(p[1]).value; if (v) { q.push(p[0] + '=' + encodeURIComponent(v)); } });
    return call('GET', '/cpi-admin/enquiries' + (q.length ? '?' + q.join('&') : '')).then(renderRows);
  }
  function loadAll() {
    $('page-error').textContent = '';
    return Promise.all([call('GET', '/cpi-admin/overview').then(renderOverview), loadRows()]).catch(function (e) { $('page-error').textContent = e.message; });
  }
  // Tabs: panes are plain divs; other scripts listen for the "adm-tab" event to load their data.
  Array.prototype.forEach.call(document.querySelectorAll('.adm-tab'), function (t) {
    t.addEventListener('click', function () {
      var name = t.getAttribute('data-tab');
      Array.prototype.forEach.call(document.querySelectorAll('.adm-tab'), function (x) { x.classList.toggle('on', x === t); x.setAttribute('aria-selected', x === t ? 'true' : 'false'); });
      Array.prototype.forEach.call(document.querySelectorAll('[data-pane]'), function (p) { p.hidden = p.getAttribute('data-pane') !== name; });
      document.dispatchEvent(new CustomEvent('adm-tab', { detail: name }));
    });
  });

  $('export').addEventListener('click', function () {
    fetch(api + '/cpi-admin/enquiries/export', { headers: { 'Authorization': 'Bearer ' + token } })
      .then(function (r) { if (!r.ok) { throw new Error('Export failed'); } return r.blob(); })
      .then(function (b) {
        var a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = 'enquiries.csv';
        document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
      })
      .catch(function (e) { $('page-error').textContent = e.message; });
  });

  loadAll();
})();
