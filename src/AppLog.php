<?php
declare(strict_types=1);

namespace MiniKbp;

/**
 * Append-only app logs under cache/logs/*.log (not public).
 */
final class AppLog
{
    public static function logDir(): string
    {
        return Bootstrap::cacheDir() . '/logs';
    }

    public static function path(?string $day = null): string
    {
        $day ??= date('Y-m-d');
        return self::logDir() . '/app-' . $day . '.log';
    }

    /** @param array<string, mixed> $ctx */
    public static function write(string $level, string $message, array $ctx = []): void
    {
        try {
            $dir = self::logDir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $line = [
                'ts' => date('c'),
                'level' => $level,
                'msg' => $message,
                'ip' => ClientRateLimit::clientIp(),
                'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
                'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
                'proto' => (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ($_SERVER['HTTPS'] ?? '')),
                'cf' => (string) ($_SERVER['HTTP_CF_RAY'] ?? ''),
            ];
            if ($ctx !== []) {
                $line['ctx'] = $ctx;
            }
            $json = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                return;
            }
            @file_put_contents(self::path(), $json . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // never break the request for logging
        }
    }

    /** @param array<string, mixed> $ctx */
    public static function info(string $message, array $ctx = []): void
    {
        self::write('info', $message, $ctx);
    }

    /** @param array<string, mixed> $ctx */
    public static function warn(string $message, array $ctx = []): void
    {
        self::write('warn', $message, $ctx);
    }

    /** @param array<string, mixed> $ctx */
    public static function error(string $message, array $ctx = []): void
    {
        self::write('error', $message, $ctx);
    }
}
