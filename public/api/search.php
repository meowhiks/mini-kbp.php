<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use MiniKbp\ClientRateLimit;
use MiniKbp\RequestGuard;
use MiniKbp\SearchService;

try {
    ClientRateLimit::enforce('search', 120, 60);

    $q = RequestGuard::searchQuery((string) ($_GET['q'] ?? ''));
    $refresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
    if ($refresh) {
        ClientRateLimit::enforce('search_refresh', 2, 300);
    }

    $svc = new SearchService();
    if ($q === '' && $refresh) {
        $index = $svc->ensureIndex(true);
        mkbp_json_response(['success' => true, 'items' => [], 'indexCount' => $index['count'] ?? 0]);
        exit;
    }
    $items = $svc->search($q, $refresh);
    mkbp_json_response(['success' => true, 'items' => $items]);
} catch (Throwable $e) {
    if (mkbp_is_kbp_unavailable($e)) {
        $payload = mkbp_kbp_down_payload();
        $payload['items'] = [];
        mkbp_json_response($payload, mkbp_kbp_down_status());
        exit;
    }
    $busy = str_contains($e->getMessage(), 'upstream busy');
    mkbp_json_response(
        ['success' => false, 'error' => mkbp_public_error($e), 'items' => []],
        $busy ? 503 : 503
    );
}
