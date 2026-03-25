<?php
return static function (PDO $db): void {
    if (DB_TYPE === 'mysql') {
        $db->exec("ALTER TABLE projects ADD COLUMN emoji VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '🤖'");
    } else {
        $db->exec("ALTER TABLE projects ADD COLUMN emoji TEXT DEFAULT '🤖'");
    }
};
