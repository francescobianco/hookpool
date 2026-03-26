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
        $afterId       = isset($_GET['after_id']) ? (int)$_GET['after_id'] : 0;
        $filterCatId   = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
        $filterProjId  = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : null;
        $filterWebhookId = isset($_GET['webhook_id']) && $_GET['webhook_id'] !== '' ? (int)$_GET['webhook_id'] : null;
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
        $stmt = $db->prepare("
            SELECT e.id, e.webhook_id, e.method, e.path, e.ip, e.received_at, e.validated, e.rejection_reason, e.body,
                   p.name AS project_name, p.id AS project_id,
                   w.name AS webhook_name
            FROM events e
            JOIN webhooks w ON w.id = e.webhook_id
            JOIN projects p ON p.id = w.project_id
            WHERE $where
            ORDER BY e.id DESC
            LIMIT ?
        ");
        $params[] = $limit;
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

    default:
        http_response_code(404);
        echo json_encode(['error' => 'not_found']) . "\n";
}
exit;
