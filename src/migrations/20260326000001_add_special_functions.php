<?php
return static function (PDO $db): void {
    // Add special_function column to webhooks (pixel | file_upload | NULL)
    execSQL($db, "ALTER TABLE webhooks ADD COLUMN special_function TEXT DEFAULT NULL");

    // event_files: stores metadata of uploaded files attached to events
    execSQL($db, "
        CREATE TABLE IF NOT EXISTS event_files (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id     INTEGER NOT NULL REFERENCES events(id),
            field_name   TEXT NOT NULL DEFAULT '',
            filename     TEXT NOT NULL DEFAULT '',
            mime_type    TEXT NOT NULL DEFAULT 'application/octet-stream',
            size         INTEGER NOT NULL DEFAULT 0,
            storage_path TEXT NOT NULL DEFAULT '',
            created_at   DATETIME DEFAULT (datetime('now'))
        )
    ");
    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_event_files_event_id ON event_files(event_id)");
};