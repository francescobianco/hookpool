<?php
return static function (PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS migrations (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            migration TEXT UNIQUE NOT NULL,
            ran_at    DATETIME DEFAULT (datetime('now'))
        )
    ");
};
