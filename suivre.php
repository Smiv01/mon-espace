<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/geo.php';

// Page publique : suivre quelqu'un qui t'a envoyé un lien de suivi temporaire (sans compte).
geo_purge();
$code = (string)opt($_GET, 'code', '');
$ok = false;
if (preg_match('/^[0-9a-f]{32}$/', $code)) {
    $stmt = db()->prepare('SELECT 1 FROM location_links WHERE token_hash = ? AND expires_at > ?');
    $stmt->execute([hash('sha256', $code), gmdate('Y-m-d H:i:s')]);
    $ok = (bool)$stmt->fetchColumn();
}
allow_map_tiles();
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Suivi de position · <?= e(cfg('brand.name', 'Lyvo')) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
</head>
<body>
<main class="track-wrap">
  <h1><?= e(cfg('brand.name', 'Lyvo')) ?> · Suivi de position</h1>
  <?php if (!$ok): ?>
    <div class="card"><p><strong>Ce lien n'est plus valable.</strong></p><p class="muted small">Il a expiré ou la personne l'a désactivé. Demande-lui de t'en envoyer un nouveau.</p></div>
  <?php else: ?>
    <div class="card map-card">
      <div id="map" class="map" data-points="[]" data-url="<?= e(url('suivre-data.php?code=' . $code)) ?>" data-sharing="0" data-follow="0"
           data-tiles="<?= e(geo_tiles_base()) ?>" role="img" aria-label="Position de la personne"></div>
      <p class="map-credit small">© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a></p>
    </div>
    <p class="muted small" id="geo-status" aria-live="polite">La position se met à jour toute seule tant que la personne garde son appli ouverte.</p>
    <div class="task-list" id="people"></div>
    <p class="muted small mt">Ce lien est temporaire : la personne peut l'arrêter à tout moment.</p>
    <script src="<?= e(url('assets/map.js')) ?>" defer></script>
  <?php endif; ?>
</main>
</body>
</html>
