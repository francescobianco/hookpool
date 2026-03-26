<?php
$current_user = requireAuth($db);
$userId       = (int)$current_user['id'];
$action       = $_GET['action'] ?? 'detail';

/**
 * Verify a webhook belongs to the current user.
 */
function loadWebhookForUser(PDO $db, int $webhookId, int $userId): ?array {
    $stmt = $db->prepare('
        SELECT w.*, p.user_id, p.name as project_name, p.id as project_id, p.slug as project_slug
        FROM webhooks w
        JOIN projects p ON p.id = w.project_id
        WHERE w.id = ? AND p.user_id = ? AND w.deleted_at IS NULL AND p.deleted_at IS NULL
    ');
    $stmt->execute([$webhookId, $userId]);
    return $stmt->fetch() ?: null;
}

// --- ADD GUARD ---
if ($action === 'add_guard' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
        setFlash('error', __('msg.csrf_error'));
        header('Location: ' . BASE_URL . '/' . (isset($_POST['redirect']) ? '?page=' . $_POST['redirect'] : '?page=project'));
        exit;
    }

    $type      = $_POST['type'] ?? '';
    $projectId = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int)$_POST['project_id'] : null;
    $webhookId = isset($_POST['webhook_id']) && $_POST['webhook_id'] !== '' ? (int)$_POST['webhook_id'] : null;
    $redirect  = $_POST['redirect'] ?? 'project';

    // Validate ownership
    if ($projectId) {
        $check = $db->prepare('SELECT id FROM projects WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
        $check->execute([$projectId, $userId]);
        if (!$check->fetch()) { setFlash('error', __('msg.unauthorized')); header('Location: ' . BASE_URL . '/?page=project'); exit; }
    }
    if ($webhookId) {
        $wh = loadWebhookForUser($db, $webhookId, $userId);
        if (!$wh) { setFlash('error', __('msg.unauthorized')); header('Location: ' . BASE_URL . '/?page=project'); exit; }
        if (!$projectId) $projectId = (int)$wh['project_id'];
    }

    // Build config JSON
    $config = [];
    switch ($type) {
        case 'required_header':
            $config = ['header' => trim($_POST['rh_header'] ?? '')];
            break;
        case 'static_token':
            $config = ['header' => trim($_POST['st_header'] ?? ''), 'value' => trim($_POST['st_value'] ?? '')];
            break;
        case 'query_secret':
            $config = ['param' => trim($_POST['qs_param'] ?? ''), 'value' => trim($_POST['qs_value'] ?? '')];
            break;
        case 'ip_whitelist':
            $config = ['ips' => trim($_POST['ip_ips'] ?? '')];
            break;
        default:
            setFlash('error', __('msg.invalid'));
            header('Location: ' . BASE_URL . '/?page=' . $redirect);
            exit;
    }

    $db->prepare('INSERT INTO guards (project_id, webhook_id, type, config) VALUES (?, ?, ?, ?)')
       ->execute([$projectId ?: null, $webhookId ?: null, $type, json_encode($config)]);

    setFlash('success', __('guard.created'));
    header('Location: ' . BASE_URL . '/?page=' . $redirect);
    exit;
}

// --- DELETE GUARD ---
if ($action === 'delete_guard' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
        setFlash('error', __('msg.csrf_error'));
        header('Location: ' . BASE_URL . '/?page=' . ($_POST['redirect'] ?? 'project'));
        exit;
    }
    $guardId  = (int)($_GET['guard_id'] ?? 0);
    $redirect = $_POST['redirect'] ?? 'project';

    // Verify ownership through project
    $gStmt = $db->prepare('
        SELECT g.id FROM guards g
        LEFT JOIN projects p ON p.id = g.project_id
        LEFT JOIN webhooks w ON w.id = g.webhook_id
        LEFT JOIN projects p2 ON p2.id = w.project_id
        WHERE g.id = ? AND g.deleted_at IS NULL
          AND (p.user_id = ? OR p2.user_id = ?)
    ');
    $gStmt->execute([$guardId, $userId, $userId]);
    if (!$gStmt->fetch()) {
        setFlash('error', __('msg.unauthorized'));
        header('Location: ' . BASE_URL . '/?page=' . $redirect);
        exit;
    }

    $db->prepare("UPDATE guards SET deleted_at = datetime('now') WHERE id = ?")->execute([$guardId]);
    setFlash('success', __('guard.deleted'));
    header('Location: ' . BASE_URL . '/?page=' . $redirect);
    exit;
}

// --- CREATE WEBHOOK ---
if ($action === 'create') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    $projCheck = $db->prepare('SELECT id, name FROM projects WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
    $projCheck->execute([$projectId, $userId]);
    $proj = $projCheck->fetch();
    if (!$proj) {
        setFlash('error', __('project.not_found'));
        header('Location: ' . BASE_URL . '/?page=project');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
            setFlash('error', __('msg.csrf_error'));
            header('Location: ' . BASE_URL . '/?page=webhook&action=create&project_id=' . $projectId);
            exit;
        }
        $name = trim($_POST['name'] ?? '');
        if ($name === '') $name = 'Webhook';

        $token = generateUniqueWebhookToken($db, $projectId);
        $db->prepare('INSERT INTO webhooks (project_id, name, token) VALUES (?, ?, ?)')->execute([$projectId, $name, $token]);
        $webhookId = (int)$db->lastInsertId();

        setFlash('success', __('webhook.created'));
        header('Location: ' . BASE_URL . '/?page=webhook&action=detail&id=' . $webhookId);
        exit;
    }

    $page_title = __('webhook.create');
    ob_start();
    ?>
    <div class="page-container">
        <div class="page-header">
            <h1><?= __('webhook.create') ?> in <?= e($proj['name']) ?></h1>
            <a href="<?= BASE_URL ?>/?page=project&action=detail&id=<?= $projectId ?>" class="btn btn-outline"><?= __('form.back') ?></a>
        </div>
        <div class="card">
            <form method="post" action="<?= BASE_URL ?>/?page=webhook&action=create&project_id=<?= $projectId ?>" class="form">
                <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                <div class="form-group">
                    <label for="name"><?= __('webhook.name') ?></label>
                    <input type="text" id="name" name="name" maxlength="100" placeholder="Webhook">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= __('webhook.create') ?></button>
                    <a href="<?= BASE_URL ?>/?page=project&action=detail&id=<?= $projectId ?>" class="btn btn-outline"><?= __('form.cancel') ?></a>
                </div>
            </form>
        </div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/../layout.php';
    exit;
}

// --- EDIT WEBHOOK ---
if ($action === 'edit') {
    $webhookId = (int)($_GET['id'] ?? 0);
    $wh = loadWebhookForUser($db, $webhookId, $userId);
    if (!$wh) {
        setFlash('error', __('webhook.not_found'));
        header('Location: ' . BASE_URL . '/?page=project');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
            setFlash('error', __('msg.csrf_error'));
            header('Location: ' . BASE_URL . '/?page=webhook&action=edit&id=' . $webhookId);
            exit;
        }

        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            setFlash('error', __('msg.required') . ' ' . __('webhook.name'));
            header('Location: ' . BASE_URL . '/?page=webhook&action=edit&id=' . $webhookId);
            exit;
        }

        $db->prepare('UPDATE webhooks SET name = ? WHERE id = ?')->execute([$name, $webhookId]);
        setFlash('success', __('msg.saved'));
        header('Location: ' . BASE_URL . '/?page=webhook&action=detail&id=' . $webhookId);
        exit;
    }

    $page_title = __('webhook.edit');
    ob_start();
    ?>
    <div class="page-container">
        <div class="page-header">
            <h1><?= __('webhook.edit') ?></h1>
            <a href="<?= BASE_URL ?>/?page=webhook&action=detail&id=<?= $webhookId ?>" class="btn btn-outline"><?= __('form.back') ?></a>
        </div>
        <div class="card">
            <form method="post" action="<?= BASE_URL ?>/?page=webhook&action=edit&id=<?= $webhookId ?>" class="form">
                <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                <div class="form-group">
                    <label for="name"><?= __('webhook.name') ?></label>
                    <input type="text" id="name" name="name" maxlength="100" value="<?= e($wh['name']) ?>" placeholder="Webhook" required>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= __('form.save') ?></button>
                    <a href="<?= BASE_URL ?>/?page=webhook&action=detail&id=<?= $webhookId ?>" class="btn btn-outline"><?= __('form.cancel') ?></a>
                </div>
            </form>
        </div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/../layout.php';
    exit;
}

