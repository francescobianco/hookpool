<?php

/**
 * Translate a key. Falls back to the key itself if not found.
 */
function __(string $key): string {
    global $translations;
    return $translations[$key] ?? $key;
}

// Determine language: cookie > browser Accept-Language > default
if (isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], SUPPORTED_LANGS, true)) {
    $lang = $_COOKIE['lang'];
} else {
    // First visit: detect from browser Accept-Language header
    $lang = DEFAULT_LANG;
    $acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if ($acceptLang) {
        // Parse "it-IT,it;q=0.9,en-US;q=0.8,en;q=0.7" → ordered list of base codes
        preg_match_all('/([a-z]{2})(?:-[a-z]{2})?(?:;q=([0-9.]+))?/i', $acceptLang, $m, PREG_SET_ORDER);
        $preferred = [];
        foreach ($m as $entry) {
            $code = strtolower($entry[1]);
            $q    = isset($entry[2]) && $entry[2] !== '' ? (float)$entry[2] : 1.0;
            if (!isset($preferred[$code])) $preferred[$code] = $q;
        }
        arsort($preferred);
        foreach (array_keys($preferred) as $code) {
            if (in_array($code, SUPPORTED_LANGS, true)) {
                $lang = $code;
                break;
            }
        }
    }
}

$translations = require __DIR__ . '/translations/' . $lang . '.php';
