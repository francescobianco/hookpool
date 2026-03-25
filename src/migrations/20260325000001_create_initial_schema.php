<?php
return static function (PDO $db): void {
    // users
    execSQL($db, "
        CREATE TABLE IF NOT EXISTS users (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            github_id    INTEGER UNIQUE,
            username     TEXT NOT NULL,
            display_name TEXT NOT NULL DEFAULT '',
            avatar_url   TEXT NOT NULL DEFAULT '',
            email        TEXT,
            created_at   DATETIME DEFAULT (datetime('now')),
            deleted_at   DATETIME DEFAULT NULL
        )
    ");

    // categories
    execSQL($db, "
        CREATE TABLE IF NOT EXISTS categories (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL REFERENCES users(id),
            name       TEXT NOT NULL,
            color      TEXT NOT NULL DEFAULT '#4361ee',
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT (datetime('now')),
            deleted_at DATETIME DEFAULT NULL
        )
    ");

    // projects
    execSQL($db, "
        CREATE TABLE IF NOT EXISTS projects (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL REFERENCES users(id),
            category_id INTEGER REFERENCES categories(id),
            name        TEXT NOT NULL,
            slug        TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            active      INTEGER NOT NULL DEFAULT 1,
            created_at  DATETIME DEFAULT (datetime('now')),
            deleted_at  DATETIME DEFAULT NULL
        )
    ");

    // webhooks
    execSQL($db, "
        CREATE TABLE IF NOT EXISTS webhooks (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL REFERENCES projects(id),
            name       TEXT NOT NULL,
            token      TEXT UNIQUE NOT NULL,
            active     INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT (datetime('now')),
            deleted_at DATETIME DEFAULT NULL
        )
    ");

    // events
    execSQL($db, "
        CREATE TABLE IF NOT EXISTS events (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            webhook_id       INTEGER NOT NULL REFERENCES webhooks(id),
            method           TEXT NOT NULL DEFAULT 'POST',
            path             TEXT NOT NULL DEFAULT '/',
            query_string     TEXT NOT NULL DEFAULT '',
            headers          TEXT NOT NULL DEFAULT '{}',
            body             TEXT NOT NULL DEFAULT '',
            content_type     TEXT NOT NULL DEFAULT '',
            ip               TEXT NOT NULL DEFAULT '',
            received_at      DATETIME DEFAULT (datetime('now')),
            validated        INTEGER NOT NULL DEFAULT 1,
            rejection_reason TEXT
        )
    ");

    // guards
    execSQL($db, "
        CREATE TABLE IF NOT EXISTS guards (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER REFERENCES projects(id),
            webhook_id INTEGER REFERENCES webhooks(id),
            type       TEXT NOT NULL,
            config     TEXT NOT NULL DEFAULT '{}',
            active     INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT (datetime('now')),
            deleted_at DATETIME DEFAULT NULL
        )
    ");

    // forward_actions
    execSQL($db, "
        CREATE TABLE IF NOT EXISTS forward_actions (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            webhook_id     INTEGER NOT NULL REFERENCES webhooks(id),
            name           TEXT NOT NULL DEFAULT '',
            url            TEXT NOT NULL,
            method         TEXT NOT NULL DEFAULT 'POST',
            custom_headers TEXT NOT NULL DEFAULT '{}',
            body_template  TEXT NOT NULL DEFAULT '',
            timeout        INTEGER NOT NULL DEFAULT 10,
            active         INTEGER NOT NULL DEFAULT 1,
            conditions     TEXT NOT NULL DEFAULT '[]',
            created_at     DATETIME DEFAULT (datetime('now')),
            deleted_at     DATETIME DEFAULT NULL
        )
    ");

    // forward_attempts
    execSQL($db, "
        CREATE TABLE IF NOT EXISTS forward_attempts (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id          INTEGER NOT NULL REFERENCES events(id),
            forward_action_id INTEGER NOT NULL REFERENCES forward_actions(id),
            request_headers   TEXT,
            request_body      TEXT,
            response_status   INTEGER,
            response_body     TEXT,
            error             TEXT,
            executed_at       DATETIME DEFAULT (datetime('now'))
        )
    ");

    // rate_limits
    execSQL($db, "
        CREATE TABLE IF NOT EXISTS rate_limits (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            ip         TEXT NOT NULL,
            created_at DATETIME DEFAULT (datetime('now'))
        )
    ");

    // Indexes for performance
    execSQL($db, "CREATE UNIQUE INDEX IF NOT EXISTS idx_webhooks_token     ON webhooks(token)");
    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_events_webhook_id         ON events(webhook_id)");
    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_events_received_at        ON events(received_at)");
    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_forward_attempts_event_id ON forward_attempts(event_id)");
    execSQL($db, "CREATE UNIQUE INDEX IF NOT EXISTS idx_users_github_id    ON users(github_id)");
    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_projects_user_id          ON projects(user_id)");
    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_projects_slug             ON projects(slug)");
    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_categories_user_id        ON categories(user_id)");
    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_guards_project_id         ON guards(project_id)");
    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_guards_webhook_id         ON guards(webhook_id)");
    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_forward_actions_webhook_id ON forward_actions(webhook_id)");
    execSQL($db, "CREATE INDEX IF NOT EXISTS idx_rate_limits_ip            ON rate_limits(ip)");
};
