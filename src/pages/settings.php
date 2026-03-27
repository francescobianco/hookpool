<?php
$current_user = requireAuth($db);
$userId       = (int)$current_user['id'];
$action       = $_GET['action'] ?? '';
$page_title   = __('settings.title');

// --- DELETE ACCOUNT ---
if ($action === 'delete_account' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
        setFlash('error', __('msg.csrf_error'));
        header('Location: ' . BASE_URL . '/?page=settings');
        exit;
    }

    // Require user to type their current username to confirm
    $confirmUsername = trim($_POST['confirm_username'] ?? '');
    if ($confirmUsername !== $current_user['username']) {
        setFlash('error', 'Username confirmation did not match. Account was NOT deleted.');
        header('Location: ' . BASE_URL . '/?page=settings');
        exit;
    }

    // Soft delete user and all their data

    // Get all user project IDs
    $projIds = $db->prepare('SELECT id FROM projects WHERE user_id = ? AND deleted_at IS NULL');
    $projIds->execute([$userId]);
    $allProjectIds = $projIds->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($allProjectIds)) {
        // Get webhook IDs for those projects
        $whIds = $db->query(
            'SELECT id FROM webhooks WHERE project_id IN (' . implode(',', array_map('intval', $allProjectIds)) . ') AND deleted_at IS NULL'
        )->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($whIds)) {
            // Soft-delete forward actions
            $db->exec('UPDATE forward_actions SET deleted_at = CURRENT_TIMESTAMP WHERE webhook_id IN (' . implode(',', array_map('intval', $whIds)) . ')');
            // Soft-delete guards at webhook level
            $db->exec('UPDATE guards SET deleted_at = CURRENT_TIMESTAMP WHERE webhook_id IN (' . implode(',', array_map('intval', $whIds)) . ')');
            // Soft-delete webhooks
            $db->exec('UPDATE webhooks SET deleted_at = CURRENT_TIMESTAMP WHERE id IN (' . implode(',', array_map('intval', $whIds)) . ')');
        }

        // Soft-delete project-level guards
        $db->exec('UPDATE guards SET deleted_at = CURRENT_TIMESTAMP WHERE project_id IN (' . implode(',', array_map('intval', $allProjectIds)) . ')');
        // Soft-delete projects
        $db->exec('UPDATE projects SET deleted_at = CURRENT_TIMESTAMP WHERE user_id = ' . $userId);
    }

    // Soft-delete categories
    $db->prepare("UPDATE categories SET deleted_at = ? WHERE user_id = ?")->execute([date('Y-m-d H:i:s'), $userId]);

    // Soft-delete user
    $db->prepare("UPDATE users SET deleted_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $userId]);

    // Destroy session and redirect
    setFlash('success', __('settings.account_deleted'));
    logout();
    exit;
}

// --- SAVE LOG RETENTION ---
if ($action === 'save_log_retention' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
        setFlash('error', __('msg.csrf_error'));
        header('Location: ' . BASE_URL . '/?page=settings#log-retention');
        exit;
    }
    $allowed = ['1', '7', '30', '90'];
    $raw = $_POST['log_retention_days'] ?? '1';
    $days = in_array($raw, $allowed, true) ? (int)$raw : 1;
    $db->prepare('UPDATE users SET log_retention_days = ? WHERE id = ?')->execute([$days, $userId]);
    setFlash('success', __('msg.saved'));
    header('Location: ' . BASE_URL . '/?page=settings#log-retention');
    exit;
}

// --- CREATE CATEGORY ---
if ($action === 'create_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
        setFlash('error', __('msg.csrf_error'));
        header('Location: ' . BASE_URL . '/?page=settings#categories');
        exit;
    }
    $catName  = trim($_POST['cat_name'] ?? '');
    $catColor = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['cat_color'] ?? '') ? $_POST['cat_color'] : '#4361ee';
    if ($catName !== '') {
        $db->prepare('INSERT INTO categories (user_id, name, color) VALUES (?, ?, ?)')->execute([$userId, $catName, $catColor]);
        setFlash('success', __('msg.saved'));
    }
    header('Location: ' . BASE_URL . '/?page=settings#categories');
    exit;
}

