<?php
/**
 * Hookpool Migration Runner
 * Run via CLI: php -f migrate.php
 * Run via browser: https://yourapp.com/migrate.php
 */

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

function migrationOutput(string $text, bool $isCli): void {
    if ($isCli) {
        echo $text . "\n";
    } else {
        echo htmlspecialchars($text) . "\n";
    }
    flush();
}

require __DIR__ . '/src/config.php';
require __DIR__ . '/src/db.php';
require __DIR__ . '/src/migrations.php';

try {
    $db = Database::get();
} catch (Throwable $e) {
    migrationOutput('ERROR: Could not connect to database: ' . $e->getMessage(), $isCli);
    exit(1);
}

// Bootstrap migrations table (dialect-aware)
execSQL($db, "CREATE TABLE IF NOT EXISTS migrations (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    migration TEXT UNIQUE NOT NULL,
    ran_at    DATETIME DEFAULT (datetime('now'))
)");

migrationOutput('Hookpool Migration Runner', $isCli);
migrationOutput(str_repeat('-', 50), $isCli);

$ranCount  = 0;
$skipCount = 0;
$errCount  = 0;

foreach ($migrations as $migrationFile) {
    $name = basename($migrationFile, '.php');

    // Check if already ran
    $existsStmt = $db->prepare('SELECT id FROM migrations WHERE migration = ?');
    $existsStmt->execute([$name]);
    if ($existsStmt->fetch()) {
        migrationOutput("  SKIP  $name", $isCli);
        $skipCount++;
        continue;
    }

    // Run migration
    try {
        $migration = require $migrationFile;
        if (!is_callable($migration)) {
            throw new RuntimeException("Migration $name did not return a callable.");
        }
        $migration($db);

        // Record it
        $db->prepare('INSERT INTO migrations (migration) VALUES (?)')->execute([$name]);
        migrationOutput("  OK    $name", $isCli);
        $ranCount++;
    } catch (Throwable $e) {
        migrationOutput("  ERROR $name: " . $e->getMessage(), $isCli);
        $errCount++;
    }
}

migrationOutput(str_repeat('-', 50), $isCli);
migrationOutput("Done. Ran: $ranCount, Skipped: $skipCount, Errors: $errCount", $isCli);

if ($errCount > 0) {
    exit(1);
}
exit(0);
