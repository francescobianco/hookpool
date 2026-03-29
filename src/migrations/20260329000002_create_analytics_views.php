<?php
return static function (PDO $db): void {
    execSQL($db, "
        CREATE TABLE IF NOT EXISTS analytics_views (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL REFERENCES users(id),
            webhook_id INTEGER NOT NULL REFERENCES webhooks(id),
            name       TEXT    DEFAULT NULL,
            fields     TEXT    NOT NULL DEFAULT '[]',
            groupby    TEXT    NOT NULL DEFAULT 'none',
            sort_by    TEXT    NOT NULL DEFAULT 'received_at',
            sort_dir   TEXT    NOT NULL DEFAULT 'desc',
            created_at DATETIME DEFAULT (datetime('now')),
            deleted_at DATETIME DEFAULT NULL
        )
    ");

    execSQL($db, "
        CREATE INDEX IF NOT EXISTS idx_analytics_views_user_webhook
        ON analytics_views(user_id, webhook_id)
    ");
};
