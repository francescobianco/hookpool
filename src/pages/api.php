<?php
header('Content-Type: application/json');

// All API endpoints require authentication
$user = getCurrentUser($db);
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']) . "\n";
    exit;
}

$userId = (int)$user['id'];
$action = $_GET['action'] ?? '';

switch ($action) {

    // --- GET EVENTS (for dashboard auto-refresh polling) ---
    case 'events':
        $afterId       = isset($_GET['after_id'])  ? (int)$_GET['after_id']  : 0;
        $beforeId      = isset($_GET['before_id']) ? (int)$_GET['before_id'] : 0;
        // scope param: c_N = category, p_N = project, w_N = webhook
        // also accept legacy category_id / project_id / webhook_id for saved presets
        $filterCatId     = null;
        $filterProjId    = null;
        $filterWebhookId = null;
        $scopeP = trim($_GET['scope'] ?? '');
        if ($scopeP !== '') {
            if (str_starts_with($scopeP, 'c_'))     $filterCatId     = (int)substr($scopeP, 2);
            elseif (str_starts_with($scopeP, 'p_')) $filterProjId    = (int)substr($scopeP, 2);
            elseif (str_starts_with($scopeP, 'w_')) $filterWebhookId = (int)substr($scopeP, 2);
        } else {
            if (isset($_GET['category_id']) && $_GET['category_id'] !== '') $filterCatId     = (int)$_GET['category_id'];
            if (isset($_GET['project_id'])  && $_GET['project_id']  !== '') $filterProjId    = (int)$_GET['project_id'];
            if (isset($_GET['webhook_id'])  && $_GET['webhook_id']  !== '') $filterWebhookId = (int)$_GET['webhook_id'];
        }
        $filterMethod  = isset($_GET['method']) && in_array($_GET['method'], ['GET','POST','PUT','DELETE','PATCH','HEAD','OPTIONS','ALARM','PIXEL','FILE'], true) ? $_GET['method'] : '';
        $filterStatus  = isset($_GET['status']) && in_array($_GET['status'], ['validated','rejected'], true) ? $_GET['status'] : '';
        $filterTime    = isset($_GET['time']) && in_array($_GET['time'], ['1h','24h','7d','30d'], true) ? $_GET['time'] : '';
        $limit         = min(100, max(1, (int)($_GET['limit'] ?? 50)));

        $whereClauses = ['p.user_id = ?', 'p.deleted_at IS NULL', 'w.deleted_at IS NULL'];
        $params       = [$userId];

        if ($afterId > 0) {
            $whereClauses[] = 'e.id > ?';
            $params[] = $afterId;
        }
        if ($beforeId > 0) {
            $whereClauses[] = 'e.id < ?';
            $params[] = $beforeId;
        }
        if ($filterCatId !== null) {
            $whereClauses[] = 'p.category_id = ?';
            $params[] = $filterCatId;
        }
        if ($filterProjId !== null) {
            $whereClauses[] = 'p.id = ?';
            $params[] = $filterProjId;
        }
        if ($filterWebhookId !== null) {
            $whereClauses[] = 'w.id = ?';
            $params[] = $filterWebhookId;
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
        $stmt = $db->prepare("
            SELECT e.id, e.webhook_id, e.method, e.path, e.query_string, e.ip, e.received_at, e.validated, e.rejection_reason, e.body,
                   p.name AS project_name, p.id AS project_id, p.active AS project_active,
                   w.name AS webhook_name, w.active AS webhook_active
            FROM events e
            JOIN webhooks w ON w.id = e.webhook_id
            JOIN projects p ON p.id = w.project_id
            WHERE $where
            ORDER BY e.id DESC
            LIMIT $limit
        ");
        $stmt->execute($params);
        $events = $stmt->fetchAll();

        echo json_encode($events) . "\n";
        break;

    // --- GET EVENT DETAIL ---
    case 'event_detail':
        $eventId = (int)($_GET['id'] ?? 0);

        $evtStmt = $db->prepare('
            SELECT e.*,
                   w.name AS webhook_name, w.id AS webhook_id,
                   p.name AS project_name
            FROM events e
            JOIN webhooks w ON w.id = e.webhook_id
            JOIN projects p ON p.id = w.project_id
            WHERE e.id = ? AND p.user_id = ? AND p.deleted_at IS NULL
        ');
        $evtStmt->execute([$eventId, $userId]);
        $event = $evtStmt->fetch();

        if (!$event) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found']) . "\n";
            break;
        }

        // Load forward attempts
        $attStmt = $db->prepare('
            SELECT fa2.*, fa.name AS action_name, fa.url AS action_url
            FROM forward_attempts fa2
            JOIN forward_actions fa ON fa.id = fa2.forward_action_id
            WHERE fa2.event_id = ?
            ORDER BY fa2.executed_at
        ');
        $attStmt->execute([$eventId]);
        $event['forward_attempts'] = $attStmt->fetchAll();
        $event['headers_decoded']  = json_decode($event['headers'] ?? '{}', true) ?: [];

        echo json_encode($event) . "\n";
        break;

    // --- TOGGLE WEBHOOK STATUS (POST) ---
    case 'toggle_webhook':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'method_not_allowed']) . "\n";
            break;
        }

        $webhookId = (int)($_GET['id'] ?? 0);

        // Verify ownership
        $whStmt = $db->prepare('
            SELECT w.id, w.active FROM webhooks w
            JOIN projects p ON p.id = w.project_id
            WHERE w.id = ? AND p.user_id = ? AND w.deleted_at IS NULL AND p.deleted_at IS NULL
        ');
        $whStmt->execute([$webhookId, $userId]);
        $wh = $whStmt->fetch();

        if (!$wh) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found']) . "\n";
            break;
        }

        $newActive = $wh['active'] ? 0 : 1;
        $db->prepare('UPDATE webhooks SET active = ? WHERE id = ?')->execute([$newActive, $webhookId]);
        echo json_encode(['ok' => true, 'active' => $newActive]) . "\n";
        break;

    // --- SAVE FILTER PRESET (POST) ---
    case 'save_filter':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'method_not_allowed']); break; }
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'csrf']); break; }

        $name   = trim($_POST['name'] ?? '');
        $params = $_POST['params'] ?? '{}';
        if ($name === '') { http_response_code(400); echo json_encode(['error' => 'name_required']); break; }

        // Validate params is valid JSON
        $decoded = json_decode($params, true);
        if (!is_array($decoded)) { $params = '{}'; $decoded = []; }

        // Build the dashboard URL from params
        $qp = array_filter($decoded, fn($v) => $v !== '' && $v !== null);
        $qp['page'] = 'dashboard';
        $url = BASE_URL . '/?' . http_build_query($qp);

        $ins = $db->prepare('INSERT INTO filter_presets (user_id, name, params, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)');
        $ins->execute([$userId, $name, $params]);
        $newId = (int)$db->lastInsertId();

        echo json_encode(['ok' => true, 'id' => $newId, 'name' => $name, 'url' => $url]) . "\n";
        break;

    // --- DELETE FILTER PRESET (POST) ---
    case 'delete_filter':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'method_not_allowed']); break; }
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'csrf']); break; }

        $presetId = (int)($_POST['id'] ?? 0);
        $del = $db->prepare('UPDATE filter_presets SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?');
        $del->execute([$presetId, $userId]);

        echo json_encode(['ok' => true]) . "\n";
        break;

    // --- DOWNLOAD ATTACHED FILE ---
    case 'download_file':
        $fileId = (int)($_GET['id'] ?? 0);
        // Verify ownership through event → webhook → project → user
        $fStmt = $db->prepare('
            SELECT ef.*, e.webhook_id
            FROM event_files ef
            JOIN events e ON e.id = ef.event_id
            JOIN webhooks w ON w.id = e.webhook_id
            JOIN projects p ON p.id = w.project_id
            WHERE ef.id = ? AND p.user_id = ? AND p.deleted_at IS NULL AND w.deleted_at IS NULL
        ');
        $fStmt->execute([$fileId, $userId]);
        $fileRow = $fStmt->fetch();
        if (!$fileRow || !file_exists($fileRow['storage_path'])) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'not_found']) . "\n";
            break;
        }
        $mime = $fileRow['mime_type'] ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . addslashes($fileRow['filename']) . '"');
        header('Content-Length: ' . $fileRow['size']);
        header('Cache-Control: no-store');
        readfile($fileRow['storage_path']);
        exit;

    case 'reorder_categories':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);

        $csrfIn = $payload['_csrf'] ?? '';
        if (!verifyCsrfToken($csrfIn)) {
            http_response_code(403);
            echo json_encode(['error' => 'invalid_csrf']) . "\n";
            break;
        }

        $order = $payload['order'] ?? [];
        if (!is_array($order)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_order']) . "\n";
            break;
        }

        $upd = $db->prepare('UPDATE categories SET sort_order = ? WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
        foreach ($order as $pos => $catId) {
            $upd->execute([(int)$pos, (int)$catId, $userId]);
        }
        echo json_encode(['ok' => true]) . "\n";
        break;

    case 'test_forward':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!$payload) { $payload = $_POST; }

        // CSRF check
        $csrfIn = $payload['_csrf'] ?? '';
        if (!verifyCsrfToken($csrfIn)) {
            http_response_code(403);
            echo json_encode(['error' => 'invalid_csrf']) . "\n";
            break;
        }

        $forwardId = (int)($payload['forward_id'] ?? 0);
        if ($forwardId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'missing forward_id']) . "\n";
            break;
        }

        // Load action and verify ownership
        $faStmt = $db->prepare('
            SELECT fa.*
            FROM forward_actions fa
            JOIN webhooks w ON w.id = fa.webhook_id
            JOIN projects p ON p.id = w.project_id
            WHERE fa.id = ? AND p.user_id = ? AND fa.deleted_at IS NULL AND w.deleted_at IS NULL AND p.deleted_at IS NULL
        ');
        $faStmt->execute([$forwardId, $userId]);
        $fa = $faStmt->fetch();
        if (!$fa) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found']) . "\n";
            break;
        }

        // Build body from template, substituting all {{placeholder}} values
        $bodyTemplate = $fa['body_template'] ?? '';
        $placeholders = $payload['placeholders'] ?? [];
        if ($bodyTemplate !== '') {
            $requestBody = $bodyTemplate;
            foreach ($placeholders as $ph => $val) {
                $requestBody = str_replace('{{' . $ph . '}}', $val, $requestBody);
            }
        } else {
            $requestBody = '';
        }

        // Parse custom headers
        $customHeaders = ['User-Agent' => 'Hookpool/1.0'];
        $parsedCustom = json_decode($fa['custom_headers'] ?? '{}', true);
        if (is_array($parsedCustom)) {
            $customHeaders = array_merge($customHeaders, $parsedCustom);
        }

        $targetUrl    = $fa['url'];
        $targetMethod = strtoupper($fa['method'] ?? 'POST');
        $timeout      = max(1, min(60, (int)($fa['timeout'] ?? 10)));

        // Execute request
        $responseStatus = 0;
        $responseBody   = '';
        $error          = null;

        $ch = curl_init($targetUrl);
        if ($ch === false) {
            echo json_encode(['error' => 'curl_init failed']) . "\n";
            break;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $curlHeaders = [];
        foreach ($customHeaders as $k => $v) {
            $curlHeaders[] = "$k: $v";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

        switch ($targetMethod) {
            case 'GET':
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
                break;
            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $targetMethod);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        }

        $responseBody   = curl_exec($ch);
        $responseStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError      = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            echo json_encode(['error' => $curlError ?: 'curl error', 'status' => 0]) . "\n";
            break;
        }

        echo json_encode([
            'status' => $responseStatus,
            'body'   => mb_substr($responseBody, 0, 10000),
        ]) . "\n";
        break;

    // --- VALIDATE DSL FORMULA ---
    case 'validate_formula':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'method_not_allowed']); break; }
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'csrf']); break; }

        require_once __DIR__ . '/../classes/DslEvaluator.php';
        $formula   = trim($_POST['formula'] ?? '');
        $error     = DslEvaluator::validate($formula);
        $normalized = $error === null ? DslEvaluator::normalize($formula) : null;
        echo json_encode(['ok' => $error === null, 'error' => $error, 'normalized' => $normalized]) . "\n";
        break;

    // --- SAVE ANALYTICS VIEW TO SIDEBAR ---
    case 'save_analytics_view':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'method_not_allowed']); break; }
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'csrf']); break; }

        $viewId  = (int)($_POST['view_id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $mode    = $_POST['mode'] ?? 'new'; // 'new' or 'update'
        if ($name === '') { http_response_code(400); echo json_encode(['error' => 'name_required']); break; }

        // Verify ownership of the analytics view
        $avStmt = $db->prepare('SELECT * FROM analytics_views WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
        $avStmt->execute([$viewId, $userId]);
        $av = $avStmt->fetch();
        if (!$av) { http_response_code(404); echo json_encode(['error' => 'view_not_found']); break; }

        if ($mode === 'update' && $av['name'] !== null) {
            // Update existing named view: rename it and update its filter_preset
            $savedViewId = $viewId;
            $db->prepare('UPDATE analytics_views SET name = ? WHERE id = ?')->execute([$name, $viewId]);

            // Find and update the existing filter_preset for this view
            $fpStmt = $db->prepare("SELECT id FROM filter_presets WHERE user_id = ? AND params LIKE ? AND deleted_at IS NULL");
            $fpStmt->execute([$userId, '%"view_id":' . $viewId . '%']);
            $fp = $fpStmt->fetch();
            if ($fp) {
                $db->prepare('UPDATE filter_presets SET name = ?, params = ? WHERE id = ?')
                   ->execute([$name, json_encode(['page' => 'analytics', 'view_id' => $savedViewId]), $fp['id']]);
                $presetId = (int)$fp['id'];
            } else {
                // No preset yet — create one
                $ins = $db->prepare('INSERT INTO filter_presets (user_id, name, params, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)');
                $ins->execute([$userId, $name, json_encode(['page' => 'analytics', 'view_id' => $savedViewId])]);
                $presetId = (int)$db->lastInsertId();
            }
        } elseif ($av['name'] === null) {
            // Name the working (unsaved) view for the first time
            $db->prepare('UPDATE analytics_views SET name = ? WHERE id = ?')->execute([$name, $viewId]);
            $savedViewId = $viewId;

            $ins = $db->prepare('INSERT INTO filter_presets (user_id, name, params, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)');
            $ins->execute([$userId, $name, json_encode(['page' => 'analytics', 'view_id' => $savedViewId])]);
            $presetId = (int)$db->lastInsertId();
        } else {
            // mode=new on an already-named view: create a new named copy
            $db->prepare('
                INSERT INTO analytics_views (user_id, webhook_id, name, fields, groupby, sort_by, sort_dir)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ')->execute([$userId, $av['webhook_id'], $name, $av['fields'], $av['groupby'], $av['sort_by'], $av['sort_dir']]);
            $savedViewId = (int)$db->lastInsertId();

            $ins = $db->prepare('INSERT INTO filter_presets (user_id, name, params, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)');
            $ins->execute([$userId, $name, json_encode(['page' => 'analytics', 'view_id' => $savedViewId])]);
            $presetId = (int)$db->lastInsertId();
        }

        $url = BASE_URL . '/?page=analytics&view_id=' . $savedViewId;
        echo json_encode(['ok' => true, 'id' => $savedViewId, 'preset_id' => $presetId, 'name' => $name, 'url' => $url]) . "\n";
        break;

    // --- SAVE CONTROL PANEL WIDGET (POST) ---
    case 'save_cp_widget':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'method_not_allowed']); break; }
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'csrf']); break; }

        $widgetId = (int)($_POST['id'] ?? 0);
        $type     = trim($_POST['type'] ?? '');
        $title    = trim($_POST['title'] ?? '');
        $width    = max(1, min(2, (int)($_POST['width'] ?? 1)));
        $configRaw = $_POST['config'] ?? '{}';

        $allowed = ['button', 'updown', 'dpad', 'send'];
        if (!in_array($type, $allowed, true)) { http_response_code(400); echo json_encode(['error' => 'invalid_type']); break; }

        $configDecoded = json_decode($configRaw, true);
        if (!is_array($configDecoded)) $configDecoded = [];
        $configJson = json_encode($configDecoded);

        if ($widgetId > 0) {
            // Update — verify ownership
            $ownStmt = $db->prepare('SELECT id FROM control_panel_widgets WHERE id = ? AND user_id = ?');
            $ownStmt->execute([$widgetId, $userId]);
            if (!$ownStmt->fetch()) { http_response_code(404); echo json_encode(['error' => 'not_found']); break; }

            $db->prepare('UPDATE control_panel_widgets SET type = ?, title = ?, width = ?, config = ? WHERE id = ? AND user_id = ?')
               ->execute([$type, $title, $width, $configJson, $widgetId, $userId]);
            echo json_encode(['ok' => true, 'id' => $widgetId]) . "\n";
        } else {
            // Insert: sort_order = max + 1
            $maxStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM control_panel_widgets WHERE user_id = ?');
            $maxStmt->execute([$userId]);
            $sortOrder = (int)$maxStmt->fetchColumn() + 1;

            $db->prepare('INSERT INTO control_panel_widgets (user_id, sort_order, type, title, width, config) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([$userId, $sortOrder, $type, $title, $width, $configJson]);
            $newId = (int)$db->lastInsertId();
            echo json_encode(['ok' => true, 'id' => $newId]) . "\n";
        }
        break;

    // --- EXECUTE CONTROL PANEL ACTION (POST, server-side HTTP) ---
    case 'execute_cp_action':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'method_not_allowed']); break; }
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'csrf']); break; }

        $targetUrl    = trim((string)($_POST['url'] ?? ''));
        $targetMethod = strtoupper(trim((string)($_POST['method'] ?? 'GET')));
        $requestBody  = (string)($_POST['body'] ?? '');
        $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];

        if ($targetUrl === '' || !filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_url']) . "\n";
            break;
        }
        if (!in_array($targetMethod, $allowedMethods, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_method']) . "\n";
            break;
        }

        $ch = curl_init($targetUrl);
        if ($ch === false) {
            http_response_code(500);
            echo json_encode(['error' => 'curl_init_failed']) . "\n";
            break;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Hookpool-Control-Panel/1.0']);

        switch ($targetMethod) {
            case 'GET':
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                break;
            case 'HEAD':
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($requestBody !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
                break;
            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $targetMethod);
                if ($requestBody !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
                break;
        }

        $responseBody   = curl_exec($ch);
        $responseStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError      = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            http_response_code(502);
            echo json_encode(['error' => $curlError ?: 'curl_error', 'status' => 0]) . "\n";
            break;
        }

        echo json_encode([
            'ok' => $responseStatus >= 200 && $responseStatus < 300,
            'status' => $responseStatus,
            'body' => mb_substr((string)$responseBody, 0, 2000),
        ]) . "\n";
        break;

    // --- DELETE CONTROL PANEL WIDGET (POST) ---
    case 'delete_cp_widget':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'method_not_allowed']); break; }
        if (!verifyCsrfToken($_POST['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'csrf']); break; }

        $widgetId = (int)($_POST['id'] ?? 0);
        $del = $db->prepare('DELETE FROM control_panel_widgets WHERE id = ? AND user_id = ?');
        $del->execute([$widgetId, $userId]);
        echo json_encode(['ok' => true]) . "\n";
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'not_found']) . "\n";
}
exit;
