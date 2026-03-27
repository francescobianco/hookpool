<?php
// Add notes field to webhooks for free-text Markdown annotations
return static function (PDO $db): void {
    execSQL($db, "ALTER TABLE webhooks ADD COLUMN notes TEXT DEFAULT NULL");
};
