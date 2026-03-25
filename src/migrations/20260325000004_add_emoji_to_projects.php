<?php
return static function (PDO $db): void {
    if (DB_TYPE === 'sqlite') {
        $db->exec("ALTER TABLE projects ADD COLUMN emoji TEXT DEFAULT '🤖'");
    } else {
        $db->exec("ALTER TABLE projects ADD COLUMN emoji TEXT NOT NULL DEFAULT '🤖'");
    }
};
