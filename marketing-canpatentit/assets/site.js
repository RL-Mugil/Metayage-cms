(function () {
  'use strict';

  function meta(name) {
    var el = document.querySelector('meta[name="' + name + '"]');
    return el ? (el.getAttribute('content') || '').trim() : '';
  }

  /* Shared helpers for the quiz and booking scripts */
  var tsLoading = null;
  window.CPI = {
    meta: meta,
    // Renders a Turnstile widget into `selector` when a site key is configured; resolves a getter for the token.
    turnstile: function (selector) {
      var key = meta('turnstile-site-key');
      var state = { token: '', enabled: !!key, reset: function () { state.token = ''; try { if (state.id !== undefined) { window.turnstile.reset(state.id); } } catch (e) { /* ignore */ } } };
      if (!key) { return state; }
      var render = function () { state.id = window.turnstile.render(selector, { sitekey: key, callback: function (t) { state.token = t; }, 'expired-callback': function () { state.token = ''; }, 'error-callback': function () { state.token = ''; } }); };
      if (window.turnstile) { render(); return state; }
      if (!tsLoading) {
        tsLoading = [];
        window.onTurnstileReady = function () { tsLoading.forEach(function (fn) { fn(); }); tsLoading = []; };
        var s = document.createElement('script');
        s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=onTurnstileReady';
        s.async = true; s.defer = true; document.head.appendChild(s);
      }
      tsLoading.push(render);
      return state;
    }
  };

  var yearEl = document.getElementById('year');
  if (yearEl) { yearEl.textContent = String(new Date().getFullYear()); }

  /* Hero photo: used when assets/hero.jpg exists, otherwise the CSS gradient stays. */
  var hero = document.querySelector('[data-hero]');
  if (hero) {
    var probe = new Image();
    probe.onload = function () { hero.classList.add('has-photo'); };
    probe.src = '/assets/hero.jpg';
  }

  /* Mobile menu */
  var nav = document.getElementById('site-nav');
  var menuBtn = document.getElementById('menu-button');
  function setMenu(open) {
    nav.classList.toggle('is-open', open);
    menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    menuBtn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    menuBtn.querySelector('use').setAttribute('href', open ? '#i-x' : '#i-menu');
  }
  if (nav && menuBtn) {
    menuBtn.addEventListener('click', function () { setMenu(!nav.classList.contains('is-open')); });
    Array.prototype.forEach.call(nav.querySelectorAll('a'), function (a) { a.addEventListener('click', function () { setMenu(false); }); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { setMenu(false); } });
  }

  /* Analytics (loads only after explicit consent) */
  var CONSENT_KEY = 'cpi_cookie_consent';
  function readConsent() { try { return window.localStorage.getItem(CONSENT_KEY); } catch (e) { return null; } }
  function writeConsent(v) { try { window.localStorage.setItem(CONSENT_KEY, v); } catch (e) { /* storage blocked */ } }
  var analyticsLoaded = false;
  function loadAnalytics() {
    var id = meta('ga-measurement-id');
    if (analyticsLoaded || !/^G-[A-Z0-9]+$/.test(id)) { return; }
    analyticsLoaded = true;
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    window.gtag('config', id, { anonymize_ip: true });
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
    document.head.appendChild(s);
  }
  var banner = document.getElementById('cookie-banner');
  if (banner) {
    var current = readConsent();
    if (current === 'granted') { loadAnalytics(); } else if (current !== 'denied') { banner.hidden = false; }
    document.getElementById('cookie-accept').addEventListener('click', function () { writeConsent('granted'); banner.hidden = true; loadAnalytics(); });
    document.getElementById('cookie-reject').addEventListener('click', function () { writeConsent('denied'); banner.hidden = true; });
    Array.prototype.forEach.call(document.querySelectorAll('[data-cookie-settings]'), function (el) {
      el.addEventListener('click', function (ev) { ev.preventDefault(); banner.hidden = false; });
    });
  }

  /* Header dropdowns (hover/focus handled in CSS; click toggles for touch and keyboard) */
  Array.prototype.forEach.call(document.querySelectorAll('.cpi-dd'), function (dd) {
    var btn = dd.querySelector('.cpi-dd-btn');
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = !dd.classList.contains('open');
      Array.prototype.forEach.call(document.querySelectorAll('.cpi-dd.open'), function (o) { o.classList.remove('open'); o.querySelector('.cpi-dd-btn').setAttribute('aria-expanded', 'false'); });
      dd.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
  document.addEventListener('click', function () {
    Array.prototype.forEach.call(document.querySelectorAll('.cpi-dd.open'), function (o) { o.classList.remove('open'); });
  });

  /* Theme toggle */
  var themeBtn = document.getElementById('theme-toggle');
  function currentTheme() {
    var t = document.documentElement.getAttribute('data-theme');
    if (t) { return t; }
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function paintThemeIcon() {
    if (!themeBtn) { return; }
    var dark = currentTheme() === 'dark';
    themeBtn.querySelector('use').setAttribute('href', dark ? '#i-sun' : '#i-moon');
    themeBtn.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
  }
  if (themeBtn) {
    paintThemeIcon();
    themeBtn.addEventListener('click', function () {
      var next = currentTheme() === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      try { window.localStorage.setItem('cpi_theme', next); } catch (e) { /* storage blocked */ }
      paintThemeIcon();
    });
  }

  /* Scroll reveal (skipped for reduced motion) */
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var revealEls = document.querySelectorAll('.cpi-intro, .cpi-services, .cpi-domain-layout, .cpi-teasers, .cpi-card, .cpi-related, .cpi-prose-sec, .cpi-side-card, .cpi-founder-copy');
  if ('IntersectionObserver' in window && !reduce) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
    }, { threshold: 0.08 });
    Array.prototype.forEach.call(revealEls, function (el) { el.classList.add('reveal'); io.observe(el); });
  }

  /* Site search (static pages + published articles) */
  var searchModal = document.getElementById('search-modal');
  var searchInput = document.getElementById('search-input');
  var searchList = document.getElementById('search-results');
  var searchData = null;
  function loadSearch() {
    if (searchData) { return Promise.resolve(searchData); }
    var all = [];
    return fetch('/assets/search-index.json').then(function (r) { return r.json(); }).then(function (pages) {
      all = pages;
      return fetch('/insights/index.json').then(function (r) { return r.ok ? r.json() : []; }).catch(function () { return []; });
    }).then(function (arts) {
      (arts || []).forEach(function (a) { all.push({ url: '/insights/' + a.slug, title: a.title, desc: a.excerpt || '', text: a.excerpt || '' }); });
      searchData = all;
      return all;
    }).catch(function () { searchData = []; return []; });
  }
  function runSearch() {
    var q = searchInput.value.trim().toLowerCase();
    searchList.textContent = '';
    if (q.length < 2) { return; }
    var terms = q.split(/\s+/);
    var scored = [];
    (searchData || []).forEach(function (p) {
      var hay = (p.title + ' ' + p.desc + ' ' + p.text).toLowerCase();
      var score = 0;
      for (var i = 0; i < terms.length; i++) {
        if (hay.indexOf(terms[i]) === -1) { return; }
        score += p.title.toLowerCase().indexOf(terms[i]) !== -1 ? 5 : 1;
      }
      scored.push({ p: p, s: score });
    });
    scored.sort(function (a, b) { return b.s - a.s; });
    scored.slice(0, 8).forEach(function (r) {
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.href = r.p.url;
      var b = document.createElement('b'); b.textContent = r.p.title;
      var s = document.createElement('span'); s.textContent = r.p.desc;
      a.appendChild(b); a.appendChild(s); li.appendChild(a); searchList.appendChild(li);
    });
    if (!scored.length) { var none = document.createElement('li'); none.className = 'cpi-fine'; none.style.padding = '10px 12px'; none.textContent = 'No matches.'; searchList.appendChild(none); }
  }
  function openSearch() { if (!searchModal) { return; } searchModal.hidden = false; searchInput.value = ''; searchList.textContent = ''; searchInput.focus(); loadSearch(); }
  function closeSearch() { if (searchModal) { searchModal.hidden = true; } }
  if (searchModal) {
    document.getElementById('search-open').addEventListener('click', openSearch);
    document.getElementById('search-close').addEventListener('click', closeSearch);
    searchModal.addEventListener('click', function (e) { if (e.target === searchModal) { closeSearch(); } });
    searchInput.addEventListener('input', function () { loadSearch().then(runSearch); });
    document.addEventListener('keydown', function (e) {
      var tag = (document.activeElement && document.activeElement.tagName) || '';
      if (e.key === 'Escape') { closeSearch(); }
      if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(tag) && searchModal.hidden) { e.preventDefault(); openSearch(); }
    });
  }

  /* Assessment form */
  var form = document.getElementById('assessment-form');
  if (!form) { return; }
  var statusEl = document.getElementById('form-status');
  var submitBtn = document.getElementById('form-submit');
  var success = document.getElementById('form-success');
  var endpoint = meta('contact-endpoint');
  var startedAt = Date.now();
  var turnstileKey = meta('turnstile-site-key');
  var turnstileToken = '';

  if (turnstileKey) {
    window.onTurnstileLoad = function () {
      window.turnstile.render('#turnstile-widget', {
        sitekey: turnstileKey,
        callback: function (t) { turnstileToken = t; },
        'expired-callback': function () { turnstileToken = ''; },
        'error-callback': function () { turnstileToken = ''; }
      });
    };
    var ts = document.createElement('script');
    ts.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=onTurnstileLoad';
    ts.async = true;
    ts.defer = true;
    document.head.appendChild(ts);
  }

  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  var rules = {
    name: function (v) { return v.length < 2 ? 'Please enter your name.' : ''; },
    email: function (v) { return !EMAIL_RE.test(v) ? 'Please enter a valid work email.' : ''; },
    organization: function (v) { return v.length < 2 ? 'Please enter your company or project.' : ''; },
    stage: function (v) { return v ? '' : 'Please select a company stage.'; },
    domain: function (v) { return v ? '' : 'Please select a technology area.'; },
    message: function (v) { return v.length < 15 ? 'Please describe what you are building (at least 15 characters).' : (v.length > 2000 ? 'Please keep this under 2000 characters.' : ''); }
  };

  function validateField(field) {
    var input = form.elements[field];
    var msg = rules[field](input.value.trim());
    document.getElementById(field + '-error').textContent = msg;
    if (msg) { input.setAttribute('aria-invalid', 'true'); } else { input.removeAttribute('aria-invalid'); }
    return msg === '';
  }
  Object.keys(rules).forEach(function (f) {
    form.elements[f].addEventListener('blur', function () { validateField(f); });
    form.elements[f].addEventListener('change', function () { if (form.elements[f].getAttribute('aria-invalid')) { validateField(f); } });
  });

  function showError(text) { statusEl.className = 'cpi-form-status err'; statusEl.textContent = text; }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    statusEl.className = 'cpi-form-status';
    statusEl.textContent = '';

    var firstInvalid = null;
    Object.keys(rules).forEach(function (f) { if (!validateField(f) && !firstInvalid) { firstInvalid = form.elements[f]; } });
    if (firstInvalid) { firstInvalid.focus(); return; }
    if (turnstileKey && !turnstileToken) { showError('Please complete the verification check and try again.'); return; }

    submitBtn.disabled = true;
    var label = submitBtn.firstChild.nodeValue;
    submitBtn.firstChild.nodeValue = 'Sending… ';

    var payload = {
      name: form.elements.name.value.trim(),
      email: form.elements.email.value.trim(),
      organization: form.elements.organization.value.trim(),
      stage: form.elements.stage.value,
      domain: form.elements.domain.value,
      message: form.elements.message.value.trim(),
      source: 'canpatentit',
      website: form.elements.website.value,
      elapsed_ms: Date.now() - startedAt
    };
    if (turnstileToken) { payload['cf-turnstile-response'] = turnstileToken; }

    fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(payload) })
      .then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) { return { ok: res.ok, status: res.status, data: data }; });
      })
      .then(function (r) {
        if (r.ok) {
          form.hidden = true;
          success.hidden = false;
          success.setAttribute('tabindex', '-1');
          success.focus();
          return;
        }
        if (r.status === 422 && r.data && r.data.errors) {
          Object.keys(r.data.errors).forEach(function (f) {
            var out = document.getElementById(f + '-error');
            if (out) { out.textContent = r.data.errors[f][0]; }
          });
          showError('Please check the highlighted fields.');
        } else if (r.status === 429) {
          showError('Too many requests. Please try again in a little while.');
        } else {
          showError((r.data && r.data.message) || 'We could not send your request. Please try again shortly or email mugilvannan@myipstrategy.com.');
        }
      })
      .catch(function () { showError('Network problem. Please try again or email mugilvannan@myipstrategy.com.'); })
      .then(function () {
        submitBtn.disabled = false;
        submitBtn.firstChild.nodeValue = label;
        if (window.turnstile && turnstileKey) { turnstileToken = ''; try { window.turnstile.reset(); } catch (e) { /* ignore */ } }
      });
  });
})();
