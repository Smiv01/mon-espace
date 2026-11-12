// Afficher / masquer le mot de passe (amélioration facultative : le site fonctionne sans JavaScript).
document.querySelectorAll('.js-toggle-password').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var input = document.getElementById(btn.dataset.target);
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? 'Masquer' : 'Voir';
    btn.setAttribute('aria-label', show ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
  });
});

// Confirmation avant les suppressions (le site fonctionne aussi sans JavaScript).
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
  f.addEventListener('submit', function (e) {
    if (!window.confirm(f.dataset.confirm)) e.preventDefault();
  });
});

// Choix du thème : Clair / Auto (suit le téléphone) / Sombre.
(function () {
  var root = document.documentElement;
  var btns = document.querySelectorAll('[data-theme-set]');
  if (!btns.length) return;
  function current() {
    var t = root.getAttribute('data-theme');
    return t === 'light' || t === 'dark' ? t : 'auto';
  }
  function mark() {
    var c = current();
    btns.forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.themeSet === c ? 'true' : 'false'); });
  }
  btns.forEach(function (b) {
    b.addEventListener('click', function () {
      var v = b.dataset.themeSet;
      try {
        if (v === 'auto') { localStorage.removeItem('me-theme'); } else { localStorage.setItem('me-theme', v); }
      } catch (e) {}
      if (v === 'auto') { root.removeAttribute('data-theme'); } else { root.setAttribute('data-theme', v); }
      mark();
    });
  });
  mark();
})();

// Copier un lien (invitation).
document.querySelectorAll('[data-copy]').forEach(function (b) {
  b.addEventListener('click', function () {
    var el = document.getElementById(b.dataset.copy);
    if (!el) return;
    el.select();
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(el.value); } else { document.execCommand('copy'); }
      b.textContent = 'Lien copié ✓';
    } catch (e) {}
  });
});

// Ma Journée : affiche le nom de la photo choisie
(function () {
  var inp = document.querySelector('.compose input[type=file]');
  var out = document.getElementById('photo-name');
  if (inp && out) {
    inp.addEventListener('change', function () {
      out.textContent = inp.files && inp.files[0] ? '📷 ' + inp.files[0].name : '';
    });
  }
})();

// Application installable : service worker + bouton « Installer »
(function () {
  var link = document.querySelector('link[rel=manifest]');
  if ('serviceWorker' in navigator && link && (window.isSecureContext || location.hostname === 'localhost')) {
    navigator.serviceWorker.register(new URL('sw.js', link.href).pathname).catch(function () {});
  }
  var btn = document.getElementById('install-btn');
  var help = document.getElementById('install-help');
  var deferred = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    if (btn) { btn.hidden = false; if (help) help.hidden = true; }
  });
  if (btn) {
    btn.addEventListener('click', function () {
      if (!deferred) return;
      deferred.prompt();
      deferred.userChoice.then(function () { deferred = null; btn.hidden = true; if (help) help.hidden = false; });
    });
  }
  window.addEventListener('appinstalled', function () {
    if (btn) btn.hidden = true;
    if (help) { help.hidden = false; help.textContent = 'Lyvo est installé sur cet appareil.'; }
  });
})();
