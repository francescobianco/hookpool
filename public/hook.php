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

// ── HTTP Relay: private polling side (PATCH only) ────────────────────────────
if (($webhook['special_function'] ?? '') === 'http_relay' && $method === 'PATCH') {
    relayHandlePoll($db, $webhook, $body);
    exit;
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

// ── HTTP Relay: public side — queue request and wait for relay response ───────
if (($webhook['special_function'] ?? '') === 'http_relay' && $validated) {
    relayHandlePublic($db, $webhook, $method, $path, $queryStringClean, $headers, $body);
    exit;
}

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

// ── HTTP Relay helpers ─────────────────────────────────────────────────────────

/**
 * PATCH side: relay client long-poll.
 *
 * If the PATCH carries X-Relay-Seq + body, deliver the response for that
 * transaction first, then wait for the next pending public request.
 * Responds with the request payload + X-Relay-Seq, or 204 on poll timeout.
 */
function relayHandlePoll(PDO $db, array $webhook, string $body): void
{
    set_time_limit(50);
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');

    $webhookId = (int)$webhook['id'];

    // Purge stale entries (done/expired older than 2 minutes)
    $db->prepare("DELETE FROM relay_queue
                  WHERE webhook_id = ? AND state IN ('done','expired')
                  AND created_at < datetime('now','-120 seconds')")
       ->execute([$webhookId]);

    // If PATCH carries a response, persist it
    $responseSeq = isset($_SERVER['HTTP_X_RELAY_SEQ']) ? (int)$_SERVER['HTTP_X_RELAY_SEQ'] : 0;
    if ($responseSeq > 0 && $body !== '') {
        $payload = json_decode($body, true);
        if (is_array($payload) && array_key_exists('status', $payload)) {
            $db->prepare("UPDATE relay_queue
                          SET state = 'done',
                              resp_status  = ?,
                              resp_headers = ?,
                              resp_body    = ?,
                              resp_b64     = ?,
                              responded_at = datetime('now')
                          WHERE webhook_id = ? AND id = ? AND state = 'dispatched'")
               ->execute([
                   (int)($payload['status'] ?? 200),
                   json_encode($payload['headers'] ?? []),
                   (string)($payload['body'] ?? ''),
                   ($payload['body_base64'] ?? false) ? 1 : 0,
                   $webhookId,
                   $responseSeq,
               ]);
        }
    }

    // Long-poll: wait up to 28 s for the next pending entry.
    // Uses a fresh DB connection so each SELECT starts a new WAL snapshot,
    // guaranteeing visibility of writes from concurrent processes.
    $pollDb   = relayOpenDb();
    $deadline = time() + 28;
    while (time() < $deadline) {
        $stmt = $pollDb->prepare("SELECT * FROM relay_queue
                                  WHERE webhook_id = ? AND state = 'pending'
                                  ORDER BY id ASC LIMIT 1");
        $stmt->execute([$webhookId]);
        $entry = $stmt->fetch();
        $stmt->closeCursor();

        if ($entry) {
            // Atomically claim the row (another process may race us)
            $upd = $db->prepare("UPDATE relay_queue
                                 SET state = 'dispatched', dispatched_at = datetime('now')
                                 WHERE id = ? AND state = 'pending'");
            $upd->execute([(int)$entry['id']]);
            if ($upd->rowCount() === 0) {
                usleep(100000); // lost the race, retry
                continue;
            }

            $respBody = json_encode([
                'method'       => $entry['req_method'],
                'path'         => $entry['req_path'],
                'query_string' => $entry['req_qs'],
                'headers'      => json_decode($entry['req_headers'], true) ?: [],
                'body'         => $entry['req_body'],
                'body_base64'  => (bool)$entry['req_b64'],
            ]);

            http_response_code(200);
            header('Content-Type: application/json');
            header('X-Relay-Seq: ' . $entry['id']);
            header('Cache-Control: no-cache, no-store');
            header('Content-Length: ' . strlen($respBody));
            echo $respBody;
            return;
        }

        usleep(500000); // 0.5 s — keep latency low
    }

    // Poll timeout — client must reconnect immediately
    http_response_code(204);
    header('X-Relay-Seq: 0');
    header('Cache-Control: no-cache, no-store');
}

/**
 * Public side (non-PATCH): queue the request and wait for the relay response.
 *
 * Returns 503 if the queue is saturated (relay client not connected).
 * Returns 504 if the relay client does not respond within the timeout.
 * Otherwise proxies the relay client's response back to the caller.
 */
function relayHandlePublic(
    PDO    $db,
    array  $webhook,
    string $method,
    string $path,
    string $qs,
    array  $headers,
    string $body
): void {
    set_time_limit(50);

    $webhookId = (int)$webhook['id'];

    // Refuse if too many requests are already queued (relay client disconnected)
    $pending = $db->prepare("SELECT COUNT(*) FROM relay_queue WHERE webhook_id = ? AND state = 'pending'");
    $pending->execute([$webhookId]);
    if ((int)$pending->fetchColumn() >= 5) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Service Unavailable', 'detail' => 'Relay queue saturated — client not connected']);
        return;
    }

    // Encode body as base64 if not valid UTF-8
    $bodyB64 = 0;
    $bodyStr = $body;
    if ($body !== '' && !mb_check_encoding($body, 'UTF-8')) {
        $bodyStr = base64_encode($body);
        $bodyB64 = 1;
    }

    $db->prepare("INSERT INTO relay_queue
                  (webhook_id, state, req_method, req_path, req_qs, req_headers, req_body, req_b64)
                  VALUES (?, 'pending', ?, ?, ?, ?, ?, ?)")
       ->execute([$webhookId, $method, $path, $qs, json_encode($headers), $bodyStr, $bodyB64]);

    $queueId = (int)$db->lastInsertId();

    // Wait up to 30 s for the relay client to deliver the response.
    // Fresh DB connection guarantees each SELECT sees the latest WAL state.
    $pollDb   = relayOpenDb();
    $deadline = time() + 30;
    while (time() < $deadline) {
        $stmt = $pollDb->prepare("SELECT * FROM relay_queue WHERE id = ? AND state = 'done'");
        $stmt->execute([$queueId]);
        $entry = $stmt->fetch();
        $stmt->closeCursor();

        if ($entry) {
            $status      = (int)($entry['resp_status'] ?? 200);
            $respHeaders = json_decode($entry['resp_headers'] ?? '{}', true) ?: [];
            $respBody    = (string)($entry['resp_body'] ?? '');
            if ($entry['resp_b64']) {
                $respBody = (string)base64_decode($respBody);
            }

            http_response_code($status);
            foreach ($respHeaders as $hk => $hv) {
                $hk = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$hk);
                if ($hk === '') continue;
                if (in_array(strtoupper($hk), ['TRANSFER-ENCODING', 'CONNECTION', 'CONTENT-LENGTH'], true)) continue;
                header("$hk: $hv");
            }
            header('Content-Length: ' . strlen($respBody));
            header('X-Hookpool-Relay: 1');
            echo $respBody;

            // Best-effort cleanup — the periodic purge in relayHandlePoll handles failures
            try { $db->prepare("DELETE FROM relay_queue WHERE id = ?")->execute([$queueId]); } catch (\Exception $e) {}
            return;
        }

        usleep(500000); // 0.5 s
    }

    // Timeout: mark expired and return 504
    try { $db->prepare("UPDATE relay_queue SET state = 'expired' WHERE id = ?")->execute([$queueId]); } catch (\Exception $e) {}
    http_response_code(504);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Gateway Timeout', 'detail' => 'Relay client did not respond in time']);
}

/**
 * Open a fresh, isolated PDO connection to the database.
 * Used in long-polling loops so each SELECT starts from the latest WAL snapshot
 * without inheriting cursor or transaction state from the main connection.
 */
function relayOpenDb(): PDO
{
    if (DB_TYPE === 'sqlite') {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000'); // wait up to 5 s on lock contention
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}
