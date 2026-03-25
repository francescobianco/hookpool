<?php
// Load env.php if present (shared hosting without Docker)
if (file_exists(__DIR__ . '/../env.php')) {
    require __DIR__ . '/../env.php';
}

define('APP_ENV',            getenv('APP_ENV')            ?: 'production');
define('BASE_URL',           rtrim(getenv('BASE_URL')     ?: 'http://localhost:8080', '/'));
define('DB_TYPE',            getenv('DB_TYPE')            ?: 'sqlite');
define('DB_PATH',            getenv('DB_PATH')            ?: __DIR__ . '/../data/app.db');
define('DB_HOST',            getenv('DB_HOST')            ?: 'localhost');
define('DB_PORT',            getenv('DB_PORT')            ?: '3306');
define('DB_NAME',            getenv('DB_NAME')            ?: 'hookpool');
define('DB_USER',            getenv('DB_USER')            ?: 'root');
define('DB_PASSWORD',        getenv('DB_PASSWORD')        ?: '');
define('SMTP_HOST',          getenv('SMTP_HOST')          ?: 'smtp.example.com');
define('SMTP_PORT',          (int)(getenv('SMTP_PORT')    ?: 587));
define('SMTP_USER',          getenv('SMTP_USER')          ?: '');
define('SMTP_PASS',          getenv('SMTP_PASS')          ?: '');
define('MAIL_FROM',          getenv('MAIL_FROM')          ?: 'noreply@hookpool.com');
define('MAIL_FROM_NAME',     getenv('MAIL_FROM_NAME')     ?: 'HookPool');
define('ADMIN_EMAIL',        getenv('ADMIN_EMAIL')        ?: '');
define('TOKEN_EXPIRY_HOURS', (int)(getenv('TOKEN_EXPIRY_HOURS') ?: 72));
define('DEFAULT_LANG',       getenv('DEFAULT_LANG')       ?: 'en');
define('GITHUB_CLIENT_ID',   getenv('GITHUB_CLIENT_ID')   ?: '');
define('GITHUB_CLIENT_SECRET', getenv('GITHUB_CLIENT_SECRET') ?: '');
define('HOOKPOOL_AUTH',      strtolower(trim((string)(getenv('HOOKPOOL_AUTH') ?: 'no'))));
define('HOOKPOOL_AUTH_ENABLED', in_array(HOOKPOOL_AUTH, ['1', 'true', 'yes', 'on'], true));

define('SUPPORTED_LANGS',    ['en']);
define('HOOK_TOKEN_LENGTH',  32);
define('WEBHOOK_CODE_LENGTH', 6);
define('APP_NAME',           'HookPool');
define('APP_VERSION',        '1.0.0');
