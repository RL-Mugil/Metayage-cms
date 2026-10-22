/* Manage a booking from the emailed link: see it, move it, or cancel it. The link's #id.token never leaves the browser
   except in the API request that needs it. */
(function () {
  'use strict';
  var root = document.getElementById('manage');
  if (!root) { return; }
  var B = window.CPIBook, el = B.el;
  var api = window.CPI.meta('api-base');
  var $ = function (id) { return document.getElementById(id); };
  var panels = ['manage-loading', 'manage-invalid', 'manage-view', 'manage-resched', 'manage-cancel', 'manage-done'];
  function show(name) { panels.forEach(function (p) { $(p).hidden = p !== name; }); $('mg-zone').hidden = ['manage-view', 'manage-resched'].indexOf(name) === -1; }

  var m = /^#(\d+)\.([A-Za-z0-9]+)$/.exec(window.location.hash || '');
  if (!m) { show('manage-invalid'); return; }
  var id = m[1], token = m[2], base = api + '/public/cpi/manage/' + id + '/' + token;
  var data = null, allSlots = [], chosen = null, day = null, zone = null;
  var wantsCancel = /[?&]action=cancel\b/.test(window.location.search);

  function post(path, body) {
    return fetch(base + path, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(body || {}) })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, status: r.status, d: d }; }); });
  }

  /** Calendar-style tile for the booking in the visitor's zone. */
  function tile() {
    var tz = zone.get(), box = $('manage-tile'); box.textContent = '';
    var mon = el('div', 'cpi-tile-mon', new Date(data.start).toLocaleDateString([], { month: 'short', timeZone: tz }));
    var dayEl = el('div', 'cpi-tile-day', new Date(data.start).toLocaleDateString([], { day: 'numeric', timeZone: tz }));
    var cal = el('div', 'cpi-tile-cal'); cal.appendChild(mon); cal.appendChild(dayEl);
    var info = el('div', 'cpi-tile-info');
    info.appendChild(el('div', 'cpi-tile-date', new Date(data.start).toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: tz })));
    info.appendChild(el('div', 'cpi-tile-time', B.timeIn(data.start, tz) + ' – ' + B.timeIn(data.end, tz)));
    info.appendChild(el('div', 'cpi-tile-zone', B.zoneLabel(tz)));
    box.appendChild(cal); box.appendChild(info);
  }

  function paintView() {
    tile();
    var cancelled = data.status !== 'confirmed';
    $('manage-title').textContent = cancelled ? 'This consultation was cancelled' : 'Your consultation';
    $('manage-actions').hidden = cancelled;
    $('manage-rebook').hidden = !cancelled;
    var join = $('manage-join'); join.textContent = '';
    if (!cancelled && data.join_url) { var a = el('a', 'cpi-button-lime', 'Join the call'); a.href = data.join_url; a.rel = 'noopener'; join.appendChild(a); }
    $('m-resched').disabled = !data.can_reschedule;
    $('manage-note').textContent = cancelled ? '' : (data.can_reschedule
      ? 'You can move the call online until ' + data.reschedule_hours + ' hours before it starts.'
      : 'This call starts in less than ' + data.reschedule_hours + ' hours, so it cannot be moved online. Please email us and we will sort it out.');
  }

  zone = B.zoneControl({ text: 'mg-tz', change: 'mg-tz-change', panel: 'mg-tz-panel', input: 'mg-tz-input', list: 'mg-tz-list', device: 'mg-tz-device' }, B.deviceZone(), function () { if (data) { paintView(); drawPicker(); updateChoice(); } });

  function drawPicker() {
    B.renderPicker({
      slots: function () { return allSlots; }, tz: zone.get, daysEl: $('m-days'), slotsEl: $('m-slots'),
      chosen: function () { return chosen; }, day: function () { return day; }, setDay: function (d) { day = d; },
      onPick: function (iso) { chosen = iso; updateChoice(); }
    });
  }
  function updateChoice() {
    $('m-confirm').disabled = !chosen;
    $('m-chosen').textContent = chosen ? 'New time: ' + B.longIn(chosen, zone.get()) + ' (' + B.zoneLabel(zone.get()) + ')' : '';
  }
  function status(id, text, bad) { var s = $(id); s.textContent = text; s.className = 'cpi-form-status' + (bad ? ' err' : ''); }

  $('m-resched').addEventListener('click', function () {
    chosen = null; day = null; updateChoice(); status('m-status', '');
    show('manage-resched'); $('m-days').textContent = 'Loading availability…'; $('m-slots').textContent = '';
    fetch(api + '/public/cpi/range').then(function (r) { return r.json(); }).then(function (d) {
      allSlots = (d.slots || []).filter(function (s) { return new Date(s).getTime() !== new Date(data.start).getTime(); });
      drawPicker();
    }).catch(function () { $('m-days').textContent = 'Could not load availability. Please try again.'; });
  });
  $('m-back').addEventListener('click', function () { show('manage-view'); });

  $('m-confirm').addEventListener('click', function () {
    if (!chosen) { return; }
    var btn = $('m-confirm'); btn.disabled = true; status('m-status', 'Moving your call…');
    post('/reschedule', { start: chosen }).then(function (x) {
      if (x.ok) {
        data.start = x.d.start; data.end = x.d.end;
        var d = new Date(data.start); data.can_reschedule = d.getTime() - Date.now() > data.reschedule_hours * 3600000;
        $('done-title').textContent = 'Your call has moved';
        $('done-text').textContent = B.longIn(data.start, zone.get()) + ' (' + B.zoneLabel(zone.get()) + '). An updated confirmation and calendar invitation are on their way to your email.';
        show('manage-done'); return;
      }
      status('m-status', (x.d && x.d.message) || 'That did not work. Please try again.', true);
      if (x.status === 409 || (x.d && x.d.code === 'unavailable')) { chosen = null; $('m-resched').click(); status('m-status', (x.d && x.d.message) || '', true); }
      else { btn.disabled = false; }
    }).catch(function () { status('m-status', 'Network problem. Please try again.', true); btn.disabled = false; });
  });

  $('m-cancel').addEventListener('click', function () { show('manage-cancel'); });
  $('m-cancel-no').addEventListener('click', function () { show('manage-view'); });
  $('m-cancel-yes').addEventListener('click', function () {
    var btn = $('m-cancel-yes'); btn.disabled = true;
    post('/cancel').then(function (x) {
      if (x.ok) {
        $('done-title').textContent = 'Your consultation is cancelled';
        $('done-text').textContent = 'We have let the team know and sent you a confirmation. You can book another time whenever it suits you.';
        data.status = 'cancelled'; show('manage-done');
      } else { btn.disabled = false; status('m-cancel-status', (x.d && x.d.message) || 'That did not work. Please try again.', true); }
    }).catch(function () { btn.disabled = false; status('m-cancel-status', 'Network problem. Please try again.', true); });
  });
  $('m-done-view').addEventListener('click', function () { paintView(); show('manage-view'); });

  fetch(base, { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
    if (!d) { show('manage-invalid'); return; }
    data = d;
    if (d.timezone && B.validZone(d.timezone)) { zone.set(d.timezone, true); }
    paintView();
    show(wantsCancel && d.status === 'confirmed' ? 'manage-cancel' : 'manage-view');
  }).catch(function () { show('manage-invalid'); });
})();
