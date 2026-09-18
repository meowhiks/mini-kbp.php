<?php
/**
 * Resolve autoload for Docker (../src) and FTP flat (./src) layouts.
 * Keep this file parseable on old PHP so we never leak runtime details.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header_remove('X-Powered-By');

// CORS: same site + common local/dev hosts (Metrika / SPA fetch)
$__origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
$__allow = array(
    'https://mini-kbp.site',
    'https://www.mini-kbp.site',
    'http://localhost',
    'http://127.0.0.1',
);
if ($__origin !== '') {
    $__ok = in_array($__origin, $__allow, true);
    if (!$__ok && preg_match('#^https?://(localhost|127\\.0\\.0\\.1)(:\\d+)?$#', $__origin)) {
        $__ok = true;
    }
    if ($__ok) {
        header('Access-Control-Allow-Origin: ' . $__origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');
    }
}
if (isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
    http_response_code(204);
    exit;
}


if (!defined('PHP_VERSION_ID') || PHP_VERSION_ID < 80100) {
    http_response_code(503);
    echo json_encode(array(
        'success' => false,
        'ok' => false,
        'error' => 'Сервис временно недоступен.',
    ));
    exit;
}

$candidates = array(
    dirname(__DIR__) . '/src/autoload.php',
    dirname(__DIR__) . '/../src/autoload.php',
);

$loaded = false;
foreach ($candidates as $file) {
    if (is_file($file)) {
        require $file;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'error' => 'Сервис временно недоступен.',
    ));
    exit;
}

require __DIR__ . '/_errors.php';

// Health must stay free — Docker/CF probes every 15s must not hit rate limits.
$__script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
$__isHealth = str_ends_with($__script, '/health.php')
    || str_ends_with(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? '')), '/health.php');
if (!$__isHealth) {
    \MiniKbp\AppLog::info('api.request', array(
        'script' => basename($__script),
        'query' => (string) ($_SERVER['QUERY_STRING'] ?? ''),
    ));
    // Anti-abuse for API only — HTML shell is never rate-limited.
    \MiniKbp\ClientRateLimit::enforce('api_all', 180, 60);
}