// --- DELETE WEBHOOK ---
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $webhookId = (int)($_GET['id'] ?? 0);
    if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
        setFlash('error', __('msg.csrf_error'));
        header('Location: ' . BASE_URL . '/?page=webhook&action=detail&id=' . $webhookId);
        exit;
    }
    $wh = loadWebhookForUser($db, $webhookId, $userId);
    if (!$wh) { setFlash('error', __('msg.unauthorized')); header('Location: ' . BASE_URL . '/?page=project'); exit; }

    $db->prepare("UPDATE webhooks SET deleted_at = datetime('now') WHERE id = ?")->execute([$webhookId]);
    setFlash('success', __('webhook.deleted'));
    header('Location: ' . BASE_URL . '/?page=project&action=detail&id=' . $wh['project_id']);
    exit;
}

// --- TOGGLE WEBHOOK ---
if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $webhookId = (int)($_GET['id'] ?? 0);
    if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
        setFlash('error', __('msg.csrf_error'));
        header('Location: ' . BASE_URL . '/?page=webhook&action=detail&id=' . $webhookId);
        exit;
    }
    $wh = loadWebhookForUser($db, $webhookId, $userId);
    if (!$wh) { setFlash('error', __('msg.unauthorized')); header('Location: ' . BASE_URL . '/?page=project'); exit; }

    $newActive = $wh['active'] ? 0 : 1;
    $db->prepare('UPDATE webhooks SET active = ? WHERE id = ?')->execute([$newActive, $webhookId]);
    setFlash('success', __('msg.saved'));
    header('Location: ' . BASE_URL . '/?page=webhook&action=detail&id=' . $webhookId);
    exit;
}

// --- ADD FORWARD ACTION ---
if ($action === 'add_forward' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
        setFlash('error', __('msg.csrf_error'));
        header('Location: ' . BASE_URL . '/?page=webhook&action=detail&id=' . (int)($_POST['webhook_id'] ?? 0));
        exit;
    }
    $webhookId = (int)($_POST['webhook_id'] ?? 0);
    $wh = loadWebhookForUser($db, $webhookId, $userId);
    if (!$wh) { setFlash('error', __('msg.unauthorized')); header('Location: ' . BASE_URL . '/?page=project'); exit; }

    $name         = trim($_POST['name'] ?? '');
    $url          = trim($_POST['url'] ?? '');
    $method       = strtoupper(trim($_POST['method'] ?? 'POST'));
    $bodyTemplate = trim($_POST['body_template'] ?? '');
    $timeout      = min(60, max(1, (int)($_POST['timeout'] ?? 10)));
    $active       = isset($_POST['active']) ? 1 : 0;

    if (!in_array($method, ['GET','POST','PUT','PATCH','DELETE'])) $method = 'POST';

    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
        setFlash('error', 'Invalid URL for forward action.');
        header('Location: ' . BASE_URL . '/?page=webhook&action=detail&id=' . $webhookId);
        exit;
    }

    // Parse custom headers from textarea (one "Key: Value" per line)
    $rawHeaders = trim($_POST['custom_headers'] ?? '');
    $customHeaders = [];
    if ($rawHeaders) {
        foreach (explode("\n", $rawHeaders) as $line) {
            $line = trim($line);
            if ($line && strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $k = trim($k);
                $v = trim($v);
                if ($k) $customHeaders[$k] = $v;
            }
        }
    }

    // Parse conditions from JSON (sent as a JSON string from the JS form)
    $conditionsRaw = trim($_POST['conditions'] ?? '[]');
    $conditions = json_decode($conditionsRaw, true);
    if (!is_array($conditions)) $conditions = [];

    $db->prepare('
        INSERT INTO forward_actions (webhook_id, name, url, method, custom_headers, body_template, timeout, active, conditions)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        $webhookId,
        $name ?: 'Forward to ' . parse_url($url, PHP_URL_HOST),
        $url,
        $method,
        json_encode($customHeaders),
        $bodyTemplate,
        $timeout,
        $active,
        json_encode($conditions),
    ]);

    setFlash('success', __('forward.created'));
    header('Location: ' . BASE_URL . '/?page=webhook&action=detail&id=' . $webhookId);
    exit;
}

// --- DELETE FORWARD ACTION ---
if ($action === 'delete_forward' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $forwardId = (int)($_GET['forward_id'] ?? 0);
    if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
        setFlash('error', __('msg.csrf_error'));
        header('Location: ' . BASE_URL . '/?page=project');
        exit;
    }
    // Verify ownership
    $faStmt = $db->prepare('
        SELECT fa.id, fa.webhook_id FROM forward_actions fa
        JOIN webhooks w ON w.id = fa.webhook_id
        JOIN projects p ON p.id = w.project_id
        WHERE fa.id = ? AND p.user_id = ? AND fa.deleted_at IS NULL
    ');
    $faStmt->execute([$forwardId, $userId]);
    $fa = $faStmt->fetch();
    if (!$fa) { setFlash('error', __('msg.unauthorized')); header('Location: ' . BASE_URL . '/?page=project'); exit; }

    $db->prepare("UPDATE forward_actions SET deleted_at = datetime('now') WHERE id = ?")->execute([$forwardId]);
    setFlash('success', __('forward.deleted'));
    header('Location: ' . BASE_URL . '/?page=webhook&action=detail&id=' . $fa['webhook_id']);
    exit;
}

// --- ADD ALARM ---
if ($action === 'add_alarm' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
        setFlash('error', __('msg.csrf_error'));
        header('Location: ' . BASE_URL . '/?page=webhook&action=detail&id=' . (int)($_POST['webhook_id'] ?? 0));
        exit;
    }
    $webhookId = (int)($_POST['webhook_id'] ?? 0);
    $wh = loadWebhookForUser($db, $webhookId, $userId);
    if (!$wh) { setFlash('error', __('msg.unauthorized')); header('Location: ' . BASE_URL . '/?page=project'); exit; }

    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? '';
    $validTypes = ['not_called_since', 'not_called_in_interval', 'called_in_interval'];
    if (!in_array($type, $validTypes)) {
        setFlash('error', __('msg.invalid'));
        header('Location: ' . BASE_URL . '/?page=webhook&action=detail&id=' . $webhookId);
        exit;
    }

    $config = [];
    if ($type === 'not_called_since') {
        $config = [
            'hours'   => max(0, (int)($_POST['hours']   ?? 0)),
            'minutes' => max(0, min(59, (int)($_POST['minutes'] ?? 0))),
        ];
        if ($config['hours'] === 0 && $config['minutes'] === 0) {
            setFlash('error', __('alarm.error_zero_threshold'));
            header('Location: ' . BASE_URL . '/?page=webhook&action=detail&id=' . $webhookId);
            exit;
        }
    } else {
        $start = trim($_POST['interval_start'] ?? '');
        $end   = trim($_POST['interval_end']   ?? '');
        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $start >= $end) {
            setFlash('error', __('alarm.error_interval'));
            header('Location: ' . BASE_URL . '/?page=webhook&action=detail&id=' . $webhookId);
            exit;
        }
        $config = ['start' => $start, 'end' => $end];
    }

    $db->prepare('INSERT INTO alarms (webhook_id, name, type, config) VALUES (?, ?, ?, ?)')
       ->execute([$webhookId, $name, $type, json_encode($config)]);
    setFlash('success', __('alarm.created'));
    header('Location: ' . BASE_URL . '/?page=webhook&action=detail&id=' . $webhookId);
    exit;
}

