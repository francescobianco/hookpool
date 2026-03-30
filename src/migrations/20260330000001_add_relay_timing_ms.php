<?php
return static function (PDO $db): void {
    execSQL($db, "ALTER TABLE relay_queue ADD COLUMN created_at_ms BIGINT DEFAULT NULL");
    execSQL($db, "ALTER TABLE relay_queue ADD COLUMN dispatched_at_ms BIGINT DEFAULT NULL");
    execSQL($db, "ALTER TABLE relay_queue ADD COLUMN responded_at_ms BIGINT DEFAULT NULL");
};
