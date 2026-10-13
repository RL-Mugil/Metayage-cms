(function () {
  'use strict';
  var root = document.getElementById('book');
  if (!root) { return; }
  var api = window.CPI.meta('api-base');
  var daysEl = document.getElementById('book-days');
  var slotsEl = document.getElementById('book-slots');
  var tzEl = document.getElementById('book-tz');
  var form = document.getElementById('book-form');
  var chosenEl = document.getElementById('book-chosen');
  var statusEl = document.getElementById('book-status');
  var submit = document.getElementById('book-submit');
  var success = document.getElementById('book-success');
  var tz = 'UTC';
  try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; } catch (e) { /* default */ }
  tzEl.textContent = 'Times shown in ' + tz.replace('_', ' ') + '.';
  var startedAt = Date.now() - Math.round(window.performance.now());
  var chosen = null;
  var ts = null;

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
  function fmtTime(iso) { return new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', timeZone: tz }); }
  function fmtLong(iso) { return new Date(iso).toLocaleString([], { weekday: 'long', day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit', timeZone: tz }); }

  function loadDays() {
    fetch(api + '/public/cpi/days').then(function (r) { return r.json(); }).then(function (d) {
      daysEl.textContent = '';
      if (!d.days || !d.days.length) { daysEl.appendChild(el('p', 'cpi-fine', 'No times are open right now. Please email us and we will arrange one.')); return; }
      d.days.forEach(function (day) {
        var dt = new Date(day + 'T12:00:00Z');
        var b = el('button', 'cpi-day'); b.type = 'button'; b.setAttribute('aria-pressed', 'false');
        b.appendChild(document.createTextNode(dt.toLocaleDateString([], { weekday: 'short', timeZone: 'UTC' })));
        b.appendChild(el('small', null, dt.toLocaleDateString([], { day: 'numeric', month: 'short', timeZone: 'UTC' })));
        b.addEventListener('click', function () {
          Array.prototype.forEach.call(daysEl.children, function (c) { c.setAttribute('aria-pressed', 'false'); });
          b.setAttribute('aria-pressed', 'true');
          loadSlots(day);
        });
        daysEl.appendChild(b);
      });
    }).catch(function () { daysEl.textContent = 'Could not load availability. Please email us instead.'; });
  }

  function loadSlots(day) {
    chosen = null; form.hidden = true;
    slotsEl.textContent = 'Loading…';
    fetch(api + '/public/cpi/slots?date=' + encodeURIComponent(day)).then(function (r) { return r.json(); }).then(function (d) {
      slotsEl.textContent = '';
      if (!d.slots || !d.slots.length) { slotsEl.appendChild(el('p', 'cpi-fine', 'No times left on that day. Try another.')); return; }
      d.slots.forEach(function (iso) {
        var b = el('button', 'cpi-slot', fmtTime(iso)); b.type = 'button'; b.setAttribute('aria-pressed', 'false');
        b.addEventListener('click', function () {
          Array.prototype.forEach.call(slotsEl.children, function (c) { c.setAttribute('aria-pressed', 'false'); });
          b.setAttribute('aria-pressed', 'true');
          chosen = iso; chosenEl.textContent = 'Selected: ' + fmtLong(iso);
          form.hidden = false;
          if (!ts) { ts = window.CPI.turnstile('#turnstile-widget'); }
          form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
        slotsEl.appendChild(b);
      });
    }).catch(function () { slotsEl.textContent = 'Could not load times. Please try again.'; });
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
          document.getElementById('book-success-text').textContent = fmtLong(chosen) + ' (' + tz.replace('_', ' ') + '). A confirmation email is on its way to ' + email + '.';
          success.setAttribute('tabindex', '-1'); success.focus();
          return;
        }
        if (x.status === 409) { fail('Sorry, that time was just taken. Please pick another.'); loadSlots(chosen.slice(0, 10)); }
        else if (x.status === 422 && x.d && x.d.errors) { fail(Object.keys(x.d.errors).map(function (k) { return x.d.errors[k][0]; }).join(' ')); }
        else if (x.status === 429) { fail('Too many attempts. Please wait a little and try again.'); }
        else { fail((x.d && x.d.message) || 'We could not complete the booking. Please try again or email us.'); }
        submit.disabled = false; if (ts) { ts.reset(); }
      })
      .catch(function () { fail('Network problem. Please try again.'); submit.disabled = false; });
  });

  loadDays();
})();
