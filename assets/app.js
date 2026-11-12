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

// Ma Journée : plusieurs photos à la fois, réduites sur le téléphone avant l'envoi (plus rapide, moins de données)
(function () {
  var inp = document.querySelector('.compose input[type=file]');
  var out = document.getElementById('photo-name');
  if (!inp) { return; }
  function shrink(file) {
    return new Promise(function (resolve) {
      if (!window.createImageBitmap || !window.DataTransfer || !/^image\/(jpeg|png|webp)$/.test(file.type)) { return resolve(file); }
      createImageBitmap(file).then(function (bmp) {
        var scale = Math.min(1, 1280 / Math.max(bmp.width, bmp.height));
        var c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(bmp.width * scale));
        c.height = Math.max(1, Math.round(bmp.height * scale));
        var ctx = c.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, c.width, c.height);
        ctx.drawImage(bmp, 0, 0, c.width, c.height);
        c.toBlob(function (blob) {
          resolve(blob ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file);
        }, 'image/jpeg', 0.82);
      }).catch(function () { resolve(file); });
    });
  }
  inp.addEventListener('change', function () {
    var files = Array.prototype.slice.call(inp.files || []);
    if (out) { out.textContent = files.length ? '📷 ' + files.length + ' photo' + (files.length > 1 ? 's' : '') + (files.length > 10 ? ' (10 maximum, les autres seront ignorées)' : '') : ''; }
    if (!files.length || !window.DataTransfer) { return; }
    var form = inp.form;
    var submit = form ? form.querySelector('button[type=submit]') : null;
    if (submit) { submit.disabled = true; }
    Promise.all(files.slice(0, 10).map(shrink)).then(function (list) {
      if (submit) { submit.disabled = false; }
      try {
        var dt = new DataTransfer();
        list.forEach(function (f) { dt.items.add(f); });
        inp.files = dt.files;
      } catch (e) {}
    });
  });
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
