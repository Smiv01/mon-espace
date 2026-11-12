<?php
require __DIR__ . '/includes/bootstrap.php';

// Manifeste de l'application installable (PWA). Public : il ne contient aucune donnée personnelle.
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$icon = function ($file, $size, $purpose) {
    return ['src' => url('assets/icons/' . $file), 'sizes' => $size . 'x' . $size, 'type' => 'image/png', 'purpose' => $purpose];
};
echo json_encode([
    'name' => cfg('brand.name'),
    'short_name' => cfg('brand.name'),
    'description' => cfg('brand.tagline'),
    'lang' => 'fr',
    'start_url' => url('accueil.php'),
    'scope' => url('') === '' ? '/' : rtrim(url(''), '/') . '/',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'background_color' => '#ECE8DE',
    'theme_color' => '#6F8672',
    'icons' => [
        $icon('app-192.png', 192, 'any'),
        $icon('app-512.png', 512, 'any'),
        $icon('app-maskable-512.png', 512, 'maskable'),
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
