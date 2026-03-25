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
        if ($name === '') $name = 'Webhook ' . date('YmdHis');

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
                    <input type="text" id="name" name="name" maxlength="100" placeholder="Default">
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

// Load recent events (last 20)
$evtStmt = $db->prepare('SELECT * FROM events WHERE webhook_id = ? ORDER BY received_at DESC LIMIT 20');
$evtStmt->execute([$webhookId]);
$recentEvents = $evtStmt->fetchAll();

$webhookUrl = webhookUrl($wh['slug'] ?? $wh['project_slug'] ?? '', $wh['token']);
$page_title = e($wh['name']);

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
            <h1><?= e($wh['name']) ?></h1>
        </div>
        <div class="header-actions">
            <span class="badge <?= $wh['active'] ? 'badge-success' : 'badge-muted' ?>">
                <?= $wh['active'] ? __('webhook.active') : __('webhook.inactive') ?>
            </span>
            <form method="post" action="<?= BASE_URL ?>/?page=webhook&action=toggle&id=<?= $webhookId ?>" class="inline">
                <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                <button type="submit" class="btn btn-sm btn-outline"><?= $wh['active'] ? 'Disable' : 'Enable' ?></button>
            </form>
            <button onclick="openModal('deleteWebhookModal')" class="btn btn-sm btn-danger"><?= __('webhook.delete') ?></button>
        </div>
    </div>

    <!-- Endpoint URL -->
    <div class="card card-endpoint">
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
    </div>

    <!-- Forward Actions -->
    <section class="section">
        <div class="section-header">
            <h2><?= __('forward.create') ?></h2>
            <button onclick="openModal('addForwardModal')" class="btn btn-sm btn-primary">+ <?= __('forward.create') ?></button>
        </div>
        <?php if (empty($forwardActions)): ?>
        <p class="text-muted">No forward actions configured. Add one to automatically forward incoming events to other endpoints.</p>
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

    <!-- Recent Events -->
    <section class="section">
        <div class="section-header">
            <h2>Recent Events</h2>
            <a href="<?= BASE_URL ?>/?page=dashboard" class="btn btn-sm btn-outline">View All in Dashboard</a>
        </div>
        <?php if (empty($recentEvents)): ?>
        <div class="empty-state-sm">
            <p><?= __('event.no_events') ?></p>
            <p class="text-muted">Try sending: <code>curl -X POST <?= e($webhookUrl) ?></code></p>
        </div>
        <?php else: ?>
        <table class="events-table events-table-compact">
            <thead>
                <tr>
                    <th><?= __('event.method') ?></th>
                    <th><?= __('event.received_at') ?></th>
                    <th><?= __('event.path') ?></th>
                    <th><?= __('event.ip') ?></th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentEvents as $ev): ?>
                <tr class="event-row" onclick="window.location='<?= BASE_URL ?>/?page=event&id=<?= $ev['id'] ?>'">
                    <td><span class="badge-method <?= strtolower($ev['method']) ?>"><?= e($ev['method']) ?></span></td>
                    <td><span title="<?= e($ev['received_at']) ?>"><?= e(date('H:i:s', strtotime($ev['received_at']))) ?></span></td>
                    <td class="mono"><?= e($ev['path']) ?></td>
                    <td class="mono"><?= e($ev['ip']) ?></td>
                    <td><?= $ev['validated'] ? '<span class="badge badge-success">Valid</span>' : '<span class="badge badge-error">Guard</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
</div>

<!-- Delete Webhook Modal -->
<div id="deleteWebhookModal" class="modal" style="display:none" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3>Delete Webhook</h3>
            <button onclick="closeModal('deleteWebhookModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <p><?= __('webhook.confirm_delete') ?></p>
        </div>
        <div class="modal-footer">
            <form method="post" action="<?= BASE_URL ?>/?page=webhook&action=delete&id=<?= $webhookId ?>">
                <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                <button type="submit" class="btn btn-danger"><?= __('form.yes_delete') ?></button>
                <button type="button" onclick="closeModal('deleteWebhookModal')" class="btn btn-outline"><?= __('form.cancel') ?></button>
            </form>
        </div>
    </div>
</div>

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
