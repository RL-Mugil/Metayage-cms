(function () {
  'use strict';
  var root = document.getElementById('book');
  if (!root) { return; }
  var B = window.CPIBook;
  var api = window.CPI.meta('api-base');
  var daysEl = document.getElementById('book-days');
  var slotsEl = document.getElementById('book-slots');
  var form = document.getElementById('book-form');
  var chosenEl = document.getElementById('book-chosen');
  var statusEl = document.getElementById('book-status');
  var submit = document.getElementById('book-submit');
  var success = document.getElementById('book-success');
  var startedAt = Date.now() - Math.round(window.performance.now());
  var allSlots = [], chosen = null, day = null, ts = null;

  var zone = B.zoneControl({ text: 'book-tz', change: 'tz-change', panel: 'tz-panel', input: 'tz-input', list: 'tz-list', device: 'tz-device' }, B.deviceZone(), function () { draw(); showChosen(); });

  function showChosen() {
    if (!chosen) { form.hidden = true; return; }
    chosenEl.textContent = 'Selected: ' + B.longIn(chosen, zone.get()) + ' (' + B.zoneLabel(zone.get()) + ')';
  }

  function draw() {
    B.renderPicker({
      slots: function () { return allSlots; }, tz: zone.get, daysEl: daysEl, slotsEl: slotsEl,
      chosen: function () { return chosen; }, day: function () { return day; }, setDay: function (d) { day = d; },
      onPick: function (iso) {
        chosen = iso; showChosen(); form.hidden = false;
        if (!ts) { ts = window.CPI.turnstile('#turnstile-widget'); }
        form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    });
  }

  function load() {
    fetch(api + '/public/cpi/range').then(function (r) { return r.json(); }).then(function (d) {
      allSlots = d.slots || [];
      if (chosen && allSlots.indexOf(chosen) === -1) { chosen = null; form.hidden = true; }
      draw();
    }).catch(function () { daysEl.textContent = 'Could not load availability. Please email us instead.'; });
  }

  function fieldError(id, msg) { var o = document.getElementById(id); if (o) { o.textContent = msg; } }
  function fail(msg) { statusEl.className = 'cpi-form-status err'; statusEl.textContent = msg; }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    statusEl.className = 'cpi-form-status'; statusEl.textContent = '';
    var name = form.elements.name.value.trim(); var email = form.elements.email.value.trim();
    var bad = false;
    fieldError('b-name-error', ''); fieldError('b-email-error', '');
    if (name.length < 2) { fieldError('b-name-error', 'Please enter your name.'); bad = true; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) { fieldError('b-email-error', 'Please enter a valid email.'); bad = true; }
    if (!chosen) { fail('Please choose a time first.'); bad = true; }
    if (bad) { return; }
    if (ts && ts.enabled && !ts.token) { fail('Please complete the verification check.'); return; }
    submit.disabled = true;
    var tz = zone.get();
    var payload = {
      start: chosen, name: name, email: email, organization: form.elements.organization.value.trim(),
      topic: form.elements.topic.value.trim(), timezone: tz, website: form.elements.website.value, elapsed_ms: Date.now() - startedAt
    };
    if (ts && ts.token) { payload['cf-turnstile-response'] = ts.token; }
    fetch(api + '/public/cpi/book', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(payload) })
      .then(function (res) { return res.json().catch(function () { return {}; }).then(function (d) { return { ok: res.ok, status: res.status, d: d }; }); })
      .then(function (x) {
        if (x.ok) {
          form.hidden = true; success.hidden = false;
          document.getElementById('book-success-text').textContent = B.longIn(chosen, tz) + ' (' + B.zoneLabel(tz) + '). A confirmation email is on its way to ' + email + ', with a link to reschedule or cancel.';
          success.setAttribute('tabindex', '-1'); success.focus();
          return;
        }
        if (x.status === 409) { fail('Sorry, that time was just taken. Please pick another.'); chosen = null; load(); }
        else if (x.status === 422 && x.d && x.d.errors) { fail(Object.keys(x.d.errors).map(function (k) { return x.d.errors[k][0]; }).join(' ')); }
        else if (x.status === 429) { fail('Too many attempts. Please wait a little and try again.'); }
        else { fail((x.d && x.d.message) || 'We could not complete the booking. Please try again or email us.'); }
        submit.disabled = false; if (ts) { ts.reset(); }
      })
      .catch(function () { fail('Network problem. Please try again.'); submit.disabled = false; });
  });

  load();
})();
