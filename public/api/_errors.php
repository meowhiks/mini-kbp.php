<?php
declare(strict_types=1);

/**
 * Public API error text — never expose stack/PHP/paths/upstream details.
 */
function mkbp_public_error(Throwable $e): string
{
    if (mkbp_is_kbp_unavailable($e)) {
        return 'kbp.by недоступен :(';
    }
    $msg = trim($e->getMessage());
    // Allow a short allowlist of known safe client-facing codes from our own code.
    $safe = [
        'Не указаны cat/id',
        'Пустой ответ',
        'upstream busy',
        'kbp unavailable',
    ];
    foreach ($safe as $prefix) {
        if ($msg === $prefix || str_starts_with($msg, $prefix)) {
            if ($msg === 'upstream busy' || str_starts_with($msg, 'upstream busy')) {
                return 'Слишком много запросов к серверу расписания. Подождите секунду.';
            }
            if (str_starts_with($msg, 'kbp unavailable')) {
                return 'kbp.by недоступен :(';
            }
            return $msg;
        }
    }
    return 'Не удалось выполнить запрос. Попробуйте позже.';
}

function mkbp_is_kbp_unavailable(Throwable $e): bool
{
    $msg = $e->getMessage();
    if ($msg === 'upstream busy' || str_starts_with($msg, 'upstream busy')) {
        return false;
    }
    return str_starts_with($msg, 'kbp unavailable')
        || str_contains($msg, 'kbp.by request failed')
        || str_contains($msg, 'kbp.by HTTP')
        || str_contains($msg, 'kbp.by');
}

/** @return array{success:false,code:string,error:string,hint:string,url:string} */
function mkbp_kbp_down_payload(): array
{
    return [
        'success' => false,
        'code' => 'kbp_unavailable',
        'error' => 'kbp.by недоступен :(',
        'hint' => 'Проблема не может быть решена, временно используйте kbp.by',
        'url' => 'https://kbp.by/rasp/timetable/view_beta_kbp/',
    ];
}

/** Cloudflare replaces origin HTTP 502 bodies with plain "error code: 502".
 *  Use 200 for app-level "kbp down" so the SPA always gets our JSON. */
function mkbp_kbp_down_status(): int
{
    return 200;
}

/** @param array<string, mixed> $payload */
function mkbp_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    if ($status >= 400 && class_exists(\MiniKbp\AppLog::class)) {
        try {
            \MiniKbp\AppLog::warn('api.response', [
                'status' => $status,
                'code' => $payload['code'] ?? null,
                'error' => $payload['error'] ?? null,
            ]);
        } catch (Throwable $e) {
            // ignore
        }
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        $json = '{"success":false,"error":"Сервис временно недоступен."}';
    }
    echo $json;
}

function mkbp_throw_or_respond(Throwable $e): void
{
    if (mkbp_is_kbp_unavailable($e)) {
        mkbp_json_response(mkbp_kbp_down_payload(), mkbp_kbp_down_status());
        return;
    }
    $busy = str_contains($e->getMessage(), 'upstream busy');
    mkbp_json_response(
        ['success' => false, 'error' => mkbp_public_error($e)],
        $busy ? 503 : 500
    );
}
