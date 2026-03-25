<?php

/**
 * Translate a key. Falls back to the key itself if not found.
 */
function __(string $key): string {
    global $translations;
    return $translations[$key] ?? $key;
}

// Determine current language from cookie or default
$lang = $_COOKIE['lang'] ?? DEFAULT_LANG;
if (!in_array($lang, SUPPORTED_LANGS)) {
    $lang = DEFAULT_LANG;
}

$translations = require __DIR__ . '/translations/' . $lang . '.php';
