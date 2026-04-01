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
                self::$instance->exec('PRAGMA busy_timeout=5000');
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
 * Execute a SQL statement with automatic SQLite→MySQL dialect translation.
 * Handles: AUTOINCREMENT, datetime('now'), IF NOT EXISTS on indexes, duplicate index errors.
 */
function execSQL(PDO $db, string $sql): void {
    if (DB_TYPE === 'mysql') {
        $sql = str_replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'INT AUTO_INCREMENT PRIMARY KEY', $sql);
        $sql = str_replace("DEFAULT (datetime('now'))",         'DEFAULT CURRENT_TIMESTAMP',      $sql);
        // MySQL: TEXT columns cannot be used in UNIQUE keys — convert to VARCHAR(500)
        $sql = str_replace('TEXT UNIQUE NOT NULL', 'VARCHAR(500) NOT NULL UNIQUE', $sql);
        $sql = str_replace('TEXT NOT NULL UNIQUE', 'VARCHAR(500) NOT NULL UNIQUE', $sql);
        $sql = preg_replace('/\bTEXT(\s+UNIQUE\b)/i', 'VARCHAR(500)$1', $sql);
        // MySQL < 8.0.13: TEXT/BLOB columns cannot have DEFAULT values — strip them
        $sql = preg_replace("/\bTEXT(\s+NOT\s+NULL)?\s+DEFAULT\s+'[^']*'/i", 'TEXT$1', $sql);
        // MySQL: TEXT columns cannot be used in index keys — convert known short-value columns to VARCHAR
        $sql = preg_replace('/\bslug\s+TEXT\b/i',     'slug VARCHAR(255)',  $sql);
        $sql = preg_replace('/\bip\s+TEXT\b/i',       'ip VARCHAR(45)',     $sql);
        // MySQL does not support IF NOT EXISTS on CREATE INDEX — silently skip duplicate
        if (preg_match('/\bCREATE\s+(?:UNIQUE\s+)?INDEX\b/i', $sql)) {
            $sql = preg_replace('/\bIF\s+NOT\s+EXISTS\s+/i', '', $sql);
        }
    }
    try {
        $db->exec($sql);
    } catch (PDOException $e) {
        // Ignore duplicate index/key errors so migrations are idempotent
        $msg = $e->getMessage();
        $isDuplicateIndex = (DB_TYPE === 'mysql' && stripos($msg, 'Duplicate key name') !== false)
                         || (DB_TYPE === 'sqlite' && stripos($msg, 'already exists') !== false);
        if (!$isDuplicateIndex) {
            throw $e;
        }
    }
}

/**
 * Cross-DB ORDER BY expression that sorts NULLs last.
 * SQLite supports NULLS LAST natively; MySQL uses the IS NULL trick.
 */
function orderNullsLast(string $col, string $dir = 'ASC'): string {
    if (DB_TYPE === 'mysql') {
        return "$col IS NULL, $col $dir";
    }
    return "$col $dir NULLS LAST";
}

/**
 * Cross-DB SQL expression for the current timestamp.
 */
function sqlNowExpr(): string {
    return DB_TYPE === 'mysql' ? 'CURRENT_TIMESTAMP' : "datetime('now')";
}

/**
 * Cross-DB SQL expression for the current timestamp minus N seconds.
 */
function sqlNowMinusSecondsExpr(int $seconds): string {
    if (DB_TYPE === 'mysql') {
        return "DATE_SUB(CURRENT_TIMESTAMP, INTERVAL $seconds SECOND)";
    }
    return "datetime('now','-$seconds seconds')";
}

/**
 * Generate a cryptographically secure token (64 hex chars = 256-bit entropy).
 */
function generateToken(): string {
    return bin2hex(random_bytes(HOOK_TOKEN_LENGTH));
}

/**
 * Generate a short base36 webhook code.
 */
function generateWebhookToken(): string {
    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $maxIndex = strlen($alphabet) - 1;
    $token = '';

    for ($i = 0; $i < WEBHOOK_CODE_LENGTH; $i++) {
        $token .= $alphabet[random_int(0, $maxIndex)];
    }

    return $token;
}
