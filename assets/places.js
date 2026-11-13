// Ajout d'un lieu : utilise la position de l'appareil.
  (function () {
    var b = document.getElementById('place-locate'), st = document.getElementById('place-status');
    if (!b) return;
    b.addEventListener('click', function () {
      if (!navigator.geolocation) { st.textContent = 'Ton navigateur ne sait pas donner ta position.'; return; }
      st.textContent = 'Recherche de ta position…';
      navigator.geolocation.getCurrentPosition(function (pos) {
        document.getElementById('place-lat').value = pos.coords.latitude.toFixed(6);
        document.getElementById('place-lng').value = pos.coords.longitude.toFixed(6);
        document.getElementById('place-save').disabled = false;
        st.textContent = 'Position trouvée (précision ' + Math.round(pos.coords.accuracy) + ' m). Tu peux ajouter.';
      }, function () { st.textContent = 'Position introuvable : autorise la localisation pour ce site.'; }, { enableHighAccuracy: true, timeout: 20000 });
    });
  })();
