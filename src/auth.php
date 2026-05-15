<?php

function authEnabled(): bool {
    return HOOKPOOL_AUTH_ENABLED;
}

function findActiveUserByEmail(PDO $db, string $email): ?array {
    $email = trim($email);
    if ($email === '') {
        return null;
    }

    $stmt = $db->prepare('
        SELECT *
        FROM users
        WHERE email IS NOT NULL
          AND LOWER(email) = LOWER(?)
          AND deleted_at IS NULL
        ORDER BY CASE WHEN github_id IS NULL THEN 1 ELSE 0 END, id
        LIMIT 1
    ');
    $stmt->execute([$email]);
    return $stmt->fetch() ?: null;
}

function findClaimableUserByEmails(PDO $db, array $emails): ?array {
    $seen = [];
    foreach ($emails as $email) {
        $email = trim((string)$email);
        if ($email === '') {
            continue;
        }
        $key = mb_strtolower($email, 'UTF-8');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $stmt = $db->prepare('
            SELECT *
            FROM users
            WHERE github_id IS NULL
              AND email IS NOT NULL
              AND LOWER(email) = LOWER(?)
              AND deleted_at IS NULL
            ORDER BY id
            LIMIT 1
        ');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user) {
            return $user;
        }
    }

    return null;
}

function mergeUserOwnedProjects(PDO $db, int $fromUserId, int $toUserId): void {
    if ($fromUserId <= 0 || $toUserId <= 0 || $fromUserId === $toUserId) {
        return;
    }

    $db->prepare('UPDATE projects SET user_id = ? WHERE user_id = ?')->execute([$toUserId, $fromUserId]);
    $db->prepare('UPDATE categories SET user_id = ? WHERE user_id = ?')->execute([$toUserId, $fromUserId]);

    $optionalTables = [
        'filter_presets',
        'known_ips',
        'analytics_views',
        'control_panel_widgets',
    ];
    foreach ($optionalTables as $table) {
        try {
            $db->prepare("UPDATE $table SET user_id = ? WHERE user_id = ?")->execute([$toUserId, $fromUserId]);
        } catch (Throwable $e) {
            // Older installations may not have every optional table yet.
        }
    }

    $db->prepare('UPDATE users SET deleted_at = ? WHERE id = ? AND github_id IS NULL')
       ->execute([date('Y-m-d H:i:s'), $fromUserId]);
}

function ensureLocalUser(PDO $db): array {
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute(['local']);
    $user = $stmt->fetch();

    if ($user) {
        $adminEmail = trim((string)ADMIN_EMAIL);
        $currentEmail = trim((string)($user['email'] ?? ''));
        if ($adminEmail !== '' && $currentEmail !== $adminEmail) {
            $db->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$adminEmail, (int)$user['id']]);
            $stmt->execute(['local']);
            $user = $stmt->fetch();
        }
        return $user;
    }

    $ins = $db->prepare(
        'INSERT INTO users (github_id, username, display_name, avatar_url, email)
         VALUES (?, ?, ?, ?, ?)'
    );
    $adminEmail = trim((string)ADMIN_EMAIL);
    $ins->execute([null, 'local', 'Local', '', $adminEmail !== '' ? $adminEmail : null]);

    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int)$db->lastInsertId()]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new RuntimeException('Failed to bootstrap the local user.');
    }

    return $user;
}

/**
 * Generate the GitHub OAuth authorization URL and save state in session.
 */
function getAuthUrl(): string {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    $params = http_build_query([
        'client_id'    => GITHUB_CLIENT_ID,
        'redirect_uri' => BASE_URL . '/?page=auth&action=callback',
        'scope'        => 'read:user user:email',
        'state'        => $state,
    ]);

    return 'https://github.com/login/oauth/authorize?' . $params;
}

/**
 * Handle the GitHub OAuth callback:
 * - Validate state
 * - Exchange code for access token
 * - Fetch GitHub user info
 * - Upsert user in DB
 * - Set session
 * Returns user array on success, throws on failure.
 */
