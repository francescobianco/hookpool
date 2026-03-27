<?php
// known_ips: user-defined IP → label mapping for display in logs
return static function (PDO $db): void {
    execSQL($db, "
        CREATE TABLE IF NOT EXISTS known_ips (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL REFERENCES users(id),
            ip         TEXT NOT NULL DEFAULT '',
            label      TEXT NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT (datetime('now'))
        )
    ");
    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_known_ips_user_id ON known_ips(user_id)");
};
