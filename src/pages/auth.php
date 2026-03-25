<?php
$action = $_GET['action'] ?? '';

if (!authEnabled()) {
    loginLocalUser($db);
    header('Location: ' . BASE_URL . '/?page=dashboard');
    exit;
}

switch ($action) {
    case 'login':
        if (!GITHUB_CLIENT_ID) {
            setFlash('error', 'GitHub OAuth is not configured. Please set GITHUB_CLIENT_ID and GITHUB_CLIENT_SECRET.');
            header('Location: ' . BASE_URL . '/');
            exit;
        }
        $authUrl = getAuthUrl();
        header('Location: ' . $authUrl);
        exit;

    case 'callback':
        try {
            $user = handleOAuthCallback($db);
            setFlash('success', 'Welcome back, ' . ($user['display_name'] ?: $user['username']) . '!');
            header('Location: ' . BASE_URL . '/?page=dashboard');
        } catch (Throwable $e) {
            setFlash('error', __('auth.oauth_error') . ': ' . $e->getMessage());
            header('Location: ' . BASE_URL . '/');
        }
        exit;

    case 'logout':
        logout(); // redirects internally
        exit;

    default:
        header('Location: ' . BASE_URL . '/');
        exit;
}
