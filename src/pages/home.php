<?php
// If already logged in, redirect to dashboard
$current_user = getCurrentUser($db);
if ($current_user) {
    header('Location: ' . BASE_URL . '/?page=dashboard');
    exit;
}

$page_title = __('nav.home');
?>

<div class="landing">
    <section class="hero">
        <div class="hero-content">
            <div class="hero-badge">Open Source · <?= authEnabled() ? 'GitHub OAuth' : 'Single User' ?> · PHP</div>
            <h1 class="hero-title">
                <span class="logo-hook">🪝</span> <?= APP_NAME ?>
                <br><span class="hero-sub"><?= __('home.hero_title') ?></span>
            </h1>
            <p class="hero-desc"><?= __('home.hero_subtitle') ?></p>
            <a href="<?= authEnabled() ? BASE_URL . '/?page=auth&action=login' : BASE_URL . '/?page=dashboard' ?>" class="btn btn-primary btn-lg hero-cta">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" style="vertical-align:middle;margin-right:8px">
                    <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.012 8.012 0 0 0 16 8c0-4.42-3.58-8-8-8z"/>
                </svg>
                <?= __('home.cta') ?>
            </a>
        </div>
        <div class="hero-visual">
            <div class="logcat-preview">
                <div class="logcat-bar">
                    <span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span>
                    <span class="logcat-title">Event Feed</span>
                </div>
                <div class="logcat-rows">
                    <div class="logcat-row">
                        <span class="badge-method get">GET</span>
                        <span class="logcat-time">12:34:01</span>
                        <span class="logcat-path">/test/a7da8d</span>
                        <span class="badge-status ok">200</span>
                    </div>
                    <div class="logcat-row">
                        <span class="badge-method post">POST</span>
                        <span class="logcat-time">12:34:05</span>
                        <span class="logcat-path">/chess/q1w2e3</span>
                        <span class="badge-status ok">200</span>
                    </div>
                    <div class="logcat-row new">
                        <span class="badge-method post">POST</span>
                        <span class="logcat-time">12:34:09</span>
                        <span class="logcat-path">/test/a7da8d</span>
                        <span class="badge-status ok">200</span>
                    </div>
                    <div class="logcat-row">
                        <span class="badge-method put">PUT</span>
                        <span class="logcat-time">12:34:12</span>
                        <span class="logcat-path">/iot/f9k2p1</span>
                        <span class="badge-status rejected">Guard</span>
                    </div>
                    <div class="logcat-row new">
                        <span class="badge-method post">POST</span>
                        <span class="logcat-time">12:34:15</span>
                        <span class="logcat-path">/test/a7da8d</span>
                        <span class="badge-status ok">200</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="features">
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">📁</div>
                <h3><?= __('home.feature_projects') ?></h3>
                <p><?= __('home.feature_projects_desc') ?></p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🪝</div>
                <h3><?= __('home.feature_hooks') ?></h3>
                <p><?= __('home.feature_hooks_desc') ?></p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📋</div>
                <h3><?= __('home.feature_events') ?></h3>
                <p><?= __('home.feature_events_desc') ?></p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🛡️</div>
                <h3><?= __('home.feature_guards') ?></h3>
                <p><?= __('home.feature_guards_desc') ?></p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">↗️</div>
                <h3><?= __('home.feature_forward') ?></h3>
                <p><?= __('home.feature_forward_desc') ?></p>
            </div>
        </div>
    </section>

    <section class="landing-cta">
        <h2>Start receiving webhooks in seconds</h2>
        <p><?= authEnabled() ? 'Login with your GitHub account — no email, no password, no friction.' : 'Single-user mode is enabled. The app opens directly with the local account.' ?></p>
        <a href="<?= authEnabled() ? BASE_URL . '/?page=auth&action=login' : BASE_URL . '/?page=dashboard' ?>" class="btn btn-primary btn-lg">
            <?= authEnabled() ? __('auth.login_with_github') : __('home.open_dashboard') ?>
        </a>
    </section>
</div>
