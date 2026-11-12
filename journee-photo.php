<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/days.php';

// Sert une photo de journée uniquement aux personnes qui ont accès à cette journée.
$user = require_login();
$stmt = db()->prepare('SELECT day_id, photo_path FROM day_posts WHERE id = ? AND photo_path IS NOT NULL');
$stmt->execute([(int)opt($_GET, 'id', 0)]);
$p = $stmt->fetch();
if (!$p || !day_access((int)$user['id'], $p['day_id'])) {
    http_response_code(404);
    exit;
}
$file = storage_dir('days') . '/' . basename($p['photo_path']);
if (!is_file($file)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($file);
