<?php
return static function (PDO $db): void {
    execSQL($db, "
        CREATE TABLE IF NOT EXISTS alarms (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            webhook_id INTEGER NOT NULL REFERENCES webhooks(id),
            name       TEXT NOT NULL DEFAULT '',
            type       TEXT NOT NULL,
            config     TEXT NOT NULL DEFAULT '{}',
            active     INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT (datetime('now')),
            deleted_at DATETIME DEFAULT NULL
        )
    ");

    execSQL($db, "
        CREATE TABLE IF NOT EXISTS alarm_logs (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            alarm_id     INTEGER NOT NULL REFERENCES alarms(id),
            webhook_id   INTEGER NOT NULL REFERENCES webhooks(id),
            triggered_at DATETIME DEFAULT (datetime('now')),
            message      TEXT NOT NULL DEFAULT ''
        )
    ");

    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_alarms_webhook_id     ON alarms(webhook_id)");
    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_alarm_logs_alarm_id   ON alarm_logs(alarm_id)");
    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_alarm_logs_webhook_id ON alarm_logs(webhook_id)");
    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_alarm_logs_triggered  ON alarm_logs(triggered_at)");
};
