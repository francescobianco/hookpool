<?php
session_start();
require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/utils.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/lang.php';
require __DIR__ . '/../src/mail.php';

function asset(string $path): string {
    $normalizedPath = ltrim($path, '/');
    return (defined('ROOT_MODE') && ROOT_MODE ? 'public/' : '') . $normalizedPath;
}

$db = Database::get();
$current_user = getCurrentUser($db);

// Language switch
if (isset($_GET['lang']) && in_array($_GET['lang'], SUPPORTED_LANGS)) {
    setcookie('lang', $_GET['lang'], time() + 365 * 24 * 3600, '/');
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '?page=home'));
    exit;
}

$page = $_GET['page'] ?? 'home';
$allowed_pages = ['home', 'auth', 'dashboard', 'project', 'webhook', 'event', 'api', 'settings', 'cron', 'diagnose', 'known_ips', 'analytics'];

if (!in_array($page, $allowed_pages)) {
    $page = 'home';
}

// Cron: public endpoint, JSON, no layout, no auth
if ($page === 'cron') {
    require __DIR__ . '/../src/pages/cron.php';
    exit;
}

// API: respond with JSON directly, no layout
if ($page === 'api') {
    require __DIR__ . '/../src/pages/api.php';
    exit;
}

// Auth page handles its own redirects
if ($page === 'auth') {
    require __DIR__ . '/../src/pages/auth.php';
    exit;
}

ob_start();
require __DIR__ . '/../src/pages/' . $page . '.php';
$content = ob_get_clean();

require __DIR__ . '/../src/layout.php';
