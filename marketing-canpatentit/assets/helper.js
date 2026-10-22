/* CanPatentIt interactions: patent journey, scenario cards, myth cards, glossary tips, count-up facts, scroll
   progress, and the "talk to us" helper. Everything except the helper's Send runs locally; nothing is stored. */
(function () {
  'use strict';

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
  function svgIcon(id) {
    var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('class', 'i'); s.setAttribute('aria-hidden', 'true');
    var u = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    u.setAttributeNS('http://www.w3.org/1999/xlink', 'href', '#i-' + id); u.setAttribute('href', '#i-' + id);
    s.appendChild(u); return s;
  }

  /* ---------- Scroll progress ---------- */
  (function () {
    var bar = el('div', 'cpi-progress'); bar.setAttribute('aria-hidden', 'true');
    document.body.appendChild(bar);
    var tick = false;
    function update() {
      tick = false;
      var h = document.documentElement.scrollHeight - window.innerHeight;
      bar.style.transform = 'scaleX(' + (h > 0 ? Math.min(1, window.scrollY / h) : 0).toFixed(4) + ')';
    }
    window.addEventListener('scroll', function () { if (!tick) { tick = true; requestAnimationFrame(update); } }, { passive: true });
    update();
  })();

  /* ---------- Count-up on the "at a glance" facts ---------- */
  (function () {
    var nums = $$('.cpi-fact strong');
    if (!nums.length || reduce || !('IntersectionObserver' in window)) { return; }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) { return; }
        io.unobserve(en.target);
        var node = en.target, m = /(\d+)/.exec(node.textContent);
        if (!m || m[1].length > 3) { return; }              // leave years such as "Since 2006" alone
        var end = +m[1], pre = node.textContent.slice(0, m.index), post = node.textContent.slice(m.index + m[1].length), t0 = null;
        function step(ts) {
          if (t0 === null) { t0 = ts; }
          var p = Math.min(1, (ts - t0) / 900), v = Math.round(end * (1 - Math.pow(1 - p, 3)));
          node.textContent = pre + v + post;
          if (p < 1) { requestAnimationFrame(step); }
        }
        node.textContent = pre + '0' + post;
        requestAnimationFrame(step);
      });
    }, { threshold: 0.6 });
    nums.forEach(function (n) { io.observe(n); });
  })();

  /* ---------- Patent journey (tabs) ---------- */
  $$('[data-journey]').forEach(function (root) {
    var tabs = $$('[role="tab"]', root), panels = $$('[role="tabpanel"]', root);
    function select(i, focus) {
      tabs.forEach(function (t, k) {
        var on = k === i;
        t.setAttribute('aria-selected', on ? 'true' : 'false'); t.tabIndex = on ? 0 : -1;
        t.classList.toggle('done', k < i);
        panels[k].hidden = !on;
      });
      root.style.setProperty('--j-progress', (tabs.length > 1 ? i / (tabs.length - 1) : 0).toFixed(3));
      if (focus) { tabs[i].focus(); }
    }
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { select(i); });
      t.addEventListener('keydown', function (ev) {
        var k = null;
        if (ev.key === 'ArrowRight' || ev.key === 'ArrowDown') { k = (i + 1) % tabs.length; }
        if (ev.key === 'ArrowLeft' || ev.key === 'ArrowUp') { k = (i - 1 + tabs.length) % tabs.length; }
        if (ev.key === 'Home') { k = 0; }
        if (ev.key === 'End') { k = tabs.length - 1; }
        if (k !== null) { ev.preventDefault(); select(k, true); }
      });
    });
    select(0);
  });

  /* ---------- Scenario cards ---------- */
  $$('[data-scenarios]').forEach(function (grid) {
    var cards = $$('[data-scn]', grid), panels = $$('[data-scn-panel]', grid.parentNode);
    panels.forEach(function (p) { p.hidden = true; });
    cards.forEach(function (c) {
      c.addEventListener('click', function () {
        var open = c.getAttribute('aria-expanded') === 'true';
        cards.forEach(function (o) { o.setAttribute('aria-expanded', 'false'); o.classList.remove('on'); });
        panels.forEach(function (p) { p.hidden = true; });
        if (!open) {
          c.setAttribute('aria-expanded', 'true'); c.classList.add('on');
          var p = document.getElementById(c.getAttribute('aria-controls'));
          if (p) { p.hidden = false; if (!reduce) { p.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } }
        }
      });
    });
  });

  /* ---------- Myth or fact cards ---------- */
  $$('.cpi-myth').forEach(function (c) {
    c.addEventListener('click', function () {
      var on = c.getAttribute('aria-pressed') === 'true';
      c.setAttribute('aria-pressed', on ? 'false' : 'true');
      c.classList.toggle('flipped', !on);
    });
  });

  /* ---------- Glossary tips ---------- */
  (function () {
    var G = window.CPI_GLOSSARY, main = $('main');
    if (!G || !G.length || !main) { return; }
    var tip = el('div', 'cpi-tip'); tip.id = 'cpi-tip'; tip.setAttribute('role', 'tooltip'); tip.hidden = true;
    var tipTerm = el('strong'), tipDef = el('span');
    tip.appendChild(tipTerm); tip.appendChild(tipDef); document.body.appendChild(tip);
    var current = null;
    function hide() { tip.hidden = true; if (current) { current.removeAttribute('aria-describedby'); } current = null; }
    function show(node) {
      current = node; tipTerm.textContent = node.getAttribute('data-term'); tipDef.textContent = node.getAttribute('data-def');
      node.setAttribute('aria-describedby', 'cpi-tip'); tip.hidden = false;
      var r = node.getBoundingClientRect(), w = tip.offsetWidth, x = Math.max(12, Math.min(window.innerWidth - w - 12, r.left + r.width / 2 - w / 2));
      var below = r.bottom + 10 + tip.offsetHeight < window.innerHeight;
      tip.style.left = (x + window.scrollX) + 'px';
      tip.style.top = ((below ? r.bottom + 10 : r.top - tip.offsetHeight - 10) + window.scrollY) + 'px';
    }
    var skip = 'a,button,summary,h1,h2,h3,h4,h5,h6,label,script,style,nav,.cpi-gloss,.cpi-tool,.cpi-page-hero,.cpi-hero,.cpi-term,[data-no-gloss]';
    var done = {};
    function wrapFirst(textNode, g) {
      var text = textNode.nodeValue, found = null;
      g.m.some(function (phrase) {
        var re = new RegExp('(^|[^A-Za-z])(' + phrase.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')(?![A-Za-z])', phrase === phrase.toUpperCase() ? '' : 'i');
        var m = re.exec(text);
        if (m) { found = { start: m.index + m[1].length, len: m[2].length }; return true; }
        return false;
      });
      if (!found) { return false; }
      var after = textNode.splitText(found.start);
      after.splitText(found.len);
      var span = el('span', 'cpi-term', after.nodeValue);
      span.tabIndex = 0; span.setAttribute('role', 'button'); span.setAttribute('data-term', g.t); span.setAttribute('data-def', g.d);
      after.parentNode.replaceChild(span, after);
      span.addEventListener('mouseenter', function () { show(span); });
      span.addEventListener('mouseleave', hide);
      span.addEventListener('focus', function () { show(span); });
      span.addEventListener('blur', hide);
      span.addEventListener('click', function (ev) { ev.preventDefault(); if (current === span && !tip.hidden) { hide(); } else { show(span); } });
      return true;
    }
    var walker = document.createTreeWalker(main, NodeFilter.SHOW_TEXT, {
      acceptNode: function (n) {
        if (!n.nodeValue.trim() || !n.parentElement || n.parentElement.closest(skip)) { return NodeFilter.FILTER_REJECT; }
        return NodeFilter.FILTER_ACCEPT;
      }
    });
    var nodes = [], n;
    while ((n = walker.nextNode())) { nodes.push(n); }
    nodes.forEach(function (tn) {
      G.forEach(function (g) {
        if (done[g.t] || !tn.parentNode || tn.parentNode.closest('.cpi-term')) { return; }
        if (wrapFirst(tn, g)) { done[g.t] = true; } else if (!tn.parentNode) { /* split already consumed */ }
      });
    });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { hide(); } });
    document.addEventListener('click', function (ev) { if (current && !ev.target.closest('.cpi-term')) { hide(); } });
    window.addEventListener('scroll', hide, { passive: true });
  })();

  /* ---------- The helper: quick actions, then one question ---------- */
  (function () {
    var endpoint = window.CPI && window.CPI.meta ? window.CPI.meta('contact-endpoint') : '';
    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    var root = el('div', 'cpi-helper'); root.id = 'cpi-helper';
    var btn = el('button', 'cpi-helper-btn'); btn.type = 'button'; btn.setAttribute('aria-expanded', 'false'); btn.setAttribute('aria-controls', 'cpi-helper-panel');
    btn.appendChild(svgIcon('message')); btn.appendChild(el('span', '', 'Questions?'));
    var panel = el('div', 'cpi-helper-panel'); panel.id = 'cpi-helper-panel'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Talk to us'); panel.hidden = true;
    var close = el('button', 'cpi-helper-x'); close.type = 'button'; close.setAttribute('aria-label', 'Close'); close.appendChild(svgIcon('x'));

    var menu = el('div', 'cpi-helper-view');
    menu.appendChild(el('p', 'cpi-helper-hi', 'Hi, how can we help?'));
    menu.appendChild(el('p', 'cpi-helper-sub', 'Pick one. Nothing is sent unless you choose to write to us.'));
    [['/book', 'calendar', 'Book a time to talk'], ['/patentability-check', 'file-search', 'Try the patentability self-check'], ['/tools/deadlines', 'clock', 'Check my dates']].forEach(function (q) {
      var a = el('a', 'cpi-helper-action'); a.href = q[0]; a.appendChild(svgIcon(q[1])); a.appendChild(el('span', '', q[2])); a.appendChild(svgIcon('arrow-right')); menu.appendChild(a);
    });
    var askBtn = el('button', 'cpi-helper-action primary'); askBtn.type = 'button'; askBtn.setAttribute('data-go', 'ask');
    askBtn.appendChild(svgIcon('message')); askBtn.appendChild(el('span', '', 'Ask us one question')); askBtn.appendChild(svgIcon('arrow-right')); menu.appendChild(askBtn);

    var form = el('form', 'cpi-helper-view'); form.hidden = true; form.noValidate = true;
    var back = el('button', 'cpi-helper-back', '← Back'); back.type = 'button'; back.setAttribute('data-go', 'menu');
    function label(text, control, extra) { var l = el('label', extra || ''); l.appendChild(el('span', '', text)); l.appendChild(control); return l; }
    var msg = el('textarea'); msg.name = 'message'; msg.rows = 4; msg.maxLength = 1500;
    var mail = el('input'); mail.type = 'email'; mail.name = 'email'; mail.autocomplete = 'email'; mail.placeholder = 'you@company.com'; mail.maxLength = 150;
    var nm = el('input'); nm.name = 'name'; nm.autocomplete = 'given-name'; nm.maxLength = 60;
    var hp = el('input'); hp.name = 'website'; hp.tabIndex = -1; hp.autocomplete = 'off';
    var honey = el('div', 'cpi-honeypot'); honey.setAttribute('aria-hidden', 'true'); honey.appendChild(hp);
    var ts = el('div'); ts.id = 'cpi-helper-ts';
    var fine = el('p', 'cpi-helper-fine', 'A real person reads this and replies by email within one working day. Please keep confidential details out for now. We use your email only to reply.');
    var send = el('button', 'cpi-helper-send', 'Send my question'); send.type = 'submit';
    var status = el('p', 'cpi-helper-status'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
    form.appendChild(back);
    form.appendChild(label('What would you like to ask?', msg));
    form.appendChild(label('Where can we reply?', mail));
    form.appendChild(label('First name (optional)', nm, 'opt'));
    form.appendChild(honey); form.appendChild(ts); form.appendChild(fine); form.appendChild(send); form.appendChild(status);

    var done = el('div', 'cpi-helper-view'); done.hidden = true;
    done.appendChild(el('p', 'cpi-helper-hi', 'Thank you.'));
    done.appendChild(el('p', 'cpi-helper-sub', 'Your question is with our team. We will reply by email within one working day.'));
    var again = el('button', 'cpi-helper-action'); again.type = 'button'; again.setAttribute('data-go', 'menu'); again.appendChild(el('span', '', 'Back to options')); done.appendChild(again);

    panel.appendChild(close); panel.appendChild(menu); panel.appendChild(form); panel.appendChild(done);
    root.appendChild(panel); root.appendChild(btn); document.body.appendChild(root);

    var views = { menu: menu, ask: form, done: done }, openedAt = 0, topic = '', tsState = null, lastFocus = null;
    function go(name) {
      Object.keys(views).forEach(function (k) { views[k].hidden = k !== name; });
      if (name === 'ask') {
        openedAt = Date.now();
        msg.placeholder = topic ? 'Your question about ' + topic.toLowerCase() + '…' : 'Ask anything about protecting what you are building…';
        if (!tsState && window.CPI && window.CPI.turnstile) { tsState = window.CPI.turnstile('#cpi-helper-ts'); }   // only now is a third-party script loaded
        setTimeout(function () { msg.focus(); }, 30);
      }
    }
    function open(view, t) {
      topic = t || topic;
      lastFocus = document.activeElement;
      panel.hidden = false; root.classList.add('open'); btn.setAttribute('aria-expanded', 'true');
      go(view || 'menu');
      if ((view || 'menu') === 'menu') { var f = $('.cpi-helper-action', panel); if (f) { f.focus(); } }
    }
    function shut() {
      panel.hidden = true; root.classList.remove('open'); btn.setAttribute('aria-expanded', 'false');
      if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) { /* ignore */ } }
    }
    btn.addEventListener('click', function () { if (panel.hidden) { open('menu'); } else { shut(); } });
    close.addEventListener('click', shut);
    panel.addEventListener('click', function (ev) { var g = ev.target.closest('[data-go]'); if (g) { go(g.getAttribute('data-go')); } });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !panel.hidden) { shut(); } });
    document.addEventListener('click', function (ev) {
      var t = ev.target.closest('[data-helper-open]');
      if (t) { ev.preventDefault(); open(t.getAttribute('data-helper-open') === 'ask' ? 'ask' : 'menu', t.getAttribute('data-helper-topic') || ''); }
    });

    function fail(text) { status.className = 'cpi-helper-status err'; status.textContent = text; }
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      status.className = 'cpi-helper-status'; status.textContent = '';
      var q = msg.value.trim(), e = mail.value.trim();
      if (q.length < 10) { fail('Could you add a little more detail to your question?'); msg.focus(); return; }
      if (!EMAIL_RE.test(e)) { fail('Please check your email address so we can reply.'); mail.focus(); return; }
      if (tsState && tsState.enabled && !tsState.token) { fail('Please complete the quick check above, then send again.'); return; }
      send.disabled = true; var label0 = send.textContent; send.textContent = 'Sending…';
      var payload = { email: e, message: q, source: 'canpatentit-ask', stage: (location.pathname + (topic ? ' | ' + topic : '')).slice(0, 60), website: hp.value, elapsed_ms: Date.now() - openedAt };
      if (nm.value.trim().length >= 2) { payload.name = nm.value.trim(); }
      if (tsState && tsState.token) { payload['cf-turnstile-response'] = tsState.token; }
      fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(payload) })
        .then(function (res) { return res.json().catch(function () { return {}; }).then(function (d) { return { ok: res.ok, status: res.status, data: d }; }); })
        .then(function (r) {
          if (r.ok) { msg.value = ''; go('done'); return; }
          if (r.status === 429) { fail('Lots of requests just now. Please try again in a little while.'); }
          else if (r.status === 422 && r.data && r.data.errors) { fail(Object.keys(r.data.errors).map(function (k) { return r.data.errors[k][0]; })[0]); }
          else { fail((r.data && r.data.message) || 'We could not send that. Please try again, or email us directly.'); }
        })
        .catch(function () { fail('Network problem. Please try again, or email us directly.'); })
        .then(function () { send.disabled = false; send.textContent = label0; if (tsState && tsState.reset) { tsState.reset(); } });
    });

    // A gentle nudge once per visit, after the reader has engaged with the page. Never an auto-opened pop-up.
    var nudged = false;
    try { nudged = window.sessionStorage.getItem('cpi_nudge') === '1'; } catch (e) { /* storage blocked */ }
    function nudge() {
      if (nudged || !panel.hidden) { return; }
      nudged = true;
      try { window.sessionStorage.setItem('cpi_nudge', '1'); } catch (e) { /* ignore */ }
      root.classList.add('nudge');
      setTimeout(function () { root.classList.remove('nudge'); }, 7000);
    }
    window.addEventListener('scroll', function onScroll() {
      var h = document.documentElement.scrollHeight - window.innerHeight;
      if (h > 0 && window.scrollY / h > 0.4) { window.removeEventListener('scroll', onScroll); nudge(); }
    }, { passive: true });
    requestAnimationFrame(function () { root.classList.add('ready'); });
  })();
})();
