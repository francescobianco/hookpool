<?php
return static function (PDO $db): void {
    $columns = $db->query("PRAGMA table_info(alarm_email_attempts)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        if (($column['name'] ?? null) === 'spool_path') {
            return;
        }
    }

    execSQL($db, "ALTER TABLE alarm_email_attempts ADD COLUMN spool_path TEXT");
};
