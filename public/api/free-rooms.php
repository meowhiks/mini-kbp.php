<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use MiniKbp\ClientRateLimit;
use MiniKbp\FreeRooms;

try {
    ClientRateLimit::enforce('free_rooms', 20, 60);
    $when = trim((string) ($_GET['when'] ?? 'now'));
    if (!in_array($when, ['now', 'tomorrow', 'date'], true)) {
        mkbp_json_response([
            'success' => false,
            'hits' => [],
            'error' => 'Параметр when: now|tomorrow|date',
        ], 400);
        exit;
    }

    $dateIso = trim((string) ($_GET['date'] ?? ''));
    if ($when === 'date' && $dateIso === '') {
        mkbp_json_response([
            'success' => false,
            'hits' => [],
            'error' => 'Для when=date нужен date=YYYY-MM-DD',
        ], 400);
        exit;
    }
    if ($dateIso !== '' && FreeRooms::parseIsoLocal($dateIso) === null) {
        mkbp_json_response([
            'success' => false,
            'hits' => [],
            'error' => 'Некорректная дата (ожидается YYYY-MM-DD)',
        ], 400);
        exit;
    }

    $lessonRaw = trim((string) ($_GET['lesson'] ?? 'auto'));
    $lessonNumber = null;
    if ($lessonRaw !== '' && strtolower($lessonRaw) !== 'auto') {
        if (!ctype_digit($lessonRaw) && !preg_match('/^-?\d+$/', $lessonRaw)) {
            mkbp_json_response([
                'success' => false,
                'hits' => [],
                'error' => 'Параметр lesson: auto|0-13',
            ], 400);
            exit;
        }
        $n = (int) $lessonRaw;
        if ($n < FreeRooms::LESSON_MIN || $n > FreeRooms::LESSON_MAX) {
            mkbp_json_response([
                'success' => false,
                'hits' => [],
                'error' => 'Параметр lesson: auto|0-13',
            ], 400);
            exit;
        }
        $lessonNumber = $n;
    }

    $filter = [
        'when' => $when,
        'dateIso' => $dateIso !== '' ? $dateIso : null,
        'lessonNumber' => $lessonNumber,
    ];

    $svc = new FreeRooms();
    $result = $svc->find($filter);

    if (!$result['success']) {
        mkbp_json_response([
            'success' => false,
            'hits' => [],
            'error' => 'Не удалось найти свободные аудитории. Попробуйте позже.',
        ], 503);
        exit;
    }

    mkbp_json_response($result);
} catch (Throwable $e) {
    if (mkbp_is_kbp_unavailable($e)) {
        $payload = mkbp_kbp_down_payload();
        $payload['hits'] = [];
        mkbp_json_response($payload, mkbp_kbp_down_status());
        exit;
    }
    $busy = str_contains($e->getMessage(), 'upstream busy');
    mkbp_json_response([
        'success' => false,
        'hits' => [],
        'error' => mkbp_public_error($e),
    ], $busy ? 503 : 500);
}
