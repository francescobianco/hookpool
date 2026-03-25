<?php

class Database {
    private static ?PDO $instance = null;

    public static function get(): PDO {
        if (self::$instance === null) {
            if (DB_TYPE === 'sqlite') {
                $dir = dirname(DB_PATH);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                self::$instance = new PDO('sqlite:' . DB_PATH);
                self::$instance->exec('PRAGMA journal_mode=WAL');
                self::$instance->exec('PRAGMA foreign_keys=ON');
            } else {
                $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
                self::$instance = new PDO($dsn, DB_USER, DB_PASSWORD);
            }
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
        return self::$instance;
    }
}

/**
 * Generate a cryptographically secure token (64 hex chars = 256-bit entropy).
 */
function generateToken(): string {
    return bin2hex(random_bytes(HOOK_TOKEN_LENGTH));
}

/**
 * Generate a shorter webhook token (32 hex chars = 128-bit entropy), URL-friendly.
 */
function generateWebhookToken(): string {
    return bin2hex(random_bytes(16));
}
