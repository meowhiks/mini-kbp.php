<?php
declare(strict_types=1);

namespace MiniKbp;

final class KbpClient
{
    private UpstreamRateLimiter $limiter;

    public function __construct(
        private readonly string $baseUrl = '',
        /** Keep short: hung kbp.by must not exhaust Apache workers. */
        private readonly int $timeoutSec = 20,
        ?UpstreamRateLimiter $limiter = null,
    ) {
        $this->limiter = $limiter ?? new UpstreamRateLimiter(UpstreamRateLimiter::defaultPath());
    }

    public function fetch(string $query = ''): string
    {
        $base = $this->baseUrl !== '' ? $this->baseUrl : Bootstrap::kbpBaseUrl();
        $url = $base . ($query !== '' ? '?' . ltrim($query, '?') : '');

        $this->limiter->acquire();

        $t0 = microtime(true);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('kbp unavailable: curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => 'MiniKBP-PHP/1.0 (+https://mini-kbp.site)',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Language: ru-RU,ru;q=0.9',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ms = (int) round((microtime(true) - $t0) * 1000);

        if ($errno !== 0 || !is_string($body)) {
            AppLog::error('kbp.fetch_fail', [
                'url' => $url,
                'errno' => $errno,
                'error' => $error,
                'ms' => $ms,
            ]);
            throw new \RuntimeException('kbp unavailable: ' . ($error ?: 'empty body'));
        }
        if ($status < 200 || $status >= 300) {
            AppLog::error('kbp.fetch_http', [
                'url' => $url,
                'status' => $status,
                'ms' => $ms,
            ]);
            throw new \RuntimeException("kbp unavailable: HTTP {$status}");
        }
        AppLog::info('kbp.fetch_ok', [
            'url' => $url,
            'bytes' => strlen($body),
            'ms' => $ms,
        ]);

        return $body;
    }

    public function fetchSearchIndex(): string
    {
        return $this->fetch('q=');
    }

    public function fetchTimetable(string $category, string $id): string
    {
        $cat = rawurlencode($category);
        $eid = rawurlencode($id);
        return $this->fetch("page=stable&cat={$cat}&id={$eid}");
    }
}
