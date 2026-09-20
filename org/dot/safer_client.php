<?php
// Lightweight SaferWebAPI client helper (no Composer required).
if (!defined('CACHE_TTL')) { define('CACHE_TTL', 900); }

function safer_call($endpoint, $cacheKey = null) {
    if (!defined('SAFER_API_KEY') || SAFER_API_KEY === 'YOUR_API_KEY_HERE') {
        throw new Exception('SAFER_API_KEY not set. Edit config.php and add your key.');
    }
    $base = 'https://saferwebapi.com';
    $url  = $base . $endpoint;

    // Simple file cache
    $cacheDir = __DIR__ . '/cache';
    if ($cacheKey && CACHE_TTL > 0) {
        if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0775, true); }
        $cacheFile = $cacheDir . '/' . preg_replace('/[^a-z0-9\-_.]/i', '_', $cacheKey) . '.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < CACHE_TTL)) {
            $contents = file_get_contents($cacheFile);
            $data = json_decode($contents, true);
            if ($data !== null) { return $data; }
        }
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . SAFER_API_KEY],
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('cURL error: ' . $err);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 400) {
        throw new Exception('SaferWebAPI returned HTTP ' . $status . ': ' . $res);
    }
    $data = json_decode($res, true);
    if ($data === null) {
        throw new Exception('Invalid JSON from SaferWebAPI');
    }

    if ($cacheKey && CACHE_TTL > 0) {
        @file_put_contents($cacheFile, json_encode($data));
    }
    return $data;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
