<?php
return static function (PDO $db): void {
    // Add paused_until to webhooks: when set and in the future, webhook is treated as inactive
    if (DB_TYPE === 'sqlite') {
        execSQL($db, "ALTER TABLE webhooks ADD COLUMN paused_until DATETIME DEFAULT NULL");
    } else {
        // MySQL: column may already exist on re-run — use try/catch in caller
        $db->exec("ALTER TABLE webhooks ADD COLUMN paused_until DATETIME DEFAULT NULL");
    }
};
