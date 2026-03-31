<?php

/**
 * Store a flash message in session.
 */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Retrieve and clear the flash message from session.
 */
function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * Safely escape a string for HTML output.
 */
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Generate a CSRF token and store it in session.
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a CSRF token against the session value (timing-safe).
 */
function verifyCsrfToken(string $token): bool {
    $stored = $_SESSION['csrf_token'] ?? '';
    if (!$stored) return false;
    return hash_equals($stored, $token);
}

/**
 * Perform an HTTP POST request using curl.
 */
function httpPost(string $url, array $data, array $headers = []): string|false {
    $ch = curl_init($url);
    if ($ch === false) return false;

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $curlHeaders = [];
    foreach ($headers as $k => $v) {
        $curlHeaders[] = "$k: $v";
    }
    if (!empty($curlHeaders)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
    }

    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

/**
 * Perform an HTTP POST request with a JSON body using curl.
 */
function httpPostJson(string $url, string $jsonBody, array $headers = []): string|false {
    $ch = curl_init($url);
    if ($ch === false) return false;

    $headers['Content-Type'] = 'application/json';
    $headers['Content-Length'] = strlen($jsonBody);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $curlHeaders = [];
    foreach ($headers as $k => $v) {
        $curlHeaders[] = "$k: $v";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

/**
 * Perform an HTTP GET request using curl.
 */
function httpGet(string $url, array $headers = []): string|false {
    $ch = curl_init($url);
    if ($ch === false) return false;

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $curlHeaders = [];
    foreach ($headers as $k => $v) {
        $curlHeaders[] = "$k: $v";
    }
    if (!empty($curlHeaders)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
    }

    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

/**
 * Access a nested array value using dot-notation key (e.g. "device.id").
 */
function dotGet(array $data, string $key): mixed {
    $parts = explode('.', $key);
    $current = $data;
    foreach ($parts as $part) {
        if (!is_array($current) || !array_key_exists($part, $current)) {
            return null;
        }
        $current = $current[$part];
    }
    return $current;
}

/**
 * Evaluate a single forwarding condition against a received event.
 * Condition structure: {type, field, operator, value}
 * Types: method, header, query, body_json
 * Operators: equals, not_equals, contains, exists
 */
function evaluateCondition(array $condition, string $method, array $headers, array $queryParams, string $body): bool {
    $type     = $condition['type']     ?? '';
    $field    = $condition['field']    ?? '';
    $operator = $condition['operator'] ?? 'equals';
    $value    = $condition['value']    ?? '';

    switch ($type) {
        case 'method':
            $actual = strtoupper($method);
            $expected = strtoupper($value);
            return match($operator) {
                'equals'     => $actual === $expected,
                'not_equals' => $actual !== $expected,
                default      => false,
            };

        case 'header':
            $headerKey = strtoupper(str_replace('-', '_', $field));
            $actual = $headers[$headerKey] ?? null;
            return match($operator) {
                'exists'     => $actual !== null,
                'equals'     => $actual === $value,
                'not_equals' => $actual !== $value,
                'contains'   => $actual !== null && str_contains($actual, $value),
                default      => false,
            };

        case 'query':
            $actual = $queryParams[$field] ?? null;
            return match($operator) {
                'exists'     => $actual !== null,
                'equals'     => $actual === $value,
                'not_equals' => $actual !== $value,
                'contains'   => $actual !== null && str_contains((string)$actual, $value),
                default      => false,
            };

        case 'body_json':
            $parsed = json_decode($body, true);
            if (!is_array($parsed)) return false;
            $actual = dotGet($parsed, $field);
            return match($operator) {
                'exists'     => $actual !== null,
                'equals'     => (string)$actual === (string)$value,
                'not_equals' => (string)$actual !== (string)$value,
                'contains'   => $actual !== null && str_contains((string)$actual, $value),
                default      => false,
            };

        default:
            return true;
    }
}

/**
 * Execute all active forward_actions for a given webhook event.
 * Evaluates conditions (ALL must match), makes HTTP calls, saves forward_attempts.
 */
function executeForwarding(PDO $db, int $eventId, int $webhookId): void {
    // Fetch the event details
    $evtStmt = $db->prepare('SELECT * FROM events WHERE id = ?');
    $evtStmt->execute([$eventId]);
    $event = $evtStmt->fetch();
    if (!$event) return;

    $method      = $event['method'];
    $headers     = json_decode($event['headers'] ?? '{}', true) ?: [];
    $body        = $event['body'] ?? '';
    $queryString = $event['query_string'] ?? '';
    parse_str($queryString, $queryParams);

    // Fetch active forward actions for this webhook
    $faStmt = $db->prepare(
        'SELECT * FROM forward_actions WHERE webhook_id = ? AND active = 1 AND deleted_at IS NULL'
    );
    $faStmt->execute([$webhookId]);
    $actions = $faStmt->fetchAll();

    foreach ($actions as $action) {
        $conditions = json_decode($action['conditions'] ?? '[]', true) ?: [];

        // Check ALL conditions (AND logic)
        $conditionsPassed = true;
        foreach ($conditions as $condition) {
            if (!evaluateCondition($condition, $method, $headers, $queryParams, $body)) {
                $conditionsPassed = false;
                break;
            }
        }

        if (!$conditionsPassed) {
            continue;
        }

        // Build outgoing request
        $targetUrl    = $action['url'];
        $targetMethod = strtoupper($action['method'] ?? 'POST');
        $timeout      = (int)($action['timeout'] ?? 10);

        // Parse custom headers (one "Key: Value" per line)
        $customHeaders = [];
        $customHeadersRaw = $action['custom_headers'] ?? '{}';
        $parsedCustom = json_decode($customHeadersRaw, true);
        if (is_array($parsedCustom)) {
            $customHeaders = $parsedCustom;
        }

        // Build request body
        $bodyTemplate = $action['body_template'] ?? '';
        if ($bodyTemplate !== '') {
            $requestBody = str_replace('{{body}}', $body, $bodyTemplate);
        } else {
            $requestBody = $body;
        }

        // Ensure User-Agent header
        $customHeaders['User-Agent'] = 'Hookpool/1.0';

        // Execute the forwarding HTTP request
        $responseStatus = null;
        $responseBody   = null;
        $error          = null;

        try {
            $ch = curl_init($targetUrl);
            if ($ch === false) {
                $error = 'curl_init failed';
            } else {
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
                    case 'PUT':
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
                        break;
                    case 'PATCH':
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
                        break;
                    case 'DELETE':
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                        break;
                    default:
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $targetMethod);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
                }

                $responseBody   = curl_exec($ch);
                $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($responseBody === false) {
                    $error = curl_error($ch);
                    $responseBody = null;
                }
                curl_close($ch);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        // Build serialized request headers for logging
        $requestHeadersLog = json_encode($customHeaders);

        // Save attempt — sanitize strings to valid UTF-8 and truncate to TEXT limit (65535 bytes)
        $safeUtf8 = static function (?string $s, int $maxBytes = 60000): ?string {
            if ($s === null) return null;
            $clean = iconv('UTF-8', 'UTF-8//IGNORE', $s);
            if ($clean === false) $clean = '';
            // Truncate to maxBytes safely (avoid cutting a multibyte char)
            if (strlen($clean) > $maxBytes) {
                $clean = mb_strcut($clean, 0, $maxBytes, 'UTF-8') . "\n[truncated]";
            }
            return $clean;
        };

        $attemptStmt = $db->prepare(
            'INSERT INTO forward_attempts
             (event_id, forward_action_id, request_headers, request_body, response_status, response_body, error)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $attemptStmt->execute([
            $eventId,
            $action['id'],
            $safeUtf8($requestHeadersLog),
            $safeUtf8($requestBody),
            $responseStatus,
            $safeUtf8($responseBody),
            $safeUtf8($error),
        ]);
    }
}

/**
 * Delete stored alarm-email attempts for the given events and remove orphaned
 * file-spool emails generated in dev mode.
 */
function deleteAlarmEmailArtifacts(PDO $db, array $eventIds): int {
    $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds), static fn($id) => $id > 0)));
    if (empty($eventIds)) {
        return 0;
    }

    $idList = implode(',', $eventIds);
    $spoolRows = $db->query(
        "SELECT DISTINCT spool_path
         FROM alarm_email_attempts
         WHERE event_id IN ($idList)
           AND spool_path IS NOT NULL
           AND spool_path != ''"
    )->fetchAll(PDO::FETCH_COLUMN);

    $deleted = (int)$db->query(
        "SELECT COUNT(*)
         FROM alarm_email_attempts
         WHERE event_id IN ($idList)"
    )->fetchColumn();

    $db->exec("DELETE FROM alarm_email_attempts WHERE event_id IN ($idList)");

    if (!empty($spoolRows)) {
        $stillUsedStmt = $db->prepare('SELECT COUNT(*) FROM alarm_email_attempts WHERE spool_path = ?');
        foreach ($spoolRows as $spoolPath) {
            $spoolPath = (string)$spoolPath;
            if ($spoolPath === '') {
                continue;
            }
            $stillUsedStmt->execute([$spoolPath]);
            if ((int)$stillUsedStmt->fetchColumn() === 0 && is_file($spoolPath)) {
                @unlink($spoolPath);
            }
        }
    }

    return $deleted;
}

/**
 * Generate a URL-friendly slug from a string.
 */
function slugify(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    $text = trim($text, '-');
    return $text ?: 'project';
}

const SLUG_MIN_LENGTH = 4;
const SLUG_MAX_LENGTH = 32;

/**
 * Reserved/forbidden words that cannot be used as public project slugs.
 */
function reservedProjectSlugs(): array {
    return [
        // App routes
        'admin', 'api', 'auth', 'cron', 'dashboard', 'diagnose',
        'event', 'events', 'hook', 'home', 'index', 'known-ips',
        'login', 'logout', 'migrate', 'project', 'projects',
        'public', 'settings', 'style', 'webhook', 'webhooks',
        // Generic app/landing words
        'about', 'account', 'app', 'billing', 'blog', 'careers',
        'contact', 'docs', 'download', 'downloads', 'faq', 'feed',
        'help', 'info', 'landing', 'legal', 'mail', 'me', 'new',
        'newsletter', 'null', 'ping', 'plans', 'press', 'pricing',
        'privacy', 'profile', 'register', 'rss', 'search', 'shop',
        'signup', 'sitemap', 'status', 'store', 'support', 'team',
        'terms', 'undefined', 'user', 'users',
        // Well-known brand/platform names
        'amazon', 'apple', 'aws', 'azure', 'bitbucket', 'cloudflare',
        'discord', 'docker', 'dropbox', 'facebook', 'figma', 'gcp',
        'git', 'github', 'gitlab', 'google', 'hookpool', 'instagram',
        'jira', 'kubernetes', 'linear', 'linkedin', 'microsoft',
        'notion', 'npm', 'openai', 'paypal', 'slack', 'stripe',
        'teams', 'telegram', 'tiktok', 'trello', 'twilio', 'twitter',
        'vercel', 'whatsapp', 'x', 'youtube', 'zapier',
    ];
}

function isReservedProjectSlug(string $slug): bool {
    return in_array($slug, reservedProjectSlugs(), true);
}

/**
 * Returns an error message if the slug is invalid, or null if valid.
 */
function validateProjectSlugFormat(string $slug): ?string {
    $len = strlen($slug);
    if ($len < SLUG_MIN_LENGTH) return 'Slug must be at least ' . SLUG_MIN_LENGTH . ' characters.';
    if ($len > SLUG_MAX_LENGTH) return 'Slug must be at most ' . SLUG_MAX_LENGTH . ' characters.';
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) return 'Slug may only contain lowercase letters, numbers, and dashes.';
    if (isReservedProjectSlug($slug)) return 'This slug is reserved and cannot be used.';
    return null;
}

