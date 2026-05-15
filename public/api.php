<?php
session_start();

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/utils.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/lang.php';
require __DIR__ . '/../src/mail.php';

if (isset($_GET['action'])) {
    $_GET['action'] = str_replace('-', '_', (string)$_GET['action']);
}

$db = Database::get();

require __DIR__ . '/../src/pages/api.php';
