<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(generateCsrfToken()) ?>">
    <title><?= e($page_title ?? APP_NAME) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/style.css">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🪝</text></svg>">
</head>
<body<?= isset($current_user) ? ' class="authenticated"' : '' ?>>

<header class="app-header">
    <div class="header-left">
        <?php if (isset($current_user)): ?>
        <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
            <span></span><span></span><span></span>
        </button>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/" class="logo">
            <span class="logo-icon">🪝</span>
            <span class="logo-text"><?= APP_NAME ?></span>
        </a>
    </div>
    <nav class="header-nav">
        <?php if (isset($current_user)): ?>
            <a href="<?= BASE_URL ?>/?page=dashboard" class="nav-link<?= (($_GET['page'] ?? '') === 'dashboard') ? ' active' : '' ?>">
                <?= __('nav.dashboard') ?>
            </a>
            <a href="<?= BASE_URL ?>/?page=project" class="nav-link<?= (($_GET['page'] ?? '') === 'project') ? ' active' : '' ?>">
                <?= __('nav.projects') ?>
            </a>
            <a href="<?= BASE_URL ?>/?page=settings" class="nav-link<?= (($_GET['page'] ?? '') === 'settings') ? ' active' : '' ?>">
                <?= __('nav.settings') ?>
            </a>
            <div class="user-menu">
                <?php if (!empty($current_user['avatar_url'])): ?>
                <img src="<?= e($current_user['avatar_url']) ?>" alt="<?= e($current_user['username'] ?? '') ?>" class="user-avatar">
                <?php else: ?>
                <div class="user-avatar user-avatar-placeholder"><?= e(strtoupper(substr((string)($current_user['display_name'] ?: $current_user['username']), 0, 1))) ?></div>
                <?php endif; ?>
                <span class="user-name"><?= e($current_user['display_name'] ?: $current_user['username']) ?></span>
                <?php if (authEnabled()): ?>
                <a href="<?= BASE_URL ?>/?page=auth&action=logout" class="btn btn-sm btn-outline">
                    <?= __('nav.logout') ?>
                </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php if (authEnabled()): ?>
                <a href="<?= BASE_URL ?>/?page=auth&action=login" class="btn btn-primary btn-sm">
                    <?= __('auth.login_with_github') ?>
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>
</header>

