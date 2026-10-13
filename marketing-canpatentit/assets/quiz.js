(function () {
  'use strict';
  var root = document.getElementById('quiz');
  if (!root) { return; }

  var QUESTIONS = [
    { id: 'what', q: 'What best describes your invention?', help: 'Pick the closest fit.', opts: [
      ['software', 'Software, AI or data system'], ['hardware', 'Hardware, device or electronics'], ['materials', 'Material, chemistry or biotech'], ['process', 'Manufacturing or industrial process'], ['other', 'Something else'] ] },
    { id: 'stage', q: 'How far along is it?', help: 'A rough answer is fine.', opts: [
      ['idea', 'An idea or early research'], ['proto', 'A working prototype or proof of concept'], ['product', 'A product in testing or early customers'], ['market', 'Already on the market'] ] },
    { id: 'disclosure', q: 'Has it been shown publicly?', help: 'Papers, talks, demos, product launches, open-source releases, sales offers and pitch decks sent without an agreement can all count.', opts: [
      ['no', 'No, nothing public'], ['nda', 'Only under confidentiality agreements'], ['recent', 'Yes, within the last 12 months'], ['old', 'Yes, more than 12 months ago'], ['unsure', 'I am not sure'] ] },
    { id: 'problem', q: 'Does it solve a specific technical problem in a new way?', help: 'For example faster, smaller, more accurate, more secure, cheaper to make.', opts: [
      ['clear', 'Yes, I can describe the problem and what is different'], ['partly', 'Partly, I am still working out what is different'], ['no', 'Not really, it is mostly a business or presentation idea'] ] },
    { id: 'prior', q: 'Have you looked for similar patents or products?', help: 'Even a quick web or patent search counts.', opts: [
      ['none', 'No search yet'], ['some', 'A quick search, nothing identical found'], ['similar', 'I found similar things'], ['thorough', 'A professional search was done'] ] },
    { id: 'goal', q: 'What matters most right now?', help: 'This shapes the next step we would suggest.', opts: [
      ['investors', 'Showing investors a clear IP position'], ['early', 'Securing an early filing date'], ['competitors', 'Keeping competitors from copying it'], ['unsure', 'Understanding my options'] ] }
  ];

  var answers = {};
  var step = 0;

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }

  function render() {
    root.textContent = '';
    if (step >= QUESTIONS.length) { return renderResult(); }
    var q = QUESTIONS[step];
    var bar = el('div', 'cpi-progress'); var fill = el('div'); fill.style.width = Math.round((step / QUESTIONS.length) * 100) + '%'; bar.appendChild(fill);
    root.appendChild(bar);
    root.appendChild(el('p', 'cpi-fine', 'Question ' + (step + 1) + ' of ' + QUESTIONS.length));
    var h = el('h2', null, q.q); h.tabIndex = -1; root.appendChild(h);
    root.appendChild(el('p', 'cpi-q-help', q.help));
    var fs = el('fieldset', 'cpi-options');
    var lg = el('legend', 'cpi-honeypot', q.q); fs.appendChild(lg);
    q.opts.forEach(function (o) {
      var lab = el('label', 'cpi-option');
      var inp = document.createElement('input'); inp.type = 'radio'; inp.name = q.id; inp.value = o[0];
      if (answers[q.id] === o[0]) { inp.checked = true; }
      inp.addEventListener('change', function () { answers[q.id] = o[0]; next.disabled = false; });
      lab.appendChild(inp); lab.appendChild(el('span', null, o[1])); fs.appendChild(lab);
    });
    root.appendChild(fs);
    var nav = el('div', 'cpi-quiz-nav');
    var back = el('button', 'cpi-btn-ghost', 'Back'); back.type = 'button'; back.disabled = step === 0;
    back.addEventListener('click', function () { step--; render(); });
    var next = el('button', 'cpi-button-lime', step === QUESTIONS.length - 1 ? 'See my result' : 'Next'); next.type = 'button'; next.disabled = !answers[q.id];
    next.addEventListener('click', function () { step++; render(); });
    nav.appendChild(back); nav.appendChild(next); root.appendChild(nav);
    h.focus();
  }

  // Guidance only. Never a legal opinion about patentability.
  function evaluate(a) {
    var points = [], urgent = false, ready = 0;
    if (a.disclosure === 'recent' || a.disclosure === 'unsure') {
      urgent = true;
      points.push('Public disclosure can limit patent rights. In the US there is generally a one-year window after your own disclosure, but many other countries have none. Talk to a patent professional soon and avoid further disclosure until you have.');
    } else if (a.disclosure === 'old') {
      urgent = true;
      points.push('A public disclosure more than 12 months ago may already affect your US rights. It is worth a prompt conversation to understand where you stand.');
    } else if (a.disclosure === 'nda') {
      points.push('Sharing only under confidentiality agreements is good practice. Keep doing so until you have decided how to protect the invention.');
      ready++;
    } else { points.push('Keeping the invention non-public keeps your options open. Try to file or plan before any paper, demo, launch or investor deck goes out.'); ready++; }
    if (a.problem === 'clear') { points.push('A clear technical problem and difference is the strongest starting point for a filing. Writing it down in plain words will make a first conversation more productive.'); ready += 2; }
    else if (a.problem === 'partly') { points.push('Spend a little time pinning down what is technically different. A first conversation can help you find it.'); ready++; }
    else { points.push('Patents protect technical solutions rather than business ideas or presentations. There may still be a protectable technical angle worth exploring together.'); }
    if (a.prior === 'none') { points.push('A prior-art search would show how crowded the area is before you spend on a filing.'); }
    else if (a.prior === 'similar') { points.push('Similar existing work does not rule out protection but it shapes what to claim. A professional comparison is worthwhile.'); }
    else { ready++; }
    if (a.stage === 'idea') { points.push('At an early stage a provisional application can secure a date cheaply, provided the description is detailed enough to support later claims.'); }
    if (a.stage === 'market') { points.push('A product already on the market makes disclosure dates important. Bring the launch and first-sale dates to the conversation.'); urgent = true; }
    var title, tag;
    if (urgent) { title = 'Time-sensitive: speak with a patent professional soon'; tag = 'warn'; }
    else if (ready >= 4) { title = 'Good candidate for a patent strategy conversation'; tag = ''; }
    else { title = 'Worth a conversation to find the protectable core'; tag = ''; }
    return { title: title, tag: tag, points: points, urgent: urgent };
  }

  function summary(a, r) {
    var lines = [];
    QUESTIONS.forEach(function (q) {
      var label = ''; q.opts.forEach(function (o) { if (o[0] === a[q.id]) { label = o[1]; } });
      lines.push(q.q + ' ' + label);
    });
    return 'Patentability self-check result: ' + r.title + '\n\n' + lines.join('\n');
  }

  function renderResult() {
    var r = evaluate(answers);
    var wrap = el('div', 'cpi-result');
    var bar = el('div', 'cpi-progress'); var fill = el('div'); fill.style.width = '100%'; bar.appendChild(fill); wrap.appendChild(bar);
    wrap.appendChild(el('span', 'tag' + (r.tag ? ' ' + r.tag : ''), r.urgent ? 'TIME-SENSITIVE' : 'GUIDANCE'));
    var h = el('h2', null, r.title); h.tabIndex = -1; wrap.appendChild(h);
    var ul = el('ul'); r.points.forEach(function (p) { ul.appendChild(el('li', null, p)); }); wrap.appendChild(ul);
    wrap.appendChild(el('p', 'cpi-fine', 'This is general guidance, not legal advice and not an opinion on whether your invention is patentable.'));

    var actions = el('div', 'cpi-hero-actions');
    var book = el('a', 'cpi-button-lime', 'Book a consultation'); book.href = '/book'; actions.appendChild(book);
    var again = el('button', 'cpi-btn-ghost', 'Start again'); again.type = 'button';
    again.addEventListener('click', function () { answers = {}; step = 0; render(); });
    actions.appendChild(again); wrap.appendChild(actions);

    var send = el('form', 'cpi-send cpi-form'); send.noValidate = true;
    send.appendChild(el('h3', null, 'Want us to look at your answers?'));
    send.appendChild(el('p', 'cpi-fine', 'Optional. We will receive your answers and contact details and reply by email. Keep confidential details out.'));
    var f1 = el('div', 'cpi-field-pair');
    f1.innerHTML = '<label for="q-name">Your name<input id="q-name" name="name" autocomplete="name" maxlength="100"></label><label for="q-email">Work email<input id="q-email" name="email" type="email" autocomplete="email" maxlength="150"></label>';
    send.appendChild(f1);
    var hp = el('div', 'cpi-honeypot'); hp.setAttribute('aria-hidden', 'true'); hp.innerHTML = '<label>Website<input name="website" tabindex="-1" autocomplete="off"></label>'; send.appendChild(hp);
    var tsBox = el('div'); tsBox.id = 'quiz-turnstile'; send.appendChild(tsBox);
    var btn = el('button', 'cpi-button-lime', 'Send my answers'); btn.type = 'submit'; send.appendChild(btn);
    var status = el('p', 'cpi-form-status'); status.setAttribute('role', 'status'); send.appendChild(status);
    wrap.appendChild(send);
    root.appendChild(wrap);
    h.focus();

    var ts = window.CPI.turnstile('#quiz-turnstile');
    var startedAt = Date.now() - Math.round(window.performance.now());
    send.addEventListener('submit', function (ev) {
      ev.preventDefault();
      status.className = 'cpi-form-status'; status.textContent = '';
      var name = send.elements.name.value.trim(); var email = send.elements.email.value.trim();
      if (name.length < 2) { status.className = 'cpi-form-status err'; status.textContent = 'Please enter your name.'; return; }
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) { status.className = 'cpi-form-status err'; status.textContent = 'Please enter a valid email address.'; return; }
      if (ts.enabled && !ts.token) { status.className = 'cpi-form-status err'; status.textContent = 'Please complete the verification check.'; return; }
      btn.disabled = true;
      var payload = { name: name, email: email, message: summary(answers, r), source: 'canpatentit-quiz', answers: answers, website: send.elements.website.value, elapsed_ms: Date.now() - startedAt };
      if (ts.token) { payload['cf-turnstile-response'] = ts.token; }
      fetch(window.CPI.meta('contact-endpoint'), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(payload) })
        .then(function (res) { return res.json().catch(function () { return {}; }).then(function (d) { return { ok: res.ok, d: d }; }); })
        .then(function (x) {
          if (x.ok) { send.textContent = ''; send.appendChild(el('p', null, 'Thank you. We have your answers and will reply by email.')); return; }
          status.className = 'cpi-form-status err'; status.textContent = (x.d && x.d.message) || 'We could not send that. Please try again.'; btn.disabled = false; ts.reset();
        })
        .catch(function () { status.className = 'cpi-form-status err'; status.textContent = 'Network problem. Please try again.'; btn.disabled = false; });
    });
  }

  render();
})();
