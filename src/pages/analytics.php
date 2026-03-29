<?php
$current_user = requireAuth($db);
$userId       = (int)$current_user['id'];

require_once __DIR__ . '/../classes/DslEvaluator.php';

// ---- Resolve view ----
// Entry via webhook_id: create or load a working (unsaved) view for this user+webhook
// Entry via view_id: load a specific view

$viewId    = isset($_GET['view_id'])    ? (int)$_GET['view_id']    : 0;
$webhookId = isset($_GET['webhook_id']) ? (int)$_GET['webhook_id'] : 0;

if ($viewId === 0 && $webhookId === 0) {
    header('Location: ' . BASE_URL . '/?page=dashboard');
    exit;
}

// Helper: verify webhook ownership and return it
function loadWebhookForAnalytics(PDO $db, int $webhookId, int $userId): ?array
{
    $stmt = $db->prepare('
        SELECT w.*, p.name AS project_name, p.id AS project_id
        FROM webhooks w
        JOIN projects p ON p.id = w.project_id
        WHERE w.id = ? AND p.user_id = ? AND w.deleted_at IS NULL AND p.deleted_at IS NULL
    ');
    $stmt->execute([$webhookId, $userId]);
    return $stmt->fetch() ?: null;
}

// Load view record
if ($viewId > 0) {
    $vStmt = $db->prepare('SELECT * FROM analytics_views WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
    $vStmt->execute([$viewId, $userId]);
    $view = $vStmt->fetch();
    if (!$view) {
        setFlash('error', 'Analytics view not found.');
        header('Location: ' . BASE_URL . '/?page=dashboard');
        exit;
    }
    $webhookId = (int)$view['webhook_id'];
    $wh = loadWebhookForAnalytics($db, $webhookId, $userId);
    if (!$wh) {
        setFlash('error', __('msg.unauthorized'));
        header('Location: ' . BASE_URL . '/?page=dashboard');
        exit;
    }
} else {
    // webhook_id provided: find or create working view
    $wh = loadWebhookForAnalytics($db, $webhookId, $userId);
    if (!$wh) {
        setFlash('error', __('msg.unauthorized'));
        header('Location: ' . BASE_URL . '/?page=dashboard');
        exit;
    }
    // Find existing unsaved working view
    $vStmt = $db->prepare(
        'SELECT * FROM analytics_views WHERE user_id = ? AND webhook_id = ? AND name IS NULL AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1'
    );
    $vStmt->execute([$userId, $webhookId]);
    $view = $vStmt->fetch();
    if (!$view) {
        // Create a new working view
        $db->prepare(
            "INSERT INTO analytics_views (user_id, webhook_id, name, fields, groupby, sort_by, sort_dir) VALUES (?, ?, NULL, '[]', 'none', 'received_at', 'desc')"
        )->execute([$userId, $webhookId]);
        $viewId = (int)$db->lastInsertId();
        $vStmt->execute([$userId, $webhookId]);
        $view = $vStmt->fetch();
    }
    $viewId = (int)$view['id'];
    // Redirect to canonical view URL
    header('Location: ' . BASE_URL . '/?page=analytics&view_id=' . $viewId);
    exit;
}

$viewId  = (int)$view['id'];
$fields  = json_decode($view['fields'], true) ?: [];
$groupby = $view['groupby'];
$sortBy  = $_GET['sort_by']  ?? $view['sort_by'];
$sortDir = $_GET['sort_dir'] ?? $view['sort_dir'];

// Sanitise
$allowedSortDirs = ['asc', 'desc'];
if (!in_array($sortDir, $allowedSortDirs, true)) $sortDir = 'desc';
$allowedGroupby = ['none', 'day', 'week', 'month'];
if (!in_array($groupby, $allowedGroupby, true)) $groupby = 'none';

// ---- Handle POST actions ----
$csrfOk = verifyCsrfToken($_POST['_csrf'] ?? '');
$analyticsAction = $_POST['_analytics_action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfOk) {

    if ($analyticsAction === 'add_field') {
        $fieldName    = trim($_POST['field_name'] ?? '');
        $fieldFormula = trim($_POST['field_formula'] ?? '');
        if ($fieldName !== '' && $fieldFormula !== '') {
            $fields[] = ['name' => $fieldName, 'formula' => $fieldFormula];
            $db->prepare('UPDATE analytics_views SET fields = ? WHERE id = ?')
               ->execute([json_encode($fields), $viewId]);
        }
        header('Location: ' . BASE_URL . '/?page=analytics&view_id=' . $viewId . '&sort_by=' . urlencode($sortBy) . '&sort_dir=' . urlencode($sortDir));
        exit;
    }

    if ($analyticsAction === 'remove_field') {
        $fieldIdx = (int)($_POST['field_idx'] ?? -1);
        if (isset($fields[$fieldIdx])) {
            array_splice($fields, $fieldIdx, 1);
            $db->prepare('UPDATE analytics_views SET fields = ? WHERE id = ?')
               ->execute([json_encode(array_values($fields)), $viewId]);
        }
        header('Location: ' . BASE_URL . '/?page=analytics&view_id=' . $viewId . '&sort_by=' . urlencode($sortBy) . '&sort_dir=' . urlencode($sortDir));
        exit;
    }

    if ($analyticsAction === 'update_groupby') {
        $newGroupby = $_POST['groupby'] ?? 'none';
        if (!in_array($newGroupby, $allowedGroupby, true)) $newGroupby = 'none';
        $db->prepare('UPDATE analytics_views SET groupby = ? WHERE id = ?')
           ->execute([$newGroupby, $viewId]);
        header('Location: ' . BASE_URL . '/?page=analytics&view_id=' . $viewId . '&sort_by=' . urlencode($sortBy) . '&sort_dir=' . urlencode($sortDir));
        exit;
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrfOk) {
    setFlash('error', __('msg.csrf_error'));
    header('Location: ' . BASE_URL . '/?page=analytics&view_id=' . $viewId);
    exit;
}

// ---- Persist sort prefs when changed via GET ----
if (isset($_GET['sort_by'])) {
    $db->prepare('UPDATE analytics_views SET sort_by = ?, sort_dir = ? WHERE id = ?')
       ->execute([$sortBy, $sortDir, $viewId]);
}

// ---- Load known IPs ----
$knownIpMap = [];
$kipStmt = $db->prepare('SELECT ip, label FROM known_ips WHERE user_id = ?');
$kipStmt->execute([$userId]);
foreach ($kipStmt->fetchAll() as $k) $knownIpMap[$k['ip']] = $k['label'];

// ---- Load events (max 2000 for performance) ----
$evStmt = $db->prepare('
    SELECT id, method, received_at, path, query_string, body, validated, ip
    FROM events
    WHERE webhook_id = ?
    ORDER BY received_at ASC
    LIMIT 2000
');
$evStmt->execute([$webhookId]);
$allEvents = $evStmt->fetchAll();

// Enrich each event with known_ip
foreach ($allEvents as &$ev) {
    $ev['known_ip'] = $knownIpMap[$ev['ip']] ?? $ev['ip'];
}
unset($ev);

$totalCount = count($allEvents);

// ---- Compute custom field values for each row (ungrouped mode) ----
$computedRows = []; // array of [event + computed field values]

if ($groupby === 'none') {
    foreach ($allEvents as $idx => $ev) {
        $row = $ev;
        $phValues = []; // placeholder name => value for this row

        foreach ($fields as $fIdx => $field) {
            try {
                $val = DslEvaluator::evaluate($field['formula'], $allEvents, $idx, $phValues);
            } catch (\Throwable $e) {
                $val = 'ERR';
            }
            // Store for subsequent placeholders
            $phValues[$field['name']] = $val;
            $row['_field_' . $fIdx] = $val;
        }
        $computedRows[] = $row;
    }

    // Sort
    $sortByField = $sortBy;
    $sortDirMul  = $sortDir === 'asc' ? 1 : -1;

    if ($sortByField === 'received_at' || $sortByField === 'method' || $sortByField === 'path' || $sortByField === 'ip' || $sortByField === 'validated') {
        usort($computedRows, function ($a, $b) use ($sortByField, $sortDirMul) {
            return $sortDirMul * strcmp((string)($a[$sortByField] ?? ''), (string)($b[$sortByField] ?? ''));
        });
    } elseif (preg_match('/^field_(\d+)$/', $sortByField, $fm)) {
        $fIdx = (int)$fm[1];
        usort($computedRows, function ($a, $b) use ($fIdx, $sortDirMul) {
            $va = $a['_field_' . $fIdx] ?? null;
            $vb = $b['_field_' . $fIdx] ?? null;
            if (is_numeric($va) && is_numeric($vb)) {
                return $sortDirMul * ($va <=> $vb);
            }
            return $sortDirMul * strcmp((string)$va, (string)$vb);
        });
    }
}

// ---- Grouped data ----
$groupedRows = [];
if ($groupby !== 'none') {
    foreach ($allEvents as $ev) {
        $ts = strtotime($ev['received_at']);
        $key = match($groupby) {
            'day'   => date('Y-m-d', $ts),
            'week'  => date('o', $ts) . '-W' . date('W', $ts),
            'month' => date('Y-m', $ts),
            default => date('Y-m-d', $ts),
        };
        if (!isset($groupedRows[$key])) {
            $groupedRows[$key] = [
                'label'   => $key,
                'count'   => 0,
                'methods' => [],
                'valid'   => 0,
                'invalid' => 0,
            ];
        }
        $groupedRows[$key]['count']++;
        $m = $ev['method'] ?? 'UNKNOWN';
        $groupedRows[$key]['methods'][$m] = ($groupedRows[$key]['methods'][$m] ?? 0) + 1;
        if ($ev['validated']) $groupedRows[$key]['valid']++;
        else $groupedRows[$key]['invalid']++;
    }

    // Sort grouped rows by label
    if ($sortDir === 'asc') {
        ksort($groupedRows);
    } else {
        krsort($groupedRows);
    }
}

// ---- Helper: sort link ----
function analyticsColLink(int $viewId, string $col, string $currentSortBy, string $currentSortDir, string $label): string
{
    $newDir = ($currentSortBy === $col && $currentSortDir === 'asc') ? 'desc' : 'asc';
    $arrow = '';
    if ($currentSortBy === $col) {
        $arrow = $currentSortDir === 'asc' ? ' ▲' : ' ▼';
    }
    $url = BASE_URL . '/?page=analytics&view_id=' . $viewId . '&sort_by=' . urlencode($col) . '&sort_dir=' . urlencode($newDir);
    return '<a href="' . e($url) . '" class="col-sort-link">' . e($label) . $arrow . '</a>';
}

// ---- Page render ----
$page_title = 'Analytics — ' . e($wh['name']);
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
                <span>Metrics &amp; Analytics</span>
            </div>
            <h1>
                Metrics &amp; Analytics
                <?php if ($view['name']): ?>
                <span class="analytics-view-name-badge"><?= e($view['name']) ?></span>
                <?php endif; ?>
            </h1>
        </div>
        <div class="header-actions">
            <a href="<?= BASE_URL ?>/?page=webhook&action=detail&id=<?= $webhookId ?>" class="btn btn-sm btn-outline">← Back</a>
            <button class="btn btn-sm btn-primary" onclick="openModal('saveViewModal')">⊕ Save to sidebar</button>
        </div>
    </div>

    <!-- Controls bar -->
    <div class="analytics-controls card">
        <form method="post" action="<?= BASE_URL ?>/?page=analytics&view_id=<?= $viewId ?>" class="analytics-groupby-form" id="groupbyForm">
            <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="_analytics_action" value="update_groupby">
            <label for="groupbySelect" class="analytics-control-label">Group by</label>
            <select name="groupby" id="groupbySelect" class="analytics-select" onchange="this.form.submit()">
                <option value="none"  <?= $groupby === 'none'  ? 'selected' : '' ?>>None</option>
                <option value="day"   <?= $groupby === 'day'   ? 'selected' : '' ?>>Day</option>
                <option value="week"  <?= $groupby === 'week'  ? 'selected' : '' ?>>Week</option>
                <option value="month" <?= $groupby === 'month' ? 'selected' : '' ?>>Month</option>
            </select>
        </form>

        <span class="text-muted analytics-total"><?= number_format($totalCount) ?> events</span>

        <button class="btn btn-primary analytics-add-field-btn" onclick="openModal('addFieldModal')">
            + Add custom field
        </button>
    </div>

    <!-- Custom fields list (chips) -->
    <?php if (!empty($fields)): ?>
    <div class="analytics-fields-bar">
        <span class="analytics-fields-label">Custom fields:</span>
        <?php foreach ($fields as $fIdx => $field): ?>
        <div class="analytics-field-chip">
            <span class="chip-name"><?= e($field['name']) ?></span>
            <span class="chip-formula text-muted"><?= e($field['formula']) ?></span>
            <form method="post" action="<?= BASE_URL ?>/?page=analytics&view_id=<?= $viewId ?>" class="inline">
                <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="_analytics_action" value="remove_field">
                <input type="hidden" name="field_idx" value="<?= $fIdx ?>">
                <button type="submit" class="chip-remove" title="Remove field">&times;</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Main table -->
    <div class="card analytics-table-card">
        <?php if ($groupby === 'none'): ?>

        <?php if (empty($computedRows)): ?>
        <p class="text-muted" style="padding:1.5rem">No events recorded for this webhook yet.</p>
        <?php else: ?>
        <div class="analytics-table-wrap">
        <table class="analytics-table">
            <thead>
                <tr>
                    <th class="col-ts col-fixed"><?= analyticsColLink($viewId, 'received_at', $sortBy, $sortDir, 'Timestamp') ?></th>
                    <th class="col-method col-fixed"><?= analyticsColLink($viewId, 'method', $sortBy, $sortDir, 'Method') ?></th>
                    <th class="col-path col-fixed"><?= analyticsColLink($viewId, 'path', $sortBy, $sortDir, 'Path') ?></th>
                    <?php foreach ($fields as $fIdx => $field): ?>
                    <th class="col-custom-field"><?= analyticsColLink($viewId, 'field_' . $fIdx, $sortBy, $sortDir, $field['name']) ?></th>
                    <?php endforeach; ?>
                    <th class="col-status col-fixed"><?= analyticsColLink($viewId, 'validated', $sortBy, $sortDir, 'Status') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($computedRows as $row): ?>
                <tr class="event-row" onclick="window.location='<?= BASE_URL ?>/?page=event&id=<?= (int)$row['id'] ?>'">
                    <td class="col-ts col-fixed mono"><?= e(date('Y-m-d H:i:s', strtotime($row['received_at']))) ?></td>
                    <td class="col-method col-fixed"><span class="badge-method <?= strtolower($row['method']) ?>"><?= e($row['method']) ?></span></td>
                    <td class="col-path col-fixed mono"><?= e($row['path'] . ($row['query_string'] !== '' ? '?' . $row['query_string'] : '')) ?></td>
                    <?php foreach ($fields as $fIdx => $field): ?>
                    <td class="analytics-field-val">
                        <?php
                        $fVal = $row['_field_' . $fIdx] ?? null;
                        if ($fVal === null)       echo '<span class="text-muted">—</span>';
                        elseif ($fVal === 'ERR')  echo '<span class="badge badge-error">ERR</span>';
                        elseif (is_float($fVal) && $fVal != (int)$fVal) echo e(number_format($fVal, 2));
                        else echo e((string)$fVal);
                        ?>
                    </td>
                    <?php endforeach; ?>
                    <td class="col-status col-fixed">
                        <?php if (strtoupper($row['method']) === 'ALARM'): ?>
                            <span class="badge badge-warning">Alarm</span>
                        <?php elseif ($row['validated']): ?>
                            <span class="badge badge-success">Valid</span>
                        <?php else: ?>
                            <span class="badge badge-error">Guard</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <?php else: /* grouped view */ ?>

        <?php if (empty($groupedRows)): ?>
        <p class="text-muted" style="padding:1.5rem">No events recorded for this webhook yet.</p>
        <?php else: ?>
        <div class="analytics-table-wrap">
        <table class="analytics-table">
            <thead>
                <tr>
                    <th>
                        <?php $newDir = $sortDir === 'asc' ? 'desc' : 'asc'; ?>
                        <a href="<?= e(BASE_URL . '/?page=analytics&view_id=' . $viewId . '&sort_by=received_at&sort_dir=' . $newDir) ?>" class="col-sort-link">
                            Period <?= $sortDir === 'asc' ? '▲' : '▼' ?>
                        </a>
                    </th>
                    <th>Events</th>
                    <th>Methods</th>
                    <th>Valid / Guard</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groupedRows as $gRow): ?>
                <tr>
                    <td class="mono"><?= e($gRow['label']) ?></td>
                    <td><?= (int)$gRow['count'] ?></td>
                    <td>
                        <?php foreach ($gRow['methods'] as $method => $cnt): ?>
                        <span class="badge-method badge-method-sm <?= strtolower($method) ?>" style="margin-right:3px"><?= e($method) ?> <?= $cnt ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php if ($gRow['valid'] > 0): ?>
                        <span class="badge badge-success"><?= $gRow['valid'] ?> valid</span>
                        <?php endif; ?>
                        <?php if ($gRow['invalid'] > 0): ?>
                        <span class="badge badge-error"><?= $gRow['invalid'] ?> guard</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Add field modal -->
<div id="addFieldModal" class="modal" style="display:none" aria-hidden="true">
    <div class="modal-dialog modal-dialog-wide">
        <div class="modal-header">
            <h3>Add Custom Field</h3>
            <button onclick="closeModal('addFieldModal')" class="modal-close">&times;</button>
        </div>
        <form method="post" action="<?= BASE_URL ?>/?page=analytics&view_id=<?= $viewId ?>" id="addFieldForm" onsubmit="return validateFormulaBeforeSubmit(event)">
            <input type="hidden" name="_csrf" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="_analytics_action" value="add_field">
            <div class="modal-body">
                <div class="form-group">
                    <label for="field_name">Field name</label>
                    <input type="text" id="field_name" name="field_name" placeholder="e.g. Count Before" required maxlength="60">
                </div>
                <div class="form-group">
                    <label for="field_formula">Formula</label>
                    <input type="text" id="field_formula" name="field_formula" placeholder="e.g. COUNT BEFORE" required class="mono" autocomplete="off" oninput="clearFormulaError()">
                    <p id="formula_error" class="form-hint formula-error" style="display:none"></p>
                    <p id="formula_ok" class="form-hint formula-ok" style="display:none">✓ Valid formula</p>
                </div>

                <div class="dsl-hint">
                    <div class="dsl-hint-section">
                        <div class="dsl-hint-title">Metrics</div>
                        <div class="dsl-hint-row"><code>COUNT BEFORE</code> <code>COUNT AFTER</code></div>
                        <div class="dsl-hint-row"><code>COUNT STREAK BEFORE</code> <code>COUNT STREAK AFTER</code></div>
                        <div class="dsl-hint-row"><code>SECONDS|MINUTES|HOURS|DAYS&nbsp;BEFORE&nbsp;LAST</code></div>
                        <div class="dsl-hint-row"><code>SECONDS|MINUTES|HOURS|DAYS&nbsp;AFTER&nbsp;FIRST</code></div>
                    </div>
                    <div class="dsl-hint-section">
                        <div class="dsl-hint-title">Filter (optional)</div>
                        <div class="dsl-hint-row"><code>WITH&nbsp;&lt;expr&gt;</code> — e.g. <code>COUNT BEFORE WITH {{status}} = 1</code></div>
                        <div class="dsl-hint-row">Operators: <code>=</code> <code>!=</code> <code>&gt;</code> <code>&gt;=</code> <code>&lt;</code> <code>&lt;=</code> <code>AND</code> <code>OR</code> <code>NOT</code></div>
                    </div>
                    <div class="dsl-hint-section">
                        <div class="dsl-hint-title">Built-in variables</div>
                        <div class="dsl-hint-grid">
                            <code>{{body}}</code><span>raw request body</span>
                            <code>{{status}}</code><span>1 = valid, 0 = guard</span>
                            <code>{{method}}</code><span>HTTP method</span>
                            <code>{{ts}}</code><span>Unix timestamp</span>
                            <code>{{ip}}</code><span>sender IP</span>
                            <code>{{known_ip}}</code><span>IP label or raw IP</span>
                            <code>{{path}}</code><span>request path</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" id="addFieldSubmitBtn" class="btn btn-primary">Add field</button>
                <button type="button" onclick="closeModal('addFieldModal')" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Save to sidebar modal -->
<div id="saveViewModal" class="modal" style="display:none" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3>Save view to sidebar</h3>
            <button onclick="closeModal('saveViewModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="saveViewName">View name</label>
                <input type="text" id="saveViewName" placeholder="e.g. Daily POST activity" maxlength="80" class="w-full">
            </div>
            <p id="saveViewError" class="form-hint" style="color:var(--color-danger);display:none"></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" onclick="saveViewToSidebar()">Save</button>
            <button type="button" onclick="closeModal('saveViewModal')" class="btn btn-outline">Cancel</button>
        </div>
    </div>
</div>

<script>
// ---- Formula validation ----
let _formulaValidating = false;

function clearFormulaError() {
    document.getElementById('formula_error').style.display = 'none';
    document.getElementById('formula_ok').style.display = 'none';
}

function validateFormulaBeforeSubmit(e) {
    e.preventDefault();
    const formula = document.getElementById('field_formula').value.trim();
    if (!formula) return;

    const btn = document.getElementById('addFieldSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Checking…';

    fetch('<?= BASE_URL ?>/?page=api&action=validate_formula', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ _csrf: csrfToken, formula })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.textContent = 'Add field';
        if (!data.ok) {
            const el = document.getElementById('formula_error');
            el.textContent = '⚠ ' + data.error;
            el.style.display = '';
            document.getElementById('formula_ok').style.display = 'none';
        } else {
            // Normalise formula in the input before submitting
            if (data.normalized) {
                document.getElementById('field_formula').value = data.normalized;
            }
            document.getElementById('formula_error').style.display = 'none';
            document.getElementById('addFieldForm').submit();
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.textContent = 'Add field';
        // On network error, submit anyway
        document.getElementById('addFieldForm').submit();
    });
}

// ---- Save view to sidebar ----
function saveViewToSidebar() {
    const name = document.getElementById('saveViewName').value.trim();
    if (!name) {
        document.getElementById('saveViewError').textContent = 'Please enter a name.';
        document.getElementById('saveViewError').style.display = '';
        return;
    }
    document.getElementById('saveViewError').style.display = 'none';

    fetch('<?= BASE_URL ?>/?page=api&action=save_analytics_view', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            _csrf: csrfToken,
            view_id: <?= $viewId ?>,
            name: name,
        })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) {
            document.getElementById('saveViewError').textContent = data.error || 'Error saving view.';
            document.getElementById('saveViewError').style.display = '';
            return;
        }
        closeModal('saveViewModal');

        // Add link to sidebar
        const list = document.getElementById('sidebarFilterList');
        if (list) {
            const li = document.createElement('li');
            li.className = 'sidebar-webhook sidebar-filter-item';
            li.setAttribute('data-filter-id', data.preset_id);
            li.innerHTML = `<a href="${data.url}">${data.name}</a>
                <button class="sidebar-filter-delete" onclick="deleteFilter(${data.preset_id}, this)" title="Remove">&times;</button>`;
            // Insert before the Settings li (last item)
            const lastLi = list.querySelector('li:last-child');
            list.insertBefore(li, lastLi);
        }
        // Show confirmation
        const btn = document.querySelector('[onclick*="saveViewModal"]');
        if (btn) {
            const orig = btn.textContent;
            btn.textContent = '✓ Saved!';
            setTimeout(() => btn.textContent = orig, 2000);
        }
    })
    .catch(() => {
        document.getElementById('saveViewError').textContent = 'Network error. Please try again.';
        document.getElementById('saveViewError').style.display = '';
    });
}

</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
exit;