<div class="app-body">
    <?php if (isset($current_user)): ?>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-inner">
            <div class="sidebar-section">
                <a href="<?= BASE_URL ?>/?page=project&action=create" class="btn btn-primary btn-sm btn-block">
                    + <?= __('project.create') ?>
                </a>
            </div>

            <?php
            // Load sidebar data: categories and projects for current user
            $sidebarDb = Database::get();
            $sidebarUserId = (int)$current_user['id'];

            // Get categories
            $catStmt = $sidebarDb->prepare(
                'SELECT * FROM categories WHERE user_id = ? AND deleted_at IS NULL ORDER BY sort_order, name'
            );
            $catStmt->execute([$sidebarUserId]);
            $sidebarCategories = $catStmt->fetchAll();

            // Get projects with event counts
            $projStmt = $sidebarDb->prepare('
                SELECT p.*,
                       COALESCE(c.name, ?) as cat_name,
                       COALESCE(c.color, \'#4361ee\') as cat_color,
                       (SELECT COUNT(*) FROM webhooks w2 WHERE w2.project_id = p.id AND w2.deleted_at IS NULL) as webhook_count
                FROM projects p
                LEFT JOIN categories c ON c.id = p.category_id AND c.deleted_at IS NULL
                WHERE p.user_id = ? AND p.deleted_at IS NULL
                ORDER BY COALESCE(c.sort_order, 2147483647), COALESCE(c.name, ''), p.name
            ');
            $projStmt->execute([__('category.uncategorized'), $sidebarUserId]);
            $sidebarProjects = $projStmt->fetchAll();

            // Group by category
            $grouped = [];
            foreach ($sidebarProjects as $proj) {
                $catName = $proj['cat_name'];
                $grouped[$catName][] = $proj;
            }

            $currentPage      = $_GET['page'] ?? '';
            $currentProjectId = 0;
            $currentWebhookId = 0;

            if ($currentPage === 'project' && isset($_GET['id'])) {
                $currentProjectId = (int)$_GET['id'];
            } elseif ($currentPage === 'webhook' && ($_GET['action'] ?? '') === 'detail' && isset($_GET['id'])) {
                $currentWebhookId = (int)$_GET['id'];
                if ($currentWebhookId > 0) {
                    $whLookup = $sidebarDb->prepare(
                        'SELECT project_id FROM webhooks WHERE id = ? AND deleted_at IS NULL LIMIT 1'
                    );
                    $whLookup->execute([$currentWebhookId]);
                    $wl = $whLookup->fetch();
                    if ($wl) $currentProjectId = (int)$wl['project_id'];
                }
            }
            ?>

            <?php if (empty($sidebarProjects)): ?>
                <p class="sidebar-empty"><?= __('project.no_projects') ?></p>
            <?php else: ?>
                <?php foreach ($grouped as $catName => $projects): ?>
                <div class="sidebar-category">
                    <div class="sidebar-category-header" onclick="toggleCategory(this)">
                        <span class="cat-name"><?= e($catName) ?></span>
                        <span class="cat-toggle">▾</span>
                    </div>
                    <ul class="sidebar-projects">
                        <?php foreach ($projects as $proj): ?>
                        <li class="sidebar-project<?= ($currentProjectId === (int)$proj['id']) ? ' active' : '' ?>">
                            <a href="<?= BASE_URL ?>/?page=project&action=detail&id=<?= $proj['id'] ?>" class="sidebar-project-link">
                                <span class="project-emoji<?= $proj['active'] ? '' : ' inactive' ?>"><?= e(($proj['emoji'] ?? '') ?: '🤖') ?></span>
                                <span class="project-name"><?= e($proj['name']) ?></span>
                                <span class="project-count"><?= (int)$proj['webhook_count'] ?></span>
                            </a>
                            <?php if ($currentProjectId === (int)$proj['id']): ?>
                            <?php
                            $whStmt = $sidebarDb->prepare(
                                'SELECT * FROM webhooks WHERE project_id = ? AND deleted_at IS NULL ORDER BY created_at'
                            );
                            $whStmt->execute([(int)$proj['id']]);
                            $sidebarWebhooks = $whStmt->fetchAll();
                            ?>
                            <?php if (!empty($sidebarWebhooks)): ?>
                            <ul class="sidebar-webhooks">
                                <?php foreach ($sidebarWebhooks as $wh): ?>
                                <li class="sidebar-webhook<?= ($currentWebhookId === (int)$wh['id']) ? ' active' : '' ?><?= $wh['active'] ? '' : ' inactive' ?>">
                                    <a href="<?= BASE_URL ?>/?page=webhook&action=detail&id=<?= $wh['id'] ?>">
                                        <?= e($wh['name']) ?>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>
    <?php endif; ?>

    <main class="main-content<?= isset($current_user) ? ' with-sidebar' : '' ?>">
        <?php $flash = getFlash(); if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="alert">
            <span class="alert-message"><?= e($flash['message']) ?></span>
            <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>
        </div>
        <?php endif; ?>

        <?= $content ?>
    </main>
</div>

<footer class="app-footer">
    <p>&copy; <?= date('Y') ?> <?= APP_NAME ?> &mdash; Built for IoT, integrations, and developers.</p>
</footer>

<script>
// CSRF helper
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

// Modal helpers
function openModal(id) {
    const m = document.getElementById(id);
    if (m) { m.style.display = 'flex'; m.setAttribute('aria-hidden', 'false'); }
}
function closeModal(id) {
    const m = document.getElementById(id);
    if (m) { m.style.display = 'none'; m.setAttribute('aria-hidden', 'true'); }
}
window.addEventListener('click', e => {
    if (e.target.classList.contains('modal')) closeModal(e.target.id);
});
window.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal[style*="flex"]').forEach(m => closeModal(m.id));
    }
});

// Sidebar toggle (mobile)
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar = document.getElementById('sidebar');
if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('open');
    });
}

// Sidebar category toggle
function toggleCategory(header) {
    const cat = header.parentElement;
    cat.classList.toggle('collapsed');
}

// Copy to clipboard with feedback
function copyToClipboard(text, btn) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            showCopyFeedback(btn);
        }).catch(() => {
            fallbackCopy(text, btn);
        });
    } else {
        fallbackCopy(text, btn);
    }
}

function fallbackCopy(text, btn) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try {
        document.execCommand('copy');
        showCopyFeedback(btn);
    } catch(e) {}
    document.body.removeChild(ta);
}

function showCopyFeedback(btn) {
    if (!btn) return;
    const original = btn.textContent;
    btn.textContent = '✓ Copied!';
    btn.classList.add('copied');
    setTimeout(() => {
        btn.textContent = original;
        btn.classList.remove('copied');
    }, 2000);
}

// Auto-dismiss alerts after 5s
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    });
});
</script>
</body>
</html>
