<?php
require __DIR__ . '/../src/config.php';
header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=86400');
$base = rtrim(BASE_URL, '/');
echo json_encode([
    'name'             => APP_NAME,
    'short_name'       => APP_NAME,
    'description'      => 'Self-hostable webhook manager',
    'start_url'        => $base . '/?page=dashboard',
    'scope'            => $base . '/',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#121212',
    'theme_color'      => '#ff5a36',
    'icons'            => [
        ['src' => $base . '/favicon-32x32.png',   'sizes' => '32x32',   'type' => 'image/png'],
        ['src' => $base . '/apple-touch-icon.png', 'sizes' => '180x180', 'type' => 'image/png'],
        ['src' => $base . '/icon-192.png',         'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => $base . '/icon-512.png',         'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
