/* Shared by the booking page and the manage page: time zone detection and picker, slot grouping and date formatting.
   The visitor's zone comes from the browser itself (no location permission, nothing is sent until they book). */
(function () {
  'use strict';

  var B = window.CPIBook = {};

  B.deviceZone = function () {
    try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; } catch (e) { return 'UTC'; }
  };

  B.zones = function () {
    var list = [];
    try { if (Intl.supportedValuesOf) { list = Intl.supportedValuesOf('timeZone'); } } catch (e) { /* older browser */ }
    var dev = B.deviceZone();
    if (list.indexOf(dev) === -1) { list.push(dev); }
    if (list.indexOf('UTC') === -1) { list.push('UTC'); }
    return list.sort();
  };

  B.validZone = function (tz) {
    try { new Intl.DateTimeFormat('en', { timeZone: tz }); return true; } catch (e) { return false; }
  };

  /** YYYY-MM-DD of an instant as seen in a zone. */
  B.dateIn = function (iso, tz) {
    var p = new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(iso));
    return p;
  };

  B.timeIn = function (iso, tz) { return new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', timeZone: tz }); };

  B.longIn = function (iso, tz) {
    return new Date(iso).toLocaleString([], { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: tz });
  };

  B.zoneLabel = function (tz) { return tz.replace(/_/g, ' '); };

  /** Group UTC slot strings by the visitor's local date. Returns {days: [YYYY-MM-DD], by: {date: [iso]}}. */
  B.group = function (slots, tz) {
    var by = {}, days = [];
    slots.forEach(function (iso) {
      var d = B.dateIn(iso, tz);
      if (!by[d]) { by[d] = []; days.push(d); }
      by[d].push(iso);
    });
    return { days: days, by: by };
  };

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
  B.el = el;

  /**
   * Wire up a zone control. `ids`: {text, change, panel, input, list, device}. `onChange(tz)` is called when the visitor
   * picks another zone. Returns {get(), set(tz)}.
   */
  B.zoneControl = function (ids, initial, onChange) {
    var tz = initial, textEl = document.getElementById(ids.text), changeBtn = document.getElementById(ids.change), panel = document.getElementById(ids.panel);
    var input = document.getElementById(ids.input), list = document.getElementById(ids.list), device = document.getElementById(ids.device);
    function paint() { textEl.textContent = ''; textEl.appendChild(document.createTextNode('Times are shown in ')); textEl.appendChild(el('strong', null, B.zoneLabel(tz))); textEl.appendChild(document.createTextNode(tz === B.deviceZone() ? ' (detected from your device).' : '.')); }
    function set(next, silent) {
      if (!B.validZone(next)) { return false; }
      tz = next; paint(); input.value = '';
      if (!silent) { onChange(tz); }
      return true;
    }
    B.zones().forEach(function (z) { var o = document.createElement('option'); o.value = z; list.appendChild(o); });
    changeBtn.addEventListener('click', function () {
      var open = panel.hidden; panel.hidden = !open; changeBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { input.focus(); }
    });
    function commit() { var v = input.value.trim(); if (v && set(v)) { panel.hidden = true; changeBtn.setAttribute('aria-expanded', 'false'); } }
    input.addEventListener('change', commit);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); commit(); } });
    device.addEventListener('click', function () { set(B.deviceZone()); panel.hidden = true; changeBtn.setAttribute('aria-expanded', 'false'); });
    paint();
    return { get: function () { return tz; }, set: set };
  };

  /** Draw day buttons and slot buttons. `state`: {tz, slots, chosen, day}. `onPick(iso)` when a slot is chosen. */
  B.renderPicker = function (cfg) {
    var g = B.group(cfg.slots(), cfg.tz());
    cfg.daysEl.textContent = '';
    if (!g.days.length) { cfg.daysEl.appendChild(el('p', 'cpi-fine', 'No times are open right now. Please email us and we will arrange one.')); cfg.slotsEl.textContent = ''; return; }
    var chosen = cfg.chosen();
    var day = chosen ? B.dateIn(chosen, cfg.tz()) : cfg.day();   // the day of the chosen slot as the visitor sees it in the current zone
    if (!day || !g.by[day]) { day = null; }
    g.days.forEach(function (d) {
      var dt = new Date(d + 'T12:00:00Z');
      var b = el('button', 'cpi-day'); b.type = 'button'; b.setAttribute('aria-pressed', d === day ? 'true' : 'false');
      b.appendChild(document.createTextNode(dt.toLocaleDateString([], { weekday: 'short', timeZone: 'UTC' })));
      b.appendChild(el('small', null, dt.toLocaleDateString([], { day: 'numeric', month: 'short', timeZone: 'UTC' })));
      b.addEventListener('click', function () { cfg.setDay(d); B.renderPicker(cfg); });
      cfg.daysEl.appendChild(b);
    });
    cfg.slotsEl.textContent = '';
    if (!day) { cfg.slotsEl.appendChild(el('p', 'cpi-fine', 'Choose a day first.')); return; }
    cfg.setDay(day);
    g.by[day].forEach(function (iso) {
      var b = el('button', 'cpi-slot', B.timeIn(iso, cfg.tz())); b.type = 'button'; b.setAttribute('aria-pressed', iso === chosen ? 'true' : 'false');
      b.addEventListener('click', function () { cfg.onPick(iso); B.renderPicker(cfg); });
      cfg.slotsEl.appendChild(b);
    });
  };
})();
