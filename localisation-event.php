<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/geo.php';

// Événements de localisation : arrivée à un lieu (automatique), « je suis bien arrivé(e) » et alerte SOS (POST + CSRF).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$user = current_user();
if (!$user || !is_post() || !csrf_valid()) {
    http_response_code(403);
    echo '{"ok":false}';
    exit;
}
geo_purge();
$uid = (int)$user['id'];
$kind = (string)opt($_POST, 'kind', '');
$mine = geo_my_circles($uid);
$lat = opt($_POST, 'lat', '');
$lng = opt($_POST, 'lng', '');
$hasPos = is_numeric($lat) && is_numeric($lng) && abs((float)$lat) <= 90 && abs((float)$lng) <= 180;
$lat = $hasPos ? round((float)$lat, 6) : null;
$lng = $hasPos ? round((float)$lng, 6) : null;
$now = gmdate('Y-m-d H:i:s');

// Cercles visés (toujours parmi les miens)
$targets = [];
$raw = isset($_POST['circles']) && is_array($_POST['circles']) ? $_POST['circles'] : (isset($_POST['circles']) ? explode(',', (string)$_POST['circles']) : []);
foreach ($raw as $c) {
    if (isset($mine[(int)$c])) {
        $targets[(int)$c] = true;
    }
}

if ($kind === 'sos') {
    $ids = $targets ? array_keys($targets) : array_keys($mine);
    if (!$ids) {
        echo '{"ok":false,"error":"no_circle"}';
        exit;
    }
    $recent = db()->prepare("SELECT COUNT(*) FROM location_events WHERE user_id = ? AND kind = 'sos' AND created_at > ?");
    $recent->execute([$uid, gmdate('Y-m-d H:i:s', time() - 120)]);
    if ((int)$recent->fetchColumn() > 0) {
        echo '{"ok":true,"duplicate":true}';
        exit;
    }
    geo_event_add($uid, $ids, 'sos', null, null, $lat, $lng);
    echo json_encode(['ok' => true, 'circles' => count($ids)]);
    exit;
}
if ($kind === 'safe') {
    if (!$targets) {
        echo '{"ok":false,"error":"no_circle"}';
        exit;
    }
    $recent = db()->prepare("SELECT COUNT(*) FROM location_events WHERE user_id = ? AND kind = 'safe' AND created_at > ?");
    $recent->execute([$uid, gmdate('Y-m-d H:i:s', time() - 60)]);
    if ((int)$recent->fetchColumn() > 0) {
        echo '{"ok":true,"duplicate":true}';
        exit;
    }
    geo_event_add($uid, array_keys($targets), 'safe', null, null, $lat, $lng);
    echo json_encode(['ok' => true, 'circles' => count($targets)]);
    exit;
}
if ($kind === 'arrived') {
    // Arrivée automatique : seulement si je partage ma position avec ce cercle, que le lieu appartient au cercle
    // et que ma dernière position enregistrée est vraiment près du lieu.
    $pid = (int)opt($_POST, 'place_id', 0);
    $st = db()->prepare("SELECT id, circle_id, name, lat, lng FROM circle_places WHERE id = ? AND kind = 'place'");
    $st->execute([$pid]);
    $pl = $st->fetch();
    if (!$pl || !isset($mine[(int)$pl['circle_id']])) {
        echo '{"ok":false}';
        exit;
    }
    $sh = db()->prepare('SELECT lat, lng FROM location_shares WHERE user_id = ? AND circle_id = ? AND expires_at > ? AND lat IS NOT NULL');
    $sh->execute([$uid, (int)$pl['circle_id'], $now]);
    $pos = $sh->fetch();
    if (!$pos || geo_distance((float)$pos['lat'], (float)$pos['lng'], (float)$pl['lat'], (float)$pl['lng']) > 250) {
        echo '{"ok":false,"error":"not_there"}';
        exit;
    }
    $recent = db()->prepare("SELECT COUNT(*) FROM location_events WHERE user_id = ? AND kind = 'arrived' AND place_id = ? AND created_at > ?");
    $recent->execute([$uid, $pid, gmdate('Y-m-d H:i:s', time() - 1800)]);
    if ((int)$recent->fetchColumn() > 0) {
        echo '{"ok":true,"duplicate":true}';
        exit;
    }
    geo_event_add($uid, [(int)$pl['circle_id']], 'arrived', $pid, $pl['name'], (float)$pos['lat'], (float)$pos['lng']);
    echo '{"ok":true}';
    exit;
}
http_response_code(422);
echo '{"ok":false}';
