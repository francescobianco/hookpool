<?php
$current_user = requireAuth($db);
$page_title   = __('nav.dashboard');
$userId       = (int)$current_user['id'];

// Filters from GET
$filterProjectId = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : null;
$filterMethod    = isset($_GET['method']) && in_array($_GET['method'], ['GET','POST','PUT','DELETE','PATCH','HEAD','OPTIONS','ALARM'], true) ? $_GET['method'] : '';
$filterStatus    = isset($_GET['status']) && in_array($_GET['status'], ['validated','rejected'], true) ? $_GET['status'] : '';
$filterTime      = isset($_GET['time']) && in_array($_GET['time'], ['1h','24h','7d','30d'], true) ? $_GET['time'] : '';

// Build query
$whereClauses = ['p.user_id = ?', 'p.deleted_at IS NULL', 'w.deleted_at IS NULL'];
$params       = [$userId];

if ($filterProjectId !== null) {
    $whereClauses[] = 'p.id = ?';
    $params[] = $filterProjectId;
}
if ($filterMethod !== '') {
    $whereClauses[] = 'e.method = ?';
    $params[] = $filterMethod;
}
if ($filterStatus === 'validated') {
    $whereClauses[] = 'e.validated = 1';
} elseif ($filterStatus === 'rejected') {
    $whereClauses[] = 'e.validated = 0';
}
if ($filterTime !== '') {
    $interval = match($filterTime) {
        '1h'  => '-1 hour',
        '24h' => '-24 hours',
        '7d'  => '-7 days',
        '30d' => '-30 days',
        default => ''
    };
    if ($interval) {
        $whereClauses[] = "e.received_at > datetime('now', '$interval')";
    }
}

