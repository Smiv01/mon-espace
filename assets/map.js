// Carte des positions partagées (tuiles OpenStreetMap, sans bibliothèque) + envoi de ma position pendant un partage.
(function () {
  var el = document.getElementById('map');
  if (!el) return;
  var T = 256;
  var tilesBase = el.dataset.tiles || 'https://tile.openstreetmap.org';
  var points = [];
  var zoomAdj = 0;
  var follow = el.dataset.follow === '1';   // page Localisation : ma position s'affiche toujours (sur mon téléphone seulement)
  var me = null;                            // ma position détectée par l'appareil
  var view = 'me';                          // 'me' = centré sur moi, 'all' = tout le monde
  var status = document.getElementById('geo-status');
  var people = document.getElementById('people');

  try { points = JSON.parse(el.dataset.points || '[]'); } catch (e) { points = []; }

  function px(lng, z) { return (lng + 180) / 360 * Math.pow(2, z) * T; }
  function py(lat, z) {
    lat = Math.max(-85, Math.min(85, lat));
    var r = lat * Math.PI / 180;
    return (1 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / Math.PI) / 2 * Math.pow(2, z) * T;
  }
  function dist(a, b) {
    var R = 6371000, r = Math.PI / 180;
    var dLat = (b.lat - a.lat) * r, dLng = (b.lng - a.lng) * r;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a.lat * r) * Math.cos(b.lat * r) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
  }
  function distText(m) {
    if (m < 50) return 'à côté de toi';
    if (m < 1000) return 'à ' + Math.round(m / 10) * 10 + ' m';
    return 'à ' + (m / 1000).toFixed(m < 10000 ? 1 : 0).replace('.', ',') + ' km';
  }
  // Points à dessiner : ceux du serveur (sans moi si je me connais déjà) + moi (position de l'appareil)
  function allPoints() {
    var list = points.filter(function (p) { return !(me && p.me); });
    if (me) list.push({ name: 'Moi', me: true, lat: me.lat, lng: me.lng, acc: me.acc, ago: 0 });
    return list;
  }
  function say(t) { if (status) status.textContent = t; }

  function ago(s) {
    if (s === null || s === undefined) return 'en attente de position';
    if (s < 60) return "à l'instant";
    if (s < 3600) return 'il y a ' + Math.floor(s / 60) + ' min';
    return 'il y a ' + Math.floor(s / 3600) + ' h';
  }

  function render() {
    var W = el.clientWidth || 320, H = el.clientHeight || 280;
    el.textContent = '';
    var pts = allPoints().filter(function (p) { return p.lat !== null && p.lng !== null; });
    if (!pts.length) {
      var empty = document.createElement('div');
      empty.className = 'map-empty';
      empty.textContent = follow ? 'Recherche de ta position… Autorise la localisation quand ton téléphone te le demande.' : 'Aucune position à afficher pour le moment.';
      el.appendChild(empty);
      return;
    }
    var minLat = 90, maxLat = -90, minLng = 180, maxLng = -180;
    var focus = follow && me && view === 'me' ? [me] : pts;
    focus.forEach(function (p) {
      minLat = Math.min(minLat, p.lat); maxLat = Math.max(maxLat, p.lat);
      minLng = Math.min(minLng, p.lng); maxLng = Math.max(maxLng, p.lng);
    });
    var z = 16;
    if (focus.length > 1) {
      for (z = 17; z > 2; z--) {
        if (px(maxLng, z) - px(minLng, z) <= W - 100 && py(minLat, z) - py(maxLat, z) <= H - 100) break;
      }
    }
    z = Math.max(2, Math.min(18, z + zoomAdj));
    var cx = (px(minLng, z) + px(maxLng, z)) / 2, cy = (py(minLat, z) + py(maxLat, z)) / 2;
    var left = cx - W / 2, top = cy - H / 2, n = Math.pow(2, z);
    for (var tx = Math.floor(left / T); tx <= Math.floor((left + W) / T); tx++) {
      for (var ty = Math.floor(top / T); ty <= Math.floor((top + H) / T); ty++) {
        if (ty < 0 || ty >= n) continue;
        var img = document.createElement('img');
        img.className = 'map-tile';
        img.alt = '';
        img.width = T; img.height = T;
        img.draggable = false;
        img.referrerPolicy = 'origin';
        img.src = tilesBase + '/' + z + '/' + (((tx % n) + n) % n) + '/' + ty + '.png';
        img.style.left = Math.round(tx * T - left) + 'px';
        img.style.top = Math.round(ty * T - top) + 'px';
        el.appendChild(img);
      }
    }
    pts.forEach(function (p) {
      var pin = document.createElement('div');
      pin.className = 'map-pin' + (p.me ? ' me' : '');
      pin.style.left = Math.round(px(p.lng, z) - left) + 'px';
      pin.style.top = Math.round(py(p.lat, z) - top) + 'px';
      var dot = document.createElement('span');
      dot.textContent = (p.name || '?').charAt(0).toUpperCase();
      var lab = document.createElement('small');
      lab.textContent = p.me ? 'Moi' : p.name;
      pin.appendChild(dot); pin.appendChild(lab);
      el.appendChild(pin);
    });
    var ctl = document.createElement('div');
    ctl.className = 'map-zoom';
    [['+', 1, 'Zoom avant'], ['−', -1, 'Zoom arrière']].forEach(function (d) {
      var b = document.createElement('button');
      b.type = 'button'; b.textContent = d[0]; b.setAttribute('aria-label', d[2]);
      b.addEventListener('click', function () { zoomAdj += d[1]; render(); });
      ctl.appendChild(b);
    });
    if (follow) {
      [['🎯', 'me', 'Me centrer sur ma position'], ['👥', 'all', 'Voir tout le monde']].forEach(function (d) {
        var b = document.createElement('button');
        b.type = 'button'; b.textContent = d[0]; b.setAttribute('aria-label', d[2]); b.title = d[2];
        b.addEventListener('click', function () { view = d[1]; zoomAdj = 0; render(); });
        ctl.appendChild(b);
      });
    }
    el.appendChild(ctl);
  }

  function renderPeople() {
    if (!people) return;
    people.textContent = '';
    var others = follow ? points.filter(function (p) { return !p.me; }) : points;
    if (!others.length) {
      var d = document.createElement('div');
      d.className = 'card muted';
      d.textContent = 'Personne ne partage sa position pour le moment.';
      people.appendChild(d);
      return;
    }
    others.forEach(function (p) {
      var row = document.createElement('div'); row.className = 'task';
      var av = document.createElement('span'); av.className = 'mini-av'; av.textContent = (p.name || '?').charAt(0).toUpperCase();
      var main = document.createElement('span'); main.className = 'task-main';
      var s = document.createElement('strong'); s.textContent = p.name + (p.me ? ' (toi)' : '');
      var sub = document.createElement('span'); sub.className = 'sub';
      var txt = ago(p.ago);
      if (follow && me && p.lat !== null && p.lng !== null) txt += ' · ' + distText(dist(me, p));
      sub.textContent = txt;
      main.appendChild(s); main.appendChild(sub); row.appendChild(av); row.appendChild(main);
      if (follow && p.lat !== null && p.lng !== null) {
        var go = document.createElement('a');
        go.className = 'btn'; go.textContent = 'Itinéraire'; go.target = '_blank'; go.rel = 'noopener';
        go.href = 'https://www.google.com/maps/dir/?api=1&destination=' + p.lat + ',' + p.lng;
        row.appendChild(go);
      }
      people.appendChild(row);
    });
  }

  function refresh() {
    if (!el.dataset.url || !window.fetch) return;
    fetch(el.dataset.url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && j.points) { points = j.points; render(); renderPeople(); } })
      .catch(function () {});
  }

  // Position de l'appareil : affichée tout de suite sur la carte (sur ce téléphone seulement),
  // et envoyée aux proches uniquement pendant un partage que j'ai activé.
  function startGeo() {
    var sharing = el.dataset.sharing === '1';
    if (!follow && !sharing) return;
    if (!navigator.geolocation) { say("Ton navigateur ne sait pas donner ta position."); return; }
    if (window.isSecureContext === false) { say('La localisation demande une connexion sécurisée (https).'); return; }
    var last = 0, first = true;
    say('Recherche de ta position…');
    navigator.geolocation.watchPosition(function (pos) {
      me = { lat: pos.coords.latitude, lng: pos.coords.longitude, acc: Math.round(pos.coords.accuracy) };
      if (first) { first = false; say(sharing ? 'Position trouvée. Tes proches te voient en direct.' : 'Position trouvée. Personne ne la voit : elle reste sur ton téléphone.'); }
      render(); renderPeople();
      if (!sharing) return;
      var now = Date.now();
      if (now - last < 15000) return;
      last = now;
      var body = new URLSearchParams();
      body.set('csrf', el.dataset.csrf || '');
      body.set('lat', pos.coords.latitude);
      body.set('lng', pos.coords.longitude);
      body.set('acc', pos.coords.accuracy);
      fetch(el.dataset.update, { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) { var d = new Date(); say('Position envoyée à ' + ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + '.'); refresh(); }
          else say("Le partage n'est plus actif.");
        })
        .catch(function () { say("Impossible d'envoyer ta position (connexion ?)."); });
    }, function (err) {
      say(err && err.code === 1 ? "Autorisation refusée : active la localisation pour ce site dans les réglages de ton navigateur." : 'Position introuvable pour le moment.');
    }, { enableHighAccuracy: true, maximumAge: 10000, timeout: 30000 });
  }

  render();
  renderPeople();
  startGeo();
  setInterval(refresh, 15000);
  window.addEventListener('resize', render);
})();
