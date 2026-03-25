<?php
return static function (PDO $db): void {
    if (DB_TYPE === 'mysql') {
        $db->exec("ALTER TABLE projects ADD COLUMN emoji VARCHAR(20) NOT NULL DEFAULT 'robot'");
    } else {
        $db->exec("ALTER TABLE projects ADD COLUMN emoji TEXT DEFAULT 'robot'");
    }
};
