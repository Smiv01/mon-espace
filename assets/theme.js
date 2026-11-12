// Thème clair / sombre : lu avant l'affichage pour éviter un flash. Sans choix enregistré, on suit le téléphone.
(function () {
  var root = document.documentElement;
  root.classList.add('js');
  try {
    var t = localStorage.getItem('me-theme');
    if (t === 'light' || t === 'dark') root.setAttribute('data-theme', t);
  } catch (e) {}
})();
