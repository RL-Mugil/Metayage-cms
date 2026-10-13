(function () {
  document.documentElement.classList.add('js');
  try {
    var t = window.localStorage.getItem('cpi_theme');
    if (t === 'dark' || t === 'light') { document.documentElement.setAttribute('data-theme', t); }
  } catch (e) { /* storage blocked */ }
})();
