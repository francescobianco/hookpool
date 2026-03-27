<?php
// Add log_retention_days to users: NULL = keep forever, otherwise hard-delete after N days via cron
return static function (PDO $db): void {
    execSQL($db, "ALTER TABLE users ADD COLUMN log_retention_days INTEGER DEFAULT NULL");
};
