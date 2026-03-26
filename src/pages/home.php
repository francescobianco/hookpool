<?php
// If already logged in, redirect to dashboard
$current_user = getCurrentUser($db);
if ($current_user) {
    header('Location: ' . BASE_URL . '/?page=dashboard');
    exit;
}

$page_title = __('nav.home');
$github_url = 'https://github.com/francescobianco/hookpool';
?>

<div class="landing">
    <section class="hero">
        <div class="hero-content">

            <div class="hero-opensource-strip">
                <span class="chip accent">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.012 8.012 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg>
                    <?= __('home.opensource_label') ?>
                </span>
                <span class="chip">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16"><path d="M2 2a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H2zm0 1h12a1 1 0 0 1 1 1v1H1V4a1 1 0 0 1 1-1zm0 3h12v6a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V6h1z"/></svg>
                    <?= __('home.selfhost_label') ?>
                </span>
                <a href="<?= $github_url ?>" target="_blank" rel="noopener" class="chip" style="text-decoration:none;color:inherit;">
                    ★ Star on GitHub
                </a>
            </div>

            <h1 class="hero-title">
                <span class="hero-name"><span class="logo-hook">hook</span><span class="logo-pool">pool</span></span>
                <br><span class="hero-sub"><?= __('home.hero_title') ?></span>
            </h1>

            <p class="hero-desc"><?= __('home.hero_subtitle') ?></p>

            <div class="hero-actions">
                <a href="<?= authEnabled() ? BASE_URL . '/?page=auth&action=login' : BASE_URL . '/?page=dashboard' ?>" class="btn btn-primary btn-lg hero-cta">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" style="vertical-align:middle;margin-right:8px"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.012 8.012 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg>
                    <?= __('home.cta') ?>
                </a>
                <a href="<?= $github_url ?>" target="_blank" rel="noopener" class="btn btn-outline btn-lg hero-github-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16" style="vertical-align:middle;margin-right:7px"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.012 8.012 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg>
                    <?= __('home.hero_github') ?>
                </a>
            </div>

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
                <div class="feature-icon">🔔</div>
                <h3><?= __('home.feature_alarms') ?></h3>
                <p><?= __('home.feature_alarms_desc') ?></p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📡</div>
                <h3><?= __('home.feature_monitor') ?></h3>
                <p><?= __('home.feature_monitor_desc') ?></p>
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
        <h2><?= __('home.cta_title') ?></h2>
        <p><?= authEnabled() ? __('home.cta_sub_auth') : __('home.cta_sub_local') ?></p>
        <a href="<?= authEnabled() ? BASE_URL . '/?page=auth&action=login' : BASE_URL . '/?page=dashboard' ?>" class="btn btn-primary btn-lg">
            <?= authEnabled() ? __('home.cta') : __('home.open_dashboard') ?>
        </a>
    </section>
</div>