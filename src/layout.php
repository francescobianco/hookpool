<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(generateCsrfToken()) ?>">
    <title><?= e($page_title ?? APP_NAME) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/<?= e(asset('assets/css/style.css')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@700&display=swap" rel="stylesheet">
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.php">
    <meta name="theme-color" content="#ff5a36">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= APP_NAME ?>">
    <link rel="icon" type="image/x-icon" href="<?= BASE_URL ?>/<?= asset('assets/images/icons/favicon.ico') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>/<?= asset('assets/images/icons/favicon-32x32.png') ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= BASE_URL ?>/<?= asset('assets/images/icons/apple-touch-icon.png') ?>">
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
            <img src="<?= BASE_URL ?>/<?= asset('assets/images/logo.png') ?>" alt="<?= APP_NAME ?>" class="logo-img">
            <span class="logo-name"><span class="logo-hook">hook</span><span class="logo-pool">pool</span></span>
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
            <div class="user-menu" id="userMenu">
                <button class="user-menu-trigger" id="userMenuTrigger" onclick="toggleUserMenu(event)" aria-expanded="false" aria-haspopup="true">
                    <?php if (!empty($current_user['avatar_url'])): ?>
                    <img src="<?= e($current_user['avatar_url']) ?>" alt="<?= e($current_user['username'] ?? '') ?>" class="user-avatar">
                    <?php else: ?>
                    <div class="user-avatar user-avatar-placeholder">🤖</div>
                    <?php endif; ?>
                    <span class="user-name"><?= e($current_user['display_name'] ?: $current_user['username']) ?></span>
                    <span class="user-menu-chevron">▾</span>
                </button>
                <div class="user-dropdown" id="userDropdown" style="display:none">
                    <div class="user-dropdown-info">
                        <?php if (!empty($current_user['avatar_url'])): ?>
                        <img src="<?= e($current_user['avatar_url']) ?>" alt="" class="user-dropdown-avatar">
                        <?php else: ?>
                        <div class="user-dropdown-avatar user-avatar-placeholder">🤖</div>
                        <?php endif; ?>
                        <div>
                            <div class="user-dropdown-name"><?= e($current_user['display_name'] ?: $current_user['username']) ?></div>
                            <?php if (!empty($current_user['email'])): ?>
                            <div class="user-dropdown-email"><?= e($current_user['email']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="user-dropdown-divider"></div>
                    <a href="<?= BASE_URL ?>/?page=settings" class="user-dropdown-item">
                        <?= __('nav.settings') ?>
                    </a>
                    <?php if (authEnabled()): ?>
                    <div class="user-dropdown-divider"></div>
                    <a href="<?= BASE_URL ?>/?page=auth&action=logout" class="user-dropdown-item user-dropdown-item-danger">
                        <?= __('nav.logout') ?>
                    </a>
                    <?php endif; ?>
                </div>
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
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-inner">
            <div class="sidebar-section">
                <a href="<?= BASE_URL ?>/?page=project&action=create" class="btn btn-primary btn-sm btn-block">
                    + <?= __('project.create') ?>
                </a>
            </div>
            <?php
            // Load saved filter presets for this user
            $fpDb     = Database::get();
            $fpUserId = (int)$current_user['id'];
            $fpStmt   = $fpDb->prepare('SELECT id, name, params FROM filter_presets WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at');
            $fpStmt->execute([$fpUserId]);
            $filterPresets = $fpStmt->fetchAll();
            ?>
            <div class="sidebar-category sidebar-links-group">
                <div class="sidebar-category-header">
                    <span class="cat-name">Links</span>
                </div>
                <ul class="sidebar-webhooks sidebar-static-links" id="sidebarFilterList">
                    <li class="sidebar-webhook<?= (($_GET['page'] ?? '') === 'dashboard' && empty(array_filter(array_intersect_key($_GET, array_flip(['category_id','project_id','method','status','time']))))) ? ' active' : '' ?>">
                        <a href="<?= BASE_URL ?>/?page=dashboard">Dashboard</a>
                    </li>
                    <?php foreach ($filterPresets as $fp): ?>
                    <?php
                        $fpParams = json_decode($fp['params'], true) ?: [];
                        $fpParams = array_filter($fpParams, fn($v) => $v !== '' && $v !== null);
                        // Analytics views have page=analytics; dashboard filters default to page=dashboard
                        if (!isset($fpParams['page']) || $fpParams['page'] === 'dashboard') {
                            $fpParams['page'] = 'dashboard';
                        }
                        $fpUrl = BASE_URL . '/?' . http_build_query($fpParams);
                        // Is this preset active?
                        $fpPage = $fpParams['page'];
                        $fpActive = false;
                        if ($fpPage === 'analytics' && ($_GET['page'] ?? '') === 'analytics') {
                            $fpActive = isset($fpParams['view_id']) && (int)$fpParams['view_id'] === (int)($_GET['view_id'] ?? 0);
                        } elseif ($fpPage === 'dashboard') {
                            $fpActive = (($_GET['page'] ?? '') === 'dashboard') && !empty(array_filter(array_intersect_key($_GET, array_flip(['category_id','project_id','method','status','time']))));
                        }
                    ?>
                    <li class="sidebar-webhook sidebar-filter-item<?= $fpActive ? ' active' : '' ?>" data-filter-id="<?= (int)$fp['id'] ?>">
                        <a href="<?= e($fpUrl) ?>"><?= e($fp['name']) ?></a>
                        <button class="sidebar-filter-delete" onclick="deleteFilter(<?= (int)$fp['id'] ?>, this)" title="Rimuovi">&times;</button>
                    </li>
                    <?php endforeach; ?>
                    <li class="sidebar-webhook<?= (($_GET['page'] ?? '') === 'settings') ? ' active' : '' ?>">
                        <a href="<?= BASE_URL ?>/?page=settings"><?= __('nav.settings') ?></a>
                    </li>
                </ul>
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
                ORDER BY COALESCE(c.sort_order, 2147483647), COALESCE(c.name, \'\'), p.name
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
            } elseif ($currentPage === 'analytics' && isset($_GET['view_id'])) {
                $avLookup = $sidebarDb->prepare(
                    'SELECT webhook_id FROM analytics_views WHERE id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1'
                );
                $avLookup->execute([(int)$_GET['view_id'], $sidebarUserId]);
                $av = $avLookup->fetch();
                if ($av) {
                    $currentWebhookId = (int)$av['webhook_id'];
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
                                <span class="project-emoji<?= $proj['active'] ? '' : ' inactive' ?>"><?= projectEmoji(($proj['emoji'] ?? '') ?: 'robot') ?></span>
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
        <!-- PWA install banner -->
        <div class="pwa-install-banner" id="pwaInstallBanner" style="display:none">
            <button class="pwa-install-dismiss" id="pwaInstallDismiss" aria-label="Chiudi">×</button>
            <div class="pwa-install-inner" id="pwaInstallBtn">
                <img class="pwa-install-icon" src="<?= BASE_URL ?>/<?= asset('assets/images/icons/icon-192.png') ?>" alt="<?= APP_NAME ?>">
                <div class="pwa-install-text">
                    <strong>Installa <?= APP_NAME ?></strong>
                    <span>Aggiungi alla schermata Home</span>
                </div>
                <span class="pwa-install-cta">Installa →</span>
            </div>
        </div>
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
    <p>&copy; <?= date('Y') ?> <span class="logo-hook" style="font-family:var(--font-mono);font-weight:700;">hook</span><span class="logo-pool" style="font-family:var(--font-mono);font-weight:700;">pool</span> &mdash; <a href="https://github.com/francescobianco/hookpool" target="_blank" rel="noopener" style="color:inherit;text-decoration:underline dotted;">Open Source</a>, Self-Hostable.</p>
    <div class="footer-center">
        <a href="#" id="footerPwaLink" class="footer-pwa-link" style="display:none">⊕ Install App</a>
    </div>
    <div class="footer-lang">
        <?php foreach (SUPPORTED_LANGS as $l):
            $langLabels = ['en' => 'EN', 'it' => 'IT'];
            $active = (isset($lang) && $lang === $l) ? ' active' : '';
        ?>
        <a href="?lang=<?= $l ?>" class="footer-lang-btn<?= $active ?>"><?= $langLabels[$l] ?? strtoupper($l) ?></a>
        <?php endforeach; ?>
    </div>
</footer>
<?php if ($page === 'home' && CUSTOM_SNIPPET !== ''): ?>
<?= CUSTOM_SNIPPET ?>
<?php endif; ?>

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
// Close modal only when both mousedown AND mouseup land on the backdrop,
// so a drag that starts inside and ends outside does not dismiss the modal.
let _modalMousedownTarget = null;
window.addEventListener('mousedown', e => {
    _modalMousedownTarget = e.target;
});
window.addEventListener('click', e => {
    if (
        e.target.classList.contains('modal') &&
        _modalMousedownTarget === e.target
    ) {
        closeModal(e.target.id);
    }
    _modalMousedownTarget = null;
});
window.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal[style*="flex"]').forEach(m => closeModal(m.id));
    }
});

// User menu dropdown
function toggleUserMenu(e) {
    e.stopPropagation();
    const drop = document.getElementById('userDropdown');
    const trigger = document.getElementById('userMenuTrigger');
    if (!drop) return;
    const open = drop.style.display === 'none';
    drop.style.display = open ? '' : 'none';
    if (trigger) trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
}
document.addEventListener('click', function(e) {
    const menu = document.getElementById('userMenu');
    const drop = document.getElementById('userDropdown');
    if (drop && menu && !menu.contains(e.target)) {
        drop.style.display = 'none';
        const trigger = document.getElementById('userMenuTrigger');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
    }
});

// Sidebar toggle (mobile)
const sidebarToggle  = document.getElementById('sidebarToggle');
const sidebar        = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function closeSidebar() {
    sidebar.classList.remove('open');
    if (sidebarOverlay) sidebarOverlay.classList.remove('active');
}

if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener('click', () => {
        const isOpen = sidebar.classList.toggle('open');
        if (sidebarOverlay) sidebarOverlay.classList.toggle('active', isOpen);
    });
}
if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', closeSidebar);
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

