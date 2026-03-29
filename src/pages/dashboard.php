<?php
$current_user = requireAuth($db);
$page_title   = __('nav.dashboard');
$userId       = (int)$current_user['id'];

// Filters from GET
$filterCategoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
$filterProjectId  = isset($_GET['project_id'])  && $_GET['project_id']  !== '' ? (int)$_GET['project_id']  : null;
$filterMethod     = isset($_GET['method']) && in_array($_GET['method'], ['GET','POST','PUT','DELETE','PATCH','HEAD','OPTIONS','ALARM','PIXEL','CRON'], true) ? $_GET['method'] : '';
$filterStatus     = isset($_GET['status']) && in_array($_GET['status'], ['validated','rejected'], true) ? $_GET['status'] : '';
$filterTime       = isset($_GET['time'])   && in_array($_GET['time'],   ['1h','24h','7d','30d'], true)  ? $_GET['time']   : '';

// Build query
$whereClauses = ['p.user_id = ?', 'p.deleted_at IS NULL', 'w.deleted_at IS NULL'];
$params       = [$userId];

if ($filterCategoryId !== null) {
    $whereClauses[] = 'p.category_id = ?';
    $params[] = $filterCategoryId;
}
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
    $seconds = match($filterTime) {
        '1h'  => 3600,
        '24h' => 86400,
        '7d'  => 604800,
        '30d' => 2592000,
        default => 0
    };
    if ($seconds > 0) {
        $whereClauses[] = 'e.received_at > ?';
        $params[] = date('Y-m-d H:i:s', time() - $seconds);
    }
}