$where = implode(' AND ', $whereClauses);
$eventsStmt = $db->prepare("
    SELECT e.*,
           p.name AS project_name,
           p.id   AS project_id,
           w.name AS webhook_name,
           w.id   AS webhook_id
    FROM events e
    JOIN webhooks w ON w.id = e.webhook_id
    JOIN projects p ON p.id = w.project_id
    WHERE $where
    ORDER BY e.received_at DESC
    LIMIT 100
");
$eventsStmt->execute($params);
$events = $eventsStmt->fetchAll();

// Get last event id for polling
$lastId = !empty($events) ? (int)$events[0]['id'] : 0;

// Get projects for filter dropdown (scoped to user)
$projStmt = $db->prepare('SELECT id, name FROM projects WHERE user_id = ? AND deleted_at IS NULL ORDER BY name');
$projStmt->execute([$userId]);
$allProjects = $projStmt->fetchAll();

// Build query params for AJAX polling
$ajaxParams = array_filter([
    'page'       => 'api',
    'action'     => 'events',
    'project_id' => $filterProjectId !== null ? $filterProjectId : '',
    'method'     => $filterMethod,
    'status'     => $filterStatus,
    'time'       => $filterTime,
]);
$ajaxBase = '?' . http_build_query($ajaxParams);
?>

<div class="dashboard">
    <div class="page-header">
        <h1><?= __('dashboard.title') ?></h1>
    </div>

    <!-- Filters -->
    <form class="filters-bar" method="get" action="">
        <input type="hidden" name="page" value="dashboard">

        <select name="project_id" onchange="this.form.submit()">
            <option value=""><?= __('dashboard.filter_project') ?></option>
            <?php foreach ($allProjects as $proj): ?>
            <option value="<?= $proj['id'] ?>"<?= $filterProjectId === (int)$proj['id'] ? ' selected' : '' ?>>
                <?= e($proj['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <select name="method" onchange="this.form.submit()">
            <option value=""><?= __('dashboard.filter_method') ?></option>
            <?php foreach (['GET','POST','PUT','DELETE','PATCH','HEAD','OPTIONS','ALARM'] as $m): ?>
            <option value="<?= $m ?>"<?= $filterMethod === $m ? ' selected' : '' ?>><?= $m ?></option>
            <?php endforeach; ?>
        </select>

        <select name="status" onchange="this.form.submit()">
            <option value=""><?= __('dashboard.filter_status') ?></option>
            <option value="validated"<?= $filterStatus === 'validated' ? ' selected' : '' ?>><?= __('dashboard.status_validated') ?></option>
            <option value="rejected"<?= $filterStatus === 'rejected' ? ' selected' : '' ?>><?= __('dashboard.status_rejected') ?></option>
        </select>

        <select name="time" onchange="this.form.submit()">
            <option value=""><?= __('dashboard.filter_time') ?></option>
            <option value="1h"<?= $filterTime === '1h' ? ' selected' : '' ?>>Last hour</option>
            <option value="24h"<?= $filterTime === '24h' ? ' selected' : '' ?>>Last 24h</option>
            <option value="7d"<?= $filterTime === '7d' ? ' selected' : '' ?>>Last 7 days</option>
            <option value="30d"<?= $filterTime === '30d' ? ' selected' : '' ?>>Last 30 days</option>
        </select>

        <div class="filter-actions">
            <a href="<?= BASE_URL ?>/?page=dashboard" class="btn btn-sm btn-outline">Reset</a>
            <span class="auto-refresh-indicator" id="refreshIndicator" title="Auto-refresh every 3s">
                <span class="pulse-dot"></span> Live
            </span>
        </div>
    </form>

    <!-- Event Table -->
    <div class="events-container">
        <?php if (empty($events)): ?>
        <div class="empty-state" id="emptyState">
            <div class="empty-icon">📭</div>
            <h3><?= __('dashboard.no_events') ?></h3>
            <p class="text-muted">Send a request to one of your webhook endpoints to get started.</p>
            <?php if (empty($allProjects)): ?>
            <p style="margin-top:1rem"><a href="<?= BASE_URL ?>/?page=project&action=create" class="btn btn-primary">Create your first project</a></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <table class="events-table<?= empty($events) ? ' hidden' : '' ?>" id="eventsTable">
            <thead>
                <tr>
                    <th class="col-method"><?= __('event.method') ?></th>
                    <th class="col-time"><?= __('event.received_at') ?></th>
                    <th class="col-project">Project</th>
                    <th class="col-webhook">Webhook</th>
                    <th class="col-path"><?= __('event.path') ?></th>
                    <th class="col-ip"><?= __('event.ip') ?></th>
                    <th class="col-status">Status</th>
                    <th class="col-info">Info</th>
                </tr>
            </thead>
            <tbody id="eventsBody">
                <?php foreach ($events as $event): ?>
                <?= renderEventRow($event) ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
function renderEventRow(array $event): string {
    $method    = strtolower($event['method'] ?? 'post');
    $validated = (int)$event['validated'];
    $path      = $event['path'] ?? '/';
    $ip        = $event['ip'] ?? '';
    $time      = $event['received_at'] ?? '';
    $projectName = $event['project_name'] ?? '';
    $webhookName = $event['webhook_name'] ?? '';
    $id        = (int)$event['id'];
    $base      = BASE_URL;

    $isAlarm = strtoupper($event['method'] ?? '') === 'ALARM';
    $statusBadge = $isAlarm
        ? '<span class="badge badge-warning">Alarm</span>'
        : ($validated ? '<span class="badge badge-success">Valid</span>' : '<span class="badge badge-error">Guard</span>');

    $methodUpper = strtoupper($event['method'] ?? 'POST');

    // Format time as relative
    $ts = strtotime($time);
    $ago = time() - $ts;
    if ($ago < 60) {
        $timeDisplay = $ago . 's ago';
    } elseif ($ago < 3600) {
        $timeDisplay = round($ago / 60) . 'm ago';
    } elseif ($ago < 86400) {
        $timeDisplay = round($ago / 3600) . 'h ago';
    } else {
        $timeDisplay = date('Y-m-d H:i', $ts);
    }

    $ePath       = htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
    $eIp         = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
    $ePrj        = htmlspecialchars($projectName, ENT_QUOTES, 'UTF-8');
    $eWh         = htmlspecialchars($webhookName, ENT_QUOTES, 'UTF-8');
    $eTime       = htmlspecialchars($timeDisplay, ENT_QUOTES, 'UTF-8');
    $eTimeTitle  = htmlspecialchars($time, ENT_QUOTES, 'UTF-8');
    $eInfo       = $isAlarm ? htmlspecialchars($event['body'] ?? '', ENT_QUOTES, 'UTF-8') : '';

    return "<tr class=\"event-row\" onclick=\"window.location='$base/?page=event&id=$id'\" data-id=\"$id\">
        <td class=\"col-method\"><span class=\"badge-method $method\">$methodUpper</span></td>
        <td class=\"col-time\"><span title=\"$eTimeTitle\">$eTime</span></td>
        <td class=\"col-project\">$ePrj</td>
        <td class=\"col-webhook\">$eWh</td>
        <td class=\"col-path mono\">$ePath</td>
        <td class=\"col-ip mono\">$eIp</td>
        <td class=\"col-status\">$statusBadge</td>
        <td class=\"col-info\">$eInfo</td>
    </tr>\n";
}
?>

<script>
(function() {
    let lastId = <?= $lastId ?>;
    const ajaxBase = '<?= $ajaxBase ?>';
    const refreshInterval = 3000;
    let isRefreshing = false;

    function poll() {
        if (isRefreshing) return;
        isRefreshing = true;

        const url = ajaxBase + '&after_id=' + lastId + '&limit=50';
        fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(r => r.json())
            .then(data => {
                if (data.error) return;
                const newEvents = Array.isArray(data) ? data : (data.events || []);
                if (newEvents.length > 0) {
                    const tbody = document.getElementById('eventsBody');
                    const table = document.getElementById('eventsTable');
                    const empty = document.getElementById('emptyState');

                    if (empty) empty.classList.add('hidden');
                    if (table) table.classList.remove('hidden');

                    // Prepend new rows
                    newEvents.forEach(ev => {
                        const tr = document.createElement('tr');
                        tr.className = 'event-row event-new';
                        tr.setAttribute('data-id', ev.id);
                        tr.onclick = () => window.location = '<?= BASE_URL ?>/?page=event&id=' + ev.id;

                        const method = (ev.method || 'POST').toUpperCase();
                        const methodLower = method.toLowerCase();
                        const ts = ev.received_at ? new Date(ev.received_at.replace(' ', 'T')) : new Date();
                        const ago = Math.floor((Date.now() - ts.getTime()) / 1000);
                        let timeStr;
                        if (ago < 60) timeStr = ago + 's ago';
                        else if (ago < 3600) timeStr = Math.round(ago/60) + 'm ago';
                        else timeStr = ts.toLocaleTimeString();

                        const statusBadge = method === 'ALARM'
                            ? '<span class="badge badge-warning">Alarm</span>'
                            : (ev.validated == 1 ? '<span class="badge badge-success">Valid</span>' : '<span class="badge badge-error">Guard</span>');

                        const infoCell = method === 'ALARM' ? escapeHtml(ev.body || '') : '';
                        tr.innerHTML = `
                            <td class="col-method"><span class="badge-method ${methodLower}">${escapeHtml(method)}</span></td>
                            <td class="col-time"><span title="${escapeHtml(ev.received_at||'')}">${escapeHtml(timeStr)}</span></td>
                            <td class="col-project">${escapeHtml(ev.project_name||'')}</td>
                            <td class="col-webhook">${escapeHtml(ev.webhook_name||'')}</td>
                            <td class="col-path mono">${escapeHtml(ev.path||'/')}</td>
                            <td class="col-ip mono">${escapeHtml(ev.ip||'')}</td>
                            <td class="col-status">${statusBadge}</td>
                            <td class="col-info">${infoCell}</td>
                        `;

                        tbody.insertBefore(tr, tbody.firstChild);

                        // Animate in
                        setTimeout(() => tr.classList.remove('event-new'), 500);
                    });

                    // Update lastId to the highest id
                    lastId = Math.max(...newEvents.map(e => parseInt(e.id)), lastId);

                    // Trim to 100 rows
                    const rows = tbody.querySelectorAll('tr');
                    if (rows.length > 100) {
                        for (let i = 100; i < rows.length; i++) rows[i].remove();
                    }
                }
            })
            .catch(() => {})
            .finally(() => { isRefreshing = false; });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    setInterval(poll, refreshInterval);
})();
</script>
