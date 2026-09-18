<?php
declare(strict_types=1);

namespace MiniKbp;

final class Bootstrap
{
    private static bool $booted = false;

    public static function init(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        self::loadConfigFile();

        date_default_timezone_set(getenv('APP_TZ') ?: 'Europe/Minsk');
        $cache = self::cacheDir();
        if (!is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }
        $logs = self::cacheDir() . '/logs';
        if (!is_dir($logs)) {
            @mkdir($logs, 0775, true);
        }
    }

    /** Load config.php from project/docroot (next to src/) or account home (SpaceWeb). */
    private static function loadConfigFile(): void
    {
        $root = dirname(__DIR__); // parent of src/
        $candidates = [
            $root . '/config.php',
            dirname($root) . '/config.php',
        ];
        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }
            $cfg = require $path;
            if (!is_array($cfg)) {
                continue;
            }
            foreach ($cfg as $key => $value) {
                if (!is_string($key) || $value === null || $value === '') {
                    continue;
                }
                $current = getenv($key);
                if ($current === false || $current === '') {
                    putenv($key . '=' . (string) $value);
                    $_ENV[$key] = (string) $value;
                }
            }
            break;
        }
    }

    public static function cacheDir(): string
    {
        $env = getenv('APP_CACHE_DIR');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        // Docker: <project>/cache ; FTP flat: <docroot>/cache
        return dirname(__DIR__) . '/cache';
    }

    public static function kbpBaseUrl(): string
    {
        return rtrim(getenv('KBP_BASE_URL') ?: 'https://kbp.by/rasp/timetable/view_beta_kbp/', '?/') . '/';
    }

    public static function raspKbpApiUrl(): string
    {
        return rtrim(getenv('RASP_KBP_API_URL') ?: 'https://rasp.kbp.by', '/');
    }
}
