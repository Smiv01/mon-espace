<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/geo.php';

// Position d'une personne qui a partagé un lien de suivi temporaire (aucune connexion nécessaire).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
geo_purge();
$code = (string)opt($_GET, 'code', '');
if (!preg_match('/^[0-9a-f]{32}$/', $code)) {
    http_response_code(404);
    echo '{"error":"not_found"}';
    exit;
}
$stmt = db()->prepare('SELECT l.lat, l.lng, l.accuracy, l.updated_at, l.expires_at, l.user_id, p.first_name, lp.pin_emoji, lp.pin_color
    FROM location_links l LEFT JOIN profiles p ON p.user_id = l.user_id LEFT JOIN location_prefs lp ON lp.user_id = l.user_id
    WHERE l.token_hash = ? AND l.expires_at > ?');
$stmt->execute([hash('sha256', $code), gmdate('Y-m-d H:i:s')]);
$r = $stmt->fetch();
if (!$r) {
    http_response_code(404);
    echo '{"error":"not_found"}';
    exit;
}
$has = $r['lat'] !== null && $r['lng'] !== null;
echo json_encode(['points' => [[
    'name' => $r['first_name'] ? $r['first_name'] : 'Quelqu\'un', 'me' => false,
    'lat' => $has ? (float)$r['lat'] : null, 'lng' => $has ? (float)$r['lng'] : null,
    'acc' => $r['accuracy'] !== null ? (int)$r['accuracy'] : null,
    'ago' => $r['updated_at'] ? max(0, time() - strtotime($r['updated_at'] . ' UTC')) : null,
    'until' => geo_local_time($r['expires_at'], 'Africa/Abidjan'),
    'emoji' => (string)$r['pin_emoji'], 'color' => $r['pin_color'] ? $r['pin_color'] : '#6c5ce7',
]]]);
