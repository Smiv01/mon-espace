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

  var places = [], trail = [];
  var meEmoji = el.dataset.meEmoji || '', meColor = el.dataset.meColor || '';
  var trailOn = el.dataset.trailOn === '1';
  var arrivedSent = {};
  try { points = JSON.parse(el.dataset.points || '[]'); } catch (e) { points = []; }
  try { places = JSON.parse(el.dataset.places || '[]'); } catch (e) { places = []; }
  try { trail = JSON.parse(el.dataset.trail || '[]'); } catch (e) { trail = []; }
  if (el.dataset.theme) el.classList.add('map-theme-' + el.dataset.theme);

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
    if (me) list.push({ name: 'Moi', me: true, lat: me.lat, lng: me.lng, acc: me.acc, ago: 0, emoji: meEmoji, color: meColor });
    return list;
  }
  function say(t) { if (status) status.textContent = t; }

  function ago(s) {
    if (s === null || s === undefined) return 'en attente de position';
    if (s < 60) return "à l'instant";
    if (s < 3600) return 'il y a ' + Math.floor(s / 60) + ' min';
    return 'il y a ' + Math.floor(s / 3600) + ' h';
  }

  // --- Carte déplaçable et zoomable au doigt (comme Snap) ---
  var cen = null, curZ = 12, auto = true;       // centre et zoom courants ; auto = la carte se cadre toute seule
  var ABIDJAN = { lat: 5.36, lng: -4.0083 };
  function lngAt(x, z) { return x / (T * Math.pow(2, z)) * 360 - 180; }
  function latAt(y, z) {
    var n = Math.PI - 2 * Math.PI * y / (T * Math.pow(2, z));
    return 180 / Math.PI * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n)));
  }
  var layer = null, home = null;

  function fit(pts, W, H) {
    var focus = follow && me && view === 'me' ? [me] : pts;
    if (!focus.length) return { lat: ABIDJAN.lat, lng: ABIDJAN.lng, z: 12 };
    var minLat = 90, maxLat = -90, minLng = 180, maxLng = -180, z = 16;
    focus.forEach(function (p) {
      minLat = Math.min(minLat, p.lat); maxLat = Math.max(maxLat, p.lat);
      minLng = Math.min(minLng, p.lng); maxLng = Math.max(maxLng, p.lng);
    });
    if (focus.length > 1) {
      for (z = 17; z > 2; z--) {
        if (px(maxLng, z) - px(minLng, z) <= W - 100 && py(minLat, z) - py(maxLat, z) <= H - 100) break;
      }
    }
    z = Math.max(2, Math.min(18, z));
    return { lat: latAt((py(minLat, z) + py(maxLat, z)) / 2, z), lng: lngAt((px(minLng, z) + px(maxLng, z)) / 2, z), z: z };
  }

  function render() {
    var W = el.clientWidth || 320, H = el.clientHeight || 280;
    el.textContent = '';
    var pts = allPoints().filter(function (p) { return p.lat !== null && p.lng !== null; });
    var z;
    if (auto || !cen) {
      var f = fit(pts, W, H);
      cen = { lat: f.lat, lng: f.lng };
      z = Math.max(2, Math.min(18, f.z + zoomAdj));
    } else {
      z = curZ;
    }
    curZ = z;
    layer = document.createElement('div');
    layer.className = 'map-layer';
    el.appendChild(layer);
    var left = px(cen.lng, z) - W / 2, top = py(cen.lat, z) - H / 2, n = Math.pow(2, z);
    for (var tx = Math.floor(left / T) - 1; tx <= Math.floor((left + W) / T) + 1; tx++) {
      for (var ty = Math.floor(top / T) - 1; ty <= Math.floor((top + H) / T) + 1; ty++) {
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
        layer.appendChild(img);
      }
    }
    if (trail.length > 1) {
      var NS = 'http://www.w3.org/2000/svg';
      var svg = document.createElementNS(NS, 'svg');
      svg.setAttribute('class', 'map-trail');
      svg.setAttribute('width', W); svg.setAttribute('height', H);
      var pl = document.createElementNS(NS, 'polyline');
      pl.setAttribute('points', trail.map(function (t) { return Math.round(px(t[1], z) - left) + ',' + Math.round(py(t[0], z) - top); }).join(' '));
      pl.setAttribute('fill', 'none'); pl.setAttribute('stroke', meColor || '#6c5ce7');
      pl.setAttribute('stroke-width', '4'); pl.setAttribute('stroke-linecap', 'round'); pl.setAttribute('stroke-linejoin', 'round');
      pl.setAttribute('stroke-dasharray', '1 9'); pl.setAttribute('opacity', '.85');
      svg.appendChild(pl); layer.appendChild(svg);
    }
    places.forEach(function (pc) {
      var pin = document.createElement('div');
      pin.className = 'map-pin ' + (pc.kind === 'meeting' ? 'meeting' : 'place');
      pin.style.left = Math.round(px(pc.lng, z) - left) + 'px';
      pin.style.top = Math.round(py(pc.lat, z) - top) + 'px';
      var d = document.createElement('span'); d.textContent = pc.emoji || '📍';
      var l = document.createElement('small'); l.textContent = pc.name;
      pin.appendChild(d); pin.appendChild(l); layer.appendChild(pin);
    });
    pts.forEach(function (p) {
      var pin = document.createElement('div');
      pin.className = 'map-pin' + (p.me ? ' me live' : '');
      pin.style.left = Math.round(px(p.lng, z) - left) + 'px';
      pin.style.top = Math.round(py(p.lat, z) - top) + 'px';
      var dot = document.createElement('span');
      dot.textContent = p.emoji ? p.emoji : (p.name || '?').charAt(0).toUpperCase();
      if (p.color) dot.style.background = p.color;
      var lab = document.createElement('small');
      lab.textContent = p.me ? 'Moi' : p.name;
      pin.appendChild(dot); pin.appendChild(lab);
      layer.appendChild(pin);
    });
    if (!pts.length) {
      var empty = document.createElement('div');
      empty.className = 'map-empty';
      empty.textContent = follow ? 'Recherche de ta position… Autorise la localisation quand ton téléphone te le demande.' : 'Aucune position à afficher pour le moment.';
      el.appendChild(empty);
    }
    var ctl = document.createElement('div');
    ctl.className = 'map-zoom';
    function addBtn(label, aria, fn) {
      var b = document.createElement('button');
      b.type = 'button'; b.textContent = label; b.setAttribute('aria-label', aria); b.title = aria;
      b.addEventListener('click', fn);
      ctl.appendChild(b);
    }
    addBtn('+', 'Zoom avant', function () { zoomBy(1); });
    addBtn('−', 'Zoom arrière', function () { zoomBy(-1); });
    if (follow) {
      addBtn('🎯', 'Me centrer sur ma position', function () { view = 'me'; zoomAdj = 0; auto = true; render(); });
      addBtn('👥', 'Voir tout le monde', function () { view = 'all'; zoomAdj = 0; auto = true; render(); });
    }
    addBtn(el.classList.contains('map-full') ? '✕' : '⛶', el.classList.contains('map-full') ? 'Quitter le plein écran' : 'Carte en plein écran', function () {
      var full = !el.classList.contains('map-full');
      if (full) {
        // Les cartes de la page ont un effet de verre qui empêche le vrai plein écran : on déplace la carte dans <body>
        home = { parent: el.parentNode, next: el.nextSibling };
        document.body.appendChild(el);
      } else if (home && home.parent) {
        home.parent.insertBefore(el, home.next);
        home = null;
      }
      el.classList.toggle('map-full', full);
      document.body.classList.toggle('map-lock', full);
      render();
    });
    el.appendChild(ctl);
    if (el.classList.contains('map-full')) {
      var cr = document.createElement('small');
      cr.className = 'map-credit-in'; cr.textContent = '© OpenStreetMap';
      el.appendChild(cr);
    }
  }

  function zoomBy(d) {
    var wasAuto = auto;
    if (wasAuto) { auto = false; }
    curZ = Math.max(2, Math.min(18, curZ + d));
    render();
  }

  // Gestes : un doigt déplace, deux doigts zooment (la souris : glisser et molette)
  var ptrs = {}, g = null;
  function ptrList() { return Object.keys(ptrs).map(function (k) { return ptrs[k]; }); }
  function startGesture() {
    var l = ptrList();
    if (!l.length) { g = null; return; }
    var r = el.getBoundingClientRect();
    if (l.length === 1) g = { x0: l[0].x, y0: l[0].y, d0: 0, tx: 0, ty: 0, s: 1, r: r };
    else g = { x0: (l[0].x + l[1].x) / 2, y0: (l[0].y + l[1].y) / 2, d0: Math.max(10, Math.hypot(l[0].x - l[1].x, l[0].y - l[1].y)), tx: 0, ty: 0, s: 1, r: r };
  }
  function moveGesture() {
    var l = ptrList();
    if (!g || !l.length || !layer) return;
    var mx = l.length > 1 ? (l[0].x + l[1].x) / 2 : l[0].x, my = l.length > 1 ? (l[0].y + l[1].y) / 2 : l[0].y;
    var s = l.length > 1 && g.d0 ? Math.max(0.3, Math.min(4, Math.hypot(l[0].x - l[1].x, l[0].y - l[1].y) / g.d0)) : 1;
    var ox = g.x0 - g.r.left, oy = g.y0 - g.r.top;
    g.s = s;
    g.tx = (mx - g.r.left) - s * ox;
    g.ty = (my - g.r.top) - s * oy;
    layer.style.transformOrigin = '0 0';
    layer.style.transform = 'translate(' + g.tx + 'px,' + g.ty + 'px) scale(' + s + ')';
  }
  function commitGesture() {
    if (!g || !cen) return;
    if (Math.abs(g.tx) < 3 && Math.abs(g.ty) < 3 && Math.abs(g.s - 1) < 0.02) return;
    var W = el.clientWidth || 320, H = el.clientHeight || 280, z = curZ;
    var offx = (W / 2 - g.tx) / g.s - W / 2, offy = (H / 2 - g.ty) / g.s - H / 2;
    var cx = px(cen.lng, z) + offx, cy = py(cen.lat, z) + offy;
    cen = { lat: Math.max(-84, Math.min(84, latAt(cy, z))), lng: lngAt(cx, z) };
    curZ = Math.max(2, Math.min(18, Math.round(z + Math.log(g.s) / Math.LN2)));
    auto = false;
    render();
  }
  el.addEventListener('pointerdown', function (e) {
    if (e.target.closest && e.target.closest('.map-zoom')) return;
    ptrs[e.pointerId] = { x: e.clientX, y: e.clientY };
    try { el.setPointerCapture(e.pointerId); } catch (x) {}
    if (g) commitGesture();
    startGesture();
  });
  el.addEventListener('pointermove', function (e) {
    if (!ptrs[e.pointerId]) return;
    ptrs[e.pointerId] = { x: e.clientX, y: e.clientY };
    moveGesture();
  });
  function endPtr(e) {
    if (!ptrs[e.pointerId]) return;
    commitGesture();
    delete ptrs[e.pointerId];
    startGesture();
  }
  el.addEventListener('pointerup', endPtr);
  el.addEventListener('pointercancel', endPtr);
  el.addEventListener('wheel', function (e) {
    e.preventDefault();
    zoomBy(e.deltaY < 0 ? 1 : -1);
  }, { passive: false });
  el.addEventListener('dblclick', function (e) {
    if (e.target.closest && e.target.closest('.map-zoom')) return;
    zoomBy(1);
  });

  // Aperçu en direct quand je personnalise ma carte (avant d'enregistrer)
  var pv0 = document.getElementById('pin-preview');
  if (pv0) { if (pv0.dataset.color) pv0.style.background = pv0.dataset.color; }
  var prefsForm = document.getElementById('prefs-form');
  if (prefsForm) {
    prefsForm.addEventListener('change', function () {
      var f = new FormData(prefsForm);
      meEmoji = f.get('emoji') || '';
      meColor = f.get('color') || meColor;
      el.className = el.className.replace(/\bmap-theme-\w+/g, '').trim();
      el.classList.add('map-theme-' + (f.get('theme') || 'classic'));
      trailOn = f.get('trail') === '1';
      render();
      var pv = document.getElementById('pin-preview');
      if (pv) { pv.textContent = meEmoji || (pv.dataset.initial || 'M'); pv.style.background = meColor; }
    });
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
      var av = document.createElement('span'); av.className = 'mini-av'; av.textContent = p.emoji ? p.emoji : (p.name || '?').charAt(0).toUpperCase();
      if (p.color) av.style.background = p.color;
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

  // Mon trajet du jour (visible par moi seul) : un point au plus toutes les 60 s
  var lastTrail = 0;
  function sendTrail(pos) {
    if (!trailOn || !el.dataset.update) return;
    var now = Date.now();
    if (now - lastTrail < 60000) return;
    lastTrail = now;
    trail.push([pos.coords.latitude, pos.coords.longitude]);
    var b = new URLSearchParams();
    b.set('csrf', el.dataset.csrf || ''); b.set('mode', 'trail');
    b.set('lat', pos.coords.latitude); b.set('lng', pos.coords.longitude);
    fetch(el.dataset.update, { method: 'POST', credentials: 'same-origin', body: b }).catch(function () {});
  }

  function postEvent(fields, done) {
    var b = new URLSearchParams();
    b.set('csrf', el.dataset.csrf || '');
    Object.keys(fields).forEach(function (k) { b.set(k, fields[k]); });
    fetch(el.dataset.eventUrl, { method: 'POST', credentials: 'same-origin', body: b })
      .then(function (r) { return r.json(); }).then(function (j) { done(j); })
      .catch(function () { done(null); });
  }

  // Arrivée à un lieu des cercles : on prévient une seule fois (le serveur revérifie la distance)
  function checkArrival() {
    if (!me || el.dataset.myShare !== '1') return;
    var day = new Date().toISOString().slice(0, 10);
    places.forEach(function (pc) {
      if (pc.kind !== 'place') return;
      var key = 'arr:' + pc.id;
      var inside = dist(me, pc) <= 150;
      if (!inside) { delete arrivedSent[key]; return; }
      if (arrivedSent[key]) return;
      var stored = null;
      try { stored = window.localStorage.getItem(key); } catch (e) {}
      if (stored && Date.now() - parseInt(stored, 10) < 1800000) { arrivedSent[key] = 1; return; }
      arrivedSent[key] = 1;
      try { window.localStorage.setItem(key, String(Date.now())); } catch (e) {}
      postEvent({ kind: 'arrived', place_id: pc.id }, function () { refresh(); });
    });
  }

  // Boutons « bien arrivé(e) » et SOS
  document.querySelectorAll('[data-geo-event]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var kind = btn.dataset.geoEvent;
      if (kind === 'sos' && btn.dataset.confirmText && !window.confirm(btn.dataset.confirmText)) return;
      var out = document.getElementById('event-status');
      var circles = [];
      document.querySelectorAll('#safe-circles input:checked').forEach(function (i) { circles.push(i.value); });
      if (kind === 'safe' && !circles.length) { if (out) out.textContent = 'Choisis au moins un cercle.'; return; }
      btn.disabled = true;
      function go(lat, lng) {
        var f = { kind: kind };
        if (kind === 'safe') f.circles = circles.join(',');
        if (lat !== null) { f.lat = lat; f.lng = lng; }
        postEvent(f, function (j) {
          btn.disabled = false;
          if (out) out.textContent = j && j.ok ? (kind === 'sos' ? 'Alerte envoyée à tes cercles.' : 'Message envoyé : tes proches savent que tu es bien arrivé(e).') : "Envoi impossible pour l'instant.";
        });
      }
      if (me) { go(me.lat, me.lng); }
      else if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function (pos) { go(pos.coords.latitude, pos.coords.longitude); }, function () { go(null, null); }, { timeout: 8000, maximumAge: 60000 });
      } else { go(null, null); }
    });
  });

  function refresh() {
    if (!el.dataset.url || !window.fetch) return;
    fetch(el.dataset.url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && j.points) { points = j.points; if (j.places) places = j.places; render(); renderPeople(); } })
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
    var retried = false;
    function watch(high) {
    navigator.geolocation.watchPosition(function (pos) {
      me = { lat: pos.coords.latitude, lng: pos.coords.longitude, acc: Math.round(pos.coords.accuracy) };
      if (first) { first = false; say(sharing ? 'Position trouvée. Tes proches te voient en direct.' : 'Position trouvée. Personne ne la voit : elle reste sur ton téléphone.'); }
      render(); renderPeople();
      sendTrail(pos);
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
          if (j && j.ok) { var d = new Date(); say('Position envoyée à ' + ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + '.'); refresh(); checkArrival(); }
          else say("Le partage n'est plus actif.");
        })
        .catch(function () { say("Impossible d'envoyer ta position (connexion ?)."); });
    }, function (err) {
      if (err && err.code === 1) { say("Autorisation refusée : active la localisation pour ce site dans les réglages de ton téléphone, puis recharge la page."); return; }
      if (high && !retried) { retried = true; say('Position difficile à trouver… nouvel essai.'); watch(false); return; }
      say('Position introuvable pour le moment. Vérifie que le GPS est activé.');
    }, { enableHighAccuracy: high, maximumAge: 10000, timeout: high ? 15000 : 40000 });
    }
    watch(true);
  }

  render();
  renderPeople();
  startGeo();
  setInterval(refresh, 15000);
  window.addEventListener('resize', render);
})();
