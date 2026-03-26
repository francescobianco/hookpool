<?php
$current_user  = requireAuth($db);
$userId        = (int)$current_user['id'];
$action        = $_GET['action'] ?? '';
$projectEmojis = array_keys(PROJECT_ICONS);

// --- CREATE PROJECT ---
if ($action === 'create') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF check
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
            setFlash('error', __('msg.csrf_error'));
            header('Location: ' . BASE_URL . '/?page=project&action=create');
            exit;
        }

        $name        = trim($_POST['name'] ?? '');
        $slugInput   = trim($_POST['slug'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $categoryId  = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
        $active      = isset($_POST['active']) ? 1 : 0;
        $emoji       = in_array($_POST['emoji'] ?? '', $projectEmojis, true) ? $_POST['emoji'] : 'robot';

        if ($name === '') {
            setFlash('error', __('msg.required') . ' ' . __('project.name'));
            header('Location: ' . BASE_URL . '/?page=project&action=create');
            exit;
        }

        // Validate category belongs to user
        if ($categoryId !== null) {
            $catCheck = $db->prepare('SELECT id FROM categories WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
            $catCheck->execute([$categoryId, $userId]);
            if (!$catCheck->fetch()) $categoryId = null;
        }

        $slug = uniqueProjectSlug($db, $slugInput !== '' ? $slugInput : $name);

        // Insert project
        $ins = $db->prepare(
            'INSERT INTO projects (user_id, category_id, name, emoji, slug, description, active) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$userId, $categoryId, $name, $emoji, $slug, $description, $active]);
        $projectId = (int)$db->lastInsertId();

        // Auto-create first webhook
        $token = generateUniqueWebhookToken($db, $projectId);
        $db->prepare('INSERT INTO webhooks (project_id, name, token) VALUES (?, ?, ?)')->execute([$projectId, 'Webhook', $token]);

        setFlash('success', __('project.created'));
        header('Location: ' . BASE_URL . '/?page=project&action=detail&id=' . $projectId);
        exit;
    }

    // GET: show create form
    $page_title = __('project.create');

    // Load categories for dropdown
    $catStmt = $db->prepare('SELECT * FROM categories WHERE user_id = ? AND deleted_at IS NULL ORDER BY sort_order, name');
    $catStmt->execute([$userId]);
    $categories = $catStmt->fetchAll();

    ob_start();
    ?>
    <div class="page-container">
        <div class="page-header">
            <h1><?= __('project.create') ?></h1>
            <a href="<?= BASE_URL ?>/?page=project" class="btn btn-outline"><?= __('form.back') ?></a>
        </div>
        <div class="card">
            <form method="post" action="<?= BASE_URL ?>/?page=project&action=create" class="form">
                <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">

                <div class="form-group">
                    <label for="name"><?= __('project.name') ?> <span class="required">*</span></label>
                    <div class="name-with-emoji">
                        <select id="emoji" name="emoji" class="emoji-picker" title="Choose a project icon">
                            <?php foreach ($projectEmojis as $key): ?>
                            <option value="<?= e($key) ?>"<?= (($_POST['emoji'] ?? 'robot') === $key) ? ' selected' : '' ?>><?= projectEmoji($key) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" id="name" name="name" value="<?= e($_POST['name'] ?? '') ?>"
                               required maxlength="100" placeholder="My IoT Project">
                    </div>
                </div>

                <div class="form-group">
                    <label for="slug"><?= __('project.slug') ?></label>
                    <input type="text" id="slug" name="slug" value="<?= e($_POST['slug'] ?? '') ?>"
                           maxlength="100" pattern="[a-z0-9-]+" placeholder="auto from project name">
                    <p class="form-hint">Public project path. Lowercase letters, numbers, dashes. Reserved words are blocked and collisions become `slug-2`, `slug-3`, ...</p>
                </div>

                <div class="form-group">
                    <label for="description"><?= __('project.description') ?></label>
                    <textarea id="description" name="description" rows="3" maxlength="500"
                              placeholder="Optional description..."><?= e($_POST['description'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label for="category_id"><?= __('project.category') ?></label>
                    <select id="category_id" name="category_id">
                        <option value=""><?= __('category.no_category') ?></option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"<?= (isset($_POST['category_id']) && (int)$_POST['category_id'] === (int)$cat['id']) ? ' selected' : '' ?>>
                            <?= e($cat['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">
                        <a href="<?= BASE_URL ?>/?page=project&action=create_category" class="link-muted">+ Create new category</a>
                    </p>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="active" value="1" checked>
                        <?= __('project.active') ?>
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= __('project.create') ?></button>
                    <a href="<?= BASE_URL ?>/?page=project" class="btn btn-outline"><?= __('form.cancel') ?></a>
                </div>
            </form>
        </div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/../layout.php';
    exit;
}

// --- CREATE CATEGORY ---
if ($action === 'create_category') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
            setFlash('error', __('msg.csrf_error'));
            header('Location: ' . BASE_URL . '/?page=project&action=create_category');
            exit;
        }
        $name  = trim($_POST['name'] ?? '');
        $color = trim($_POST['color'] ?? '#4361ee');
        if ($name === '') {
            setFlash('error', __('msg.required'));
            header('Location: ' . BASE_URL . '/?page=project&action=create_category');
            exit;
        }
        // Validate hex color
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#4361ee';
        $db->prepare('INSERT INTO categories (user_id, name, color) VALUES (?, ?, ?)')->execute([$userId, $name, $color]);
        setFlash('success', __('msg.saved'));
        header('Location: ' . BASE_URL . '/?page=project&action=create');
        exit;
    }

    $page_title = __('category.create');
    ob_start();
    ?>
    <div class="page-container">
        <div class="page-header">
            <h1><?= __('category.create') ?></h1>
            <a href="<?= BASE_URL ?>/?page=project&action=create" class="btn btn-outline"><?= __('form.back') ?></a>
        </div>
        <div class="card">
            <form method="post" action="<?= BASE_URL ?>/?page=project&action=create_category" class="form">
                <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                <div class="form-group">
                    <label for="cat_name"><?= __('category.name') ?> <span class="required">*</span></label>
                    <input type="text" id="cat_name" name="name" value="" required maxlength="50" placeholder="My Category">
                </div>
                <div class="form-group">
                    <label for="cat_color"><?= __('category.color') ?></label>
                    <input type="color" id="cat_color" name="color" value="#4361ee">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= __('form.create') ?></button>
                    <a href="<?= BASE_URL ?>/?page=project&action=create" class="btn btn-outline"><?= __('form.cancel') ?></a>
                </div>
            </form>
        </div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/../layout.php';
    exit;
}

// --- PROJECT DETAIL ---
if ($action === 'detail') {
    $projectId = (int)($_GET['id'] ?? 0);

    $projStmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
    $projStmt->execute([$projectId, $userId]);
    $project = $projStmt->fetch();

    if (!$project) {
        setFlash('error', __('project.not_found'));
        header('Location: ' . BASE_URL . '/?page=project');
        exit;
    }

    // Load webhooks
    $whStmt = $db->prepare('SELECT * FROM webhooks WHERE project_id = ? AND deleted_at IS NULL ORDER BY created_at');
    $whStmt->execute([$projectId]);
    $webhooks = $whStmt->fetchAll();

    // Load event counts per webhook
    $eventCounts = [];
    foreach ($webhooks as $wh) {
        $cStmt = $db->prepare('SELECT COUNT(*) FROM events WHERE webhook_id = ?');
        $cStmt->execute([(int)$wh['id']]);
        $eventCounts[(int)$wh['id']] = (int)$cStmt->fetchColumn();
    }

    // Load project-level guards
    $guardStmt = $db->prepare('SELECT * FROM guards WHERE project_id = ? AND webhook_id IS NULL AND deleted_at IS NULL ORDER BY created_at');
    $guardStmt->execute([$projectId]);
    $guards = $guardStmt->fetchAll();

    // Load categories for the edit form
    $catStmt = $db->prepare('SELECT * FROM categories WHERE user_id = ? AND deleted_at IS NULL ORDER BY sort_order, name');
    $catStmt->execute([$userId]);
    $categories = $catStmt->fetchAll();

    $page_title = e($project['name']);

    ob_start();
    ?>
    <div class="page-container">
        <div class="page-header">
            <div class="header-title-group">
                <h1><?= projectEmoji(($project['emoji'] ?? '') ?: 'robot') ?> <?= e($project['name']) ?></h1>
                <?php if ($project['description']): ?>
                <p class="text-muted"><?= e($project['description']) ?></p>
                <?php endif; ?>
            </div>
            <div class="header-actions">
                <span class="badge <?= $project['active'] ? 'badge-success' : 'badge-muted' ?>">
                    <?= $project['active'] ? __('project.active') : __('project.inactive') ?>
                </span>
                <a href="<?= BASE_URL ?>/?page=project&action=edit&id=<?= $project['id'] ?>" class="btn btn-sm btn-outline"><?= __('project.edit') ?></a>
            </div>
        </div>

        <!-- Webhook List -->
        <section class="section">
            <div class="section-header">
                <h2><?= __('project.webhooks') ?></h2>
                <a href="<?= BASE_URL ?>/?page=webhook&action=create&project_id=<?= $project['id'] ?>" class="btn btn-sm btn-primary">+ <?= __('webhook.create') ?></a>
            </div>
            <?php if (empty($webhooks)): ?>
            <div class="empty-state-sm"><?= __('webhook.no_webhooks') ?></div>
            <?php else: ?>
            <div class="cards-grid">
                <?php foreach ($webhooks as $wh):
                    $sfn        = $wh['special_function'] ?? null;
                    $isPixel    = $sfn === 'pixel';
                    $isFile     = $sfn === 'file_upload';
                    $whDot      = $isPixel ? '🎯' : ($isFile ? '📎' : '🌐');
                    $whBaseUrl  = webhookUrl($project['slug'], $wh['token']);
                    $whDispUrl  = $isPixel ? ($whBaseUrl . '.png') : $whBaseUrl;
                ?>
                <div class="card card-webhook">
                    <div class="card-header">
                        <div class="card-title-group">
                            <span class="webhook-dot<?= $wh['active'] ? '' : ' inactive' ?>"><?= $whDot ?></span>
                            <h3 class="card-title"><?= e($wh['name']) ?></h3>
                        </div>
                        <span class="badge <?= $wh['active'] ? 'badge-success' : 'badge-muted' ?>"><?= $wh['active'] ? 'Active' : 'Inactive' ?></span>
                    </div>
                    <div class="webhook-url-row">
                        <code class="webhook-url"><?= e($whDispUrl) ?></code>
                        <button class="btn btn-sm btn-outline copy-btn"
                                onclick="copyToClipboard(<?= htmlspecialchars(json_encode($whDispUrl)) ?>, this)">
                            <?= __('webhook.copy') ?>
                        </button>
                    </div>
                    <?php if ($isPixel): ?>
                    <div class="card-meta" style="font-size:0.8rem;color:var(--text-muted)">
                        <?= __('webhook.sfn_pixel') ?>
                    </div>
                    <?php elseif ($isFile): ?>
                    <div class="card-meta" style="font-size:0.8rem;color:var(--text-muted)">
                        <?= __('webhook.sfn_file_upload') ?>
                    </div>
                    <?php endif; ?>
                    <div class="card-meta">
                        <span><?= (int)($eventCounts[(int)$wh['id']] ?? 0) ?> events</span>
                    </div>
                    <div class="card-actions">
                        <a href="<?= BASE_URL ?>/?page=webhook&action=detail&id=<?= $wh['id'] ?>" class="btn btn-sm btn-outline">View</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <!-- Project-level Guards -->
        <section class="section">
            <div class="section-header">
                <h2>Project Guards <span class="text-muted text-sm">(apply to all webhooks)</span></h2>
                <button onclick="openModal('addGuardModal')" class="btn btn-sm btn-outline">+ <?= __('guard.create') ?></button>
            </div>
            <?php if (empty($guards)): ?>
            <p class="text-muted">No project-level guards configured.</p>
            <?php else: ?>
            <div class="guards-list">
                <?php foreach ($guards as $guard): ?>
                <?php $cfg = json_decode($guard['config'], true) ?: []; ?>
                <div class="guard-item">
                    <div class="guard-info">
                        <span class="badge badge-info"><?= e($guard['type']) ?></span>
                        <span class="guard-config text-muted">
                            <?php
                            $cfgStr = match($guard['type']) {
                                'required_header' => 'Header: ' . ($cfg['header'] ?? ''),
                                'static_token'    => 'Header: ' . ($cfg['header'] ?? '') . ' = [token]',
                                'query_secret'    => 'Param: ' . ($cfg['param'] ?? '') . ' = [secret]',
                                'ip_whitelist'    => 'IPs: ' . ($cfg['ips'] ?? ''),
                                default           => json_encode($cfg),
                            };
                            echo e($cfgStr);
                            ?>
                        </span>
                    </div>
                    <form method="post" action="<?= BASE_URL ?>/?page=webhook&action=delete_guard&guard_id=<?= $guard['id'] ?>" class="inline">
                        <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                        <input type="hidden" name="redirect" value="project&action=detail&id=<?= $projectId ?>">
                        <button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Remove this guard?')">
                            Remove
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- Add Guard Modal -->
    <?php include __DIR__ . '/partials/add_guard_modal.php'; ?>
    <script>document.getElementById('addGuardForm')?.addEventListener('submit', function(e) {
        document.getElementById('addGuardProjectId').value = '<?= $projectId ?>';
        document.getElementById('addGuardWebhookId').value = '';
        document.getElementById('addGuardRedirect').value = 'project&action=detail&id=<?= $projectId ?>';
    });</script>

    <?php
    $content = ob_get_clean();
    require __DIR__ . '/../layout.php';
    exit;
}

// --- EDIT PROJECT ---
if ($action === 'edit') {
    $projectId = (int)($_GET['id'] ?? 0);
    $projStmt  = $db->prepare('SELECT * FROM projects WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
    $projStmt->execute([$projectId, $userId]);
    $project = $projStmt->fetch();

    if (!$project) {
        setFlash('error', __('project.not_found'));
        header('Location: ' . BASE_URL . '/?page=project');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
            setFlash('error', __('msg.csrf_error'));
            header('Location: ' . BASE_URL . '/?page=project&action=edit&id=' . $projectId);
            exit;
        }
        $name        = trim($_POST['name'] ?? '');
        $slugInput   = trim($_POST['slug'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $categoryId  = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
        $active      = isset($_POST['active']) ? 1 : 0;
        $emoji       = in_array($_POST['emoji'] ?? '', $projectEmojis, true) ? $_POST['emoji'] : 'robot';

        if ($name === '') {
            setFlash('error', __('msg.required'));
            header('Location: ' . BASE_URL . '/?page=project&action=edit&id=' . $projectId);
            exit;
        }
        if ($categoryId !== null) {
            $catCheck = $db->prepare('SELECT id FROM categories WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
            $catCheck->execute([$categoryId, $userId]);
            if (!$catCheck->fetch()) $categoryId = null;
        }

        $slug = uniqueProjectSlug($db, $slugInput !== '' ? $slugInput : $name, $projectId);
        $db->prepare('UPDATE projects SET name = ?, emoji = ?, slug = ?, description = ?, category_id = ?, active = ? WHERE id = ? AND user_id = ?')
           ->execute([$name, $emoji, $slug, $description, $categoryId, $active, $projectId, $userId]);

        setFlash('success', __('project.updated'));
        header('Location: ' . BASE_URL . '/?page=project&action=detail&id=' . $projectId);
        exit;
    }

    $catStmt = $db->prepare('SELECT * FROM categories WHERE user_id = ? AND deleted_at IS NULL ORDER BY sort_order, name');
    $catStmt->execute([$userId]);
    $categories = $catStmt->fetchAll();

    $page_title = 'Edit: ' . e($project['name']);
    ob_start();
    ?>
    <div class="page-container">
        <div class="page-header">
            <h1><?= __('project.edit') ?>: <?= e($project['name']) ?></h1>
            <a href="<?= BASE_URL ?>/?page=project&action=detail&id=<?= $project['id'] ?>" class="btn btn-outline"><?= __('form.back') ?></a>
        </div>
        <div class="card">
            <form method="post" action="<?= BASE_URL ?>/?page=project&action=edit&id=<?= $project['id'] ?>" class="form">
                <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                <div class="form-group">
                    <label for="name"><?= __('project.name') ?> <span class="required">*</span></label>
                    <div class="name-with-emoji">
                        <select id="emoji" name="emoji" class="emoji-picker" title="Choose a project icon">
                            <?php foreach ($projectEmojis as $key): ?>
                            <option value="<?= e($key) ?>"<?= ((($project['emoji'] ?? '') ?: 'robot') === $key) ? ' selected' : '' ?>><?= projectEmoji($key) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" id="name" name="name" value="<?= e($project['name']) ?>" required maxlength="100">
                    </div>
                </div>
                <div class="form-group">
                    <label for="slug"><?= __('project.slug') ?></label>
                    <input type="text" id="slug" name="slug" value="<?= e($project['slug']) ?>" required maxlength="100" pattern="[a-z0-9-]+">
                    <p class="form-hint">Public project path used in webhook URLs.</p>
                </div>
                <div class="form-group">
                    <label for="description"><?= __('project.description') ?></label>
                    <textarea id="description" name="description" rows="3" maxlength="500"><?= e($project['description']) ?></textarea>
                </div>
                <div class="form-group">
                    <label for="category_id"><?= __('project.category') ?></label>
                    <select id="category_id" name="category_id">
                        <option value=""><?= __('category.no_category') ?></option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"<?= (int)$project['category_id'] === (int)$cat['id'] ? ' selected' : '' ?>>
                            <?= e($cat['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="active" value="1"<?= $project['active'] ? ' checked' : '' ?>>
                        <?= __('project.active') ?>
                    </label>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= __('form.save') ?></button>
                    <a href="<?= BASE_URL ?>/?page=project&action=detail&id=<?= $project['id'] ?>" class="btn btn-outline"><?= __('form.cancel') ?></a>
                </div>
            </form>
        </div>

        <!-- Danger Zone -->
        <div class="card" style="border-color:var(--color-danger,#e74c3c);margin-top:24px">
            <h3 style="margin-top:0;color:var(--color-danger,#e74c3c)"><?= __('settings.danger_zone') ?></h3>
            <p class="text-muted"><?= __('project.confirm_delete') ?></p>
            <button onclick="openModal('deleteProjectModal')" class="btn btn-danger"><?= __('project.delete') ?></button>
        </div>
    </div>

    <!-- Delete Project Modal -->
    <div id="deleteProjectModal" class="modal" style="display:none" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3><?= __('project.delete') ?></h3>
                <button onclick="closeModal('deleteProjectModal')" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p><?= __('project.confirm_delete') ?></p>
            </div>
            <div class="modal-footer">
                <form method="post" action="<?= BASE_URL ?>/?page=project&action=delete&id=<?= $project['id'] ?>">
                    <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                    <button type="submit" class="btn btn-danger"><?= __('form.yes_delete') ?></button>
                    <button type="button" onclick="closeModal('deleteProjectModal')" class="btn btn-outline"><?= __('form.cancel') ?></button>
                </form>
            </div>
        </div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/../layout.php';
    exit;
}

// --- DELETE PROJECT ---
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $projectId = (int)($_GET['id'] ?? 0);
    if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
        setFlash('error', __('msg.csrf_error'));
        header('Location: ' . BASE_URL . '/?page=project&action=detail&id=' . $projectId);
        exit;
    }
    $projStmt = $db->prepare('SELECT id FROM projects WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
    $projStmt->execute([$projectId, $userId]);
    if (!$projStmt->fetch()) {
        setFlash('error', __('project.not_found'));
        header('Location: ' . BASE_URL . '/?page=project');
        exit;
    }
    // Soft delete project and its webhooks
    $db->prepare("UPDATE projects SET deleted_at = datetime('now') WHERE id = ? AND user_id = ?")->execute([$projectId, $userId]);
    $db->prepare("UPDATE webhooks SET deleted_at = datetime('now') WHERE project_id = ?")->execute([$projectId]);

    setFlash('success', __('project.deleted'));
    header('Location: ' . BASE_URL . '/?page=project');
    exit;
}

// --- DEFAULT: PROJECT LIST ---
$page_title = __('nav.projects');

$projStmt = $db->prepare('
    SELECT p.*,
           COALESCE(c.name, ?) as cat_name,
           COALESCE(c.color, \'#4361ee\') as cat_color,
           (SELECT COUNT(*) FROM webhooks w WHERE w.project_id = p.id AND w.deleted_at IS NULL) as webhook_count,
           (SELECT COUNT(*) FROM events e JOIN webhooks w2 ON w2.id = e.webhook_id WHERE w2.project_id = p.id AND w2.deleted_at IS NULL) as event_count
    FROM projects p
    LEFT JOIN categories c ON c.id = p.category_id AND c.deleted_at IS NULL
    WHERE p.user_id = ? AND p.deleted_at IS NULL
    ORDER BY COALESCE(c.sort_order, 2147483647), COALESCE(c.name, \'\'), p.name
');
$projStmt->execute([__('category.uncategorized'), $userId]);
$projects = $projStmt->fetchAll();
?>

<div class="page-container">
    <div class="page-header">
        <h1><?= __('nav.projects') ?></h1>
        <a href="<?= BASE_URL ?>/?page=project&action=create" class="btn btn-primary">+ <?= __('project.create') ?></a>
    </div>

    <?php if (empty($projects)): ?>
    <div class="empty-state">
        <div class="empty-icon">📁</div>
        <h3><?= __('project.no_projects') ?></h3>
        <a href="<?= BASE_URL ?>/?page=project&action=create" class="btn btn-primary">Create your first project</a>
    </div>
    <?php else: ?>
    <div class="cards-grid cards-grid-2">
        <?php foreach ($projects as $proj): ?>
        <div class="card card-project">
            <div class="card-header">
                <div class="card-title-group">
                    <span class="project-emoji<?= $proj['active'] ? '' : ' inactive' ?>" style="font-size:1.6rem;line-height:1;"><?= projectEmoji(($proj['emoji'] ?? '') ?: 'robot') ?></span>
                    <div>
                        <h3 class="card-title"><a href="<?= BASE_URL ?>/?page=project&action=detail&id=<?= $proj['id'] ?>"><?= e($proj['name']) ?></a></h3>
                        <?php if ($proj['description']): ?>
                        <p class="card-subtitle text-muted"><?= e(mb_strimwidth($proj['description'], 0, 80, '...')) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="badge <?= $proj['active'] ? 'badge-success' : 'badge-muted' ?>">
                    <?= $proj['active'] ? 'Active' : 'Inactive' ?>
                </span>
            </div>
            <div class="card-stats">
                <span><strong><?= (int)$proj['webhook_count'] ?></strong> webhooks</span>
                <span><strong><?= (int)$proj['event_count'] ?></strong> events</span>
                <?php if ($proj['cat_name'] !== __('category.uncategorized')): ?>
                <span style="color:var(--text-muted)">📁 <?= e($proj['cat_name']) ?></span>
                <?php endif; ?>
            </div>
            <div class="card-actions">
                <a href="<?= BASE_URL ?>/?page=project&action=detail&id=<?= $proj['id'] ?>" class="btn btn-sm btn-primary">View</a>
                <a href="<?= BASE_URL ?>/?page=project&action=edit&id=<?= $proj['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
