<?php
declare(strict_types=1);

namespace MiniKbp;

/**
 * Per-IP sliding window limiter for public APIs (shared-host safe via flock).
 */
final class ClientRateLimit
{
    public static function clientIp(): string
    {
        $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if (is_string($cf) && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($xff) && $xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($remote) && filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    /**
     * @throws \RuntimeException when open/lock fails
     */
    public static function allow(string $bucket, int $maxRequests, int $windowSec): bool
    {
        $ip = self::clientIp();
        $safeBucket = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $bucket) ?: 'api';
        $safeIp = hash('sha256', $ip);
        $dir = Bootstrap::cacheDir() . '/rate_ip';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . $safeBucket . '_' . $safeIp . '.json';

        $fh = fopen($path, 'c+');
        if ($fh === false) {
            return true; // fail open if cache unwritable
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                return true;
            }
            rewind($fh);
            $raw = stream_get_contents($fh);
            $now = time();
            $hits = [];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['hits']) && is_array($decoded['hits'])) {
                    foreach ($decoded['hits'] as $t) {
                        $ts = (int) $t;
                        if ($ts > $now - $windowSec) {
                            $hits[] = $ts;
                        }
                    }
                }
            }
            if (count($hits) >= $maxRequests) {
                flock($fh, LOCK_UN);
                return false;
            }
            $hits[] = $now;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode(['hits' => $hits], JSON_THROW_ON_ERROR));
            fflush($fh);
            flock($fh, LOCK_UN);
            return true;
        } finally {
            fclose($fh);
        }
    }

    public static function enforce(string $bucket, int $maxRequests, int $windowSec): void
    {
        if (self::allow($bucket, $maxRequests, $windowSec)) {
            return;
        }
        http_response_code(429);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: ' . max(1, $windowSec));
            header('Cache-Control: no-store');
        }
        echo json_encode([
            'success' => false,
            'error' => 'Слишком много запросов. Подождите немного.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