function handleOAuthCallback(PDO $db): array {
    if (!authEnabled()) {
        return loginLocalUser($db);
    }

    $state = $_GET['state'] ?? '';
    $code  = $_GET['code']  ?? '';

    $expectedState = $_SESSION['oauth_state'] ?? '';
    unset($_SESSION['oauth_state']);

    if (!$state || !hash_equals($expectedState, $state)) {
        throw new RuntimeException('Invalid OAuth state parameter.');
    }

    if (!$code) {
        throw new RuntimeException('No OAuth code received from GitHub.');
    }

    // Exchange code for access token
    $tokenResponse = httpPost(
        'https://github.com/login/oauth/access_token',
        [
            'client_id'     => GITHUB_CLIENT_ID,
            'client_secret' => GITHUB_CLIENT_SECRET,
            'code'          => $code,
            'redirect_uri'  => BASE_URL . '/?page=auth&action=callback',
        ],
        [
            'Accept'     => 'application/json',
            'User-Agent' => 'Hookpool/1.0',
        ]
    );

    if ($tokenResponse === false) {
        throw new RuntimeException('Failed to connect to GitHub token endpoint.');
    }

    $tokenData = json_decode($tokenResponse, true);
    $accessToken = $tokenData['access_token'] ?? '';

    if (!$accessToken) {
        $errorDesc = $tokenData['error_description'] ?? ($tokenData['error'] ?? 'Unknown error');
        throw new RuntimeException('GitHub OAuth error: ' . $errorDesc);
    }

    // Fetch user info from GitHub API
    $userJson = httpGet(
        'https://api.github.com/user',
        [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'Hookpool/1.0',
        ]
    );

    if ($userJson === false) {
        throw new RuntimeException('Failed to fetch user info from GitHub.');
    }

    $githubUser = json_decode($userJson, true);
    if (!isset($githubUser['id'])) {
        throw new RuntimeException('Invalid user data received from GitHub.');
    }

    $githubId    = (int)$githubUser['id'];
    $username    = $githubUser['login'] ?? '';
    $displayName = $githubUser['name'] ?? $username;
    $avatarUrl   = $githubUser['avatar_url'] ?? '';
    $email       = $githubUser['email'] ?? null;
    $verifiedEmails = [];
    if ($email) {
        $verifiedEmails[] = $email;
    }

    $emailsJson = httpGet(
        'https://api.github.com/user/emails',
        [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'Hookpool/1.0',
        ]
    );
    if ($emailsJson !== false) {
        $emails = json_decode($emailsJson, true);
        if (is_array($emails)) {
            foreach ($emails as $e) {
                $candidate = trim((string)($e['email'] ?? ''));
                if ($candidate === '' || !($e['verified'] ?? false)) {
                    continue;
                }
                $verifiedEmails[] = $candidate;
                if (($e['primary'] ?? false)) {
                    $email = $candidate;
                }
            }
        }
    }

    // Upsert user in DB
    $stmt = $db->prepare('SELECT * FROM users WHERE github_id = ? AND deleted_at IS NULL');
    $stmt->execute([$githubId]);
    $existingUser = $stmt->fetch();

    if ($existingUser) {
        // Update existing user info
        $upd = $db->prepare(
            'UPDATE users SET username = ?, display_name = ?, avatar_url = ?, email = ?
             WHERE github_id = ? AND deleted_at IS NULL'
        );
        $upd->execute([$username, $displayName, $avatarUrl, $email, $githubId]);
        $userId = (int)$existingUser['id'];
        $claimableUser = findClaimableUserByEmails($db, $verifiedEmails);
        if ($claimableUser && (int)$claimableUser['id'] !== $userId) {
            mergeUserOwnedProjects($db, (int)$claimableUser['id'], $userId);
        }
    } else {
        $claimableUser = findClaimableUserByEmails($db, $verifiedEmails);
        if ($claimableUser) {
            $userId = (int)$claimableUser['id'];
            $upd = $db->prepare(
                'UPDATE users SET github_id = ?, username = ?, display_name = ?, avatar_url = ?, email = ?
                 WHERE id = ? AND github_id IS NULL AND deleted_at IS NULL'
            );
            $upd->execute([$githubId, $username, $displayName, $avatarUrl, $email, $userId]);
        } else {
            // Insert new user
            $ins = $db->prepare(
                'INSERT INTO users (github_id, username, display_name, avatar_url, email)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $ins->execute([$githubId, $username, $displayName, $avatarUrl, $email]);
            $userId = (int)$db->lastInsertId();
        }
    }

    // Fetch the full user record
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    // Regenerate session ID after login (security: session fixation prevention)
    session_regenerate_id(true);

    // Store user in session
    $_SESSION['user_id'] = $userId;

    return $user;
}

function loginLocalUser(PDO $db): array {
    $user = ensureLocalUser($db);

    if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] !== (int)$user['id']) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id'] = (int)$user['id'];

    return $user;
}

/**
 * Get the currently authenticated user from session, or null if not logged in.
 */
function getCurrentUser(PDO $db): ?array {
    if (!authEnabled()) {
        return loginLocalUser($db);
    }

    if (!isset($_SESSION['user_id'])) return null;

    $userId = (int)$_SESSION['user_id'];
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    return $user ?: null;
}

/**
 * Require authentication; redirect to home if not logged in.
 * Returns the current user array.
 */
function requireAuth(PDO $db): array {
    $user = getCurrentUser($db);
    if (!$user) {
        setFlash('error', 'You must be logged in to access this page.');
        header('Location: ' . BASE_URL . '/');
        exit;
    }
    return $user;
}

/**
 * Destroy session and redirect to home.
 */
function logout(): void {
    if (!authEnabled()) {
        header('Location: ' . BASE_URL . '/?page=dashboard');
        exit;
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
    header('Location: ' . BASE_URL . '/');
    exit;
}