// --- DELETE ALARM ---
if ($action === 'delete_alarm' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $alarmId = (int)($_GET['alarm_id'] ?? 0);
    if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
        setFlash('error', __('msg.csrf_error'));
        header('Location: ' . BASE_URL . '/?page=project');
        exit;
    }
    $alStmt = $db->prepare('
        SELECT a.id, a.webhook_id FROM alarms a
        JOIN webhooks w ON w.id = a.webhook_id
        JOIN projects p ON p.id = w.project_id
        WHERE a.id = ? AND p.user_id = ? AND a.deleted_at IS NULL
    ');
    $alStmt->execute([$alarmId, $userId]);
    $al = $alStmt->fetch();
    if (!$al) { setFlash('error', __('msg.unauthorized')); header('Location: ' . BASE_URL . '/?page=project'); exit; }

    $db->prepare("UPDATE alarms SET deleted_at = datetime('now') WHERE id = ?")->execute([$alarmId]);
    setFlash('success', __('alarm.deleted'));
    header('Location: ' . BASE_URL . '/?page=webhook&action=detail&id=' . $al['webhook_id']);
    exit;
}

// --- WEBHOOK SETTINGS ---
if ($action === 'settings') {
    $webhookId = (int)($_GET['id'] ?? 0);
    $wh = loadWebhookForUser($db, $webhookId, $userId);
    if (!$wh) {
        setFlash('error', __('webhook.not_found'));
        header('Location: ' . BASE_URL . '/?page=project');
        exit;
    }

    // Handle rename
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'rename') {
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
            setFlash('error', __('msg.csrf_error'));
            header('Location: ' . BASE_URL . '/?page=webhook&action=settings&id=' . $webhookId);
            exit;
        }
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            setFlash('error', __('msg.required'));
            header('Location: ' . BASE_URL . '/?page=webhook&action=settings&id=' . $webhookId);
            exit;
        }
        $db->prepare('UPDATE webhooks SET name = ? WHERE id = ?')->execute([$name, $webhookId]);
        setFlash('success', __('msg.saved'));
        header('Location: ' . BASE_URL . '/?page=webhook&action=settings&id=' . $webhookId);
        exit;
    }

    // Handle toggle active
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'toggle') {
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
            setFlash('error', __('msg.csrf_error'));
            header('Location: ' . BASE_URL . '/?page=webhook&action=settings&id=' . $webhookId);
            exit;
        }
        $newActive = $wh['active'] ? 0 : 1;
        $db->prepare('UPDATE webhooks SET active = ? WHERE id = ?')->execute([$newActive, $webhookId]);
        setFlash('success', __('msg.saved'));
        header('Location: ' . BASE_URL . '/?page=webhook&action=settings&id=' . $webhookId);
        exit;
    }

    // Handle pause for 24 hours
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'pause') {
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
            setFlash('error', __('msg.csrf_error'));
            header('Location: ' . BASE_URL . '/?page=webhook&action=settings&id=' . $webhookId);
            exit;
        }
        $db->prepare("UPDATE webhooks SET paused_until = datetime('now', '+24 hours') WHERE id = ?")
           ->execute([$webhookId]);
        setFlash('success', __('webhook.paused'));
        header('Location: ' . BASE_URL . '/?page=webhook&action=settings&id=' . $webhookId);
        exit;
    }

    // Handle unpause
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'unpause') {
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
            setFlash('error', __('msg.csrf_error'));
            header('Location: ' . BASE_URL . '/?page=webhook&action=settings&id=' . $webhookId);
            exit;
        }
        $db->prepare("UPDATE webhooks SET paused_until = NULL WHERE id = ?")->execute([$webhookId]);
        setFlash('success', __('msg.saved'));
        header('Location: ' . BASE_URL . '/?page=webhook&action=settings&id=' . $webhookId);
        exit;
    }

    // Handle special function toggle
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'set_special_function') {
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
            setFlash('error', __('msg.csrf_error'));
            header('Location: ' . BASE_URL . '/?page=webhook&action=settings&id=' . $webhookId);
            exit;
        }
        $allowed = ['', 'pixel', 'file_upload'];
        $fn = $_POST['special_function'] ?? '';
        if (!in_array($fn, $allowed, true)) {
            setFlash('error', __('msg.invalid'));
            header('Location: ' . BASE_URL . '/?page=webhook&action=settings&id=' . $webhookId);
            exit;
        }
        $db->prepare('UPDATE webhooks SET special_function = ? WHERE id = ?')
           ->execute([$fn === '' ? null : $fn, $webhookId]);
        setFlash('success', __('msg.saved'));
        header('Location: ' . BASE_URL . '/?page=webhook&action=settings&id=' . $webhookId);
        exit;
    }

    // Handle delete
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) {
            setFlash('error', __('msg.csrf_error'));
            header('Location: ' . BASE_URL . '/?page=webhook&action=settings&id=' . $webhookId);
            exit;
        }
        $db->prepare("UPDATE webhooks SET deleted_at = datetime('now') WHERE id = ?")->execute([$webhookId]);
        setFlash('success', __('webhook.deleted'));
        header('Location: ' . BASE_URL . '/?page=project&action=detail&id=' . $wh['project_id']);
        exit;
    }

    $isPaused    = !empty($wh['paused_until']) && strtotime($wh['paused_until']) > time();
    $pausedUntil = $isPaused ? $wh['paused_until'] : null;
    $page_title  = __('webhook.settings') . ' — ' . e($wh['name']);
    ob_start();
    ?>
    <div class="page-container">
        <div class="page-header">
            <div class="header-title-group">
                <div class="breadcrumb">
                    <a href="<?= BASE_URL ?>/?page=project&action=detail&id=<?= $wh['project_id'] ?>"><?= e($wh['project_name']) ?></a>
                    <span class="breadcrumb-sep">›</span>
                    <a href="<?= BASE_URL ?>/?page=webhook&action=detail&id=<?= $webhookId ?>"><?= e($wh['name']) ?></a>
                    <span class="breadcrumb-sep">›</span>
                    <span><?= __('webhook.settings') ?></span>
                </div>
                <h1><?= __('webhook.settings') ?></h1>
            </div>
            <a href="<?= BASE_URL ?>/?page=webhook&action=detail&id=<?= $webhookId ?>" class="btn btn-outline"><?= __('form.back') ?></a>
        </div>

        <!-- Rename -->
        <div class="card">
            <h3 style="margin-top:0"><?= __('webhook.name') ?></h3>
            <form method="post" action="<?= BASE_URL ?>/?page=webhook&action=settings&id=<?= $webhookId ?>" class="form">
                <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="_action" value="rename">
                <div class="form-row" style="align-items:flex-end">
                    <div class="form-group flex-1" style="margin-bottom:0">
                        <input type="text" name="name" value="<?= e($wh['name']) ?>" maxlength="100" required>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= __('form.save') ?></button>
                </div>
            </form>
        </div>

        <!-- Status -->
        <div class="card">
            <h3 style="margin-top:0"><?= __('webhook.status') ?></h3>
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                <span class="badge <?= $wh['active'] ? 'badge-success' : 'badge-muted' ?>" style="font-size:14px">
                    <?= $wh['active'] ? __('webhook.active') : __('webhook.inactive') ?>
                </span>
                <?php if ($isPaused): ?>
                    <span class="badge badge-warning" style="font-size:14px">
                        <?= __('webhook.paused_until') ?>: <?= e(date('d/m H:i', strtotime($pausedUntil))) ?>
                    </span>
                <?php endif; ?>
                <form method="post" action="<?= BASE_URL ?>/?page=webhook&action=settings&id=<?= $webhookId ?>" class="inline">
                    <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="_action" value="toggle">
                    <button type="submit" class="btn btn-sm btn-outline">
                        <?= $wh['active'] ? __('webhook.disable') : __('webhook.enable') ?>
                    </button>
                </form>
                <?php if (!$isPaused): ?>
                <form method="post" action="<?= BASE_URL ?>/?page=webhook&action=settings&id=<?= $webhookId ?>" class="inline">
                    <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="_action" value="pause">
                    <button type="submit" class="btn btn-sm btn-outline"><?= __('webhook.pause_24h') ?></button>
                </form>
                <?php else: ?>
                <form method="post" action="<?= BASE_URL ?>/?page=webhook&action=settings&id=<?= $webhookId ?>" class="inline">
                    <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="_action" value="unpause">
                    <button type="submit" class="btn btn-sm btn-outline"><?= __('webhook.unpause') ?></button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Special Functions -->
        <?php
        $currentFn  = $wh['special_function'] ?? '';
        $pixelUrl   = BASE_URL . '/' . rawurlencode($wh['project_slug']) . '/' . rawurlencode($wh['token']) . '.png';
        $pixelImg   = '<img src="' . $pixelUrl . '" width="1" height="1" alt="" style="display:none">';
        $pixelMd    = '![track](' . $pixelUrl . ')';
        ?>
        <div class="card">
            <h3 style="margin-top:0"><?= __('webhook.special_functions') ?></h3>
            <p class="text-muted" style="margin-bottom:1.25rem"><?= __('webhook.special_functions_desc') ?></p>

            <form method="post" action="<?= BASE_URL ?>/?page=webhook&action=settings&id=<?= $webhookId ?>">
                <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="_action" value="set_special_function">
                <div class="special-fn-grid">

                    <label class="special-fn-card<?= $currentFn === '' ? ' active' : '' ?>">
                        <input type="radio" name="special_function" value="" <?= $currentFn === '' ? 'checked' : '' ?> onchange="this.form.submit()">
                        <div class="special-fn-icon">🔌</div>
                        <div class="special-fn-title"><?= __('webhook.sfn_none') ?></div>
                        <div class="special-fn-desc"><?= __('webhook.sfn_none_desc') ?></div>
                    </label>

                    <label class="special-fn-card<?= $currentFn === 'pixel' ? ' active' : '' ?>">
                        <input type="radio" name="special_function" value="pixel" <?= $currentFn === 'pixel' ? 'checked' : '' ?> onchange="this.form.submit()">
                        <div class="special-fn-icon">🎯</div>
                        <div class="special-fn-title"><?= __('webhook.sfn_pixel') ?></div>
                        <div class="special-fn-desc"><?= __('webhook.sfn_pixel_desc') ?></div>
                    </label>

                    <label class="special-fn-card<?= $currentFn === 'file_upload' ? ' active' : '' ?>">
                        <input type="radio" name="special_function" value="file_upload" <?= $currentFn === 'file_upload' ? 'checked' : '' ?> onchange="this.form.submit()">
                        <div class="special-fn-icon">📎</div>
                        <div class="special-fn-title"><?= __('webhook.sfn_file_upload') ?></div>
                        <div class="special-fn-desc"><?= __('webhook.sfn_file_upload_desc') ?></div>
                    </label>

                </div>
            </form>

            <?php if ($currentFn === 'pixel'): ?>
            <div class="special-fn-detail" style="margin-top:1.5rem">
                <p class="text-muted" style="font-size:0.88rem;margin-bottom:0.75rem"><?= __('webhook.sfn_pixel_url_label') ?></p>
                <div class="pixel-url-row">
                    <code class="pixel-url-code"><?= e($pixelUrl) ?></code>
                    <div class="pixel-copy-dropdown" id="pixelCopyDropdown">
                        <button type="button" class="btn btn-sm btn-outline pixel-copy-trigger" onclick="togglePixelDropdown(event)">
                            <?= __('webhook.sfn_copy_url') ?> ▾
                        </button>
                        <div class="pixel-copy-menu" id="pixelCopyMenu" style="display:none">
                            <button class="pixel-copy-option" onclick="doCopy(<?= htmlspecialchars(json_encode($pixelUrl)) ?>, this)"><?= __('webhook.sfn_copy_url_plain') ?></button>
                            <button class="pixel-copy-option" onclick="doCopy(<?= htmlspecialchars(json_encode($pixelImg)) ?>, this)"><?= __('webhook.sfn_copy_img_tag') ?></button>
                            <button class="pixel-copy-option" onclick="doCopy(<?= htmlspecialchars(json_encode($pixelMd)) ?>, this)"><?= __('webhook.sfn_copy_markdown') ?></button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($currentFn === 'file_upload'): ?>
            <div class="special-fn-detail special-fn-note" style="margin-top:1.5rem">
                <div class="special-fn-note-icon">⚠️</div>
                <div>
                    <strong><?= __('webhook.sfn_file_note_title') ?></strong>
                    <p style="margin:0.35rem 0 0"><?= __('webhook.sfn_file_note_body') ?></p>
                    <p style="margin:0.5rem 0 0;font-family:var(--font-mono);font-size:0.8rem;color:var(--text-muted)">
                        Content-Type: <code>multipart/form-data</code>
                    </p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Danger Zone -->
        <div class="card" style="border-color:var(--color-danger,#e74c3c)">
            <h3 style="margin-top:0;color:var(--color-danger,#e74c3c)"><?= __('settings.danger_zone') ?></h3>
            <p class="text-muted"><?= __('webhook.confirm_delete') ?></p>
            <button onclick="openModal('deleteWebhookModal')" class="btn btn-danger"><?= __('webhook.delete') ?></button>
        </div>
    </div>

    <script>
    function togglePixelDropdown(e) {
        e.stopPropagation();
        const menu = document.getElementById('pixelCopyMenu');
        if (menu) menu.style.display = menu.style.display === 'none' ? '' : 'none';
    }
    document.addEventListener('click', function() {
        const menu = document.getElementById('pixelCopyMenu');
        if (menu) menu.style.display = 'none';
    });
    function doCopy(text, btn, label) {
        copyToClipboard(text, btn);
        const menu = document.getElementById('pixelCopyMenu');
        if (menu) menu.style.display = 'none';
    }
    </script>

    <!-- Delete Modal -->
    <div id="deleteWebhookModal" class="modal" style="display:none" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3><?= __('webhook.delete') ?></h3>
                <button onclick="closeModal('deleteWebhookModal')" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p><?= __('webhook.confirm_delete') ?></p>
            </div>
            <div class="modal-footer">
                <form method="post" action="<?= BASE_URL ?>/?page=webhook&action=settings&id=<?= $webhookId ?>">
                    <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="_action" value="delete">
                    <button type="submit" class="btn btn-danger"><?= __('form.yes_delete') ?></button>
                    <button type="button" onclick="closeModal('deleteWebhookModal')" class="btn btn-outline"><?= __('form.cancel') ?></button>
                </form>
            </div>
        </div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/../layout.php';
    exit;
}

