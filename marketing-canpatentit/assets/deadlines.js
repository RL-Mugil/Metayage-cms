/* Patent deadline calculators. Pure date arithmetic in the browser: nothing is stored or sent anywhere. */
(function () {
  'use strict';

  function parseDate(v) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || '');
    if (!m) { return null; }
    var y = +m[1], mo = +m[2] - 1, d = +m[3];
    var dt = new Date(Date.UTC(y, mo, d));
    if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== mo || dt.getUTCDate() !== d) { return null; }
    return dt;
  }

  // Calendar-month arithmetic that clamps to the end of the target month (31 Jan + 1 month = 28/29 Feb).
  function addMonths(dt, n) {
    var m = dt.getUTCMonth() + n, ty = dt.getUTCFullYear() + Math.floor(m / 12), tm = ((m % 12) + 12) % 12;
    var last = new Date(Date.UTC(ty, tm + 1, 0)).getUTCDate();
    return new Date(Date.UTC(ty, tm, Math.min(dt.getUTCDate(), last)));
  }

  function format(dt) {
    return dt.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', timeZone: 'UTC' });
  }

  function isoDate(dt) { return dt.toISOString().slice(0, 10); }

  function daysFromToday(dt) {
    var now = new Date();
    return Math.round((dt.getTime() - Date.UTC(now.getFullYear(), now.getMonth(), now.getDate())) / 86400000);
  }

  function relative(n) {
    if (n === 0) { return 'today'; }
    var a = Math.abs(n), unit = a === 1 ? 'day' : 'days';
    return n > 0 ? 'in ' + a + ' ' + unit : a + ' ' + unit + ' ago';
  }

  // Each calculator returns the dates to show for one input date.
  var CALCS = {
    provisional: function (d) {
      return [
        { label: 'Convert to a full application by', date: addMonths(d, 12), note: 'Missing this loses the provisional application’s filing date.', key: true },
        { label: 'A good time to start preparing', date: addMonths(d, 9), note: 'Drafting a full application usually takes longer than people expect.' }
      ];
    },
    disclosure: function (d) {
      return [
        { label: 'Last day to file in the US', date: addMonths(d, 12), note: 'The US gives inventors a 12-month grace period. Many other countries give none, so rights there may already be affected.', key: true }
      ];
    },
    publication: function (d) {
      return [
        { label: 'Likely publication around', date: addMonths(d, 18), note: 'Most applications are published about 18 months after the earliest filing date. After that the content is public.', key: true }
      ];
    },
    pct: function (d) {
      return [
        { label: 'File foreign and PCT applications by', date: addMonths(d, 12), note: 'Twelve months from the priority date under the Paris Convention.', key: true },
        { label: 'National phase in most countries', date: addMonths(d, 30), note: 'Some offices allow 31 months, and a few differ. Check each country.' }
      ];
    }
  };

  // Minimal iCalendar file with a reminder 30 days before. Built and downloaded locally.
  function icsFor(title, dt) {
    var next = new Date(dt.getTime() + 86400000);
    var stamp = function (x) { return x.toISOString().slice(0, 10).replace(/-/g, ''); };
    var esc = function (t) { return String(t).replace(/([,;\\])/g, '\\$1'); };
    return ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//CanPatentIt//Deadline tools//EN', 'BEGIN:VEVENT',
      'UID:' + stamp(dt) + '-' + Math.random().toString(36).slice(2) + '@canpatentit.com',
      'DTSTAMP:' + new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+/, ''),
      'DTSTART;VALUE=DATE:' + stamp(dt), 'DTEND;VALUE=DATE:' + stamp(next), 'SUMMARY:' + esc(title),
      'DESCRIPTION:General guide from canpatentit.com. Confirm your own dates with a professional.',
      'BEGIN:VALARM', 'TRIGGER:-P30D', 'ACTION:DISPLAY', 'DESCRIPTION:' + esc(title), 'END:VALARM', 'END:VEVENT', 'END:VCALENDAR'].join('\r\n') + '\r\n';
  }

  if (typeof module !== 'undefined' && module.exports) { module.exports = { parseDate: parseDate, addMonths: addMonths, format: format, CALCS: CALCS, icsFor: icsFor, isoDate: isoDate }; }
  if (typeof document === 'undefined') { return; }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined) { n.textContent = text; }
    return n;
  }

  function render(card) {
    var out = card.querySelector('[data-out]'), input = card.querySelector('[data-in]');
    out.textContent = '';
    var d = parseDate(input.value);
    if (!d) { out.appendChild(el('p', 'cpi-fine', 'Pick a date to see the result.')); return; }
    CALCS[card.getAttribute('data-calc')](d).forEach(function (r) {
      var row = el('div', 'cpi-result' + (r.key ? ' key' : ''));
      row.appendChild(el('span', 'cpi-result-label', r.label));
      row.appendChild(el('strong', 'cpi-result-date', format(r.date)));
      var n = daysFromToday(r.date);
      row.appendChild(el('span', 'cpi-result-rel', relative(n) + (n < 0 ? ' (already passed)' : '')));
      var wd = r.date.getUTCDay();
      var note = r.note + (wd === 0 || wd === 6 ? ' This date is a weekend; a deadline like this generally moves to the next business day.' : '');
      row.appendChild(el('span', 'cpi-result-note', note));
      var add = el('button', 'cpi-ghost-btn', 'Add to my calendar');
      add.type = 'button';
      add.addEventListener('click', function () {
        var blob = new Blob([icsFor(r.label, r.date)], { type: 'text/calendar' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'patent-deadline-' + isoDate(r.date) + '.ics';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
      });
      row.appendChild(add);
      out.appendChild(row);
    });
    var ask = el('button', 'cpi-text-link cpi-tool-ask', 'Want us to check these dates with you?');
    ask.type = 'button';
    ask.setAttribute('data-helper-open', 'ask');
    ask.setAttribute('data-helper-topic', 'Checking my dates');
    out.appendChild(ask);
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-calc]'), function (card) {
    card.querySelector('[data-in]').addEventListener('input', function () { render(card); });
    card.querySelector('[data-in]').addEventListener('change', function () { render(card); });
  });
})();
