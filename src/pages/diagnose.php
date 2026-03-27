<?php
if (!$current_user) {
    header('Location: ' . BASE_URL . '/?page=home');
    exit;
}

$page_title = 'System Diagnose';

// ── Checks ────────────────────────────────────────────────────────────────────

// 1. PHP version
$phpVersion    = PHP_VERSION;
$phpVersionOk  = version_compare(PHP_VERSION, '8.0.0', '>=');

// 2. curl extension
$curlLoaded    = extension_loaded('curl');
$curlVersion   = $curlLoaded ? (curl_version()['version'] ?? '?') : null;
$opensslVer    = $curlLoaded ? (curl_version()['ssl_version'] ?? '?') : null;

// 3. PDO + driver
$pdoDrivers    = PDO::getAvailableDrivers();
$pdoSqlite     = in_array('sqlite', $pdoDrivers);
$pdoMysql      = in_array('mysql', $pdoDrivers);

// 4. DB type in use
$dbType        = strtolower(getenv('DB_TYPE') ?: 'sqlite');

// 5. curl connectivity test
$curlTestUrl   = 'https://raw.githubusercontent.com/francescobianco/hookpool/refs/heads/main/LICENSE';
$curlTestOk    = false;
$curlTestStatus = null;
$curlTestError  = null;
$curlTestSnippet = null;

if ($curlLoaded) {
    $ch = curl_init($curlTestUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Hookpool/' . APP_VERSION . ' diagnose');
    $curlTestBody   = curl_exec($ch);
    $curlTestStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlTestError  = curl_error($ch) ?: null;
    curl_close($ch);

    if ($curlTestBody !== false && $curlTestStatus === 200) {
        $curlTestOk      = true;
        $curlTestSnippet = trim(substr($curlTestBody, 0, 120));
    }
}

// 6. User count
$userCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();

// 7. Writable data dir
$dataDir       = defined('DB_PATH') ? dirname(DB_PATH) : (dirname(__DIR__, 2) . '/data');
$dataDirWrite  = is_writable($dataDir);

// 7. Uploads dir
$uploadsDir    = defined('UPLOADS_DIR') ? UPLOADS_DIR : null;
$uploadsDirWrite = $uploadsDir ? is_writable($uploadsDir) : null;

// Helper
function diagBadge(bool $ok, string $okLabel = 'OK', string $failLabel = 'FAIL'): string {
    $cls = $ok ? 'badge-success' : 'badge-error';
    $lbl = $ok ? $okLabel : $failLabel;
    return '<span class="badge ' . $cls . '">' . $lbl . '</span>';
}
?>

<div class="page-container" style="max-width:740px">
    <div class="page-header">
        <h1>System Diagnose</h1>
        <a href="<?= BASE_URL ?>/?page=settings" class="btn btn-outline">← Settings</a>
    </div>

    <?php if (!$curlLoaded): ?>
    <div class="alert alert-error" role="alert" style="font-size:1rem;font-weight:600">
        ⚠️ cURL PHP extension is NOT installed. Forwarding actions will not work.
        Install it with: <code>apt install php-curl</code> or enable it in <code>php.ini</code>.
        <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
    </div>
    <?php elseif (!$curlTestOk): ?>
    <div class="alert alert-error" role="alert" style="font-size:0.95rem;font-weight:600">
        ⚠️ cURL is installed but the outbound connectivity test failed.
        Forwarding actions may not reach external endpoints.
        <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Hookpool -->
    <section class="section">
        <h2>Hookpool</h2>
        <div class="card">
            <table class="diag-table">
                <tr>
                    <td>Version</td>
                    <td><code><?= e(APP_VERSION) ?></code></td>
                    <td></td>
                </tr>
                <tr>
                    <td>Environment</td>
                    <td><code><?= e(APP_ENV ?: 'production') ?></code></td>
                    <td></td>
                </tr>
                <tr>
                    <td>Auth</td>
                    <td><code><?= authEnabled() ? 'GitHub OAuth' : 'disabled (single-user)' ?></code></td>
                    <td></td>
                </tr>
                <tr>
                    <td>Users</td>
                    <td><code><?= $userCount ?></code></td>
                    <td></td>
                </tr>
                <tr>
                    <td>Database</td>
                    <td><code><?= e(strtoupper($dbType)) ?></code></td>
                    <td><?= diagBadge($dbType === 'sqlite' ? $pdoSqlite : $pdoMysql) ?></td>
                </tr>
                <tr>
                    <td>Data directory writable</td>
                    <td><code><?= e($dataDir) ?></code></td>
                    <td><?= diagBadge($dataDirWrite) ?></td>
                </tr>
                <?php if ($uploadsDir !== null): ?>
                <tr>
                    <td>Uploads directory writable</td>
                    <td><code><?= e($uploadsDir) ?></code></td>
                    <td><?= diagBadge((bool)$uploadsDirWrite) ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
    </section>

    <!-- PHP -->
    <section class="section">
        <h2>PHP</h2>
        <div class="card">
            <table class="diag-table">
                <tr>
                    <td>PHP version</td>
                    <td><code><?= e($phpVersion) ?></code></td>
                    <td><?= diagBadge($phpVersionOk, $phpVersion, $phpVersion . ' (requires ≥ 8.0)') ?></td>
                </tr>
                <tr>
                    <td>PDO SQLite</td>
                    <td></td>
                    <td><?= diagBadge($pdoSqlite) ?></td>
                </tr>
                <tr>
                    <td>PDO MySQL</td>
                    <td></td>
                    <td><?= diagBadge($pdoMysql, 'OK', 'not available') ?></td>
                </tr>
                <tr>
                    <td>cURL extension</td>
                    <td><?= $curlLoaded ? '<code>' . e($curlVersion) . '</code>' : '' ?></td>
                    <td><?= diagBadge($curlLoaded, 'installed', 'NOT INSTALLED ⚠️') ?></td>
                </tr>
                <?php if ($curlLoaded): ?>
                <tr>
                    <td>SSL / TLS</td>
                    <td><code><?= e($opensslVer) ?></code></td>
                    <td></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
    </section>

    <!-- Connectivity -->
    <section class="section">
        <h2>Outbound connectivity</h2>
        <div class="card">
            <?php if (!$curlLoaded): ?>
            <p class="text-muted">Cannot test — cURL extension is not installed.</p>
            <?php else: ?>
            <table class="diag-table">
                <tr>
                    <td>Test URL</td>
                    <td><code style="font-size:0.8rem;word-break:break-all"><?= e($curlTestUrl) ?></code></td>
                    <td></td>
                </tr>
                <tr>
                    <td>HTTP status</td>
                    <td><code><?= $curlTestStatus !== null ? $curlTestStatus : '—' ?></code></td>
                    <td><?= diagBadge($curlTestOk, 'reachable', 'unreachable ⚠️') ?></td>
                </tr>
                <?php if ($curlTestError): ?>
                <tr>
                    <td>Error</td>
                    <td colspan="2"><code class="text-error"><?= e($curlTestError) ?></code></td>
                </tr>
                <?php endif; ?>
                <?php if ($curlTestOk && $curlTestSnippet): ?>
                <tr>
                    <td>Response preview</td>
                    <td colspan="2"><code style="font-size:0.8rem"><?= e($curlTestSnippet) ?>…</code></td>
                </tr>
                <?php endif; ?>
            </table>
            <?php endif; ?>
        </div>
    </section>
</div>
