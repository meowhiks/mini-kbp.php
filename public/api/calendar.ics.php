<?php
declare(strict_types=1);

/**
 * Subscribable ICS feed for Google / Apple Calendar.
 * Writes a static .ics under /cal/ and redirects there (fast download via Apache).
 *
 * Example: /api/calendar.ics.php?cat=group&id=51&name=T-494
 */

header_remove('X-Powered-By');

$candidates = [
    dirname(__DIR__) . '/src/autoload.php',
    dirname(__DIR__) . '/../src/autoload.php',
];
$loaded = false;
foreach ($candidates as $file) {
    if (is_file($file)) {
        require $file;
        $loaded = true;
        break;
    }
}
if (!$loaded) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Boot error\n";
    exit;
}

use MiniKbp\Bootstrap;
use MiniKbp\ClientRateLimit;
use MiniKbp\IcalBuilder;
use MiniKbp\RequestGuard;
use MiniKbp\TimetableService;

try {
    @set_time_limit(20);
    ClientRateLimit::enforce('calendar_ics', 60, 60);

    $cat = RequestGuard::category((string) ($_GET['cat'] ?? $_GET['type'] ?? ''));
    $id = RequestGuard::entityId((string) ($_GET['id'] ?? ''));
    $name = RequestGuard::displayName((string) ($_GET['name'] ?? ''));
    if ($cat === null || $id === null) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Bad request\n";
        exit;
    }

    $publicRoot = dirname(__DIR__); // …/public or FTP docroot
    $calDir = $publicRoot . '/cal';
    if (!is_dir($calDir)) {
        @mkdir($calDir, 0775, true);
    }
    $fileName = $cat . '-' . $id . '.txt';
    $icsPath = $calDir . '/' . $fileName;
    $publicUrlPath = '/cal/' . rawurlencode($cat) . '-' . rawurlencode($id) . '.txt';

    $ttl = 1800;
    $fresh = is_file($icsPath) && (time() - (int) filemtime($icsPath)) < $ttl && filesize($icsPath) > 100;

    // ?download=1 or calendar bots that follow redirects — serve/refresh file
    if (!$fresh) {
        $data = readTimetableCacheFast($cat, $id, TimetableService::STALE_MAX_AGE_SEC);
        if ($data === null) {
            $svc = new TimetableService();
            $result = $svc->fetch($cat, $id, $name);
            if (!empty($result['success']) && is_array($result['data'] ?? null)) {
                $data = $result['data'];
            }
        } elseif ($name !== '') {
            $data['groupName'] = $name;
        }
        if ($data === null || !is_array($data['pairs'] ?? null) || $data['pairs'] === []) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Timetable unavailable\n";
            exit;
        }
        $data['category'] = $cat;
        $ics = IcalBuilder::build($data, $name !== '' ? $name : (string) ($data['groupName'] ?? ''));
        @file_put_contents($icsPath, $ics, LOCK_EX);
    }

    // Prefer redirect to static file (Apache/nginx serves full body quickly)
    if (is_file($icsPath) && filesize($icsPath) > 100 && empty($_GET['inline'])) {
        header('Cache-Control: public, max-age=1800');
        header('Location: ' . $publicUrlPath, true, 302);
        exit;
    }

    // Fallback: inline body
    $body = is_file($icsPath) ? (string) file_get_contents($icsPath) : '';
    if ($body === '') {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Timetable unavailable\n";
        exit;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: inline; filename="minikbp-' . rawurlencode($cat) . '-' . rawurlencode($id) . '.ics"');
    header('Cache-Control: public, max-age=1800');
    header('Content-Length: ' . strlen($body));
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error\n";
}

/** @return array<string, mixed>|null */
function readTimetableCacheFast(string $category, string $id, int $maxAgeSec): ?array
{
    $path = Bootstrap::cacheDir() . '/tt_' . $category . '_' . $id . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    $cached = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($cached) || !isset($cached['savedAt'], $cached['data']) || !is_array($cached['data'])) {
        return null;
    }
    $pairs = $cached['data']['pairs'] ?? null;
    if (!is_array($pairs) || $pairs === []) {
        return null;
    }
    if ((time() - (int) $cached['savedAt']) >= $maxAgeSec) {
        return null;
    }
    return $cached['data'];
}
