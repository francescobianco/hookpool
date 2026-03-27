<?php
/**
 * Add autocall support to webhooks:
 *   cron_expression — standard 5-field cron string (e.g. "*/5 * * * *")
 *   cron_next_run   — next scheduled execution timestamp (NULL = not scheduled)
 */
function migrate_20260327000001(PDO $db): void {
    execSQL($db, "ALTER TABLE webhooks ADD COLUMN cron_expression TEXT DEFAULT NULL");
    execSQL($db, "ALTER TABLE webhooks ADD COLUMN cron_next_run DATETIME DEFAULT NULL");
    execSQL($db, "ALTER TABLE webhooks ADD COLUMN cron_last_run DATETIME DEFAULT NULL");
}
