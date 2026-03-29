<?php
return static function (PDO $db): void {
    execSQL($db, "
        CREATE TABLE IF NOT EXISTS control_panel_widgets (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            type       TEXT NOT NULL DEFAULT 'button',
            title      TEXT NOT NULL DEFAULT '',
            width      INTEGER NOT NULL DEFAULT 1,
            config     TEXT NOT NULL DEFAULT '{}',
            created_at DATETIME DEFAULT (datetime('now'))
        )
    ");
    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_cp_widgets_user ON control_panel_widgets(user_id, sort_order)");
};