// Delete a sidebar filter/analytics preset
function deleteFilter(id, btn) {
    fetch('<?= BASE_URL ?>/?page=api&action=delete_filter', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ _csrf: csrfToken, id: id }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            const li = btn.closest('li');
            if (li) li.remove();
        }
    });
}

// Auto-dismiss alerts after 5s
document.addEventListener('DOMContentLoaded', () => {
    // Flash alert: constrain width + pin fixed if page has an anchor (will scroll away from alert)
    const flashAlert = document.querySelector('.main-content > .alert');
    if (flashAlert) {
        const pageContainer = document.querySelector('.page-container');
        const hasAnchor = !!window.location.hash;

        if (hasAnchor) {
            // The browser will scroll to the anchor, making the top alert invisible — pin it fixed
            const headerHeight = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--header-height')) || 68;
            const ref = pageContainer || flashAlert.closest('.main-content');
            const refRect = ref ? ref.getBoundingClientRect() : {left: 0, width: window.innerWidth};
            flashAlert.classList.add('alert--fixed');
            flashAlert.style.position = 'fixed';
            flashAlert.style.top = (headerHeight + 12) + 'px';
            flashAlert.style.left = refRect.left + 'px';
            flashAlert.style.width = refRect.width + 'px';
            flashAlert.style.zIndex = '190';
            flashAlert.style.marginBottom = '0';
        } else if (pageContainer) {
            flashAlert.style.maxWidth = pageContainer.offsetWidth + 'px';
        }
    }

    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => {
            // Phase 1: fade out
            alert.style.opacity = '0';
            setTimeout(() => {
                // Phase 2: collapse height
                alert.style.maxHeight = '0';
                alert.style.padding = '0 16px';
                alert.style.marginBottom = '0';
                alert.style.borderWidth = '0';
                setTimeout(() => alert.remove(), 370);
            }, 370);
        }, 5000);
    });
});
</script>
<script>
// Service Worker registration
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js', {scope: '<?= BASE_URL ?>/'})
        .catch(() => {});
}

