(function () {
  'use strict';
  var pane = document.getElementById('bookings-pane');
  if (!pane) { return; }
  var loaded = false;
  var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  function A() { return window.ADM; }
  function el(tag, cls, text) { return A().el(tag, cls, text); }
  function setErr(msg) { document.getElementById('page-error').textContent = msg || ''; }

  document.addEventListener('adm-tab', function (e) { if (e.detail === 'bookings' && !loaded) { loaded = true; load(); } });

  function when(iso, tz) { return new Date(iso).toLocaleString('en-GB', { timeZone: tz, weekday: 'short', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true }); }

  function load() {
    A().call('GET', '/cpi-admin/bookings').then(render).catch(function (e) { setErr(e.message); });
  }

  function render(d, note) {
    pane.textContent = '';
    var tz = d.settings.timezone;
    pane.appendChild(el('h2', null, 'Bookings'));
    var wrap = el('div', 'adm-table-wrap'); var t = el('table', 'adm-table');
    t.innerHTML = '<thead><tr><th>When (' + tz + ')</th><th>Who</th><th>Topic</th><th>Status</th><th></th></tr></thead>';
    var tb = el('tbody'); t.appendChild(tb); wrap.appendChild(t); pane.appendChild(wrap);
    if (!d.bookings.length) { var tr0 = el('tr'); var td0 = el('td', 'adm-muted', 'No bookings yet.'); td0.colSpan = 5; tr0.appendChild(td0); tb.appendChild(tr0); }
    d.bookings.forEach(function (b) {
      var tr = el('tr');
      tr.appendChild(el('td', 'nowrap', when(b.start_utc, tz)));
      var who = el('td'); who.appendChild(el('strong', null, b.name)); who.appendChild(el('br'));
      var a = el('a', null, b.email); a.href = 'mailto:' + b.email; who.appendChild(a);
      if (b.organization) { who.appendChild(el('br')); who.appendChild(el('span', 'adm-muted', b.organization)); }
      tr.appendChild(who);
      tr.appendChild(el('td', 'adm-msg', b.topic || ''));
      var st = el('td'); st.appendChild(el('span', 'adm-pill ' + (b.status === 'confirmed' ? 'ok' : 'warn'), b.status)); tr.appendChild(st);
      var act = el('td');
      if (b.status === 'confirmed') {
        var cb = el('button', 'adm-btn', 'Cancel'); cb.type = 'button';
        cb.addEventListener('click', function () {
          if (!window.confirm('Cancel this booking? We will notify you by email; tell the visitor yourself.')) { return; }
          A().call('POST', '/cpi-admin/bookings/' + b.id + '/cancel').then(load).catch(function (e) { setErr(e.message); });
        });
        act.appendChild(cb);
      }
      tr.appendChild(act); tb.appendChild(tr);
    });

    // ---- Availability editor ----
    pane.appendChild(el('h2', null, 'Availability'));
    pane.appendChild(el('p', 'adm-muted', 'Weekly hours are in the booking timezone. Visitors see them converted to their own timezone.'));
    var form = el('div', 'adm-form');
    var rulesBox = el('div', 'adm-rules');
    var rules = d.rules.map(function (r) { return { weekday: r.weekday, start_time: r.start_time, end_time: r.end_time }; });
    function drawRules() {
      rulesBox.textContent = '';
      rules.forEach(function (r, i) {
        var row = el('div', 'adm-rule');
        var sel = el('select'); sel.setAttribute('aria-label', 'Day');
        DAYS.forEach(function (n, k) { var o = el('option', null, n); o.value = k; if (k === r.weekday) { o.selected = true; } sel.appendChild(o); });
        sel.addEventListener('change', function () { r.weekday = parseInt(sel.value, 10); });
        var s = el('input'); s.type = 'time'; s.value = r.start_time; s.setAttribute('aria-label', 'Start'); s.addEventListener('change', function () { r.start_time = s.value; });
        var e = el('input'); e.type = 'time'; e.value = r.end_time; e.setAttribute('aria-label', 'End'); e.addEventListener('change', function () { r.end_time = e.value; });
        var rm = el('button', 'adm-btn', 'Remove'); rm.type = 'button'; rm.addEventListener('click', function () { rules.splice(i, 1); drawRules(); });
        row.appendChild(sel); row.appendChild(s); row.appendChild(document.createTextNode(' to ')); row.appendChild(e); row.appendChild(rm); rulesBox.appendChild(row);
      });
      var add = el('button', 'adm-btn', 'Add hours'); add.type = 'button';
      add.addEventListener('click', function () { rules.push({ weekday: 1, start_time: '10:00', end_time: '17:00' }); drawRules(); });
      rulesBox.appendChild(add);
    }
    drawRules(); form.appendChild(rulesBox);

    var s = d.settings;
    function num(label, key, min, max) { var l = el('label'); l.appendChild(document.createTextNode(label)); var i = el('input'); i.type = 'number'; i.min = min; i.max = max; i.value = s[key]; i.addEventListener('input', function () { s[key] = parseInt(i.value, 10) || 0; }); l.appendChild(i); return l; }
    var cfg = el('div', 'adm-cfg');
    cfg.appendChild(num('Call length (minutes)', 'slot_minutes', 15, 120));
    cfg.appendChild(num('Gap between calls (minutes)', 'buffer_minutes', 0, 60));
    cfg.appendChild(num('Minimum notice (hours)', 'min_notice_hours', 0, 168));
    cfg.appendChild(num('Bookable days ahead', 'horizon_days', 1, 90));
    var tl = el('label'); tl.appendChild(document.createTextNode('Booking timezone (IANA name)')); var ti = el('input'); ti.value = s.timezone; ti.addEventListener('input', function () { s.timezone = ti.value.trim(); }); tl.appendChild(ti); cfg.appendChild(tl);
    form.appendChild(cfg);

    var bo = el('div'); bo.appendChild(el('h3', null, 'Days off'));
    var blackouts = d.blackouts.map(function (b) { return { day: String(b.day).slice(0, 10), note: b.note || '' }; });
    var boList = el('div', 'adm-rules');
    function drawBo() {
      boList.textContent = '';
      blackouts.forEach(function (b, i) {
        var row = el('div', 'adm-rule');
        row.appendChild(el('span', null, b.day + (b.note ? ' · ' + b.note : '')));
        var rm = el('button', 'adm-btn', 'Remove'); rm.type = 'button'; rm.addEventListener('click', function () { blackouts.splice(i, 1); drawBo(); });
        row.appendChild(rm); boList.appendChild(row);
      });
      var date = el('input'); date.type = 'date'; date.setAttribute('aria-label', 'Day off');
      var note = el('input'); note.placeholder = 'Note (optional)'; note.maxLength = 120;
      var add = el('button', 'adm-btn', 'Add day off'); add.type = 'button';
      add.addEventListener('click', function () { if (date.value) { blackouts.push({ day: date.value, note: note.value.trim() }); drawBo(); } });
      var row = el('div', 'adm-rule'); row.appendChild(date); row.appendChild(note); row.appendChild(add); boList.appendChild(row);
    }
    drawBo(); bo.appendChild(boList); form.appendChild(bo);
    pane.appendChild(form);

    var bar = el('div', 'adm-filters'); var save = el('button', 'adm-btn', 'Save availability'); save.type = 'button'; var msg = el('span', 'adm-muted', note || '');
    save.addEventListener('click', function () {
      setErr(''); msg.textContent = 'Saving…';
      A().call('PUT', '/cpi-admin/availability', { rules: rules, blackouts: blackouts, settings: s })
        .then(function (r) { render(r, 'Saved.'); })
        .catch(function (e) { msg.textContent = ''; setErr(e.message); });
    });
    bar.appendChild(save); bar.appendChild(msg); pane.appendChild(bar);
  }
})();
