<?php
return static function (PDO $db): void {
    if (!tableExists($db, 'users')) {
        return;
    }

    if (!githubIdIsRequired($db)) {
        return;
    }

    if (DB_TYPE === 'mysql') {
        $db->exec('ALTER TABLE users MODIFY github_id BIGINT NULL');
        return;
    }

    $db->beginTransaction();

    try {
        $db->exec("
            CREATE TABLE users_new (
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

        $db->exec("
            INSERT INTO users_new (id, github_id, username, display_name, avatar_url, email, created_at, deleted_at)
            SELECT id, github_id, username, display_name, avatar_url, email, created_at, deleted_at
            FROM users
        ");

        $db->exec('DROP TABLE users');
        $db->exec('ALTER TABLE users_new RENAME TO users');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_github_id ON users(github_id)');
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
};

function tableExists(PDO $db, string $table): bool {
    if (DB_TYPE === 'mysql') {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function githubIdIsRequired(PDO $db): bool {
    if (DB_TYPE === 'mysql') {
        $stmt = $db->prepare("
            SELECT IS_NULLABLE
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'users'
              AND column_name = 'github_id'
        ");
        $stmt->execute();
        return strtoupper((string)$stmt->fetchColumn()) === 'NO';
    }

    $cols = $db->query("PRAGMA table_info(users)")->fetchAll();
    foreach ($cols as $col) {
        if (($col['name'] ?? '') === 'github_id') {
            return (int)($col['notnull'] ?? 0) === 1;
        }
    }

    return false;
}
