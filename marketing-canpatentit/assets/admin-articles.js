(function () {
  'use strict';
  var pane = document.getElementById('articles-pane');
  if (!pane) { return; }
  var loaded = false;

  function A() { return window.ADM; }
  function el(tag, cls, text) { return A().el(tag, cls, text); }
  function setErr(msg) { document.getElementById('page-error').textContent = msg || ''; }

  document.addEventListener('adm-tab', function (e) { if (e.detail === 'insights' && !loaded) { loaded = true; list(); } });

  function list() {
    pane.textContent = '';
    var head = el('div', 'adm-filters');
    head.appendChild(el('h2', null, 'Insights'));
    var nb = el('button', 'adm-btn', 'New article'); nb.type = 'button'; nb.addEventListener('click', function () { edit(null); });
    head.appendChild(nb); pane.appendChild(head);
    var wrap = el('div', 'adm-table-wrap'); var t = el('table', 'adm-table');
    t.innerHTML = '<thead><tr><th>Title</th><th>Status</th><th>Updated (IST)</th><th></th></tr></thead>';
    var tb = el('tbody'); t.appendChild(tb); wrap.appendChild(t); pane.appendChild(wrap);
    A().call('GET', '/cpi-admin/articles').then(function (rows) {
      if (!rows.length) { var tr0 = el('tr'); var td0 = el('td', 'adm-muted', 'No articles yet.'); td0.colSpan = 4; tr0.appendChild(td0); tb.appendChild(tr0); }
      rows.forEach(function (r) {
        var tr = el('tr');
        tr.appendChild(el('td', null, r.title));
        var st = el('td'); st.appendChild(el('span', 'adm-pill ' + (r.status === 'published' ? 'ok' : ''), r.status)); tr.appendChild(st);
        tr.appendChild(el('td', 'nowrap', A().ist(r.updated_at)));
        var act = el('td');
        var eb = el('button', 'adm-btn', 'Edit'); eb.type = 'button'; eb.addEventListener('click', function () { edit(r.id); });
        act.appendChild(eb);
        if (r.status === 'published') {
          var link = el('a', 'adm-btn', 'View'); link.href = 'https://canpatentit.com/insights/' + encodeURIComponent(r.slug); link.target = '_blank'; link.rel = 'noopener'; act.appendChild(document.createTextNode(' ')); act.appendChild(link);
        }
        tr.appendChild(act); tb.appendChild(tr);
      });
    }).catch(function (e) { setErr(e.message); });
  }

  function field(label, id, node) { var l = el('label'); l.htmlFor = id; l.appendChild(document.createTextNode(label)); node.id = id; l.appendChild(node); return l; }

  // Shrinks an image in the browser (max 1600px, WebP/JPEG) so uploads stay small and fast.
  function prepareImage(file) {
    return new Promise(function (resolve, reject) {
      if (!/^image\/(jpeg|png|webp|gif)$/.test(file.type)) { reject(new Error('Choose a JPG, PNG, WebP or GIF image.')); return; }
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        var scale = Math.min(1, 1600 / img.width), q = 0.85, tries = 0;
        function attempt() {
          var c = document.createElement('canvas');
          c.width = Math.max(1, Math.round(img.width * scale)); c.height = Math.max(1, Math.round(img.height * scale));
          c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
          c.toBlob(function (b) {
            if (!b) { URL.revokeObjectURL(url); reject(new Error('Could not process that image.')); return; }
            if (b.size > 900 * 1024 && tries < 4) { tries++; scale *= 0.8; q = Math.max(0.6, q - 0.08); attempt(); return; }
            URL.revokeObjectURL(url); resolve(b);
          }, 'image/webp', q);
        }
        attempt();
      };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('That file could not be read as an image.')); };
      img.src = url;
    });
  }

  function uploadFile(file) {
    return prepareImage(file).then(function (blob) {
      var fd = new FormData();
      fd.append('image', blob, 'image.' + (blob.type === 'image/webp' ? 'webp' : 'jpg'));
      return A().upload('/cpi-admin/images', fd);
    });
  }

  // Shared with the patent showcase editor.
  if (window.ADM) { window.ADM.uploadFile = uploadFile; }

  function edit(id) {
    pane.textContent = '';
    var load = id ? A().call('GET', '/cpi-admin/articles/' + id) : Promise.resolve({ title: '', slug: '', excerpt: '', cover_image: '', cover_alt: '', body_html: '', meta_title: '', meta_description: '', author: 'CanPatentIt', status: 'draft' });
    load.then(function (a) {
      var back = el('button', 'adm-btn', 'Back to list'); back.type = 'button'; back.addEventListener('click', list); pane.appendChild(back);
      pane.appendChild(el('h2', null, id ? 'Edit article' : 'New article'));
      var form = el('div', 'adm-form');
      var title = el('input'); title.value = a.title; title.maxLength = 160;
      var slug = el('input'); slug.value = a.slug || ''; slug.maxLength = 120; slug.placeholder = 'auto from title'; slug.disabled = a.status === 'published';
      var excerpt = el('textarea'); excerpt.rows = 2; excerpt.maxLength = 320; excerpt.value = a.excerpt || '';
      var author = el('input'); author.value = a.author || ''; author.maxLength = 100;
      var mt = el('input'); mt.value = a.meta_title || ''; mt.maxLength = 160;
      var md = el('textarea'); md.rows = 2; md.maxLength = 200; md.value = a.meta_description || '';
      form.appendChild(field('Title', 'a-title', title));
      form.appendChild(field('URL slug (fixed once published)', 'a-slug', slug));
      form.appendChild(field('Short summary (shown on the list and in search)', 'a-excerpt', excerpt));
      form.appendChild(field('Author', 'a-author', author));

      // Cover image: shown at the top of the article and as the picture on the Insights list.
      var cover = { url: a.cover_image || '', alt: a.cover_alt || '' };
      var coverBox = el('div', 'adm-cover');
      var preview = el('div', 'adm-cover-preview');
      var coverAlt = el('input'); coverAlt.maxLength = 200; coverAlt.value = cover.alt; coverAlt.placeholder = 'Describe the picture for people who cannot see it';
      coverAlt.addEventListener('input', function () { cover.alt = coverAlt.value; });
      var pick = el('input'); pick.type = 'file'; pick.accept = 'image/jpeg,image/png,image/webp,image/gif'; pick.hidden = true;
      var pickBtn = el('button', 'adm-btn', 'Choose cover image'); pickBtn.type = 'button';
      var rmBtn = el('button', 'adm-btn', 'Remove'); rmBtn.type = 'button';
      var coverMsg = el('span', 'adm-muted');
      function paintCover() {
        preview.textContent = '';
        if (cover.url) { var im = document.createElement('img'); im.src = cover.url; im.alt = ''; preview.appendChild(im); }
        else { preview.appendChild(el('span', 'adm-muted', 'No cover image yet')); }
        rmBtn.hidden = !cover.url; coverAlt.parentNode && (coverAlt.parentNode.hidden = !cover.url);
        pickBtn.textContent = cover.url ? 'Replace cover image' : 'Choose cover image';
      }
      pickBtn.addEventListener('click', function () { pick.click(); });
      rmBtn.addEventListener('click', function () { cover.url = ''; paintCover(); });
      pick.addEventListener('change', function () {
        if (!pick.files[0]) { return; }
        coverMsg.textContent = 'Uploading…'; setErr('');
        uploadFile(pick.files[0]).then(function (r) { cover.url = r.url; coverMsg.textContent = 'Uploaded.'; paintCover(); })
          .catch(function (e) { coverMsg.textContent = ''; setErr(e.message); }).then(function () { pick.value = ''; });
      });
      var covLabel = el('label'); covLabel.appendChild(document.createTextNode('Cover image description (alt text)')); covLabel.appendChild(coverAlt);
      var covLabelLbl = el('label'); covLabelLbl.appendChild(document.createTextNode('Cover image'));
      coverBox.appendChild(covLabelLbl); coverBox.appendChild(preview);
      var covBtns = el('div', 'adm-filters'); covBtns.appendChild(pickBtn); covBtns.appendChild(rmBtn); covBtns.appendChild(coverMsg); coverBox.appendChild(covBtns); coverBox.appendChild(pick); coverBox.appendChild(covLabel);
      form.appendChild(coverBox);
      paintCover();

      // Pictures inside the article body: uploaded, then inserted where the cursor was.
      var inlinePick = el('input'); inlinePick.type = 'file'; inlinePick.accept = 'image/jpeg,image/png,image/webp,image/gif'; inlinePick.hidden = true;
      var inlineSel = null;
      inlinePick.addEventListener('change', function () {
        var file = inlinePick.files[0]; if (!file) { return; }
        var alt = (window.prompt('Describe this picture (alt text, optional)') || '').replace(/[&<>"]/g, '');
        setErr('');
        uploadFile(file).then(function (r) {
          ed.focus();
          if (inlineSel) { var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(inlineSel); }
          document.execCommand('insertHTML', false, '<img src="' + r.url + '" alt="' + alt + '">');
        }).catch(function (e) { setErr(e.message); }).then(function () { inlinePick.value = ''; });
      });
      var tools = el('div', 'adm-tools');
      tools.appendChild(inlinePick);
      var ed = el('div', 'adm-editor'); ed.contentEditable = 'true'; ed.setAttribute('role', 'textbox'); ed.setAttribute('aria-label', 'Article body'); ed.setAttribute('aria-multiline', 'true');
      ed.innerHTML = a.body_html || '<p></p>';
      ed.addEventListener('blur', function () { var s = window.getSelection(); inlineSel = s.rangeCount ? s.getRangeAt(0).cloneRange() : null; });
      [['B', 'bold'], ['I', 'italic'], ['H2', 'h2'], ['H3', 'h3'], ['• List', 'ul'], ['1. List', 'ol'], ['Quote', 'quote'], ['Link', 'link'], ['Image', 'image'], ['Clear', 'clear']].forEach(function (b) {
        var bt = el('button', 'adm-btn', b[0]); bt.type = 'button';
        bt.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
        bt.addEventListener('click', function () {
          ed.focus();
          if (b[1] === 'bold') { document.execCommand('bold'); }
          else if (b[1] === 'italic') { document.execCommand('italic'); }
          else if (b[1] === 'h2' || b[1] === 'h3') { document.execCommand('formatBlock', false, b[1]); }
          else if (b[1] === 'ul') { document.execCommand('insertUnorderedList'); }
          else if (b[1] === 'ol') { document.execCommand('insertOrderedList'); }
          else if (b[1] === 'quote') { document.execCommand('formatBlock', false, 'blockquote'); }
          else if (b[1] === 'image') { inlinePick.click(); }
          else if (b[1] === 'clear') { document.execCommand('removeFormat'); document.execCommand('formatBlock', false, 'p'); }
          else if (b[1] === 'link') { var u = window.prompt('Link address (https://…)'); if (u && /^(https?:|mailto:)/i.test(u)) { document.execCommand('createLink', false, u); } }
        });
        tools.appendChild(bt);
      });
      var bl = el('label'); bl.appendChild(document.createTextNode('Body')); form.appendChild(bl); form.appendChild(tools); form.appendChild(ed);
      form.appendChild(field('Search title (optional)', 'a-mt', mt));
      form.appendChild(field('Search description (optional, up to 200 characters)', 'a-md', md));
      pane.appendChild(form);

      var bar = el('div', 'adm-filters');
      var save = el('button', 'adm-btn', 'Save'); save.type = 'button';
      var msg = el('span', 'adm-muted');
      bar.appendChild(save);
      var cur = a;
      function payload() {
        var p = { title: title.value.trim(), excerpt: excerpt.value.trim() || null, cover_image: cover.url || null, cover_alt: cover.url ? (cover.alt.trim() || null) : null, body_html: ed.innerHTML, author: author.value.trim() || null, meta_title: mt.value.trim() || null, meta_description: md.value.trim() || null };
        if (!slug.disabled && slug.value.trim()) { p.slug = slug.value.trim(); }
        return p;
      }
      function doSave() {
        msg.textContent = 'Saving…';
        var req = cur.id ? A().call('PUT', '/cpi-admin/articles/' + cur.id, payload()) : A().call('POST', '/cpi-admin/articles', payload());
        return req.then(function (r) { cur = r; slug.value = r.slug; msg.textContent = 'Saved' + (r.status === 'published' ? ' and live.' : ' as a draft.'); return r; })
          .catch(function (e) { msg.textContent = ''; setErr(e.message); throw e; });
      }
      save.addEventListener('click', function () { setErr(''); doSave().catch(function () {}); });
      var pub = el('button', 'adm-btn', 'Publish'); pub.type = 'button';
      var unpub = el('button', 'adm-btn', 'Unpublish'); unpub.type = 'button';
      var del = el('button', 'adm-btn', 'Delete'); del.type = 'button';
      function sync() { pub.hidden = cur.status === 'published' && !!cur.id; pub.textContent = cur.status === 'published' ? 'Republish' : 'Publish'; unpub.hidden = cur.status !== 'published'; del.hidden = !cur.id; }
      pub.addEventListener('click', function () {
        setErr('');
        doSave().then(function (r) { return A().call('POST', '/cpi-admin/articles/' + r.id + '/publish'); }).then(function (r) { cur = r; msg.textContent = 'Published: canpatentit.com/insights/' + r.slug; sync(); slug.disabled = true; }).catch(function () {});
      });
      unpub.addEventListener('click', function () { A().call('POST', '/cpi-admin/articles/' + cur.id + '/unpublish').then(function (r) { cur = r; msg.textContent = 'Unpublished. It is now a draft.'; slug.disabled = false; sync(); }).catch(function (e) { setErr(e.message); }); });
      del.addEventListener('click', function () {
        if (!window.confirm('Delete this article permanently? If it is published it will be taken offline.')) { return; }
        A().call('DELETE', '/cpi-admin/articles/' + cur.id).then(list).catch(function (e) { setErr(e.message); });
      });
      bar.appendChild(pub); bar.appendChild(unpub); bar.appendChild(del); bar.appendChild(msg); pane.appendChild(bar);
      sync();
    }).catch(function (e) { setErr(e.message); });
  }
})();