// --- EDIT CATEGORY ---
if ($action === 'edit_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $catId = (int)($_GET['id'] ?? 0);
    if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
        setFlash('error', __('msg.csrf_error'));
        header('Location: ' . BASE_URL . '/?page=settings#categories');
        exit;
    }
    $catName  = trim($_POST['cat_name'] ?? '');
    $catColor = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['cat_color'] ?? '') ? $_POST['cat_color'] : '#4361ee';
    if ($catName !== '' && $catId > 0) {
        $db->prepare('UPDATE categories SET name = ?, color = ? WHERE id = ? AND user_id = ? AND deleted_at IS NULL')
           ->execute([$catName, $catColor, $catId, $userId]);
        setFlash('success', __('msg.saved'));
    }
    header('Location: ' . BASE_URL . '/?page=settings#categories');
    exit;
}

// --- DELETE CATEGORY ---
if ($action === 'delete_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $catId = (int)($_GET['id'] ?? 0);
    if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
        setFlash('error', __('msg.csrf_error'));
        header('Location: ' . BASE_URL . '/?page=settings#categories');
        exit;
    }
    if ($catId > 0) {
        $db->prepare("UPDATE categories SET deleted_at = ? WHERE id = ? AND user_id = ?")
           ->execute([date('Y-m-d H:i:s'), $catId, $userId]);
        $db->prepare('UPDATE projects SET category_id = NULL WHERE category_id = ? AND user_id = ?')
           ->execute([$catId, $userId]);
        setFlash('success', __('msg.deleted'));
    }
    header('Location: ' . BASE_URL . '/?page=settings#categories');
    exit;
}

