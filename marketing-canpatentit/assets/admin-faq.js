(function () {
  'use strict';
  var pane = document.getElementById('faq-pane');
  if (!pane) { return; }
  var loaded = false;
  var rows = [];

  function A() { return window.ADM; }
  function el(tag, cls, text) { return A().el(tag, cls, text); }
  function setErr(msg) { document.getElementById('page-error').textContent = msg || ''; }

  document.addEventListener('adm-tab', function (e) { if (e.detail === 'faq' && !loaded) { loaded = true; load(); } });

  function load() {
    A().call('GET', '/cpi-admin/faqs').then(function (r) { rows = r; list(); }).catch(function (e) { setErr(e.message); });
  }

  function head(title) {
    var h = el('div', 'adm-filters');
    h.appendChild(el('h2', null, title));
    return h;
  }

  function list(note) {
    pane.textContent = '';
    var h = head('FAQ questions');
    var add = el('button', 'adm-btn', 'Add question'); add.type = 'button'; add.addEventListener('click', function () { edit(null); });
    h.appendChild(add); pane.appendChild(h);
    pane.appendChild(el('p', 'adm-muted', 'These appear on canpatentit.com/faq in this order. Changes go live as soon as you save. Hidden questions stay here but are not shown to visitors.'));
    if (note) { pane.appendChild(el('p', 'adm-muted', note)); }
    if (!rows.length) { pane.appendChild(el('p', 'adm-muted', 'No questions yet. Add the first one.')); return; }

    var wrap = el('div', 'adm-faq-list');
    rows.forEach(function (r, i) {
      var item = el('div', 'adm-faq-item' + (r.active ? '' : ' is-hidden'));
      var main = el('div', 'adm-faq-main');
      main.appendChild(el('strong', null, r.question));
      main.appendChild(el('span', 'adm-muted adm-faq-ans', r.answer));
      if (!r.active) { main.appendChild(el('span', 'adm-pill warn', 'hidden')); }
      item.appendChild(main);

      var acts = el('div', 'adm-faq-acts');
      var up = el('button', 'adm-btn', '↑'); up.type = 'button'; up.title = 'Move up'; up.setAttribute('aria-label', 'Move up'); up.disabled = i === 0;
      var down = el('button', 'adm-btn', '↓'); down.type = 'button'; down.title = 'Move down'; down.setAttribute('aria-label', 'Move down'); down.disabled = i === rows.length - 1;
      up.addEventListener('click', function () { move(i, -1); });
      down.addEventListener('click', function () { move(i, 1); });
      var ed = el('button', 'adm-btn', 'Edit'); ed.type = 'button'; ed.addEventListener('click', function () { edit(r); });
      var del = el('button', 'adm-btn', 'Delete'); del.type = 'button';
      del.addEventListener('click', function () {
        if (!window.confirm('Delete this question? It will disappear from the website.')) { return; }
        setErr('');
        A().call('DELETE', '/cpi-admin/faqs/' + r.id).then(function () { rows = rows.filter(function (x) { return x.id !== r.id; }); list('Deleted.'); }).catch(function (e) { setErr(e.message); });
      });
      acts.appendChild(up); acts.appendChild(down); acts.appendChild(ed); acts.appendChild(del);
      item.appendChild(acts);
      wrap.appendChild(item);
    });
    pane.appendChild(wrap);
  }

  function move(i, dir) {
    var j = i + dir;
    if (j < 0 || j >= rows.length) { return; }
    var copy = rows.slice();
    var t = copy[i]; copy[i] = copy[j]; copy[j] = t;
    setErr('');
    A().call('PUT', '/cpi-admin/faqs-order', { ids: copy.map(function (r) { return r.id; }) })
      .then(function (r) { rows = r; list('Order saved.'); })
      .catch(function (e) { setErr(e.message); });
  }

  function edit(r) {
    pane.textContent = '';
    var back = el('button', 'adm-btn', 'Back to list'); back.type = 'button'; back.addEventListener('click', function () { list(); });
    pane.appendChild(back);
    pane.appendChild(el('h2', null, r ? 'Edit question' : 'New question'));

    var form = el('div', 'adm-form');
    var q = el('input'); q.maxLength = 300; q.value = r ? r.question : ''; q.id = 'faq-q';
    var a = el('textarea'); a.rows = 8; a.maxLength = 4000; a.value = r ? r.answer : ''; a.id = 'faq-a';
    var act = el('input'); act.type = 'checkbox'; act.id = 'faq-active'; act.checked = r ? r.active : true;
    var lq = el('label'); lq.htmlFor = 'faq-q'; lq.appendChild(document.createTextNode('Question')); lq.appendChild(q);
    var la = el('label'); la.htmlFor = 'faq-a'; la.appendChild(document.createTextNode('Answer (plain text; leave a blank line between paragraphs)')); la.appendChild(a);
    var lc = el('label', 'adm-check'); lc.htmlFor = 'faq-active'; lc.appendChild(act); lc.appendChild(document.createTextNode(' Show on the website'));
    form.appendChild(lq); form.appendChild(la); form.appendChild(lc);
    pane.appendChild(form);

    var bar = el('div', 'adm-filters');
    var save = el('button', 'adm-btn', r ? 'Save changes' : 'Add question'); save.type = 'button';
    var msg = el('span', 'adm-muted');
    save.addEventListener('click', function () {
      setErr('');
      var body = { question: q.value.trim(), answer: a.value.trim(), active: act.checked };
      if (body.question.length < 3 || body.answer.length < 3) { setErr('Please fill in both the question and the answer.'); return; }
      save.disabled = true; msg.textContent = 'Saving…';
      var req = r ? A().call('PUT', '/cpi-admin/faqs/' + r.id, body) : A().call('POST', '/cpi-admin/faqs', body);
      req.then(function () { return A().call('GET', '/cpi-admin/faqs'); })
        .then(function (all) { rows = all; list(r ? 'Saved.' : 'Question added.'); })
        .catch(function (e) { msg.textContent = ''; save.disabled = false; setErr(e.message); });
    });
    bar.appendChild(save); bar.appendChild(msg);
    pane.appendChild(bar);
    q.focus();
  }
})();
