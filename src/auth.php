<?php

function authEnabled(): bool {
    return HOOKPOOL_AUTH_ENABLED;
}

function ensureLocalUser(PDO $db): array {
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute(['local']);
    $user = $stmt->fetch();

    if ($user) {
        return $user;
    }

    $ins = $db->prepare(
        'INSERT INTO users (github_id, username, display_name, avatar_url, email)
         VALUES (?, ?, ?, ?, ?)'
    );
    $ins->execute([null, 'local', 'Local', '', null]);

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
            'User-Agent' => 'HookPool/1.0',
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
            'User-Agent'    => 'HookPool/1.0',
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

    // If email is null from the public endpoint, try to fetch primary verified email
    if (!$email) {
        $emailsJson = httpGet(
            'https://api.github.com/user/emails',
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept'        => 'application/vnd.github+json',
                'User-Agent'    => 'HookPool/1.0',
            ]
        );
        if ($emailsJson !== false) {
            $emails = json_decode($emailsJson, true);
            if (is_array($emails)) {
                foreach ($emails as $e) {
                    if (($e['primary'] ?? false) && ($e['verified'] ?? false)) {
                        $email = $e['email'];
                        break;
                    }
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
    } else {
        // Insert new user
        $ins = $db->prepare(
            'INSERT INTO users (github_id, username, display_name, avatar_url, email)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute([$githubId, $username, $displayName, $avatarUrl, $email]);
        $userId = (int)$db->lastInsertId();
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
