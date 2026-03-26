<?php
// No session needed — this is a public endpoint
require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/utils.php';
require __DIR__ . '/../src/mail.php';

$token = $_GET['token'] ?? '';
$projectSlug = trim((string)($_GET['project'] ?? ''));
if (!$token) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']) . "\n";
    exit;
}

$db = Database::get();

if ($projectSlug !== '') {
    $stmt = $db->prepare('
        SELECT w.*, p.active as project_active, p.user_id, p.id as pid, p.slug as project_slug
        FROM webhooks w
        JOIN projects p ON p.id = w.project_id
        WHERE p.slug = ? AND w.token = ? AND w.deleted_at IS NULL AND p.deleted_at IS NULL
    ');
    $stmt->execute([$projectSlug, $token]);
} else {
    // Legacy compatibility for /hook/<token>
    $stmt = $db->prepare('
        SELECT w.*, p.active as project_active, p.user_id, p.id as pid, p.slug as project_slug
        FROM webhooks w
        JOIN projects p ON p.id = w.project_id
        WHERE w.token = ? AND w.deleted_at IS NULL AND p.deleted_at IS NULL
    ');
    $stmt->execute([$token]);
}
$webhook = $stmt->fetch();

if (!$webhook) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Webhook not found']) . "\n";
    exit;
}

if (!$webhook['active'] || !$webhook['project_active']) {
    http_response_code(410);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Webhook is disabled']) . "\n";
    exit;
}

// Check if webhook is paused
$pausedUntil = $webhook['paused_until'] ?? null;
if ($pausedUntil && strtotime($pausedUntil) > time()) {
    http_response_code(410);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Webhook is paused']) . "\n";
    exit;
}

// ── Pixel tracking mode ──────────────────────────────────────────────────────
$isPixelRequest = isset($_GET['_pixel']);
if ($isPixelRequest) {
    if (($webhook['special_function'] ?? '') !== 'pixel') {
        http_response_code(404);
        exit;
    }
    // Log a minimal GET event for the pixel hit
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = str_replace('_', '-', substr($k, 5));
            if (empty(IGNORED_HEADERS) || !in_array(strtoupper($name), IGNORED_HEADERS, true)) {
                $headers[$name] = $v;
            }
        }
    }
    $pixelPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
    $pixelQs   = $_SERVER['QUERY_STRING'] ?? '';
    $pixelQp   = $_GET;
    unset($pixelQp['token'], $pixelQp['project'], $pixelQp['_pixel']);
    $db->prepare('INSERT INTO events (webhook_id, method, path, query_string, headers, body, content_type, ip, validated) VALUES (?,?,?,?,?,?,?,?,1)')
       ->execute([$webhook['id'], 'PIXEL', $pixelPath, http_build_query($pixelQp), json_encode($headers), '', 'image/png', $ip]);

    // Serve 1×1 transparent PNG
    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    $px = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    echo $px;
    exit;
}

// Collect request details
$method      = $_SERVER['REQUEST_METHOD'];
$path        = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$queryString = $_SERVER['QUERY_STRING'] ?? '';

// Remove legacy routing params from logged query string
$queryParams = $_GET;
unset($queryParams['token']);
unset($queryParams['project']);
$queryStringClean = http_build_query($queryParams);

// Parse headers from $_SERVER
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $headerName = str_replace('_', '-', substr($key, 5));
        if (empty(IGNORED_HEADERS) || !in_array(strtoupper($headerName), IGNORED_HEADERS, true)) {
            $headers[$headerName] = $value;
        }
    }
}
// Also capture CONTENT_TYPE and CONTENT_LENGTH directly
if (isset($_SERVER['CONTENT_TYPE'])   && (empty(IGNORED_HEADERS) || !in_array('CONTENT-TYPE',   IGNORED_HEADERS, true))) $headers['CONTENT-TYPE']   = $_SERVER['CONTENT_TYPE'];
if (isset($_SERVER['CONTENT_LENGTH']) && (empty(IGNORED_HEADERS) || !in_array('CONTENT-LENGTH', IGNORED_HEADERS, true))) $headers['CONTENT-LENGTH'] = $_SERVER['CONTENT_LENGTH'];

