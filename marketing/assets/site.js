(function () {
  'use strict';

  var CONSENT_KEY = 'mypl_cookie_consent';

  function meta(name) {
    var el = document.querySelector('meta[name="' + name + '"]');
    return el ? (el.getAttribute('content') || '').trim() : '';
  }

  function readConsent() {
    try { return window.localStorage.getItem(CONSENT_KEY); } catch (e) { return null; }
  }
  function writeConsent(value) {
    try { window.localStorage.setItem(CONSENT_KEY, value); } catch (e) { /* storage blocked */ }
  }

  /* ---------- Analytics (loads only after explicit consent) ---------- */
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

  /* ---------- Cookie consent banner ---------- */
  var banner = document.getElementById('cookie-banner');
  function showBanner() { if (banner) { banner.hidden = false; } }
  function hideBanner() { if (banner) { banner.hidden = true; } }

  if (banner) {
    var current = readConsent();
    if (current === 'granted') { loadAnalytics(); } else if (current !== 'denied') { showBanner(); }

    var accept = document.getElementById('cookie-accept');
    var reject = document.getElementById('cookie-reject');
    if (accept) { accept.addEventListener('click', function () { writeConsent('granted'); hideBanner(); loadAnalytics(); }); }
    if (reject) { reject.addEventListener('click', function () { writeConsent('denied'); hideBanner(); }); }

    Array.prototype.forEach.call(document.querySelectorAll('[data-cookie-settings]'), function (el) {
      el.addEventListener('click', function (ev) { ev.preventDefault(); showBanner(); });
    });
  }

  /* ---------- Legal pages: show the version a system admin saved in the portal ---------- */
  var legalBody = document.getElementById('legal-body');
  if (legalBody && legalBody.getAttribute('data-legal-slug')) {
    var apiBase = (meta('contact-endpoint') || 'https://login.myipstrategy.com/api/public/contact').replace(/\/api\/public\/contact$/, '');
    fetch(apiBase + '/api/public/pages/' + encodeURIComponent(legalBody.getAttribute('data-legal-slug')), { headers: { 'Accept': 'application/json' } })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) {
        // Server-sanitized allow-listed HTML. On any failure the static text above stays.
        if (data && typeof data.html === 'string' && data.html.length > 0) { legalBody.innerHTML = data.html; }
      })
      .catch(function () { /* keep static fallback */ });
  }

  /* ---------- Contact form ---------- */
  var form = document.getElementById('contact-form');
  if (!form) { return; }

  var statusEl = document.getElementById('form-status');
  var submitBtn = document.getElementById('contact-submit');
  var startedAt = Date.now();
  var turnstileToken = '';
  var turnstileKey = meta('turnstile-site-key');
  var endpoint = meta('contact-endpoint') || 'https://login.myipstrategy.com/api/public/contact';

  if (turnstileKey) {
    window.onTurnstileLoad = function () {
      window.turnstile.render('#turnstile-widget', {
        sitekey: turnstileKey,
        callback: function (token) { turnstileToken = token; },
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
    name: function (v) { return v.length < 2 ? 'Please enter your name.' : (v.length > 100 ? 'Name is too long.' : ''); },
    email: function (v) { return !EMAIL_RE.test(v) ? 'Please enter a valid email address.' : (v.length > 150 ? 'Email is too long.' : ''); },
    organization: function (v) { return v.length > 150 ? 'Organization name is too long.' : ''; },
    message: function (v) { return v.length < 10 ? 'Please tell us a little more (at least 10 characters).' : (v.length > 2000 ? 'Message is too long (2000 characters max).' : ''); }
  };

  function setError(field, message) {
    var input = form.elements[field];
    var out = document.getElementById(field + '-error');
    if (out) { out.textContent = message; }
    if (input) {
      if (message) { input.setAttribute('aria-invalid', 'true'); } else { input.removeAttribute('aria-invalid'); }
    }
  }

  function validateField(field) {
    var input = form.elements[field];
    var message = rules[field](input.value.trim());
    setError(field, message);
    return message === '';
  }

  Object.keys(rules).forEach(function (field) {
    form.elements[field].addEventListener('blur', function () { validateField(field); });
  });

  function showStatus(kind, text) {
    statusEl.className = 'form-status ' + kind;
    statusEl.textContent = text;
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    statusEl.className = 'form-status';
    statusEl.textContent = '';

    var firstInvalid = null;
    Object.keys(rules).forEach(function (field) {
      if (!validateField(field) && !firstInvalid) { firstInvalid = form.elements[field]; }
    });
    if (firstInvalid) { firstInvalid.focus(); return; }

    if (turnstileKey && !turnstileToken) {
      showStatus('err', 'Please complete the verification check and try again.');
      return;
    }

    submitBtn.disabled = true;
    var originalLabel = submitBtn.textContent;
    submitBtn.textContent = 'Sending…';

    var payload = {
      name: form.elements.name.value.trim(),
      email: form.elements.email.value.trim(),
      organization: form.elements.organization.value.trim(),
      message: form.elements.message.value.trim(),
      website: form.elements.website.value,
      elapsed_ms: Date.now() - startedAt,
      'cf-turnstile-response': turnstileToken
    };

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (body) { return { status: res.status, body: body }; });
    }).then(function (r) {
      if (r.status >= 200 && r.status < 300) {
        form.reset();
        startedAt = Date.now();
        showStatus('ok', 'Thank you. We have received your message and will reply by email shortly.');
        if (window.gtag && readConsent() === 'granted') { window.gtag('event', 'generate_lead', { method: 'contact_form' }); }
      } else if (r.status === 422 && r.body && r.body.errors) {
        Object.keys(r.body.errors).forEach(function (field) {
          if (rules[field]) { setError(field, r.body.errors[field][0]); }
        });
        showStatus('err', 'Please correct the highlighted fields.');
      } else if (r.status === 429) {
        showStatus('err', 'Too many attempts. Please wait a minute and try again.');
      } else {
        showStatus('err', 'We could not send your message. Please try again shortly.');
      }
    }).catch(function () {
      showStatus('err', 'Network error. Please check your connection and try again.');
    }).then(function () {
      submitBtn.disabled = false;
      submitBtn.textContent = originalLabel;
      if (window.turnstile && turnstileKey) { turnstileToken = ''; try { window.turnstile.reset(); } catch (e) { /* ignore */ } }
    });
  });
})();
