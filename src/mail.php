<?php

function setLastEmailError(?string $message): void {
    $GLOBALS['hookpool_last_email_error'] = $message;
}

function getLastEmailError(): ?string {
    $value = $GLOBALS['hookpool_last_email_error'] ?? null;
    return is_string($value) && $value !== '' ? $value : null;
}

/**
 * Resolve the email recipient for alarm notifications.
 *
 * In single-user mode (`HOOKPOOL_AUTH=no`), alarms must go to ADMIN_EMAIL.
 * In GitHub auth mode, alarms go to the owning user's GitHub email.
 */
function resolveAlarmEmailRecipient(?string $userEmail): string {
    if (!HOOKPOOL_AUTH_ENABLED) {
        return trim((string)ADMIN_EMAIL);
    }

    return trim((string)$userEmail);
}

/**
 * Send an email. In dev mode (SMTP_HOST = smtp.example.com), saves to file.
 */
function sendEmail(string $to, string $subject, string $htmlBody): bool {
    setLastEmailError(null);
    $prefix = APP_ENV ? '[' . strtoupper(APP_ENV) . '] ' : '';
    $fullSubject = $prefix . APP_NAME . ' - ' . $subject;

    if (SMTP_HOST === 'smtp.example.com') {
        // Dev mode: save to file
        $filename = date('YmdHis') . '_' . md5($to . $subject . microtime()) . '.html';
        $dir = __DIR__ . '/../data/emails/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $error = 'Failed to create email spool directory: ' . $dir;
            setLastEmailError($error);
            error_log($error);
            return false;
        }

        $written = file_put_contents(
            $dir . $filename,
            "To: $to\nSubject: $fullSubject\n\n$htmlBody"
        );
        if ($written === false) {
            $error = 'Failed to write email spool file: ' . $dir . $filename;
            setLastEmailError($error);
            error_log($error);
            return false;
        }

        return true;
    }

    return sendViaSMTP($to, $fullSubject, $htmlBody);
}

/**
 * Send email via SMTP using PHP sockets (no external library).
 */