// Read raw body
$body        = file_get_contents('php://input');
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

// Determine client IP (respect reverse proxy headers)
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
// Take first IP from X-Forwarded-For chain
if (strpos($ip, ',') !== false) {
    $ip = trim(explode(',', $ip)[0]);
}

// Evaluate guards
$validated       = 1;
$rejectionReason = null;

$guardStmt = $db->prepare('
    SELECT * FROM guards
    WHERE (project_id = ? OR webhook_id = ?)
    AND active = 1 AND deleted_at IS NULL
');
$guardStmt->execute([$webhook['project_id'], $webhook['id']]);
$guards = $guardStmt->fetchAll();

foreach ($guards as $guard) {
    $config = json_decode($guard['config'], true) ?? [];
    $passed = evaluateGuard($guard['type'], $config, $headers, $queryParams, $body, $ip);
    if (!$passed) {
        $validated       = 0;
        $rejectionReason = 'Guard failed: ' . $guard['type'];
        break;
    }
}

// Save event to database
$insertStmt = $db->prepare('
    INSERT INTO events (webhook_id, method, path, query_string, headers, body, content_type, ip, validated, rejection_reason)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');
$insertStmt->execute([
    $webhook['id'],
    $method,
    $path,
    $queryStringClean,
    json_encode($headers),
    $body,
    $contentType,
    $ip,
    $validated,
    $rejectionReason,
]);
$eventId = (int)$db->lastInsertId();

// ── File upload mode ──────────────────────────────────────────────────────────
if (($webhook['special_function'] ?? '') === 'file_upload' && !empty($_FILES)) {
    $uploadDir = UPLOADS_DIR . '/' . $eventId;
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0750, true);
    }
    $fileCount = 0;
    $fileStmt  = $db->prepare('INSERT INTO event_files (event_id, field_name, filename, mime_type, size, storage_path) VALUES (?,?,?,?,?,?)');
    // Normalize $_FILES to handle both single and array inputs
    $normalizedFiles = [];
    foreach ($_FILES as $fieldName => $fileData) {
        if (is_array($fileData['name'])) {
            for ($i = 0; $i < count($fileData['name']); $i++) {
                if ($fileData['error'][$i] === UPLOAD_ERR_OK) {
                    $normalizedFiles[] = [
                        'field'    => $fieldName . '[' . $i . ']',
                        'name'     => $fileData['name'][$i],
                        'tmp_name' => $fileData['tmp_name'][$i],
                        'type'     => $fileData['type'][$i],
                        'size'     => $fileData['size'][$i],
                    ];
                }
            }
        } else {
            if ($fileData['error'] === UPLOAD_ERR_OK) {
                $normalizedFiles[] = [
                    'field'    => $fieldName,
                    'name'     => $fileData['name'],
                    'tmp_name' => $fileData['tmp_name'],
                    'type'     => $fileData['type'],
                    'size'     => $fileData['size'],
                ];
            }
        }
    }
    foreach ($normalizedFiles as $f) {
        $safeName    = preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($f['name']));
        $storagePath = $uploadDir . '/' . $fileCount . '_' . $safeName;
        if (move_uploaded_file($f['tmp_name'], $storagePath)) {
            $fileStmt->execute([$eventId, $f['field'], $safeName, $f['type'], $f['size'], $storagePath]);
            $fileCount++;
        }
    }
    // Override method to FILE if at least one file was received
    if ($fileCount > 0) {
        $db->prepare('UPDATE events SET method = ? WHERE id = ?')->execute(['FILE', $eventId]);
    }
}

// Execute forwarding (only if event passed guards)
if ($validated) {
    executeForwarding($db, $eventId, $webhook['id']);
}