$where = implode(' AND ', $whereClauses);
$eventsStmt = $db->prepare("
    SELECT e.*,
           p.name   AS project_name,
           p.id     AS project_id,
           p.active AS project_active,
           w.name   AS webhook_name,
           w.id     AS webhook_id,
           w.active AS webhook_active
    FROM events e
    JOIN webhooks w ON w.id = e.webhook_id
    JOIN projects p ON p.id = w.project_id
    WHERE $where
    ORDER BY e.id DESC
    LIMIT 100
");
$eventsStmt->execute($params);
$events = $eventsStmt->fetchAll();

// Get last event id for polling
$lastId = !empty($events) ? (int)$events[0]['id'] : 0;

// Load known IPs for label substitution
$kipStmt = $db->prepare('SELECT ip, label FROM known_ips WHERE user_id = ?');
$kipStmt->execute([$userId]);
$knownIpMap = [];
foreach ($kipStmt->fetchAll() as $k) { $knownIpMap[$k['ip']] = $k['label']; }

// Get categories for filter dropdown
$catStmt = $db->prepare('SELECT id, name FROM categories WHERE user_id = ? AND deleted_at IS NULL ORDER BY sort_order, name');
$catStmt->execute([$userId]);
$allCategories = $catStmt->fetchAll();

// Get projects for filter dropdown (scoped to user)
$projStmt = $db->prepare('SELECT id, name FROM projects WHERE user_id = ? AND deleted_at IS NULL ORDER BY name');
$projStmt->execute([$userId]);
$allProjects = $projStmt->fetchAll();

// Build query params for AJAX polling
$ajaxParams = array_filter([
    'page'        => 'api',
    'action'      => 'events',
    'category_id' => $filterCategoryId !== null ? $filterCategoryId : '',
    'project_id'  => $filterProjectId  !== null ? $filterProjectId  : '',
    'method'      => $filterMethod,
    'status'      => $filterStatus,
    'time'        => $filterTime,
]);
$ajaxBase = '?' . http_build_query($ajaxParams);

// Current active filter params (for save)
$activeFilterParams = array_filter([
    'category_id' => $filterCategoryId !== null ? $filterCategoryId : '',
    'project_id'  => $filterProjectId  !== null ? $filterProjectId  : '',
    'method'      => $filterMethod,
    'status'      => $filterStatus,
    'time'        => $filterTime,
]);
$hasActiveFilters = !empty(array_filter($activeFilterParams, fn($v) => $v !== ''));
?>

<div class="dashboard">
    <div class="page-header">
        <h1><?= __('dashboard.title') ?></h1>
    </div>

    <!-- Filters -->
    <button class="filters-toggle" id="filtersToggle" onclick="toggleFilters()" aria-expanded="false">
        <?= __('dashboard.filters') ?> <span id="filtersChevron">▾</span>
        <?php if ($hasActiveFilters): ?><span class="filter-active-dot"></span><?php endif; ?>
    </button>
    <form class="filters-bar" id="filtersBar" method="get" action="">
        <input type="hidden" name="page" value="dashboard">

        <?php if (!empty($allCategories)): ?>
        <select name="category_id" onchange="this.form.submit()">
            <option value=""><?= __('dashboard.filter_all_categories') ?></option>
            <?php foreach ($allCategories as $cat): ?>
            <option value="<?= $cat['id'] ?>"<?= $filterCategoryId === (int)$cat['id'] ? ' selected' : '' ?>>
                <?= e($cat['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

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
            <?php foreach (['GET','POST','PUT','DELETE','PATCH','HEAD','OPTIONS','ALARM','PIXEL','CRON'] as $m): ?>
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
            <?php if ($hasActiveFilters): ?>
            <button type="button" class="btn btn-sm btn-outline" onclick="openModal('saveFilterModal')" title="Salva filtro">
                ＋ Salva filtro
            </button>
            <?php endif; ?>
            <span class="auto-refresh-indicator" id="refreshIndicator" title="Auto-refresh every 3s">
                <span class="pulse-dot"></span> Live
            </span>
        </div>
    </form>

    <script>
    (function() {
        var bar = document.getElementById('filtersBar');
        var toggle = document.getElementById('filtersToggle');
        var chevron = document.getElementById('filtersChevron');
        var isMobile = window.innerWidth <= 768;
        if (isMobile && bar) {
            bar.classList.add('filters-bar-collapsed');
        }
        window.toggleFilters = function() {
            if (!bar) return;
            var collapsed = bar.classList.toggle('filters-bar-collapsed');
            if (toggle) toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            if (chevron) chevron.textContent = collapsed ? '▾' : '▴';
        };
    })();
    </script>

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

        <div class="table-scroll-wrapper">
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
        <div id="scrollSentinel"></div>
        <div id="scrollSpinner" style="display:none;text-align:center;padding:1.2rem 0">
            <span class="scroll-spinner"></span>
        </div>
        <div id="noMoreEvents" style="text-align:center;padding:1.2rem 0;color:var(--text-muted,#888);<?= count($events) < 100 ? '' : 'display:none' ?>">— No more logs —</div>
    </div>
</div>

<!-- Save Filter Modal -->
<div class="modal" id="saveFilterModal" style="display:none" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Salva filtro</h3>
            <button class="modal-close" onclick="closeModal('saveFilterModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p class="text-muted" style="margin-bottom:1rem;font-size:0.88rem;">
                Il filtro verrà aggiunto alla barra laterale come collegamento rapido.
            </p>
            <div class="form-group">
                <label class="form-label">Nome</label>
                <input type="text" id="saveFilterName" class="form-control" placeholder="es. Errori POST ultimi 7gg" maxlength="60" autofocus>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('saveFilterModal')">Annulla</button>
            <button type="button" class="btn btn-primary" onclick="saveFilter()">Salva</button>
        </div>
    </div>
</div>

<?php
function renderEventRow(array $event): string {
    $method    = strtolower($event['method'] ?? 'post');
    $validated = (int)$event['validated'];
    $qs        = $event['query_string'] ?? '';
    $path      = ($event['path'] ?? '/') . ($qs !== '' ? '?' . $qs : '');
    $rawIp     = $event['ip'] ?? '';
    $ip        = $GLOBALS['knownIpMap'][$rawIp] ?? $rawIp;
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

    $inactiveClass = (!($event['webhook_active'] ?? 1) || !($event['project_active'] ?? 1)) ? ' event-row-inactive' : '';

    return "<tr class=\"event-row$inactiveClass\" onclick=\"window.location='$base/?page=event&id=$id'\" data-id=\"$id\">
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
    let lastId   = <?= $lastId ?>;
    let oldestId = <?= !empty($events) ? (int)$events[array_key_last($events)]['id'] : 0 ?>;
    let infiniteScrollDone = <?= count($events) < 100 ? 'true' : 'false' ?>;
    const ajaxBase = '<?= $ajaxBase ?>';
    const knownIps = <?= json_encode($knownIpMap) ?>;
    const refreshInterval = 3000;
    let isRefreshing  = false;
    let isLoadingMore = false;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function buildEventRow(ev, isNew) {
        const tr = document.createElement('tr');
        const inactive = (!ev.webhook_active || !ev.project_active) ? ' event-row-inactive' : '';
        tr.className = 'event-row' + (isNew ? ' event-new' : '') + inactive;
        tr.setAttribute('data-id', ev.id);
        tr.onclick = () => window.location = '<?= BASE_URL ?>/?page=event&id=' + ev.id;

        const method = (ev.method || 'POST').toUpperCase();
        const methodLower = method.toLowerCase();
        const ts = ev.received_at ? new Date(ev.received_at.replace(' ', 'T')) : new Date();
        const ago = Math.floor((Date.now() - ts.getTime()) / 1000);
        let timeStr;
        if (ago < 60)        timeStr = ago + 's ago';
        else if (ago < 3600) timeStr = Math.round(ago / 60) + 'm ago';
        else if (ago < 86400) timeStr = Math.round(ago / 3600) + 'h ago';
        else                 timeStr = ts.toLocaleDateString() + ' ' + ts.toLocaleTimeString();

        const statusBadge = method === 'ALARM'
            ? '<span class="badge badge-warning">Alarm</span>'
            : (ev.validated == 1 ? '<span class="badge badge-success">Valid</span>' : '<span class="badge badge-error">Guard</span>');

        const infoCell = method === 'ALARM' ? escapeHtml(ev.body || '') : '';
        tr.innerHTML = `
            <td class="col-method"><span class="badge-method ${methodLower}">${escapeHtml(method)}</span></td>
            <td class="col-time"><span title="${escapeHtml(ev.received_at||'')}">${escapeHtml(timeStr)}</span></td>
            <td class="col-project">${escapeHtml(ev.project_name||'')}</td>
            <td class="col-webhook">${escapeHtml(ev.webhook_name||'')}</td>
            <td class="col-path mono">${escapeHtml((ev.path||'/') + (ev.query_string ? '?' + ev.query_string : ''))}</td>
            <td class="col-ip mono">${escapeHtml(knownIps[ev.ip] || ev.ip || '')}</td>
            <td class="col-status">${statusBadge}</td>
            <td class="col-info">${infoCell}</td>
        `;
        return tr;
    }

    function poll() {
        if (isRefreshing) return;
        isRefreshing = true;

        fetch(ajaxBase + '&after_id=' + lastId + '&limit=50', {headers: {'X-Requested-With': 'XMLHttpRequest'}})
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

                    newEvents.forEach(ev => {
                        const tr = buildEventRow(ev, true);
                        tbody.insertBefore(tr, tbody.firstChild);
                        setTimeout(() => tr.classList.remove('event-new'), 500);
                    });

                    lastId = Math.max(...newEvents.map(e => parseInt(e.id)), lastId);
                }
            })
            .catch(() => {})
            .finally(() => { isRefreshing = false; });
    }

    function showNoMore() {
        const noMore = document.getElementById('noMoreEvents');
        if (noMore) noMore.style.display = 'block';
    }

    function loadMore() {
        if (infiniteScrollDone || isLoadingMore || oldestId === 0) return;
        isLoadingMore = true;

        const spinner = document.getElementById('scrollSpinner');
        if (spinner) spinner.style.display = 'block';

        setTimeout(function() {
            fetch(ajaxBase + '&before_id=' + oldestId + '&limit=100', {headers: {'X-Requested-With': 'XMLHttpRequest'}})
                .then(r => r.json())
                .then(data => {
                    if (data.error) return;
                    const moreEvents = Array.isArray(data) ? data : (data.events || []);

                    if (moreEvents.length > 0) {
                        const tbody = document.getElementById('eventsBody');
                        const table = document.getElementById('eventsTable');
                        if (table) table.classList.remove('hidden');
                        moreEvents.forEach(ev => tbody.appendChild(buildEventRow(ev, false)));
                        oldestId = Math.min(...moreEvents.map(e => parseInt(e.id)));
                    }

                    if (moreEvents.length < 100) {
                        infiniteScrollDone = true;
                        showNoMore();
                        observer.disconnect();
                    }
                })
                .catch(() => {})
                .finally(() => {
                    if (spinner) spinner.style.display = 'none';
                    isLoadingMore = false;
                });
        }, 2000);
    }

    setInterval(poll, refreshInterval);

    // Infinite scroll
    const sentinel = document.getElementById('scrollSentinel');
    let observer;
    if (infiniteScrollDone) {
        showNoMore();
    } else if (sentinel) {
        observer = new IntersectionObserver(entries => {
            if (entries[0].isIntersecting) loadMore();
        }, {rootMargin: '200px'});
        observer.observe(sentinel);
    }
})();

// Save filter
const filterParams = <?= json_encode($activeFilterParams) ?>;

function saveFilter() {
    const name = document.getElementById('saveFilterName').value.trim();
    if (!name) { document.getElementById('saveFilterName').focus(); return; }

    fetch('<?= BASE_URL ?>/?page=api&action=save_filter', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            _csrf: csrfToken,
            name: name,
            params: JSON.stringify(filterParams),
        }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            closeModal('saveFilterModal');
            // Add to sidebar without reload
            addFilterToSidebar(data.id, name, data.url);
        }
    })
    .catch(() => {});
}

function addFilterToSidebar(id, name, url) {
    const list = document.getElementById('sidebarFilterList');
    if (!list) return;
    const li = document.createElement('li');
    li.className = 'sidebar-webhook sidebar-filter-item';
    li.setAttribute('data-filter-id', id);
    li.innerHTML = `<a href="${escSidebar(url)}">${escSidebar(name)}</a><button class="sidebar-filter-delete" onclick="deleteFilter(${id}, this)" title="Rimuovi">&times;</button>`;
    // Insert before Settings (last li)
    const items = list.querySelectorAll('li');
    const settingsLi = items[items.length - 1];
    list.insertBefore(li, settingsLi);
}

function escSidebar(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

</script>
