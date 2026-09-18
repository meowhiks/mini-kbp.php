<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use MiniKbp\ClientRateLimit;
use MiniKbp\MergeRows;
use MiniKbp\RequestGuard;
use MiniKbp\TimetableService;

try {
    ClientRateLimit::enforce('timetable', 90, 60);

    $cat = RequestGuard::category((string) ($_GET['cat'] ?? $_GET['type'] ?? ''));
    $id = RequestGuard::entityId((string) ($_GET['id'] ?? ''));
    $name = RequestGuard::displayName((string) ($_GET['name'] ?? ''));
    if ($cat === null || $id === null) {
        mkbp_json_response(['success' => false, 'error' => 'Некорректные параметры'], 400);
        exit;
    }

    $day = isset($_GET['day']) ? (int) $_GET['day'] : null;
    $showReplacements = !isset($_GET['replacements']) || $_GET['replacements'] !== '0';

    $svc = new TimetableService();
    $result = $svc->fetch($cat, $id, $name);
    if (!$result['success']) {
        $busy = ($result['error'] ?? '') === 'upstream busy';
        $down = ($result['code'] ?? '') === 'kbp_unavailable'
            || str_starts_with((string) ($result['error'] ?? ''), 'kbp unavailable');
        if ($down) {
            mkbp_json_response(mkbp_kbp_down_payload(), mkbp_kbp_down_status());
            exit;
        }
        mkbp_json_response([
            'success' => false,
            'error' => $busy
                ? 'Слишком много запросов к серверу расписания. Подождите секунду.'
                : 'Не удалось загрузить расписание. Попробуйте позже.',
        ], $busy ? 503 : 503);
        exit;
    }

    $data = $result['data'];
    if ($day !== null && $day >= 0 && $day <= 6) {
        $data['dayPairs'] = MergeRows::resolveDayPairs($data['pairs'] ?? [], $day, $showReplacements);
        $data['selectedDay'] = $day;
        $data['showReplacements'] = $showReplacements;
    }

    mkbp_json_response(['success' => true, 'data' => $data]);
} catch (Throwable $e) {
    if (mkbp_is_kbp_unavailable($e)) {
        mkbp_json_response(mkbp_kbp_down_payload(), mkbp_kbp_down_status());
        exit;
    }
    mkbp_json_response(['success' => false, 'error' => mkbp_public_error($e)], 500);
}
