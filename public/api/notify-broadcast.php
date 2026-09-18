<?php
declare(strict_types=1);

/**
 * Public read of the current test broadcast (if any).
 * Safe to leave; returns null when nothing pending / test cleared.
 */
require __DIR__ . '/_init.php';

use MiniKbp\Bootstrap;

$path = Bootstrap::cacheDir() . '/notify_broadcast_v1.json';
if (!is_file($path)) {
    mkbp_json_response(['success' => true, 'broadcast' => null]);
    exit;
}

$raw = file_get_contents($path);
$data = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($data) || empty($data['id'])) {
    mkbp_json_response(['success' => true, 'broadcast' => null]);
    exit;
}

// Expire after 2 hours so stale pings don't reappear forever
$age = time() - (int) ($data['createdAt'] ?? 0);
if ($age < 0 || $age > 7200) {
    mkbp_json_response(['success' => true, 'broadcast' => null]);
    exit;
}

mkbp_json_response([
    'success' => true,
    'broadcast' => [
        'id' => (string) $data['id'],
        'title' => (string) ($data['title'] ?? 'MiniKBP'),
        'body' => (string) ($data['body'] ?? ''),
        'createdAt' => (int) ($data['createdAt'] ?? 0),
    ],
]);
