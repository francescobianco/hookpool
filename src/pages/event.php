<?php
$current_user = requireAuth($db);
$userId       = (int)$current_user['id'];
$eventId      = (int)($_GET['id'] ?? 0);

// Load event and verify ownership
$evtStmt = $db->prepare('
    SELECT e.*,
           w.name AS webhook_name, w.id AS webhook_id, w.token AS webhook_token,
           p.name AS project_name, p.id AS project_id
    FROM events e
    JOIN webhooks w ON w.id = e.webhook_id
    JOIN projects p ON p.id = w.project_id
    WHERE e.id = ? AND p.user_id = ? AND p.deleted_at IS NULL
');
$evtStmt->execute([$eventId, $userId]);
$event = $evtStmt->fetch();

if (!$event) {
    setFlash('error', __('event.not_found'));
    header('Location: ' . BASE_URL . '/?page=dashboard');
    exit;
}

// Load forward attempts
$attStmt = $db->prepare('
    SELECT fa2.*, fa.name AS action_name, fa.url AS action_url, fa.method AS action_method
    FROM forward_attempts fa2
    JOIN forward_actions fa ON fa.id = fa2.forward_action_id
    WHERE fa2.event_id = ?
    ORDER BY fa2.executed_at
');
$attStmt->execute([$eventId]);
$attempts = $attStmt->fetchAll();

// Parse headers
$headers     = json_decode($event['headers'] ?? '{}', true) ?: [];
$body        = $event['body'] ?? '';
$queryString = $event['query_string'] ?? '';
parse_str($queryString, $queryParams);

// Try to pretty-print JSON body
$bodyPretty = null;
$bodyJson   = json_decode($body, true);
if (json_last_error() === JSON_ERROR_NONE && (is_array($bodyJson) || is_object($bodyJson))) {
    $bodyPretty = json_encode($bodyJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$page_title = 'Event #' . $eventId;
$webhookUrl = BASE_URL . '/hook/' . $event['webhook_token'];
?>

<div class="page-container">
    <div class="page-header">
        <div class="header-title-group">
            <div class="breadcrumb">
                <a href="<?= BASE_URL ?>/?page=dashboard"><?= __('nav.dashboard') ?></a>
                <span class="breadcrumb-sep">›</span>
                <a href="<?= BASE_URL ?>/?page=project&action=detail&id=<?= $event['project_id'] ?>"><?= e($event['project_name']) ?></a>
                <span class="breadcrumb-sep">›</span>
                <a href="<?= BASE_URL ?>/?page=webhook&action=detail&id=<?= $event['webhook_id'] ?>"><?= e($event['webhook_name']) ?></a>
                <span class="breadcrumb-sep">›</span>
                <span>Event #<?= $eventId ?></span>
            </div>
            <h1 class="event-title">
                <span class="badge-method <?= strtolower($event['method']) ?>"><?= e($event['method']) ?></span>
                <span class="mono"><?= e($event['path']) ?></span>
            </h1>
        </div>
        <div class="header-actions">
            <?php if ($event['validated']): ?>
            <span class="badge badge-success">✓ <?= __('event.validated') ?></span>
            <?php else: ?>
            <span class="badge badge-error">✕ <?= __('event.rejected') ?></span>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/?page=dashboard" class="btn btn-sm btn-outline"><?= __('event.back') ?></a>
        </div>
    </div>

    <!-- Meta Info -->
    <div class="card">
        <div class="event-meta-grid">
            <div class="meta-item">
                <span class="meta-label"><?= __('event.received_at') ?></span>
                <span class="meta-value"><?= e($event['received_at']) ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label"><?= __('event.ip') ?></span>
                <span class="meta-value mono"><?= e($event['ip']) ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label"><?= __('event.content_type') ?></span>
                <span class="meta-value mono"><?= e($event['content_type'] ?: '—') ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Webhook</span>
                <span class="meta-value">
                    <a href="<?= BASE_URL ?>/?page=webhook&action=detail&id=<?= $event['webhook_id'] ?>"><?= e($event['webhook_name']) ?></a>
                </span>
            </div>
            <?php if (!$event['validated'] && $event['rejection_reason']): ?>
            <div class="meta-item meta-item-full">
                <span class="meta-label"><?= __('event.rejection_reason') ?></span>
                <span class="meta-value text-error"><?= e($event['rejection_reason']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($queryString): ?>
            <div class="meta-item meta-item-full">
                <span class="meta-label"><?= __('event.path') ?></span>
                <span class="meta-value mono"><?= e($event['path'] . ($queryString ? '?' . $queryString : '')) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabs for Headers / Query / Body -->
    <div class="tabs-container">
        <div class="tabs-nav">
            <button class="tab-btn active" onclick="showTab('tab-headers', this)">Headers (<?= count($headers) ?>)</button>
            <button class="tab-btn" onclick="showTab('tab-query', this)">Query (<?= count($queryParams) ?>)</button>
            <button class="tab-btn" onclick="showTab('tab-body', this)">
                Body <?= $body ? '(' . strlen($body) . ' bytes)' : '(empty)' ?>
            </button>
        </div>

        <!-- Headers Tab -->
        <div id="tab-headers" class="tab-content active">
            <?php if (empty($headers)): ?>
            <p class="text-muted">No headers recorded.</p>
            <?php else: ?>
            <table class="kv-table">
                <thead><tr><th>Header</th><th>Value</th></tr></thead>
                <tbody>
                    <?php foreach ($headers as $k => $v): ?>
                    <tr>
                        <td class="mono key-col"><?= e($k) ?></td>
                        <td class="mono"><?= e($v) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Query Tab -->
        <div id="tab-query" class="tab-content" style="display:none">
            <?php if (empty($queryParams)): ?>
            <p class="text-muted">No query parameters.</p>
            <?php else: ?>
            <table class="kv-table">
                <thead><tr><th>Parameter</th><th>Value</th></tr></thead>
                <tbody>
                    <?php foreach ($queryParams as $k => $v): ?>
                    <tr>
                        <td class="mono key-col"><?= e($k) ?></td>
                        <td class="mono"><?= e(is_array($v) ? json_encode($v) : $v) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Body Tab -->
        <div id="tab-body" class="tab-content" style="display:none">
            <?php if ($body === ''): ?>
            <p class="text-muted">No body content.</p>
            <?php else: ?>
            <?php if ($bodyPretty !== null): ?>
            <div class="body-tabs-nav">
                <button class="body-tab-btn active" onclick="showBodyTab('raw', this)">Raw</button>
                <button class="body-tab-btn" onclick="showBodyTab('pretty', this)">Pretty JSON</button>
                <button class="btn btn-xs btn-outline copy-btn" onclick="copyToClipboard(document.getElementById('bodyRaw').textContent, this)" style="margin-left:auto">Copy</button>
            </div>
            <div id="bodyTab-raw" class="body-tab">
                <pre class="code-block" id="bodyRaw"><?= e($body) ?></pre>
            </div>
            <div id="bodyTab-pretty" class="body-tab" style="display:none">
                <pre class="code-block code-json"><?= e($bodyPretty) ?></pre>
            </div>
            <?php else: ?>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                <span class="text-muted text-sm">Raw</span>
                <button class="btn btn-xs btn-outline copy-btn" onclick="copyToClipboard(document.getElementById('bodyRaw').textContent, this)">Copy</button>
            </div>
            <pre class="code-block" id="bodyRaw"><?= e($body) ?></pre>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Forwarding Attempts -->
    <?php if (!empty($attempts)): ?>
    <section class="section">
        <h2><?= __('event.forwarding') ?></h2>
        <div class="attempts-list">
            <?php foreach ($attempts as $att): ?>
            <?php
                $statusCode = (int)($att['response_status'] ?? 0);
                $statusClass = $statusCode >= 200 && $statusCode < 300 ? 'badge-success' :
                               ($statusCode >= 400 ? 'badge-error' : 'badge-warning');
            ?>
            <div class="card card-attempt">
                <div class="card-header">
                    <div>
                        <h4 class="card-title"><?= e($att['action_name'] ?? '') ?></h4>
                        <div class="forward-url">
                            <span class="badge badge-method-sm <?= strtolower($att['action_method'] ?? 'post') ?>"><?= e($att['action_method'] ?? 'POST') ?></span>
                            <code><?= e($att['action_url'] ?? '') ?></code>
                        </div>
                    </div>
                    <div class="attempt-status">
                        <?php if ($att['error']): ?>
                        <span class="badge badge-error">Error</span>
                        <?php elseif ($statusCode): ?>
                        <span class="badge <?= $statusClass ?>"><?= $statusCode ?></span>
                        <?php else: ?>
                        <span class="badge badge-muted">—</span>
                        <?php endif; ?>
                        <span class="text-muted text-sm"><?= e($att['executed_at'] ?? '') ?></span>
                    </div>
                </div>
                <?php if ($att['error']): ?>
                <div class="attempt-error text-error"><?= e($att['error']) ?></div>
                <?php endif; ?>
                <?php if ($att['response_body']): ?>
                <details class="attempt-response">
                    <summary>Response Body</summary>
                    <?php
                    $respJson = json_decode($att['response_body'], true);
                    $respPretty = (json_last_error() === JSON_ERROR_NONE && is_array($respJson))
                        ? json_encode($respJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        : null;
                    ?>
                    <pre class="code-block"><?= e($respPretty ?? $att['response_body']) ?></pre>
                </details>
                <?php endif; ?>
                <?php if ($att['request_headers']): ?>
                <details class="attempt-request-headers">
                    <summary>Request Headers Sent</summary>
                    <?php $rh = json_decode($att['request_headers'], true) ?: []; ?>
                    <table class="kv-table kv-table-sm">
                        <?php foreach ($rh as $k => $v): ?>
                        <tr><td class="mono key-col"><?= e($k) ?></td><td class="mono"><?= e($v) ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </details>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>

<script>
function showTab(tabId, btn) {
    document.querySelectorAll('.tab-content').forEach(t => t.style.display = 'none');
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(tabId).style.display = '';
    btn.classList.add('active');
}

function showBodyTab(which, btn) {
    document.querySelectorAll('.body-tab').forEach(t => t.style.display = 'none');
    document.querySelectorAll('.body-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('bodyTab-' + which).style.display = '';
    btn.classList.add('active');
}
</script>