// --- WEBHOOK DETAIL ---
$webhookId = (int)($_GET['id'] ?? 0);
$wh = loadWebhookForUser($db, $webhookId, $userId);
if (!$wh) {
    setFlash('error', __('webhook.not_found'));
    header('Location: ' . BASE_URL . '/?page=project');
    exit;
}

// Load forward actions
$faStmt = $db->prepare('SELECT * FROM forward_actions WHERE webhook_id = ? AND deleted_at IS NULL ORDER BY created_at');
$faStmt->execute([$webhookId]);
$forwardActions = $faStmt->fetchAll();

// Load webhook-level guards
$guardStmt = $db->prepare('SELECT * FROM guards WHERE webhook_id = ? AND deleted_at IS NULL ORDER BY created_at');
$guardStmt->execute([$webhookId]);
$guards = $guardStmt->fetchAll();

// Load alarms
$alarmStmt = $db->prepare('SELECT * FROM alarms WHERE webhook_id = ? AND deleted_at IS NULL ORDER BY created_at');
$alarmStmt->execute([$webhookId]);
$alarms = $alarmStmt->fetchAll();

// Load recent events (last 20)
$evtStmt = $db->prepare('SELECT * FROM events WHERE webhook_id = ? ORDER BY received_at DESC LIMIT 20');
$evtStmt->execute([$webhookId]);
$recentEvents = $evtStmt->fetchAll();