function sendViaSMTP(string $to, string $subject, string $htmlBody): bool {
    $host    = SMTP_HOST;
    $port    = SMTP_PORT;
    $user    = SMTP_USER;
    $pass    = SMTP_PASS;
    $from    = MAIL_FROM;
    $fromName = MAIL_FROM_NAME;

    $errno  = 0;
    $errstr = '';
    $socket = @fsockopen('ssl://' . $host, $port, $errno, $errstr, 15);
    if (!$socket) {
        $error = "SMTP connection failed: $errstr ($errno)";
        setLastEmailError($error);
        error_log($error);
        return false;
    }

    $boundary = md5(uniqid('', true));

    $read = fgets($socket, 515);
    if (strpos($read, '220') !== 0) {
        setLastEmailError('SMTP greeting failed: ' . trim((string)$read));
        fclose($socket);
        return false;
    }

    $commands = [
        "EHLO " . gethostname() . "\r\n",
        "AUTH LOGIN\r\n",
        base64_encode($user) . "\r\n",
        base64_encode($pass) . "\r\n",
        "MAIL FROM:<$from>\r\n",
        "RCPT TO:<$to>\r\n",
        "DATA\r\n",
    ];

    foreach ($commands as $cmd) {
        fwrite($socket, $cmd);
        $resp = fgets($socket, 515);
        $code = (int)substr($resp, 0, 3);
        if ($code >= 500) {
            setLastEmailError('SMTP command failed: ' . trim((string)$resp));
            fclose($socket);
            return false;
        }
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFrom    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $date           = date('r');

    $message  = "Date: $date\r\n";
    $message .= "From: $encodedFrom <$from>\r\n";
    $message .= "To: $to\r\n";
    $message .= "Subject: $encodedSubject\r\n";
    $message .= "MIME-Version: 1.0\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= "\r\n";
    $message .= chunk_split(base64_encode($htmlBody));
    $message .= "\r\n.\r\n";

    fwrite($socket, $message);
    $resp = fgets($socket, 515);

    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    if (strpos($resp, '250') === 0) {
        return true;
    }

    setLastEmailError('SMTP DATA failed: ' . trim((string)$resp));
    return false;
}

/**
 * Persist alarm-email delivery status for one or more event ids.
 *
 * Each item may contain:
 * - event_id
 */
function logAlarmEmailAttempts(string $to, string $subject, bool $ok, array $items): void
{
    $eventIds = [];
    foreach ($items as $item) {
        $eventId = (int)($item['event_id'] ?? 0);
        if ($eventId > 0) {
            $eventIds[$eventId] = true;
        }
    }

    if (empty($eventIds)) {
        return;
    }

    $transport = SMTP_HOST === 'smtp.example.com' ? 'file-spool' : 'smtp';
    $status = $ok ? 'sent' : 'failed';
    $errorMessage = getLastEmailError();

    try {
        $db = Database::get();
        $stmt = $db->prepare('
            INSERT INTO alarm_email_attempts (event_id, recipient_email, subject, transport, status, error_message)
            VALUES (?, ?, ?, ?, ?, ?)
        ');

        foreach (array_keys($eventIds) as $eventId) {
            $stmt->execute([$eventId, $to, $subject, $transport, $status, $errorMessage]);
        }
    } catch (Throwable $e) {
        error_log('Failed to log alarm email attempt: ' . $e->getMessage());
    }
}

/**
 * Send an alarm notification email with webhook link + event log link.
 */
function sendAlarmEmail(
    string $to,
    string $webhookName,
    string $alarmName,
    string $alarmType,
    string $message,
    string $webhookUrl,
    string $eventUrl,
    ?int $eventId = null
): bool {
    $typeLabel = match($alarmType) {
        'not_called_since'       => 'Nessuna chiamata ricevuta',
        'not_called_in_interval' => 'Nessuna chiamata nell\'intervallo',
        'called_in_interval'     => 'Chiamata ricevuta nell\'intervallo',
        'log_expression'         => 'Espressione sui log',
        default                  => $alarmType,
    };

    $safeWebhook = htmlspecialchars($webhookName, ENT_QUOTES, 'UTF-8');
    $safeAlarm   = htmlspecialchars($alarmName,   ENT_QUOTES, 'UTF-8');
    $safeType    = htmlspecialchars($typeLabel,    ENT_QUOTES, 'UTF-8');
    $safeMsg     = htmlspecialchars($message,      ENT_QUOTES, 'UTF-8');
    $safeWUrl    = htmlspecialchars($webhookUrl,   ENT_QUOTES, 'UTF-8');
    $safeEUrl    = htmlspecialchars($eventUrl,     ENT_QUOTES, 'UTF-8');

    $htmlBody = "<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'><title>Allarme Webhook — {$safeAlarm}</title></head>
<body style='margin:0;padding:0;background:#0d0d14;font-family:Arial,sans-serif;'>
  <div style='max-width:600px;margin:40px auto;background:#1a1a2e;border-radius:12px;overflow:hidden;'>

    <div style='background:#e74c3c;padding:24px 32px;'>
      <h1 style='color:#fff;margin:0;font-size:22px;letter-spacing:.5px;'>&#9888; Allarme Webhook</h1>
      <p style='color:#ffd5d5;margin:6px 0 0;font-size:14px;'>" . APP_NAME . "</p>
    </div>

    <div style='padding:32px;color:#e8e8f0;'>
      <h2 style='color:#f87171;margin-top:0;font-size:20px;'>{$safeAlarm}</h2>

      <table style='width:100%;border-collapse:collapse;margin-bottom:24px;'>
        <tr>
          <td style='padding:8px 12px;background:#252540;border-radius:6px 6px 0 0;color:#8888aa;font-size:12px;text-transform:uppercase;letter-spacing:.8px;width:120px;'>Webhook</td>
          <td style='padding:8px 12px;background:#252540;border-radius:6px 6px 0 0;color:#e8e8f0;font-weight:600;'>{$safeWebhook}</td>
        </tr>
        <tr>
          <td style='padding:8px 12px;background:#1e1e38;color:#8888aa;font-size:12px;text-transform:uppercase;letter-spacing:.8px;'>Tipo</td>
          <td style='padding:8px 12px;background:#1e1e38;color:#e8e8f0;'>{$safeType}</td>
        </tr>
        <tr>
          <td style='padding:8px 12px;background:#252540;border-radius:0 0 6px 6px;color:#8888aa;font-size:12px;text-transform:uppercase;letter-spacing:.8px;'>Dettaglio</td>
          <td style='padding:8px 12px;background:#252540;border-radius:0 0 6px 6px;color:#fbbf24;'>{$safeMsg}</td>
        </tr>
      </table>

      <div style='text-align:center;margin:28px 0 16px;'>
        <a href='{$safeWUrl}'
           style='background:#4361ee;color:#fff;padding:13px 26px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;display:inline-block;'>
          Vai al Webhook
        </a>
      </div>

      <div style='text-align:center;margin-bottom:8px;'>
        <a href='{$safeEUrl}'
           style='color:#8888aa;font-size:13px;text-decoration:underline;'>
          Vuoi sapere di pi&ugrave; su questo allarme? Clicca qui per vedere il log dell\'evento.
        </a>
      </div>
    </div>

    <div style='padding:16px 32px;border-top:1px solid #252540;text-align:center;'>
      <p style='color:#8888aa;font-size:12px;margin:0;'>&copy; " . date('Y') . " " . APP_NAME . ". All rights reserved.</p>
    </div>
  </div>
</body>
</html>";

    $subject = "Allarme: {$alarmName} — {$typeLabel}";
    $ok = sendEmail($to, $subject, $htmlBody);
    logAlarmEmailAttempts($to, $subject, $ok, $eventId !== null ? [['event_id' => $eventId]] : []);
    return $ok;
}

/**
 * Send a single summary email for multiple alarm events targeting the same user.
 *
 * Each item:
 * - webhook_name
 * - alarm_name
 * - alarm_type
 * - message
 * - webhook_url
 * - event_url
 */
function sendAlarmDigestEmail(string $to, array $items): bool
{
    $items = array_values(array_filter($items, static fn($item) => is_array($item)));
    if (empty($items)) return true;
    if (count($items) === 1) {
        $item = $items[0];
        return sendAlarmEmail(
            (string)($to ?? ''),
            (string)($item['webhook_name'] ?? ''),
            (string)($item['alarm_name'] ?? ''),
            (string)($item['alarm_type'] ?? ''),
            (string)($item['message'] ?? ''),
            (string)($item['webhook_url'] ?? ''),
            (string)($item['event_url'] ?? ''),
            isset($item['event_id']) ? (int)$item['event_id'] : null
        );
    }

    $typeLabel = static function (string $alarmType): string {
        return match($alarmType) {
            'not_called_since'       => 'Nessuna chiamata ricevuta',
            'not_called_in_interval' => 'Nessuna chiamata nell\'intervallo',
            'called_in_interval'     => 'Chiamata ricevuta nell\'intervallo',
            'log_expression'         => 'Espressione sui log',
            default                  => $alarmType,
        };
    };

    $count = count($items);
    $firstWebhookUrl = htmlspecialchars((string)($items[0]['webhook_url'] ?? BASE_URL), ENT_QUOTES, 'UTF-8');
    $listHtml = '';
    foreach ($items as $item) {
        $safeWebhook = htmlspecialchars((string)($item['webhook_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $safeAlarm   = htmlspecialchars((string)($item['alarm_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $safeType    = htmlspecialchars($typeLabel((string)($item['alarm_type'] ?? '')), ENT_QUOTES, 'UTF-8');
        $safeMsg     = htmlspecialchars((string)($item['message'] ?? ''), ENT_QUOTES, 'UTF-8');
        $safeEventUrl= htmlspecialchars((string)($item['event_url'] ?? BASE_URL), ENT_QUOTES, 'UTF-8');

        $listHtml .= "
        <div style='padding:16px 0;border-top:1px solid #252540;'>
          <div style='display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;'>
            <div>
              <div style='color:#e8e8f0;font-weight:700;font-size:15px;'>{$safeAlarm}</div>
              <div style='color:#8888aa;font-size:12px;text-transform:uppercase;letter-spacing:.08em;margin-top:4px;'>{$safeWebhook} · {$safeType}</div>
            </div>
            <a href='{$safeEventUrl}' style='color:#fbbf24;font-size:13px;text-decoration:underline;'>Apri log</a>
          </div>
          <div style='margin-top:10px;color:#f3d1d1;line-height:1.55;'>{$safeMsg}</div>
        </div>";
    }

    $htmlBody = "<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'><title>Allarmi Webhook</title></head>
<body style='margin:0;padding:0;background:#0d0d14;font-family:Arial,sans-serif;'>
  <div style='max-width:700px;margin:40px auto;background:#1a1a2e;border-radius:12px;overflow:hidden;'>
    <div style='background:#e74c3c;padding:24px 32px;'>
      <h1 style='color:#fff;margin:0;font-size:22px;letter-spacing:.5px;'>&#9888; {$count} allarmi webhook</h1>
      <p style='color:#ffd5d5;margin:6px 0 0;font-size:14px;'>" . APP_NAME . "</p>
    </div>
    <div style='padding:32px;color:#e8e8f0;'>
      <p style='margin-top:0;color:#b8b8cc;line-height:1.6;'>Pi&ugrave; allarmi sono scattati nello stesso flusso. I log evento restano separati, ma questa email li raggruppa per evitare invii multipli.</p>
      {$listHtml}
      <div style='text-align:center;margin:28px 0 8px;'>
        <a href='{$firstWebhookUrl}'
           style='background:#4361ee;color:#fff;padding:13px 26px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;display:inline-block;'>
          Vai al Webhook
        </a>
      </div>
    </div>
    <div style='padding:16px 32px;border-top:1px solid #252540;text-align:center;'>
      <p style='color:#8888aa;font-size:12px;margin:0;'>&copy; " . date('Y') . " " . APP_NAME . ". All rights reserved.</p>
    </div>
  </div>
</body>
</html>";

    $subject = "Allarmi webhook: {$count} eventi";
    $ok = sendEmail($to, $subject, $htmlBody);
    logAlarmEmailAttempts($to, $subject, $ok, $items);
    return $ok;
}

/**
 * Build a standard HTML email template.
 */
function buildEmailTemplate(
    string $title,
    string $body,
    string $buttonUrl = '',
    string $buttonText = '',
    string $buttonColor = '#4361ee'
): string {
    $button = '';
    if ($buttonUrl && $buttonText) {
        $button = "<div style='text-align:center;margin:32px 0;'>
            <a href='" . htmlspecialchars($buttonUrl, ENT_QUOTES) . "'
               style='background:$buttonColor;color:#fff;padding:14px 28px;border-radius:6px;text-decoration:none;font-weight:600;display:inline-block;'>
               " . htmlspecialchars($buttonText) . "
            </a>
        </div>";
    }

    return "<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'><title>" . htmlspecialchars($title) . "</title></head>
<body style='margin:0;padding:0;background:#0d0d14;font-family:Arial,sans-serif;'>
  <div style='max-width:600px;margin:40px auto;background:#1a1a2e;border-radius:12px;overflow:hidden;'>
    <div style='background:#4361ee;padding:24px 32px;'>
      <h1 style='color:#fff;margin:0;font-size:24px;'>" . APP_NAME . "</h1>
    </div>
    <div style='padding:32px;color:#e8e8f0;'>
      <h2 style='color:#e8e8f0;margin-top:0;'>" . htmlspecialchars($title) . "</h2>
      <p style='line-height:1.6;color:#b8b8cc;'>" . $body . "</p>
      $button
    </div>
    <div style='padding:16px 32px;border-top:1px solid #252540;text-align:center;'>
      <p style='color:#8888aa;font-size:12px;margin:0;'>&copy; " . date('Y') . " " . APP_NAME . ". All rights reserved.</p>
    </div>
  </div>
</body>
</html>";
}
