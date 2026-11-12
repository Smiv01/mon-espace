<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/geo.php';

// Positions actuelles d'un cercle (JSON), pour la carte. Réservé aux membres du cercle.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo '{"error":"auth"}';
    exit;
}
geo_purge();
$c = circle_access((int)$user['id'], opt($_GET, 'id', 0));
if (!$c) {
    http_response_code(404);
    echo '{"error":"not_found"}';
    exit;
}
echo json_encode(['points' => circle_points($c['id'], (int)$user['id'], opt($user, 'timezone', 'Africa/Abidjan'))]);
