(function () {
  'use strict';
  var api = document.querySelector('meta[name="api-base"]').getAttribute('content');
  var form = document.getElementById('login-form');
  var err = document.getElementById('login-error');
  var btn = document.getElementById('login-submit');

  try { if (window.sessionStorage.getItem('cpi_admin_token')) { window.location.replace('/admin'); return; } } catch (e) { /* ignore */ }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    err.textContent = '';
    var email = form.elements.email.value.trim();
    var password = form.elements.password.value;
    if (!email || !password) { err.textContent = 'Enter your email and password.'; return; }
    btn.disabled = true;
    fetch(api + '/cpi-admin/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ email: email, password: password })
    })
      .then(function (res) { return res.json().catch(function () { return {}; }).then(function (d) { return { ok: res.ok, status: res.status, d: d }; }); })
      .then(function (r) {
        if (r.ok && r.d.token) {
          try {
            window.sessionStorage.setItem('cpi_admin_token', r.d.token);
            window.sessionStorage.setItem('cpi_admin_name', r.d.name || '');
          } catch (e) { err.textContent = 'Your browser blocked session storage.'; return; }
          window.location.replace('/admin');
          return;
        }
        err.textContent = r.status === 429 ? 'Too many attempts. Wait a minute and try again.' : ((r.d.errors && r.d.errors.email && r.d.errors.email[0]) || 'Sign in failed.');
      })
      .catch(function () { err.textContent = 'Network problem. Try again.'; })
      .then(function () { btn.disabled = false; form.elements.password.value = ''; });
  });
})();
