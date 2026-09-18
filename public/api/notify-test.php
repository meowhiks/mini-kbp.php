<?php
declare(strict_types=1);

/**
 * TEST: curl-trigger for a site-wide notification ping.
 *
 * Выключить быстро:
 *   1) поставь MKBP_NOTIFY_TEST_ENABLED = false  ← достаточно
 *   2) или удали этот файл
 *
 * Пример:
 *   curl -sS 'https://mini-kbp.site/api/notify-test.php?key=SECRET'
 *   curl -sS 'https://mini-kbp.site/api/notify-test.php?key=SECRET&title=MiniKBP&body=Привет'
 *   curl -sS 'https://mini-kbp.site/api/notify-test.php?key=SECRET&clear=1'
 *
 * Получат только клиенты с включёнными уведомлениями (SW подхватит при проверке / опросе).
 */
const MKBP_NOTIFY_TEST_ENABLED = false;

require __DIR__ . '/_init.php';

use MiniKbp\Bootstrap;

if (!MKBP_NOTIFY_TEST_ENABLED) {
    mkbp_json_response(['success' => false, 'error' => 'notify-test disabled', 'code' => 'disabled'], 404);
    exit;
}

$secret = (string) (getenv('NOTIFY_TEST_SECRET') ?: '');
$key = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
if ($secret === '' || $key === '' || !hash_equals($secret, $key)) {
    mkbp_json_response(['success' => false, 'error' => 'forbidden'], 403);
    exit;
}

$path = Bootstrap::cacheDir() . '/notify_broadcast_v1.json';

if (isset($_GET['clear']) || isset($_POST['clear'])) {
    @unlink($path);
    mkbp_json_response(['success' => true, 'cleared' => true]);
    exit;
}

$title = trim((string) ($_GET['title'] ?? $_POST['title'] ?? 'MiniKBP'));
$body = trim((string) ($_GET['body'] ?? $_POST['body'] ?? 'Тестовое уведомление с сайта'));
if ($title === '') {
    $title = 'MiniKBP';
}
if ($body === '') {
    $body = 'Тестовое уведомление с сайта';
}
if (mb_strlen($title) > 80) {
    $title = mb_substr($title, 0, 80);
}
if (mb_strlen($body) > 200) {
    $body = mb_substr($body, 0, 200);
}

$payload = [
    'id' => bin2hex(random_bytes(8)),
    'title' => $title,
    'body' => $body,
    'createdAt' => time(),
];

@file_put_contents(
    $path,
    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    LOCK_EX
);

mkbp_json_response([
    'success' => true,
    'broadcast' => $payload,
    'hint' => 'Клиенты с включёнными уведомлениями покажут это при следующей проверке SW (~до минуты, если вкладка открыта).',
]);