// Check called_in_interval alarms — log as ALARM event when a call arrives within the window
if ($validated) {
    $nowTime   = date('H:i');
    $alarmStmt = $db->prepare("
        SELECT a.*, u.email AS user_email
        FROM alarms a
        JOIN webhooks w ON w.id = a.webhook_id
        JOIN projects p ON p.id = w.project_id
        JOIN users    u ON u.id = p.user_id
        WHERE a.webhook_id = ? AND a.type = 'called_in_interval'
          AND a.active = 1 AND a.deleted_at IS NULL
    ");
    $alarmStmt->execute([$webhook['id']]);
    foreach ($alarmStmt->fetchAll(PDO::FETCH_ASSOC) as $alarm) {
        $cfg   = json_decode($alarm['config'], true) ?: [];
        $start = $cfg['start'] ?? '';
        $end   = $cfg['end']   ?? '';
        if (!$start || !$end) continue;
        if ($nowTime < $start || $nowTime > $end) continue;

        $alarmName = $alarm['name'] !== '' ? $alarm['name'] : $webhook['name'];
        $msg       = "Chiamata ricevuta nell'intervallo {$start}–{$end}.";

        // Insert as ALARM event (use same path as the triggering request)
        $alarmInsert = $db->prepare("
            INSERT INTO events (webhook_id, method, path, query_string, headers, body, content_type, ip, validated)
            VALUES (?, 'ALARM', ?, '', ?, ?, 'application/alarm', '', 1)
        ");
        $alarmInsert->execute([
            $webhook['id'],
            $path,
            json_encode(['X-Alarm-Id' => (string)$alarm['id'], 'X-Alarm-Name' => $alarmName, 'X-Alarm-Type' => 'called_in_interval']),
            $msg,
        ]);
        $alarmEventId = (int)$db->lastInsertId();

        $userEmail = $alarm['user_email'] ?? '';
        if ($userEmail) {
            sendAlarmEmail(
                $userEmail,
                $webhook['name'],
                $alarmName,
                'called_in_interval',
                $msg,
                BASE_URL . '/?page=webhook&action=detail&id=' . $webhook['id'],
                BASE_URL . '/?page=event&id=' . $alarmEventId
            );
        }
    }
}

// Respond quickly
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'event_id' => $eventId]) . "\n";
exit;

/**
 * Evaluate a single guard rule against the incoming request.
 */
function evaluateGuard(string $type, array $config, array $headers, array $query, string $body, string $ip): bool {
    switch ($type) {
        case 'required_header':
            $name = strtoupper(str_replace('-', '_', $config['header'] ?? ''));
            return isset($headers[$name]) && $headers[$name] !== '';

        case 'static_token':
            $name     = strtoupper(str_replace('-', '_', $config['header'] ?? ''));
            $expected = $config['value'] ?? '';
            if (!$expected || !isset($headers[$name])) return false;
            return hash_equals($expected, $headers[$name]);

        case 'query_secret':
            $param    = $config['param']  ?? '';
            $expected = $config['value']  ?? '';
            if (!$param || !$expected) return false;
            if (!isset($query[$param])) return false;
            return hash_equals($expected, (string)$query[$param]);

        case 'ip_whitelist':
            $whitelist = array_map('trim', explode(',', $config['ips'] ?? ''));
            foreach ($whitelist as $allowed) {
                if ($allowed === $ip) return true;
                // Basic CIDR support: check prefix match
                if (strpos($allowed, '/') !== false) {
                    if (ipInCidr($ip, $allowed)) return true;
                }
            }
            return false;

        default:
            return true;
    }
}

/**
 * Check if an IP is within a CIDR range.
 */
function ipInCidr(string $ip, string $cidr): bool {
    [$range, $mask] = explode('/', $cidr, 2);
    $mask    = (int)$mask;
    $ipLong  = ip2long($ip);
    $rngLong = ip2long($range);
    if ($ipLong === false || $rngLong === false) return false;
    $maskLong = $mask === 0 ? 0 : (~0 << (32 - $mask));
    return ($ipLong & $maskLong) === ($rngLong & $maskLong);
}