$isPaused      = !empty($wh['paused_until']) && strtotime($wh['paused_until']) > time();
$webhookUrl    = webhookUrl($wh['slug'] ?? $wh['project_slug'] ?? '', $wh['token']);
$isPixelMode   = ($wh['special_function'] ?? '') === 'pixel';
$pixelUrlDetail = $webhookUrl . '.png';
$pixelImgTag    = '<img src="' . $pixelUrlDetail . '" width="1" height="1" alt="" style="display:none">';
$pixelMdDetail  = '![track](' . $pixelUrlDetail . ')';
$page_title    = e($wh['name']);
$lastEventId = !empty($recentEvents) ? (int)$recentEvents[0]['id'] : 0;
$eventsAjaxBase = '?page=api&action=events&webhook_id=' . $webhookId;

ob_start();
?>
<div class="page-container">
    <div class="page-header">
        <div class="header-title-group">
            <div class="breadcrumb">
                <a href="<?= BASE_URL ?>/?page=project&action=detail&id=<?= $wh['project_id'] ?>"><?= e($wh['project_name']) ?></a>
                <span class="breadcrumb-sep">›</span>
                <span><?= e($wh['name']) ?></span>
            </div>
            <div class="title-inline" id="webhookTitleBlock">
                <h1 id="webhookTitleText"><?= e($wh['name']) ?></h1>
                <button type="button" class="title-edit-link" id="webhookRenameBtn" aria-label="<?= __('webhook.edit') ?>" title="<?= __('webhook.edit') ?>" onclick="startRename()">✎</button>
                <form id="webhookRenameForm" style="display:none;align-items:center;gap:6px" onsubmit="submitRename(event)">
                    <input type="text" id="webhookRenameInput" value="<?= e($wh['name']) ?>" maxlength="100" class="inline-rename-input">
                    <button type="submit" class="btn btn-xs btn-primary">✓</button>
                    <button type="button" class="btn btn-xs btn-outline" onclick="cancelRename()">✕</button>
                </form>
            </div>
        </div>
        <div class="header-actions">
            <?php if ($isPaused): ?>
            <span class="badge badge-warning"><?= __('webhook.paused') ?></span>
            <?php else: ?>
            <span class="badge <?= $wh['active'] ? 'badge-success' : 'badge-muted' ?>">
                <?= $wh['active'] ? __('webhook.active') : __('webhook.inactive') ?>
            </span>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/?page=webhook&action=settings&id=<?= $webhookId ?>" class="btn btn-sm btn-outline">&#9881; <?= __('webhook.settings') ?></a>
        </div>
    </div>

    <!-- Endpoint URL -->
    <div class="card card-endpoint">
        <?php if ($isPixelMode): ?>
        <div class="card-label" style="display:flex;align-items:center;gap:6px">
            🎯 <?= __('webhook.sfn_pixel') ?>
            <span class="badge badge-muted" style="font-size:0.72rem">pixel</span>
        </div>
        <div class="endpoint-row">
            <code class="endpoint-url" id="endpointUrl"><?= e($pixelUrlDetail) ?></code>
            <div class="pixel-copy-dropdown" id="detailPixelDropdown">
                <button type="button" class="btn btn-outline pixel-copy-trigger" onclick="toggleDetailPixelDropdown(event)">
                    <?= __('webhook.sfn_copy_url') ?>
                </button>
                <div class="pixel-copy-menu" id="detailPixelMenu" style="display:none">
                    <button class="pixel-copy-option" onclick="detailCopy(<?= htmlspecialchars(json_encode($pixelUrlDetail)) ?>, this)"><?= __('webhook.sfn_copy_url_plain') ?></button>
                    <button class="pixel-copy-option" onclick="detailCopy(<?= htmlspecialchars(json_encode($pixelImgTag)) ?>, this)"><?= __('webhook.sfn_copy_img_tag') ?></button>
                    <button class="pixel-copy-option" onclick="detailCopy(<?= htmlspecialchars(json_encode($pixelMdDetail)) ?>, this)"><?= __('webhook.sfn_copy_markdown') ?></button>
                </div>
            </div>
        </div>
        <div class="endpoint-hint">
            <?= __('webhook.sfn_pixel_desc') ?>
            <br>
            <code class="example-curl">&lt;img src="<?= e($pixelUrlDetail) ?>" width="1" height="1" alt=""&gt;</code>
        </div>
        <?php else: ?>
        <div class="card-label">Webhook Endpoint</div>
        <div class="endpoint-row">
            <code class="endpoint-url" id="endpointUrl"><?= e($webhookUrl) ?></code>
            <button class="btn btn-outline copy-btn" onclick="copyToClipboard('<?= e($webhookUrl) ?>', this)">
                <?= __('webhook.copy') ?>
            </button>
        </div>
        <div class="endpoint-hint">
            Send any HTTP request to this URL. All methods are accepted.
            <br>
            <code class="example-curl">curl -X POST <?= e($webhookUrl) ?> -H "Content-Type: application/json" -d '{"key":"value"}'</code>
        </div>
        <?php endif; ?>
    </div>

    <!-- Webhook-level Guards -->
    <section class="section">
        <div class="section-header">
            <h2>Webhook Guards</h2>
            <button onclick="openModal('addGuardModal')" class="btn btn-sm btn-outline">+ <?= __('guard.create') ?></button>
        </div>
        <?php if (empty($guards)): ?>
        <p class="text-muted">No guards on this webhook.</p>
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
                    <input type="hidden" name="redirect" value="webhook&action=detail&id=<?= $webhookId ?>">
                    <button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Remove this guard?')">Remove</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- Forward Actions (Azioni) -->
    <section class="section">
        <div class="section-header">
            <h2>Azioni</h2>
            <button onclick="openModal('addForwardModal')" class="btn btn-sm btn-outline">+ Aggiungi Azione</button>
        </div>
        <?php if (empty($forwardActions)): ?>
        <p class="text-muted">Nessuna azione configurata. Aggiungine una per inoltrare gli eventi ad altri endpoint.</p>
        <?php else: ?>
        <div class="forward-actions-list">
            <?php foreach ($forwardActions as $fa): ?>
            <?php $faConds = json_decode($fa['conditions'] ?? '[]', true) ?: []; ?>
            <div class="card card-forward">
                <div class="card-header">
                    <div>
                        <h4 class="card-title"><?= e($fa['name'] ?: $fa['url']) ?></h4>
                        <div class="forward-url">
                            <span class="badge badge-method-sm <?= strtolower($fa['method']) ?>"><?= e($fa['method']) ?></span>
                            <code><?= e($fa['url']) ?></code>
                        </div>
                    </div>
                    <div class="card-actions">
                        <span class="badge <?= $fa['active'] ? 'badge-success' : 'badge-muted' ?>"><?= $fa['active'] ? 'Active' : 'Inactive' ?></span>
                        <button type="button" class="btn btn-xs btn-outline"
                            onclick="openTestForwardModal(<?= (int)$fa['id'] ?>, <?= htmlspecialchars(json_encode($fa['name'] ?: $fa['url'])) ?>, <?= htmlspecialchars(json_encode($fa['body_template'] ?? '')) ?>)">
                            Testa Azione
                        </button>
                        <form method="post" action="<?= BASE_URL ?>/?page=webhook&action=delete_forward&forward_id=<?= $fa['id'] ?>" class="inline">
                            <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                            <button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Remove this forward action?')">Remove</button>
                        </form>
                    </div>
                </div>
                <?php if (!empty($faConds)): ?>
                <div class="forward-conditions">
                    <strong>Conditions:</strong>
                    <?php foreach ($faConds as $cond): ?>
                    <span class="condition-tag">
                        <?= e($cond['type'] ?? '') ?><?= isset($cond['field']) ? '.' . e($cond['field']) : '' ?>
                        <?= e($cond['operator'] ?? '') ?>
                        <?= isset($cond['value']) ? '"' . e($cond['value']) . '"' : '' ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ($fa['timeout'] != 10): ?>
                <div class="forward-meta text-muted text-sm">Timeout: <?= (int)$fa['timeout'] ?>s</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- Alarms -->
    <section class="section">
        <div class="section-header">
            <h2><?= __('alarm.title') ?></h2>
            <button onclick="openModal('addAlarmModal')" class="btn btn-sm btn-outline">+ <?= __('alarm.create') ?></button>
        </div>
        <?php if (empty($alarms)): ?>
        <p class="text-muted"><?= __('alarm.none') ?></p>
        <?php else: ?>
        <div class="guards-list">
            <?php foreach ($alarms as $al): ?>
            <?php $alCfg = json_decode($al['config'], true) ?: []; ?>
            <div class="guard-item">
                <div class="guard-info">
                    <span class="badge badge-warning"><?= e($al['name'] ?: __('alarm.type.' . $al['type'])) ?></span>
                    <span class="guard-config text-muted">
                        <?php
                        echo e(match($al['type']) {
                            'not_called_since'      => __('alarm.type.not_called_since') . ': ' . ($alCfg['hours'] ?? 0) . 'h ' . ($alCfg['minutes'] ?? 0) . 'm',
                            'not_called_in_interval'=> __('alarm.type.not_called_in_interval') . ': ' . ($alCfg['start'] ?? '') . '–' . ($alCfg['end'] ?? ''),
                            'called_in_interval'    => __('alarm.type.called_in_interval') . ': ' . ($alCfg['start'] ?? '') . '–' . ($alCfg['end'] ?? ''),
                            default => $al['type'],
                        });
                        ?>
                    </span>
                </div>
                <form method="post" action="<?= BASE_URL ?>/?page=webhook&action=delete_alarm&alarm_id=<?= $al['id'] ?>" class="inline">
                    <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                    <button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('<?= __('alarm.confirm_delete') ?>')">Remove</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </section>

    <!-- Recent Events -->
    <section class="section">
        <div class="section-header">
            <h2>Recent Events</h2>
            <a href="<?= BASE_URL ?>/?page=dashboard" class="btn btn-sm btn-outline">View All in Dashboard</a>
        </div>
        <?php if (empty($recentEvents)): ?>
        <div class="empty-state-sm" id="recentEventsEmpty">
            <p><?= __('event.no_events') ?></p>
            <p class="text-muted">Try sending: <code>curl -X POST <?= e($webhookUrl) ?></code></p>
        </div>
        <?php else: ?>
        <table class="events-table" id="recentEventsTable">
            <thead>
                <tr>
                    <th class="col-method"><?= __('event.method') ?></th>
                    <th class="col-time"><?= __('event.received_at') ?></th>
                    <th class="col-path"><?= __('event.path') ?></th>
                    <th class="col-ip"><?= __('event.ip') ?></th>
                    <th class="col-status">Status</th>
                    <th class="col-info">Info</th>
                </tr>
            </thead>
            <tbody id="recentEventsBody">
                <?php foreach ($recentEvents as $ev): ?>
                <tr class="event-row" onclick="window.location='<?= BASE_URL ?>/?page=event&id=<?= $ev['id'] ?>'">
                    <td class="col-method"><span class="badge-method <?= strtolower($ev['method']) ?>"><?= e($ev['method']) ?></span></td>
                    <td class="col-time"><span title="<?= e($ev['received_at']) ?>"><?= e(date('H:i:s', strtotime($ev['received_at']))) ?></span></td>
                    <td class="col-path mono"><?= e($ev['path']) ?></td>
                    <td class="col-ip mono"><?= e($ev['ip']) ?></td>
                    <td class="col-status"><?= strtoupper($ev['method']) === 'ALARM' ? '<span class="badge badge-warning">Alarm</span>' : ($ev['validated'] ? '<span class="badge badge-success">Valid</span>' : '<span class="badge badge-error">Guard</span>') ?></td>
                    <td class="col-info text-muted"><?= strtoupper($ev['method']) === 'ALARM' ? e($ev['body']) : '' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
