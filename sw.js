// Service worker de Mon Espace.
// Il ne garde en mémoire QUE les fichiers publics de l'interface (style, scripts, icônes) et une page « hors connexion ».
// Aucune page de l'application (donc aucune donnée personnelle) n'est jamais mise en cache.
var CACHE = 'mon-espace-static-v1';
var BASE = new URL('./', self.location).pathname;
var PRECACHE = ['offline.html', 'assets/style.css', 'assets/theme.js', 'assets/app.js', 'assets/icon.svg', 'assets/icons/app-192.png'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) {
    return c.addAll(PRECACHE.map(function (p) { return BASE + p; }));
  }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) return; // tuiles de carte, etc. : le navigateur s'en charge

  // Pages : toujours le réseau ; hors connexion, une page d'attente (jamais de contenu personnel en cache).
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(function () { return caches.match(BASE + 'offline.html'); }));
    return;
  }
  // Fichiers publics de l'interface : réseau d'abord, copie en secours.
  if (url.pathname.indexOf(BASE + 'assets/') === 0) {
    e.respondWith(fetch(req).then(function (res) {
      if (res && res.ok) {
        var copy = res.clone();
        caches.open(CACHE).then(function (c) { c.put(req, copy); });
      }
      return res;
    }).catch(function () { return caches.match(req); }));
  }
});
