<?php
// Add autocall columns: cron schedule + run timestamps
return static function (PDO $db): void {
    execSQL($db, "ALTER TABLE webhooks ADD COLUMN cron_expression TEXT DEFAULT NULL");
    execSQL($db, "ALTER TABLE webhooks ADD COLUMN cron_next_run DATETIME DEFAULT NULL");
    execSQL($db, "ALTER TABLE webhooks ADD COLUMN cron_last_run DATETIME DEFAULT NULL");
};