</div>

<script>
(function() {
    let lastId = <?= $lastEventId ?>;
    const ajaxBase = '<?= $eventsAjaxBase ?>';
    const refreshInterval = 3000;
    let isRefreshing = false;

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function renderTime(value) {
        if (!value) return '';
        const ts = new Date(value.replace(' ', 'T'));
        const ago = Math.floor((Date.now() - ts.getTime()) / 1000);
        if (ago < 60) return ago + 's ago';
        if (ago < 3600) return Math.round(ago / 60) + 'm ago';
        if (ago < 86400) return Math.round(ago / 3600) + 'h ago';
        return ts.toLocaleTimeString();
    }

    function ensureTable() {
        let table = document.getElementById('recentEventsTable');
        if (table) return table;

        const section = document.querySelector('#recentEventsEmpty')?.parentElement;
        if (!section) return null;

        const empty = document.getElementById('recentEventsEmpty');
        if (empty) empty.remove();

        section.insertAdjacentHTML('beforeend', `
            <table class="events-table" id="recentEventsTable">
                <thead>
                    <tr>
                        <th class="col-method"><?= __('event.method') ?></th>
                        <th class="col-time"><?= __('event.received_at') ?></th>
                        <th class="col-path"><?= __('event.path') ?></th>
                        <th class="col-ip"><?= __('event.ip') ?></th>
                        <th class="col-status">Status</th>
                        <th class="col-info">Info</th>
                    </tr>
                </thead>
                <tbody id="recentEventsBody"></tbody>
            </table>
        `);

        return document.getElementById('recentEventsTable');
    }

    function poll() {
        if (isRefreshing) return;
        isRefreshing = true;

        fetch(ajaxBase + '&after_id=' + lastId + '&limit=20', {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(r => r.json())
            .then(data => {
                if (data.error) return;
                const newEvents = Array.isArray(data) ? data : (data.events || []);
                if (!newEvents.length) return;

                const table = ensureTable();
                const tbody = document.getElementById('recentEventsBody');
                if (!table || !tbody) return;

                table.classList.remove('hidden');

                newEvents.forEach(ev => {
                    const tr = document.createElement('tr');
                    tr.className = 'event-row event-new';
                    tr.setAttribute('data-id', ev.id);
                    tr.onclick = () => window.location = '<?= BASE_URL ?>/?page=event&id=' + ev.id;

                    const method = (ev.method || 'POST').toUpperCase();
                    const methodLower = method.toLowerCase();
                    const statusBadge = method === 'ALARM'
                        ? '<span class="badge badge-warning">Alarm</span>'
                        : (ev.validated == 1 ? '<span class="badge badge-success">Valid</span>' : '<span class="badge badge-error">Guard</span>');

                    const infoCell = method === 'ALARM' ? escapeHtml(ev.body || '') : '';
                    tr.innerHTML = `
                        <td class="col-method"><span class="badge-method ${methodLower}">${escapeHtml(method)}</span></td>
                        <td class="col-time"><span title="${escapeHtml(ev.received_at || '')}">${escapeHtml(renderTime(ev.received_at || ''))}</span></td>
                        <td class="col-path mono">${escapeHtml(ev.path || '/')}</td>
                        <td class="col-ip mono">${escapeHtml(ev.ip || '')}</td>
                        <td class="col-status">${statusBadge}</td>
                        <td class="col-info text-muted">${infoCell}</td>
                    `;

                    tbody.insertBefore(tr, tbody.firstChild);
                    setTimeout(() => tr.classList.remove('event-new'), 500);
                });

                lastId = Math.max(...newEvents.map(e => parseInt(e.id, 10)), lastId);

                const rows = tbody.querySelectorAll('tr');
                if (rows.length > 20) {
                    for (let i = 20; i < rows.length; i++) rows[i].remove();
                }
            })
            .catch(() => {})
            .finally(() => {
                isRefreshing = false;
            });
    }

    setInterval(poll, refreshInterval);
})();
</script>

<script>
function toggleDetailPixelDropdown(e) {
    e.stopPropagation();
    const menu = document.getElementById('detailPixelMenu');
    if (menu) menu.style.display = menu.style.display === 'none' ? '' : 'none';
}
document.addEventListener('click', function() {
    const menu = document.getElementById('detailPixelMenu');
    if (menu) menu.style.display = 'none';
});
function detailCopy(text, btn) {
    copyToClipboard(text, btn);
    const menu = document.getElementById('detailPixelMenu');
    if (menu) menu.style.display = 'none';
}
</script>

<script>
function startRename() {
    document.getElementById('webhookTitleText').style.display = 'none';
    document.getElementById('webhookRenameBtn').style.display = 'none';
    const form = document.getElementById('webhookRenameForm');
    form.style.display = 'flex';
    const input = document.getElementById('webhookRenameInput');
    input.focus();
    input.select();
}

function cancelRename() {
    document.getElementById('webhookRenameForm').style.display = 'none';
    document.getElementById('webhookTitleText').style.display = '';
    document.getElementById('webhookRenameBtn').style.display = '';
}

function submitRename(e) {
    e.preventDefault();
    const name = document.getElementById('webhookRenameInput').value.trim();
    if (!name) return;

    fetch('<?= BASE_URL ?>/?page=webhook&action=settings&id=<?= $webhookId ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            _csrf: '<?= e(generateCsrfToken()) ?>',
            _action: 'rename',
            name: name,
        }),
    }).then(r => {
        if (r.redirected || r.ok) {
            document.getElementById('webhookTitleText').textContent = name;
            // update breadcrumb too
            const crumb = document.querySelector('.breadcrumb span:last-child');
            if (crumb) crumb.textContent = name;
            cancelRename();
        }
    }).catch(() => cancelRename());
}
</script>

