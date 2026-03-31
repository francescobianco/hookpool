<?php
return static function (PDO $db): void {
    execSQL($db, "ALTER TABLE alarm_email_attempts ADD COLUMN spool_path TEXT");
};