/**
 * Generate a globally unique slug for a project.
 */
function uniqueProjectSlug(PDO $db, string $nameOrSlug, ?int $excludeId = null): string {
    $base = slugify($nameOrSlug);
    if (isReservedProjectSlug($base)) {
        $base .= '-1';
    }
    $slug = $base;
    $i    = 1;

    while (true) {
        if (isReservedProjectSlug($slug)) {
            $i++;
            $slug = $base . '-' . $i;
            continue;
        }

        $q = 'SELECT id FROM projects WHERE slug = ?';
        $params = [$slug];
        if ($excludeId !== null) {
            $q .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $stmt = $db->prepare($q);
        $stmt->execute($params);
        if (!$stmt->fetch()) break;
        $i++;
        $slug = $base . '-' . $i;
    }

    return $slug;
}

function webhookUrl(string $projectSlug, string $webhookToken): string {
    return BASE_URL . '/' . rawurlencode($projectSlug) . '/' . rawurlencode($webhookToken);
}

function relayWebhookUrl(string $projectSlug, string $webhookToken): string {
    return webhookUrl($projectSlug, $webhookToken) . '.relay';
}

function generateUniqueWebhookToken(PDO $db, int $projectId): string {
    do {
        $token = generateWebhookToken();
        $stmt = $db->prepare('SELECT id FROM webhooks WHERE project_id = ? AND token = ? AND deleted_at IS NULL');
        $stmt->execute([$projectId, $token]);
        $exists = $stmt->fetch();
    } while ($exists);

    return $token;
}

/**
 * Map of project icon key → emoji glyph.
 * Keys are stored in the DB; glyphs are only used for rendering.
 */
const PROJECT_ICONS = [
    'robot'       => '🤖',
    'folder'      => '📁',
    'globe'       => '🌐',
    'radar'       => '📡',
    'plug'        => '🔌',
    'idea'        => '💡',
    'home'        => '🏠',
    'car'         => '🚗',
    'thermometer' => '🌡️',
    'chart'       => '📊',
    'bell'        => '🔔',
    'lightning'   => '⚡',
    'cart'        => '🛒',
    'chat'        => '💬',
    'lock'        => '🔐',
    'lab'         => '🧪',
    'rocket'      => '🚀',
    'mobile'      => '📱',
    'cloud'       => '☁️',
    'game'        => '🎮',
    'watch'       => '⌚',
];

/**
 * Render an emoji from its stored key. Falls back to robot emoji.
 */
function projectEmoji(string $key): string {
    return PROJECT_ICONS[$key] ?? PROJECT_ICONS['robot'];
}

/**
 * Parse one cron field against a value.
 * Supports: star, star/step, n, n-m, n,m, n-m/step
 */
function cronFieldMatches(string $field, int $value, int $min, int $max): bool {
    foreach (explode(',', $field) as $part) {
        $step = 1;
        if (str_contains($part, '/')) {
            [$part, $s] = explode('/', $part, 2);
            $step = max(1, (int)$s);
        }
        if ($part === '*') {
            for ($i = $min; $i <= $max; $i += $step) {
                if ($i === $value) return true;
            }
        } elseif (str_contains($part, '-')) {
            [$lo, $hi] = explode('-', $part, 2);
            for ($i = (int)$lo; $i <= (int)$hi; $i += $step) {
                if ($i === $value) return true;
            }
        } else {
            if ((int)$part === $value) return true;
        }
    }
    return false;
}

/**
 * Calculate the next timestamp (>= $fromTs + 60s) matching the cron expression.
 * Returns null if expression is invalid or no match found within 4 years.
 * Format: "minute hour day month weekday"  (standard 5-field)
 */
function cronNextRun(string $expr, int $fromTs): ?int {
    $fields = preg_split('/\s+/', trim($expr));
    if (count($fields) !== 5) return null;
    [$fMin, $fHour, $fDay, $fMon, $fWday] = $fields;

    // Start from the next whole minute
    $ts = $fromTs - ($fromTs % 60) + 60;
    $limit = $fromTs + 366 * 4 * 24 * 3600; // 4 years max scan

    while ($ts <= $limit) {
        $dt = getdate($ts);
        if (
            cronFieldMatches($fMon,  $dt['mon'],     1, 12) &&
            cronFieldMatches($fDay,  $dt['mday'],    1, 31) &&
            cronFieldMatches($fWday, $dt['wday'],    0, 6)  &&
            cronFieldMatches($fHour, $dt['hours'],   0, 23) &&
            cronFieldMatches($fMin,  $dt['minutes'], 0, 59)
        ) {
            return $ts;
        }
        $ts += 60;
    }
    return null;
}
