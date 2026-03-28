<?php
return static function (PDO $db): void {
    // relay_queue: one row per in-flight relay transaction
    // id doubles as the sequence number sent in X-Relay-Seq
    execSQL($db, "
        CREATE TABLE IF NOT EXISTS relay_queue (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            webhook_id    INTEGER NOT NULL,
            state         TEXT NOT NULL DEFAULT 'pending',
            req_method    TEXT NOT NULL DEFAULT 'GET',
            req_path      TEXT NOT NULL DEFAULT '/',
            req_qs        TEXT NOT NULL DEFAULT '',
            req_headers   TEXT NOT NULL DEFAULT '{}',
            req_body      TEXT NOT NULL DEFAULT '',
            req_b64       INTEGER NOT NULL DEFAULT 0,
            resp_status   INTEGER DEFAULT NULL,
            resp_headers  TEXT DEFAULT NULL,
            resp_body     TEXT DEFAULT NULL,
            resp_b64      INTEGER NOT NULL DEFAULT 0,
            created_at    DATETIME DEFAULT (datetime('now')),
            dispatched_at DATETIME DEFAULT NULL,
            responded_at  DATETIME DEFAULT NULL
        )
    ");
    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_relay_queue_webhook_state ON relay_queue(webhook_id, state)");
};
