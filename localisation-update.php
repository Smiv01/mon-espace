<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/geo.php';

// Reçoit la position de l'appareil et la range dans les partages actifs de l'utilisateur (POST + jeton CSRF).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$user = current_user();
if (!$user || !is_post() || !csrf_valid()) {
    http_response_code(403);
    echo '{"ok":false}';
    exit;
}
$lat = opt($_POST, 'lat', '');
$lng = opt($_POST, 'lng', '');
$acc = opt($_POST, 'acc', '');
if (!is_numeric($lat) || !is_numeric($lng) || (float)$lat < -90 || (float)$lat > 90 || (float)$lng < -180 || (float)$lng > 180) {
    http_response_code(422);
    echo '{"ok":false,"error":"position"}';
    exit;
}
$acc = is_numeric($acc) ? max(0, min(100000, (int)round((float)$acc))) : null;
geo_purge();
$now = gmdate('Y-m-d H:i:s');
$uid = (int)$user['id'];
if ((string)opt($_POST, 'mode', '') === 'trail') {
    // Mon trajet du jour : seulement si je l'ai activé, jamais visible par les autres.
    $prefs = geo_prefs($uid);
    $added = 0;
    if ($prefs['trail']) {
        $last = db()->prepare('SELECT MAX(at) FROM location_trail WHERE user_id = ?');
        $last->execute([$uid]);
        $lastAt = $last->fetchColumn();
        if (!$lastAt || strtotime($lastAt . ' UTC') <= time() - 60) {
            db()->prepare('INSERT INTO location_trail (user_id, lat, lng, at) VALUES (?, ?, ?, ?)')->execute([$uid, round((float)$lat, 6), round((float)$lng, 6), $now]);
            $added = 1;
        }
    }
    echo json_encode(['ok' => true, 'trail' => $added]);
    exit;
}
$stmt = db()->prepare('UPDATE location_shares SET lat = ?, lng = ?, accuracy = ?, updated_at = ? WHERE user_id = ? AND expires_at > ?');
$stmt->execute([round((float)$lat, 6), round((float)$lng, 6), $acc, $now, $uid, $now]);
$active = $stmt->rowCount();
$stmt = db()->prepare('UPDATE location_links SET lat = ?, lng = ?, accuracy = ?, updated_at = ? WHERE user_id = ? AND expires_at > ?');
$stmt->execute([round((float)$lat, 6), round((float)$lng, 6), $acc, $now, $uid, $now]);
echo json_encode(['ok' => true, 'active' => $active + $stmt->rowCount()]);
