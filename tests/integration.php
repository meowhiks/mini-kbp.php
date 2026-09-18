<?php
declare(strict_types=1);

/**
 * Integration smoke against a running stack (default http://127.0.0.1).
 * Usage: php tests/integration.php [baseUrl]
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1', '/');
$failed = 0;
$passed = 0;

function req(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, is_string($body) ? $body : '', $err];
}

function check(bool $ok, string $msg): void
{
    global $failed, $passed;
    if ($ok) {
        echo "  OK  {$msg}\n";
        $passed++;
    } else {
        echo " FAIL {$msg}\n";
        $failed++;
    }
}

echo "== Integration against {$base} ==\n";

[$code, $body, $err] = req("{$base}/api/health.php");
$health = json_decode($body, true);
check($code === 200 && ($health['ok'] ?? false) === true, "health {$code}");

[$code, $body] = req("{$base}/");
check($code === 200 && str_contains($body, 'Мини КБиП'), 'index html');

[$code, $body] = req("{$base}/api/search.php?q=" . rawurlencode('9'));
$search = json_decode($body, true);
check($code === 200 && ($search['success'] ?? false) && count($search['items'] ?? []) > 0, 'search returns items');

$group = null;
foreach ($search['items'] ?? [] as $item) {
    if (($item['type'] ?? '') === 'group') {
        $group = $item;
        break;
    }
}
if ($group === null) {
    // broaden query
    [, $body] = req("{$base}/api/search.php?q=" . rawurlencode('а'));
    $search = json_decode($body, true);
    foreach ($search['items'] ?? [] as $item) {
        if (($item['type'] ?? '') === 'group') {
            $group = $item;
            break;
        }
    }
}

check($group !== null, 'found a group in search');

if ($group) {
    $url = "{$base}/api/timetable.php?" . http_build_query([
        'cat' => $group['type'],
        'id' => $group['id'],
        'name' => $group['name'],
        'day' => 0,
        'replacements' => 1,
    ]);
    [$code, $body] = req($url);
    $tt = json_decode($body, true);
    check($code === 200 && ($tt['success'] ?? false), 'timetable success');
    $pairs = $tt['data']['pairs'] ?? [];
    check(is_array($pairs), 'timetable has pairs array');
    echo '       group=' . $group['name'] . ' pairs=' . count($pairs) . "\n";
}

echo "\nPassed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
