<?php
return static function (PDO $db): void {
    execSQL($db, "
        CREATE TABLE IF NOT EXISTS alarm_email_attempts (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id        INTEGER NOT NULL REFERENCES events(id),
            recipient_email TEXT NOT NULL DEFAULT '',
            subject         TEXT NOT NULL DEFAULT '',
            transport       TEXT NOT NULL DEFAULT '',
            status          TEXT NOT NULL DEFAULT '',
            error_message   TEXT,
            spool_path      TEXT,
            created_at      DATETIME DEFAULT (datetime('now'))
        )
    ");

    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_alarm_email_attempts_event_id ON alarm_email_attempts(event_id)");
};
