<?php

return static function (PDO $db): void {
    $projects = $db->query("SELECT id, name, slug FROM projects WHERE deleted_at IS NULL ORDER BY id")->fetchAll();
    if (!$projects) {
        createProjectSlugIndexes($db);
        return;
    }

    $seen = [];
    $reserved = [
        'admin', 'api', 'auth', 'dashboard', 'event', 'hook', 'home', 'index',
        'login', 'logout', 'migrate', 'project', 'projects', 'public', 'settings',
        'style', 'webhook', 'webhooks',
    ];

    $update = $db->prepare('UPDATE projects SET slug = ? WHERE id = ?');

    foreach ($projects as $project) {
        $base = migrationSlugify((string)($project['slug'] ?: $project['name']));
        if (in_array($base, $reserved, true)) {
            $base .= '-1';
        }

        $slug = $base;
        $i = 1;
        while (isset($seen[$slug]) || in_array($slug, $reserved, true)) {
            $i++;
            $slug = $base . '-' . $i;
        }

        $seen[$slug] = true;

        if ($slug !== $project['slug']) {
            $update->execute([$slug, (int)$project['id']]);
        }
    }

    createProjectSlugIndexes($db);
};

function migrationSlugify(string $text): string {
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\\s-]/', '', $text);
    $text = preg_replace('/[\\s-]+/', '-', $text);
    $text = trim((string)$text, '-');
    return $text ?: 'project';
}

function createProjectSlugIndexes(PDO $db): void {
    try {
        $db->exec('DROP INDEX IF EXISTS idx_projects_slug');
    } catch (Throwable $e) {
    }

    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_projects_slug ON projects(slug)');
}
