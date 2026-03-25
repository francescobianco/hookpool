<?php
return static function (PDO $db): void {
    execSQL($db, "
        CREATE TABLE IF NOT EXISTS filter_presets (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL REFERENCES users(id),
            name       TEXT    NOT NULL,
            params     TEXT    NOT NULL DEFAULT '{}',
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT (datetime('now')),
            deleted_at DATETIME DEFAULT NULL
        )
    ");

    execSQL($db, "
        CREATE INDEX IF NOT EXISTS idx_filter_presets_user ON filter_presets(user_id)
    ");
};
