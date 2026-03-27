<?php
/**
 * Cron endpoint — no session, no auth UI.
 * Call from an external cron: curl https://yourdomain/?page=cron&secret=YOUR_CRON_SECRET
 *
 * Alarms are logged as events with method='ALARM' so they appear in the dashboard
 * and in the webhook detail event feed like any other event.
 *
 * Anti-spam:
 *   not_called_since       — cooldown = the configured threshold (checks last ALARM event)
 *   not_called_in_interval — once per interval per day
 */

header('Content-Type: application/json');

$cronSecret = defined('CRON_SECRET') ? CRON_SECRET : '';
if ($cronSecret !== '') {
    $provided = $_GET['secret'] ?? '';
    if (!hash_equals($cronSecret, $provided)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

$triggered = [];
$now       = time();
$today     = date('Y-m-d');
$nowTime   = date('H:i');

/**
 * Insert an alarm event into the events table (method = 'ALARM').
 */
function insertAlarmEvent(PDO $db, int $webhookId, int $alarmId, string $alarmName, string $alarmType, string $message, string $webhookPath): int {
    $db->prepare("
        INSERT INTO events (webhook_id, method, path, query_string, headers, body, content_type, ip, validated)
        VALUES (?, 'ALARM', ?, '', ?, ?, 'application/alarm', '', 1)
    ")->execute([
        $webhookId,
        $webhookPath,
        json_encode(['X-Alarm-Id' => (string)$alarmId, 'X-Alarm-Name' => $alarmName, 'X-Alarm-Type' => $alarmType]),
        $message,
    ]);
    return (int)$db->lastInsertId();
}

// Load all active cron-based alarms for active webhooks, including owner email
$stmt = $db->query("
    SELECT a.*, w.name AS webhook_name, w.token AS webhook_token, p.slug AS project_slug, u.email AS user_email
    FROM alarms a
    JOIN webhooks w ON w.id  = a.webhook_id
    JOIN projects p ON p.id  = w.project_id
    JOIN users    u ON u.id  = p.user_id
    WHERE a.active = 1
      AND a.deleted_at IS NULL
      AND a.type IN ('not_called_since', 'not_called_in_interval')
      AND w.deleted_at IS NULL
      AND w.active = 1
      AND p.deleted_at IS NULL
");
$alarms = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($alarms as $alarm) {
    $alarmId     = (int)$alarm['id'];
    $webhookId   = (int)$alarm['webhook_id'];
    $config      = json_decode($alarm['config'], true) ?: [];
    $userEmail   = $alarm['user_email'] ?? '';
    $webhookName = $alarm['webhook_name'] ?? '';
    $alarmName   = $alarm['name'] !== '' ? $alarm['name'] : $webhookName;
    $webhookPath = '/' . rawurlencode($alarm['project_slug']) . '/' . rawurlencode($alarm['webhook_token']);

    if ($alarm['type'] === 'not_called_since') {
        $hours         = max(0, (int)($config['hours']   ?? 0));
        $minutes       = max(0, (int)($config['minutes'] ?? 0));
        $thresholdSecs = $hours * 3600 + $minutes * 60;
        if ($thresholdSecs <= 0) continue;

        // Cooldown: skip if we already fired an ALARM event for this specific alarm within the threshold window
        $lastAlarm = $db->prepare("
            SELECT received_at FROM events
            WHERE webhook_id = ? AND method = 'ALARM'
              AND JSON_EXTRACT(headers, '$.\"X-Alarm-Id\"') = ?
            ORDER BY received_at DESC LIMIT 1
        ");
        $lastAlarm->execute([$webhookId, (string)$alarmId]);
        $lastAt = $lastAlarm->fetchColumn();
        if ($lastAt && ($now - strtotime($lastAt)) < $thresholdSecs) continue;

        // Check last non-ALARM event
        $evtStmt = $db->prepare("SELECT received_at FROM events WHERE webhook_id = ? AND method != 'ALARM' ORDER BY received_at DESC LIMIT 1");
        $evtStmt->execute([$webhookId]);
        $lastReceived = $evtStmt->fetchColumn();

        $shouldFire = false;
        $message    = '';
        if (!$lastReceived) {
            $shouldFire = true;
            $message    = "Nessuna chiamata mai ricevuta (soglia: {$hours}h {$minutes}m).";
        } else {
            $age = $now - strtotime($lastReceived);
            if ($age > $thresholdSecs) {
                $hh         = (int)floor($age / 3600);
                $mm         = (int)floor(($age % 3600) / 60);
                $message    = "Nessuna chiamata da {$hh}h {$mm}m. Soglia: {$hours}h {$minutes}m.";
                $shouldFire = true;
            }
        }

        if ($shouldFire) {
            $eventId = insertAlarmEvent($db, $webhookId, $alarmId, $alarmName, $alarm['type'], $message, $webhookPath);
            $triggered[] = ['alarm_id' => $alarmId, 'type' => $alarm['type'], 'webhook' => $webhookName, 'message' => $message];

            if ($userEmail) {
                sendAlarmEmail(
                    $userEmail,
                    $webhookName,
                    $alarmName,
                    $alarm['type'],
                    $message,
                    BASE_URL . '/?page=webhook&action=detail&id=' . $webhookId,
                    BASE_URL . '/?page=event&id=' . $eventId
                );
            }
        }

    } elseif ($alarm['type'] === 'not_called_in_interval') {
        $start = $config['start'] ?? '';
        $end   = $config['end']   ?? '';
        if (!$start || !$end || $start >= $end) continue;

        if ($nowTime < $start || $nowTime > $end) continue;

        // Already fired today for this specific alarm?
        $todayAlarm = $db->prepare("
            SELECT id FROM events
            WHERE webhook_id = ? AND method = 'ALARM'
              AND JSON_EXTRACT(headers, '$.\"X-Alarm-Id\"') = ?
              AND DATE(received_at) = ?
            LIMIT 1
        ");
        $todayAlarm->execute([$webhookId, (string)$alarmId, $today]);
        if ($todayAlarm->fetchColumn()) continue;

        // Check if any non-ALARM event arrived during this window today
        $todayStart = $today . ' ' . $start . ':00';
        $todayEnd   = $today . ' ' . $end   . ':59';
        $evtCheck   = $db->prepare("SELECT COUNT(*) FROM events WHERE webhook_id = ? AND method != 'ALARM' AND received_at >= ? AND received_at <= ?");
        $evtCheck->execute([$webhookId, $todayStart, $todayEnd]);
        $count = (int)$evtCheck->fetchColumn();

        if ($count === 0) {
            $message = "Nessuna chiamata nell'intervallo {$start}–{$end} di oggi.";
            $eventId = insertAlarmEvent($db, $webhookId, $alarmId, $alarmName, $alarm['type'], $message, $webhookPath);
            $triggered[] = ['alarm_id' => $alarmId, 'type' => $alarm['type'], 'webhook' => $webhookName, 'message' => $message];

            if ($userEmail) {
                sendAlarmEmail(
                    $userEmail,
                    $webhookName,
                    $alarmName,
                    $alarm['type'],
                    $message,
                    BASE_URL . '/?page=webhook&action=detail&id=' . $webhookId,
                    BASE_URL . '/?page=event&id=' . $eventId
                );
            }
        }
    }
}

// ── Autocall: claim and execute a batch of due jobs ──────────────────────────

$batchSize   = defined('CRON_BATCH_SIZE') ? CRON_BATCH_SIZE : 10;
$nowStr      = date('Y-m-d H:i:s');
$autocallRan = [];
$remaining   = 0;

// Self-repair: initialize cron_next_run for autocall webhooks where it is NULL
// but cron_expression is set (e.g. after a fresh migration).
try {
    $repairStmt = $db->query(
        "SELECT id, cron_expression FROM webhooks
         WHERE special_function = 'autocall'
           AND active = 1
           AND deleted_at IS NULL
           AND cron_expression IS NOT NULL
           AND cron_next_run IS NULL"
    );
    foreach ($repairStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $next = cronNextRun($r['cron_expression'], time());
        if ($next !== null) {
            $db->prepare('UPDATE webhooks SET cron_next_run = ? WHERE id = ?')
               ->execute([date('Y-m-d H:i:s', $next), (int)$r['id']]);
        }
    }
} catch (Throwable $e) {
    // columns may not exist yet; skip repair silently
}

// Count total due BEFORE claiming (for reporting)
$dueCount = 0;
try {
    $dueCount = (int)$db->query(
        "SELECT COUNT(*) FROM webhooks
         WHERE special_function = 'autocall'
           AND active = 1
           AND deleted_at IS NULL
           AND cron_next_run IS NOT NULL
           AND cron_next_run <= " . $db->quote($nowStr)
    )->fetchColumn();
} catch (Throwable $e) {
    // columns not yet available; skip
}

if ($dueCount > 0) {
    // --- ATOMIC CLAIM: fetch IDs then NULL them in one transaction ---
    $db->beginTransaction();
    $claimed = [];
    try {
        $claimStmt = $db->query(
            "SELECT w.id, w.cron_expression, w.token, p.slug AS project_slug
             FROM webhooks w
             JOIN projects p ON p.id = w.project_id
             WHERE w.special_function = 'autocall'
               AND w.active = 1
               AND w.deleted_at IS NULL
               AND p.deleted_at IS NULL
               AND w.cron_next_run IS NOT NULL
               AND w.cron_next_run <= " . $db->quote($nowStr) . "
             LIMIT $batchSize"
        );
        $claimed = $claimStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($claimed)) {
            $ids = implode(',', array_map(fn($r) => (int)$r['id'], $claimed));
            // Set NULL → job is "owned" by this caller; concurrent callers skip it
            $db->exec("UPDATE webhooks SET cron_next_run = NULL WHERE id IN ($ids)");
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        $claimed = [];
    }

    // --- EXECUTE claimed jobs (outside transaction) ---
    foreach ($claimed as $wh) {
        $webhookId   = (int)$wh['id'];
        $cronExpr    = $wh['cron_expression'];
        $webhookPath = '/' . rawurlencode($wh['project_slug']) . '/' . rawurlencode($wh['token']);

        $db->prepare("
            INSERT INTO events (webhook_id, method, path, query_string, headers, body, content_type, ip, validated)
            VALUES (?, 'CRON', ?, '', ?, '', 'application/cron', '', 1)
        ")->execute([
            $webhookId,
            $webhookPath,
            json_encode(['X-Autocall-Schedule' => $cronExpr]),
        ]);
        $eventId = (int)$db->lastInsertId();

        executeForwarding($db, $eventId, $webhookId);

        $nextTs  = cronNextRun($cronExpr, time());
        $nextStr = $nextTs ? date('Y-m-d H:i:s', $nextTs) : null;
        $db->prepare('UPDATE webhooks SET cron_next_run = ?, cron_last_run = ? WHERE id = ?')
           ->execute([$nextStr, date('Y-m-d H:i:s'), $webhookId]);

        $autocallRan[] = ['webhook_id' => $webhookId, 'event_id' => $eventId, 'next_run' => $nextStr];
    }

    $remaining = max(0, $dueCount - count($claimed));
}

echo json_encode([
    'ok'                 => true,
    'checked'            => count($alarms),
    'triggered'          => count($triggered),
    'alarms'             => $triggered,
    'autocall_ran'       => count($autocallRan),
    'autocall_jobs'      => $autocallRan,
    'autocall_remaining' => $remaining,
    'ts'                 => date('c'),
]);
exit;
