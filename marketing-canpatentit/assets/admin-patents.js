(function () {
  'use strict';
  var pane = document.getElementById('patents-pane');
  if (!pane) { return; }
  var loaded = false;
  var rows = [];
  var domains = [];
  var COUNTRIES = ['India', 'USA', 'Others'];
  var filters = { q: '', domain: '', country: '', status: '' };

  function A() { return window.ADM; }
  function el(tag, cls, text) { return A().el(tag, cls, text); }
  function setErr(msg) { document.getElementById('page-error').textContent = msg || ''; }

  document.addEventListener('adm-tab', function (e) {
    if (e.detail === 'patents' && !loaded) {
      loaded = true;
      Promise.all([A().call('GET', '/cpi-admin/patents'), A().call('GET', '/cpi-admin/patent-domains')])
        .then(function (r) { rows = r[0]; domains = r[1]; list(); })
        .catch(function (err) { setErr(err.message); });
    }
  });

  function reload(note) {
    return Promise.all([A().call('GET', '/cpi-admin/patents'), A().call('GET', '/cpi-admin/patent-domains')])
      .then(function (r) { rows = r[0]; domains = r[1]; list(note); })
      .catch(function (err) { setErr(err.message); });
  }

  function select(opts, value, label) {
    var s = el('select'); s.setAttribute('aria-label', label);
    opts.forEach(function (o) { var op = document.createElement('option'); op.value = o[0]; op.textContent = o[1]; if (o[0] === value) { op.selected = true; } s.appendChild(op); });
    return s;
  }

  function list(note) {
    pane.textContent = '';
    var head = el('div', 'adm-filters');
    head.appendChild(el('h2', null, 'Patent showcase'));
    var add = el('button', 'adm-btn', 'Add patent'); add.type = 'button'; add.addEventListener('click', function () { edit(null); });
    head.appendChild(add); pane.appendChild(head);
    pane.appendChild(el('p', 'adm-muted', 'Shown on canpatentit.com/patent-showcase. Each published patent gets its own page with a Recommended patents section that suggests others from the same domain.'));
    if (note) { pane.appendChild(el('p', 'adm-muted', note)); }

    var bar = el('div', 'adm-filters');
    var q = el('input'); q.placeholder = 'Search ID or title'; q.value = filters.q; q.setAttribute('aria-label', 'Search');
    var domOpts = [['', 'All domains']].concat(domains.map(function (d) { return [d, d]; }));
    var dsel = select(domOpts, filters.domain, 'Domain');
    var csel = select([['', 'All countries']].concat(COUNTRIES.map(function (c) { return [c, c]; })), filters.country, 'Country');
    var ssel = select([['', 'Any status'], ['published', 'Published'], ['draft', 'Draft']], filters.status, 'Status');
    [q, dsel, csel, ssel].forEach(function (c) { bar.appendChild(c); });
    pane.appendChild(bar);

    var wrap = el('div', 'adm-table-wrap'); var t = el('table', 'adm-table');
    t.innerHTML = '<thead><tr><th></th><th>Patent ID</th><th>Title</th><th>Domain</th><th>Country</th><th>Status</th><th></th></tr></thead>';
    var tb = el('tbody'); t.appendChild(tb); wrap.appendChild(t); pane.appendChild(wrap);
    var empty = el('p', 'adm-muted', 'No patents match.'); empty.hidden = true; pane.appendChild(empty);

    function draw() {
      tb.textContent = '';
      var needle = filters.q.trim().toLowerCase();
      var shown = rows.filter(function (r) {
        return (!needle || (r.patent_number + ' ' + r.title).toLowerCase().indexOf(needle) >= 0) && (!filters.domain || r.domain === filters.domain) && (!filters.country || r.country === filters.country) && (!filters.status || r.status === filters.status);
      });
      empty.hidden = shown.length > 0;
      shown.forEach(function (r) {
        var tr = el('tr');
        var th = el('td', 'adm-thumb');
        if (r.image) { var im = document.createElement('img'); im.src = 'https://canpatentit.com' + r.image; im.alt = ''; th.appendChild(im); }
        tr.appendChild(th);
        tr.appendChild(el('td', 'nowrap', r.patent_number));
        tr.appendChild(el('td', 'adm-msg', r.title));
        tr.appendChild(el('td', null, r.domain));
        tr.appendChild(el('td', null, r.country));
        var st = el('td'); st.appendChild(el('span', 'adm-pill ' + (r.status === 'published' ? 'ok' : ''), r.status)); tr.appendChild(st);
        var act = el('td');
        var eb = el('button', 'adm-btn', 'Edit'); eb.type = 'button'; eb.addEventListener('click', function () { edit(r); });
        act.appendChild(eb);
        if (r.status === 'published') {
          act.appendChild(document.createTextNode(' '));
          var v = el('a', 'adm-btn', 'View'); v.href = 'https://canpatentit.com/patent-showcase/' + encodeURIComponent(r.slug); v.target = '_blank'; v.rel = 'noopener'; act.appendChild(v);
        }
        tr.appendChild(act); tb.appendChild(tr);
      });
    }
    q.addEventListener('input', function () { filters.q = q.value; draw(); });
    dsel.addEventListener('change', function () { filters.domain = dsel.value; draw(); });
    csel.addEventListener('change', function () { filters.country = csel.value; draw(); });
    ssel.addEventListener('change', function () { filters.status = ssel.value; draw(); });
    draw();
  }

  function field(label, id, node) { var l = el('label'); l.htmlFor = id; l.appendChild(document.createTextNode(label)); node.id = id; l.appendChild(node); return l; }

  function edit(r) {
    pane.textContent = '';
    var back = el('button', 'adm-btn', 'Back to list'); back.type = 'button'; back.addEventListener('click', function () { list(); });
    pane.appendChild(back);
    pane.appendChild(el('h2', null, r ? 'Edit patent' : 'Add patent'));
    var cur = r || { patent_number: '', title: '', domain: '', country: 'India', abstract: '', image: '', image_alt: '', status: 'draft' };
    var form = el('div', 'adm-form');

    var num = el('input'); num.maxLength = 60; num.value = cur.patent_number; num.placeholder = 'e.g. WO/2020/136668 or US 11,000,000 B2';
    var title = el('input'); title.maxLength = 220; title.value = cur.title;
    var dom = el('input'); dom.maxLength = 80; dom.value = cur.domain; dom.setAttribute('list', 'patent-domain-list'); dom.placeholder = 'Choose or type a domain';
    var dl = document.createElement('datalist'); dl.id = 'patent-domain-list';
    domains.forEach(function (d) { var o = document.createElement('option'); o.value = d; dl.appendChild(o); });
    var country = select(COUNTRIES.map(function (c) { return [c, c]; }), cur.country, 'Country');
    var abs = el('textarea'); abs.rows = 9; abs.maxLength = 8000; abs.value = cur.abstract; abs.placeholder = 'The abstract. Leave a blank line between paragraphs.';

    form.appendChild(field('Patent ID', 'p-num', num));
    form.appendChild(field('Title', 'p-title', title));
    var two = el('div', 'adm-cfg');
    two.appendChild(field('Domain', 'p-dom', dom)); two.appendChild(dl);
    two.appendChild(field('Country', 'p-country', country));
    form.appendChild(two);
    form.appendChild(field('Abstract', 'p-abs', abs));

    // Figure
    var img = { url: cur.image || '', alt: cur.image_alt || '' };
    var box = el('div', 'adm-cover');
    var preview = el('div', 'adm-cover-preview adm-fig-preview');
    var alt = el('input'); alt.maxLength = 200; alt.value = img.alt; alt.placeholder = 'Describe the figure for people who cannot see it';
    alt.addEventListener('input', function () { img.alt = alt.value; });
    var pick = el('input'); pick.type = 'file'; pick.accept = 'image/jpeg,image/png,image/webp,image/gif'; pick.hidden = true;
    var pickBtn = el('button', 'adm-btn', 'Choose figure'); pickBtn.type = 'button';
    var rm = el('button', 'adm-btn', 'Remove'); rm.type = 'button';
    var imsg = el('span', 'adm-muted');
    var altLabel = el('label'); altLabel.appendChild(document.createTextNode('Figure description (alt text)')); altLabel.appendChild(alt);
    function paint() {
      preview.textContent = '';
      if (img.url) { var im = document.createElement('img'); im.src = 'https://canpatentit.com' + img.url; im.alt = ''; preview.appendChild(im); } else { preview.appendChild(el('span', 'adm-muted', 'No figure yet. A branded placeholder is shown.')); }
      rm.hidden = !img.url; altLabel.hidden = !img.url; pickBtn.textContent = img.url ? 'Replace figure' : 'Choose figure';
    }
    pickBtn.addEventListener('click', function () { pick.click(); });
    rm.addEventListener('click', function () { img.url = ''; paint(); });
    pick.addEventListener('change', function () {
      if (!pick.files[0]) { return; }
      imsg.textContent = 'Uploading…'; setErr('');
      A().uploadFile(pick.files[0]).then(function (res) { img.url = res.url; imsg.textContent = 'Uploaded.'; paint(); })
        .catch(function (e) { imsg.textContent = ''; setErr(e.message); }).then(function () { pick.value = ''; });
    });
    var lbl = el('label'); lbl.appendChild(document.createTextNode('Figure (a drawing from the patent)'));
    var btns = el('div', 'adm-filters'); btns.appendChild(pickBtn); btns.appendChild(rm); btns.appendChild(imsg);
    box.appendChild(lbl); box.appendChild(preview); box.appendChild(btns); box.appendChild(pick); box.appendChild(altLabel);
    form.appendChild(box);
    pane.appendChild(form);
    paint();

    var bar = el('div', 'adm-filters');
    var save = el('button', 'adm-btn', 'Save'); save.type = 'button';
    var pub = el('button', 'adm-btn', 'Publish'); pub.type = 'button';
    var unpub = el('button', 'adm-btn', 'Unpublish'); unpub.type = 'button';
    var del = el('button', 'adm-btn', 'Delete'); del.type = 'button';
    var msg = el('span', 'adm-muted');
    function payload() {
      return { patent_number: num.value.trim(), title: title.value.trim(), domain: dom.value.trim(), country: country.value, abstract: abs.value.trim(), image: img.url || null, image_alt: img.url ? (img.alt.trim() || null) : null };
    }
    function doSave() {
      var p = payload();
      if (!p.patent_number || !p.title || !p.domain || p.abstract.length < 10) { setErr('Please fill in the patent ID, title, domain and abstract.'); return Promise.reject(new Error('invalid')); }
      msg.textContent = 'Saving…';
      var req = cur.id ? A().call('PUT', '/cpi-admin/patents/' + cur.id, p) : A().call('POST', '/cpi-admin/patents', p);
      return req.then(function (saved) { cur = saved; msg.textContent = 'Saved' + (saved.status === 'published' ? ' and live.' : ' as a draft.'); sync(); return saved; })
        .catch(function (e) { msg.textContent = ''; if (e.message !== 'invalid') { setErr(e.message); } throw e; });
    }
    function sync() { pub.textContent = cur.status === 'published' ? 'Republish' : 'Publish'; pub.hidden = false; unpub.hidden = cur.status !== 'published'; del.hidden = !cur.id; }
    save.addEventListener('click', function () { setErr(''); doSave().catch(function () {}); });
    pub.addEventListener('click', function () {
      setErr('');
      doSave().then(function (s) { return A().call('POST', '/cpi-admin/patents/' + s.id + '/publish'); })
        .then(function (s) { cur = s; msg.textContent = 'Published: canpatentit.com/patent-showcase/' + s.slug; sync(); }).catch(function () {});
    });
    unpub.addEventListener('click', function () {
      A().call('POST', '/cpi-admin/patents/' + cur.id + '/unpublish').then(function (s) { cur = s; msg.textContent = 'Unpublished. It is now a draft.'; sync(); }).catch(function (e) { setErr(e.message); });
    });
    del.addEventListener('click', function () {
      if (!window.confirm('Delete this patent permanently? If it is published it will be taken offline.')) { return; }
      A().call('DELETE', '/cpi-admin/patents/' + cur.id).then(function () { reload('Deleted.'); }).catch(function (e) { setErr(e.message); });
    });
    [save, pub, unpub, del, msg].forEach(function (c) { bar.appendChild(c); });
    pane.appendChild(bar);
    sync();
  }
})();
