<?php
// Add notes field to webhooks for free-text Markdown annotations
function migrate_20260327000002(PDO $db): void {
    execSQL($db, "ALTER TABLE webhooks ADD COLUMN notes TEXT DEFAULT NULL");
}