// Get statistics for display
$statsStmt = $db->prepare('
    SELECT
        (SELECT COUNT(*) FROM projects WHERE user_id = ? AND deleted_at IS NULL) as project_count,
        (SELECT COUNT(*) FROM webhooks w JOIN projects p ON p.id = w.project_id WHERE p.user_id = ? AND w.deleted_at IS NULL AND p.deleted_at IS NULL) as webhook_count,
        (SELECT COUNT(*) FROM events e JOIN webhooks w2 ON w2.id = e.webhook_id JOIN projects p2 ON p2.id = w2.project_id WHERE p2.user_id = ? AND w2.deleted_at IS NULL AND p2.deleted_at IS NULL) as event_count
');
$statsStmt->execute([$userId, $userId, $userId]);
$stats = $statsStmt->fetch();
?>

<div class="page-container">
    <div class="page-header">
        <h1><?= __('settings.title') ?></h1>
    </div>

    <!-- Profile Card -->
    <section class="section">
        <div class="card">
            <div class="profile-header">
                <?php if (!empty($current_user['avatar_url'])): ?>
                <img src="<?= e($current_user['avatar_url']) ?>"
                     alt="<?= e($current_user['username']) ?>"
                     class="profile-avatar">
                <?php else: ?>
                <div class="profile-avatar profile-avatar-placeholder"><?= e(strtoupper(substr((string)($current_user['display_name'] ?: $current_user['username']), 0, 1))) ?></div>
                <?php endif; ?>
                <div class="profile-info">
                    <h2 class="profile-name"><?= e($current_user['display_name'] ?: $current_user['username']) ?></h2>
                    <div class="profile-meta">
                        <span class="text-muted">@<?= e($current_user['username']) ?></span>
                        <?php if ($current_user['email']): ?>
                        <span class="text-muted"><?= e($current_user['email']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($current_user['github_id'])): ?>
                    <div class="profile-github">
                        <a href="https://github.com/<?= e($current_user['username']) ?>" target="_blank" rel="noopener" class="link-muted">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" style="vertical-align:middle">
                                <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.012 8.012 0 0 0 16 8c0-4.42-3.58-8-8-8z"/>
                            </svg>
                            github.com/<?= e($current_user['username']) ?>
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="profile-github">
                        <span class="link-muted"><?= __('settings.local_mode') ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats -->
    <section class="section">
        <h2>Your Data</h2>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= (int)$stats['project_count'] ?></div>
                <div class="stat-label">Projects</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= (int)$stats['webhook_count'] ?></div>
                <div class="stat-label">Webhooks</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format((int)$stats['event_count']) ?></div>
                <div class="stat-label">Events Received</div>
            </div>
        </div>
    </section>

    <!-- Language -->
    <section class="section">
        <div class="section-header">
            <h2><?= __('settings.language') ?></h2>
        </div>
        <div class="card">
            <div class="lang-switcher">
                <?php
                $currentLang = $_COOKIE['lang'] ?? DEFAULT_LANG;
                $langs = ['en' => '🇬🇧 English', 'it' => '🇮🇹 Italiano'];
                foreach ($langs as $code => $label):
                    $active = ($currentLang === $code);
                ?>
                <a href="<?= BASE_URL ?>/?lang=<?= $code ?>&page=settings"
                   class="lang-option<?= $active ? ' active' : '' ?>">
                    <?= $label ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Categories -->
    <section class="section" id="categories">
        <div class="section-header">
            <h2>Categories</h2>
        </div>
        <?php
        $catListStmt = $db->prepare('SELECT * FROM categories WHERE user_id = ? AND deleted_at IS NULL ORDER BY sort_order, name');
        $catListStmt->execute([$userId]);
        $userCategories = $catListStmt->fetchAll();
        ?>
        <?php if (!empty($userCategories)): ?>
        <div class="categories-manage-list" id="categoryList">
            <?php foreach ($userCategories as $cat): ?>
            <div class="category-manage-item card" draggable="true" data-cat-id="<?= (int)$cat['id'] ?>">
                <div class="category-info">
                    <span class="drag-handle" title="Trascina per riordinare">⠿</span>
                    <span class="cat-color-swatch" style="background:<?= e($cat['color']) ?>"></span>
                    <span><?= e($cat['name']) ?></span>
                </div>
                <div class="category-actions">
                    <button onclick="openModal('editCatModal<?= (int)$cat['id'] ?>')" class="btn btn-xs btn-outline">Edit</button>
                    <form method="post" action="<?= BASE_URL ?>/?page=settings&action=delete_category&id=<?= (int)$cat['id'] ?>" class="inline">
                        <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                        <button type="submit" class="btn btn-xs btn-danger"
                                onclick="return confirm('Delete category? Projects will become uncategorized.')">Delete</button>
                    </form>
                </div>
            </div>
            <!-- Edit modal -->
            <div id="editCatModal<?= (int)$cat['id'] ?>" class="modal" style="display:none" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-header">
                        <h3>Edit Category</h3>
                        <button onclick="closeModal('editCatModal<?= (int)$cat['id'] ?>')" class="modal-close">&times;</button>
                    </div>
                    <form method="post" action="<?= BASE_URL ?>/?page=settings&action=edit_category&id=<?= (int)$cat['id'] ?>">
                        <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>Name</label>
                                <input type="text" name="cat_name" value="<?= e($cat['name']) ?>" required maxlength="50">
                            </div>
                            <div class="form-group">
                                <label>Color</label>
                                <input type="color" name="cat_color" value="<?= e($cat['color']) ?>">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-primary"><?= __('form.save') ?></button>
                            <button type="button" onclick="closeModal('editCatModal<?= (int)$cat['id'] ?>')" class="btn btn-outline"><?= __('form.cancel') ?></button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-muted" style="margin-bottom:12px">No categories yet. Create one to organize your projects.</p>
        <?php endif; ?>

        <!-- Create new category -->
        <div class="card" style="margin-top:12px">
            <form method="post" action="<?= BASE_URL ?>/?page=settings&action=create_category" class="form">
                <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                <div class="form-row-inline">
                    <input type="color" name="cat_color" value="#4361ee" title="Category color"
                           style="width:44px;height:38px;padding:2px;border-radius:var(--radius-sm);cursor:pointer;flex-shrink:0">
                    <input type="text" name="cat_name" placeholder="New category name" required maxlength="50">
                    <button type="submit" class="btn btn-primary" style="white-space:nowrap">+ Add</button>
                </div>
            </form>
        </div>
    </section>

    <!-- Log Retention -->
    <section class="section" id="log-retention">
        <h2><?= __('settings.log_retention') ?></h2>
        <div class="card">
            <p class="text-muted" style="margin-top:0"><?= __('settings.log_retention_desc') ?></p>
            <form method="post" action="<?= BASE_URL ?>/?page=settings&action=save_log_retention" class="form">
                <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                <?php $currentRetention = (string)($current_user['log_retention_days'] ?? '1'); ?>
                <div class="retention-options">
                    <?php foreach (['1' => __('settings.retention_1d'), '7' => __('settings.retention_7d'), '30' => __('settings.retention_30d'), '90' => __('settings.retention_90d')] as $val => $label): ?>
                    <label class="retention-option<?= ($currentRetention === $val) ? ' active' : '' ?>">
                        <input type="radio" name="log_retention_days" value="<?= $val ?>"
                               <?= ($currentRetention === $val) ? 'checked' : '' ?>
                               onchange="this.form.submit()">
                        <?= $label ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <p class="form-hint" style="margin-top:0.75rem"><?= __('settings.log_retention_hint') ?></p>
            </form>
        </div>
    </section>

    <!-- Danger Zone (hidden for local single-user mode) -->
    <?php if (authEnabled()): ?>
    <section class="section danger-zone">
        <h2 class="text-error"><?= __('settings.danger_zone') ?></h2>
        <div class="card card-danger">
            <div class="danger-item">
                <div>
                    <h4><?= __('settings.delete_account') ?></h4>
                    <p class="text-muted"><?= __('settings.delete_account_desc') ?></p>
                </div>
                <button onclick="openModal('deleteAccountModal')" class="btn btn-danger">
                    <?= __('settings.delete_account') ?>
                </button>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Footer links -->
    <div style="text-align:center;margin-top:1.5rem;display:flex;justify-content:center;gap:1.5rem">
        <a href="<?= BASE_URL ?>/?page=known_ips" class="text-muted" style="font-size:0.8rem;text-decoration:none;opacity:0.6">
            Known IP Addresses ›
        </a>
        <a href="<?= BASE_URL ?>/?page=diagnose" class="text-muted" style="font-size:0.8rem;text-decoration:none;opacity:0.6">
            System Diagnose ›
        </a>
    </div>
</div>

<?php if (authEnabled()): ?>
<!-- Delete Account Modal -->
<div id="deleteAccountModal" class="modal" style="display:none" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="text-error">Delete Account</h3>
            <button onclick="closeModal('deleteAccountModal')" class="modal-close">&times;</button>
        </div>
        <form method="post" action="<?= BASE_URL ?>/?page=settings&action=delete_account">
            <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
            <div class="modal-body">
                <p><?= __('settings.delete_account_desc') ?></p>
                <div class="form-group">
                    <label><?= __('settings.confirm_delete_account') ?></label>
                    <input type="text" name="confirm_username"
                           placeholder="<?= e($current_user['username']) ?>"
                           required
                           autocomplete="off">
                    <p class="form-hint text-error">Type <strong><?= e($current_user['username']) ?></strong> to confirm.</p>
                </div>
                <div class="warning-box">
                    <strong>This will permanently delete:</strong>
                    <ul>
                        <li><?= (int)$stats['project_count'] ?> projects</li>
                        <li><?= (int)$stats['webhook_count'] ?> webhooks</li>
                        <li><?= number_format((int)$stats['event_count']) ?> events</li>
                        <li>All forward actions and guard rules</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-danger"><?= __('form.yes_delete') ?> My Account</button>
                <button type="button" onclick="closeModal('deleteAccountModal')" class="btn btn-outline"><?= __('form.cancel') ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function() {
    const list = document.getElementById('categoryList');
    if (!list) return;

    let dragSrc = null;

    list.addEventListener('dragstart', function(e) {
        dragSrc = e.target.closest('[data-cat-id]');
        if (!dragSrc) return;
        dragSrc.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    });

    list.addEventListener('dragover', function(e) {
        e.preventDefault();
        const target = e.target.closest('[data-cat-id]');
        if (!target || target === dragSrc) return;
        e.dataTransfer.dropEffect = 'move';

        const rect = target.getBoundingClientRect();
        const mid = rect.top + rect.height / 2;
        if (e.clientY < mid) {
            list.insertBefore(dragSrc, target);
        } else {
            list.insertBefore(dragSrc, target.nextSibling);
        }
    });

    list.addEventListener('dragend', function() {
        if (dragSrc) dragSrc.classList.remove('dragging');
        dragSrc = null;
        saveOrder();
    });

    function saveOrder() {
        const order = Array.from(list.querySelectorAll('[data-cat-id]')).map(el => parseInt(el.dataset.catId, 10));
        fetch('<?= BASE_URL ?>/?page=api&action=reorder_categories', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({_csrf: csrfToken, order: order}),
        }).catch(() => {});
    }
})();
</script>
