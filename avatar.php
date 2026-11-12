<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/media.php';

// Sert uniquement la photo de l'utilisateur connecté.
$user = require_login();
$path = (string)$user['avatar_path'];
$file = storage_dir('avatars') . '/' . basename($path);
if ($path === '' || !is_file($file)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=86400');
readfile($file);