<!-- Add Alarm Modal -->
<div id="addAlarmModal" class="modal" style="display:none" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3><?= __('alarm.create') ?></h3>
            <button onclick="closeModal('addAlarmModal')" class="modal-close">&times;</button>
        </div>
        <form method="post" action="<?= BASE_URL ?>/?page=webhook&action=add_alarm">
            <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="webhook_id" value="<?= $webhookId ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label><?= __('alarm.name') ?></label>
                    <input type="text" name="name" placeholder="<?= __('alarm.name_placeholder') ?>" maxlength="100">
                </div>
                <div class="form-group">
                    <label><?= __('alarm.type_label') ?> <span class="required">*</span></label>
                    <select name="type" id="alarmTypeSelect" onchange="updateAlarmFields(this.value)" required>
                        <option value="">— <?= __('alarm.type_select') ?> —</option>
                        <option value="not_called_since"><?= __('alarm.type.not_called_since') ?></option>
                        <option value="not_called_in_interval"><?= __('alarm.type.not_called_in_interval') ?></option>
                        <option value="called_in_interval"><?= __('alarm.type.called_in_interval') ?></option>
                    </select>
                </div>
                <!-- Fields for not_called_since -->
                <div id="alarm_fields_since" style="display:none">
                    <p class="form-hint"><?= __('alarm.hint.not_called_since') ?></p>
                    <div class="form-row">
                        <div class="form-group form-group-sm">
                            <label><?= __('alarm.hours') ?></label>
                            <input type="number" name="hours" min="0" max="999" value="1" placeholder="0">
                        </div>
                        <div class="form-group form-group-sm">
                            <label><?= __('alarm.minutes') ?></label>
                            <input type="number" name="minutes" min="0" max="59" value="0" placeholder="0">
                        </div>
                    </div>
                </div>
                <!-- Fields for interval-based types -->
                <div id="alarm_fields_interval" style="display:none">
                    <p class="form-hint" id="alarm_interval_hint"></p>
                    <div class="form-row">
                        <div class="form-group form-group-sm">
                            <label><?= __('alarm.interval_start') ?></label>
                            <input type="time" name="interval_start" value="09:00">
                        </div>
                        <div class="form-group form-group-sm">
                            <label><?= __('alarm.interval_end') ?></label>
                            <input type="time" name="interval_end" value="17:00">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary"><?= __('alarm.create') ?></button>
                <button type="button" onclick="closeModal('addAlarmModal')" class="btn btn-outline"><?= __('form.cancel') ?></button>
            </div>
        </form>
    </div>
</div>
<script>
function updateAlarmFields(type) {
    document.getElementById('alarm_fields_since').style.display    = type === 'not_called_since' ? '' : 'none';
    document.getElementById('alarm_fields_interval').style.display = (type === 'not_called_in_interval' || type === 'called_in_interval') ? '' : 'none';
    const hints = {
        'not_called_in_interval': '<?= __('alarm.hint.not_called_in_interval') ?>',
        'called_in_interval':     '<?= __('alarm.hint.called_in_interval') ?>',
    };
    document.getElementById('alarm_interval_hint').textContent = hints[type] || '';
}
</script>

<!-- Add Forward Action Modal -->
<div id="addForwardModal" class="modal" style="display:none" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-header">
            <h3><?= __('forward.create') ?></h3>
            <button onclick="closeModal('addForwardModal')" class="modal-close">&times;</button>
        </div>
        <form method="post" action="<?= BASE_URL ?>/?page=webhook&action=add_forward">
            <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="webhook_id" value="<?= $webhookId ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label><?= __('forward.name') ?></label>
                    <input type="text" name="name" placeholder="My Forward Action" maxlength="100">
                </div>
                <div class="form-row">
                    <div class="form-group flex-1">
                        <label><?= __('forward.url') ?> <span class="required">*</span></label>
                        <input type="url" name="url" placeholder="https://example.com/webhook" required>
                    </div>
                    <div class="form-group form-group-sm">
                        <label><?= __('forward.method') ?></label>
                        <select name="method">
                            <option value="POST">POST</option>
                            <option value="GET">GET</option>
                            <option value="PUT">PUT</option>
                            <option value="PATCH">PATCH</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label><?= __('forward.headers') ?></label>
                    <textarea name="custom_headers" rows="3" placeholder="Authorization: Bearer my-token&#10;X-Custom-Header: value"><?= '' ?></textarea>
                    <p class="form-hint"><?= __('forward.headers_hint') ?></p>
                </div>
                <div class="form-group">
                    <label><?= __('forward.body_template') ?></label>
                    <textarea name="body_template" rows="3" placeholder="Leave empty to forward original body. Use {{body}} as placeholder."></textarea>
                    <p class="form-hint"><?= __('forward.body_hint') ?></p>
                </div>
                <div class="form-row">
                    <div class="form-group form-group-sm">
                        <label><?= __('forward.timeout') ?></label>
                        <input type="number" name="timeout" value="10" min="1" max="60">
                    </div>
                    <div class="form-group form-group-sm" style="align-self:flex-end;padding-bottom:4px">
                        <label class="checkbox-label">
                            <input type="checkbox" name="active" value="1" checked>
                            <?= __('forward.active') ?>
                        </label>
                    </div>
                </div>

                <!-- Conditions Builder -->
                <div class="form-group">
                    <label><?= __('forward.conditions') ?></label>
                    <p class="form-hint"><?= __('forward.conditions_hint') ?></p>
                    <div id="conditionsBuilder"></div>
                    <button type="button" onclick="addCondition()" class="btn btn-sm btn-outline">+ <?= __('condition.add') ?></button>
                    <input type="hidden" name="conditions" id="conditionsJson" value="[]">
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary" onclick="serializeConditions()"><?= __('forward.create') ?></button>
                <button type="button" onclick="closeModal('addForwardModal')" class="btn btn-outline"><?= __('form.cancel') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Test Forward Action Modal -->
