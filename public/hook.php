<?php
// No session needed — this is a public endpoint
require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/utils.php';

$token = $_GET['token'] ?? '';
if (!$token) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']);
    exit;
}

$db = Database::get();

// Look up webhook (validate token via prepared statement)
$stmt = $db->prepare('
    SELECT w.*, p.active as project_active, p.user_id, p.id as pid
    FROM webhooks w
    JOIN projects p ON p.id = w.project_id
    WHERE w.token = ? AND w.deleted_at IS NULL AND p.deleted_at IS NULL
');
$stmt->execute([$token]);
$webhook = $stmt->fetch();

if (!$webhook) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Webhook not found']);
    exit;
}

if (!$webhook['active'] || !$webhook['project_active']) {
    http_response_code(410);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Webhook is disabled']);
    exit;
}

// Collect request details
$method      = $_SERVER['REQUEST_METHOD'];
$path        = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$queryString = $_SERVER['QUERY_STRING'] ?? '';

// Remove token from logged query string
$queryParams = $_GET;
unset($queryParams['token']);
$queryStringClean = http_build_query($queryParams);

// Parse headers from $_SERVER
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        // Convert HTTP_CONTENT_TYPE → CONTENT-TYPE
        $headerName = str_replace('_', '-', substr($key, 5));
        $headers[$headerName] = $value;
    }
}
// Also capture CONTENT_TYPE and CONTENT_LENGTH directly
if (isset($_SERVER['CONTENT_TYPE']))   $headers['CONTENT-TYPE']   = $_SERVER['CONTENT_TYPE'];
if (isset($_SERVER['CONTENT_LENGTH'])) $headers['CONTENT-LENGTH'] = $_SERVER['CONTENT_LENGTH'];

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

// Execute forwarding (only if event passed guards)
if ($validated) {
    executeForwarding($db, $eventId, $webhook['id']);
}

// Respond quickly
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'event_id' => $eventId]);
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