// PWA install prompt
(function() {
    const banner     = document.getElementById('pwaInstallBanner');
    const installBtn = document.getElementById('pwaInstallBtn');
    const dismissBtn = document.getElementById('pwaInstallDismiss');
    const footerLink = document.getElementById('footerPwaLink');

    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
    if (isStandalone) return; // already installed, hide everything

    const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
    let deferredPrompt = null;

    // Footer link: always visible on iOS, visible on Android once prompt is ready
    function showFooterLink() {
        if (footerLink) footerLink.style.display = '';
    }

    // --- Android / Chrome install flow ---
    window.addEventListener('beforeinstallprompt', e => {
        e.preventDefault();
        deferredPrompt = e;
        showFooterLink();
        // Show banner only if not dismissed
        if (banner && localStorage.getItem('pwaInstallDismissed') !== '1') {
            banner.style.display = '';
        }
    });

    async function triggerInstall() {
        if (!deferredPrompt) return;
        await deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        deferredPrompt = null;
        if (banner) banner.style.display = 'none';
        if (outcome === 'accepted' && footerLink) footerLink.style.display = 'none';
    }

    if (installBtn) installBtn.addEventListener('click', triggerInstall);

    if (footerLink) {
        footerLink.addEventListener('click', e => {
            e.preventDefault();
            if (deferredPrompt) {
                triggerInstall();
            } else if (isIos) {
                alert('Su Safari: tocca il tasto Condividi (□↑) e poi "Aggiungi alla schermata Home".');
            }
        });
    }

    if (dismissBtn) {
        dismissBtn.addEventListener('click', () => {
            if (banner) banner.style.display = 'none';
            localStorage.setItem('pwaInstallDismissed', '1');
            // footer link remains visible after dismiss
        });
    }

    // --- iOS: no beforeinstallprompt, always show footer link and banner (if not dismissed) ---
    if (isIos) {
        showFooterLink();
        if (banner && localStorage.getItem('pwaInstallDismissed') !== '1') {
            if (installBtn) {
                installBtn.querySelector('.pwa-install-cta').textContent = 'Come →';
                installBtn.addEventListener('click', () => {
                    alert('Su Safari: tocca il tasto Condividi (□↑) e poi "Aggiungi alla schermata Home".');
                });
            }
            banner.style.display = '';
        }
    }
})();
</script>
</body>
</html>