<div id="testForwardModal" class="modal" style="display:none" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-header">
            <h3>Testa Azione: <span id="testForwardName"></span></h3>
            <button onclick="closeModal('testForwardModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="testForwardFields"></div>
            <div id="testForwardResult" style="display:none">
                <hr style="margin:16px 0">
                <div class="form-group">
                    <label>Risposta</label>
                    <div id="testForwardStatus" style="margin-bottom:6px"></div>
                    <pre id="testForwardBody" style="background:var(--bg-card);border:1px solid var(--border);border-radius:6px;padding:12px;max-height:200px;overflow:auto;white-space:pre-wrap;word-break:break-all;font-size:13px;margin:0"></pre>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-primary" id="testForwardSubmitBtn" onclick="submitTestForward()">Esegui</button>
            <button type="button" onclick="closeModal('testForwardModal')" class="btn btn-outline">Chiudi</button>
        </div>
    </div>
</div>
<script>
let _testForwardActionId = null;
let _testForwardTemplate = '';

function openTestForwardModal(actionId, name, bodyTemplate) {
    _testForwardActionId = actionId;
    _testForwardTemplate = bodyTemplate || '';
    document.getElementById('testForwardName').textContent = name;
    document.getElementById('testForwardResult').style.display = 'none';
    document.getElementById('testForwardBody').textContent = '';
    document.getElementById('testForwardStatus').innerHTML = '';

    // Extract all {{placeholder}} tokens from the template
    const placeholders = [];
    const re = /\{\{([^}]+)\}\}/g;
    let m;
    while ((m = re.exec(_testForwardTemplate)) !== null) {
        if (!placeholders.includes(m[1])) placeholders.push(m[1]);
    }

    const fields = document.getElementById('testForwardFields');
    fields.innerHTML = '';

    if (placeholders.length === 0) {
        fields.innerHTML = '<p class="text-muted">Nessun placeholder nel template. L\'azione verrà eseguita con il body vuoto.</p>';
    } else {
        placeholders.forEach(ph => {
            const div = document.createElement('div');
            div.className = 'form-group';
            div.innerHTML = '<label><code>{{' + escapeHtml(ph) + '}}</code></label>'
                + '<input type="text" class="test-forward-input" data-placeholder="' + escapeHtml(ph) + '" placeholder="Valore per ' + escapeHtml(ph) + '">';
            fields.appendChild(div);
        });
    }

    openModal('testForwardModal');
}

function submitTestForward() {
    const btn = document.getElementById('testForwardSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'In corso…';

    const inputs = document.querySelectorAll('#testForwardFields .test-forward-input');
    const values = {};
    inputs.forEach(inp => { values[inp.dataset.placeholder] = inp.value; });

    fetch('<?= BASE_URL ?>/?page=api&action=test_forward', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        body: JSON.stringify({
            _csrf: csrfToken,
            forward_id: _testForwardActionId,
            placeholders: values,
        }),
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('testForwardResult').style.display = '';
        if (data.error) {
            document.getElementById('testForwardStatus').innerHTML = '<span class="badge badge-error">Errore</span> ' + escapeHtml(data.error);
            document.getElementById('testForwardBody').textContent = '';
        } else {
            const statusClass = data.status >= 200 && data.status < 300 ? 'badge-success' : 'badge-error';
            document.getElementById('testForwardStatus').innerHTML = '<span class="badge ' + statusClass + '">HTTP ' + escapeHtml(String(data.status)) + '</span>';
            document.getElementById('testForwardBody').textContent = data.body || '';
        }
    })
    .catch(err => {
        document.getElementById('testForwardResult').style.display = '';
        document.getElementById('testForwardStatus').innerHTML = '<span class="badge badge-error">Errore di rete</span>';
        document.getElementById('testForwardBody').textContent = String(err);
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = 'Esegui';
    });
}
</script>

<!-- Guard Modal -->
<?php include __DIR__ . '/partials/add_guard_modal.php'; ?>
<script>
document.getElementById('addGuardForm')?.addEventListener('submit', function() {
    document.getElementById('addGuardProjectId').value = '';
    document.getElementById('addGuardWebhookId').value = '<?= $webhookId ?>';
    document.getElementById('addGuardRedirect').value = 'webhook&action=detail&id=<?= $webhookId ?>';
});
</script>

<script>
// Conditions builder
let conditionCount = 0;

function addCondition() {
    conditionCount++;
    const idx = conditionCount;
    const builder = document.getElementById('conditionsBuilder');
    const div = document.createElement('div');
    div.className = 'condition-row';
    div.id = 'cond_' + idx;
    div.innerHTML = `
        <select name="cond_type_${idx}" onchange="updateConditionFields(${idx}, this.value)" class="cond-type">
            <option value="">— Type —</option>
            <option value="method">HTTP Method</option>
            <option value="header">Header</option>
            <option value="query">Query Param</option>
            <option value="body_json">JSON Body Field</option>
        </select>
        <input type="text" name="cond_field_${idx}" placeholder="Field/Header" class="cond-field" style="display:none">
        <select name="cond_op_${idx}" class="cond-op">
            <option value="equals">equals</option>
            <option value="not_equals">not equals</option>
            <option value="contains">contains</option>
            <option value="exists">exists</option>
        </select>
        <input type="text" name="cond_value_${idx}" placeholder="Value" class="cond-value">
        <button type="button" onclick="removeCondition(${idx})" class="btn btn-xs btn-danger">✕</button>
    `;
    builder.appendChild(div);
}

function updateConditionFields(idx, type) {
    const fieldInput = document.querySelector('#cond_' + idx + ' .cond-field');
    if (['header','query','body_json'].includes(type)) {
        fieldInput.style.display = '';
        fieldInput.placeholder = type === 'header' ? 'X-Header-Name' :
                                  type === 'query' ? 'param_name' : 'device.id';
    } else {
        fieldInput.style.display = 'none';
    }
}

function removeCondition(idx) {
    const el = document.getElementById('cond_' + idx);
    if (el) el.remove();
}

function serializeConditions() {
    const rows = document.querySelectorAll('#conditionsBuilder .condition-row');
    const conditions = [];
    rows.forEach(row => {
        const id = row.id.replace('cond_', '');
        const type = row.querySelector('.cond-type').value;
        const field = row.querySelector('.cond-field').value.trim();
        const op = row.querySelector('.cond-op').value;
        const value = row.querySelector('.cond-value').value.trim();
        if (type) {
            const cond = {type, operator: op, value};
            if (field) cond.field = field;
            conditions.push(cond);
        }
    });
    document.getElementById('conditionsJson').value = JSON.stringify(conditions);
}

// Ensure conditions are serialized on modal form submit
document.querySelector('#addForwardModal form')?.addEventListener('submit', serializeConditions);
</script>
