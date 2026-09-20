<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use MiniKbp\ClientRateLimit;
use MiniKbp\DeliveryThrottle;
use MiniKbp\BellSchedule;
use MiniKbp\FreeRooms;
use MiniKbp\KeyboardLayout;
use MiniKbp\MergeRows;
use MiniKbp\NameEnricher;
use MiniKbp\RequestGuard;
use MiniKbp\SearchService;
use MiniKbp\RaspTimetableBuilder;
use MiniKbp\TimetableParser;
use MiniKbp\TimetableService;
use MiniKbp\UpstreamRateLimiter;

$failed = 0;
$passed = 0;

function assert_true(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) {
        echo "  OK  {$msg}\n";
        $passed++;
    } else {
        echo " FAIL {$msg}\n";
        $failed++;
    }
}

echo "== RequestGuard ==\n";
assert_true(RequestGuard::category('group') === 'group', 'category group');
assert_true(RequestGuard::category('evil') === null, 'category reject');
assert_true(RequestGuard::entityId('42') === '42', 'id ok');
assert_true(RequestGuard::entityId('../etc/passwd') === null, 'id traversal reject');
assert_true(RequestGuard::entityId(str_repeat('a', 100)) === null, 'id too long');
assert_true(RequestGuard::displayName('<b>Hi</b>') === 'Hi', 'name strips tags');
assert_true(RequestGuard::sanitizeLabel("A\x00B") === 'AB', 'strip controls');

echo "== UpstreamRateLimiter ==\n";
$tmpDir = sys_get_temp_dir() . '/mkbp_rate_' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0775, true);
$stateFile = $tmpDir . '/rate.json';
$slept = [];
$now = 1000.0;
$limiter = new UpstreamRateLimiter(
    $stateFile,
    sleeper: static function (float $sec) use (&$slept): void {
        $slept[] = $sec;
    },
    clock: static function () use (&$now): float {
        return $now;
    },
);
$limiter->acquire();
$now = 1000.0 + UpstreamRateLimiter::MIN_INTERVAL / 2;
$limiter->acquire();
assert_true(
    count($slept) === 1 && $slept[0] > 0 && $slept[0] < UpstreamRateLimiter::MIN_INTERVAL + 0.001,
    'max-speed spacing waits fractional MIN_INTERVAL'
);
assert_true(abs($limiter->currentSlowdown() - 0.0) < 0.0001, 'slowdown starts at 0%');

// One active second (1000→1001) then read slowdown at 1001.0 without new acquire activity marking further.
$now = 1001.0;
assert_true(abs($limiter->currentSlowdown() - 0.01) < 0.0001, 'active second → +1% slowdown');

// Idle seconds recover −1%/s
$now = 1003.0;
assert_true(abs($limiter->currentSlowdown() - 0.0) < 0.0001, 'idle seconds recover to 0%');

// Sustained pressure: acquires across many seconds
$slept = [];
for ($i = 0; $i < 5; $i++) {
    $now = 2000.0 + $i;
    $limiter->acquire();
}
$now = 2005.0;
$sd = $limiter->currentSlowdown();
assert_true($sd >= 0.04 && $sd <= 0.06, 'sustained acquires raise slowdown ~5%');

// Fail-fast when wait queue too long (does not park workers)
$slept = [];
$rejected = false;
try {
    // Push nextAllowed far ahead
    $now = 3000.0;
    $limiter->acquire();
    $now = 3000.0; // immediate second acquire → wait ≈ MIN_INTERVAL; force busy via state
    file_put_contents($stateFile, json_encode([
        'slowdown' => 0.0,
        'lastTick' => 3000,
        'activeUntil' => 3000,
        'nextAllowed' => 3000.0 + UpstreamRateLimiter::MAX_WAIT + 1,
        'updatedAt' => 3000.0,
    ], JSON_THROW_ON_ERROR));
    $limiter->acquire();
} catch (Throwable $e) {
    $rejected = str_contains($e->getMessage(), 'upstream busy');
}
assert_true($rejected, 'long queue rejects instead of sleeping');
assert_true(count($slept) === 0, 'reject path does not sleep');

$rejected = false;
file_put_contents($stateFile, json_encode([
    'slowdown' => UpstreamRateLimiter::REJECT_SLOWDOWN,
    'lastTick' => 4000,
    'activeUntil' => 4001,
    'nextAllowed' => 4000.0,
    'updatedAt' => 4000.0,
], JSON_THROW_ON_ERROR));
$now = 4000.0;
try {
    $limiter->acquire();
} catch (Throwable $e) {
    $rejected = str_contains($e->getMessage(), 'upstream busy');
}
assert_true($rejected, 'high slowdown rejects new upstream work');
@unlink($stateFile);

echo "== DeliveryThrottle ==\n";
$delays = [];
$captured = '';
$throttleFile = $tmpDir . '/delivery.json';
$throttle = new DeliveryThrottle(
    $throttleFile,
    sleeper: static function (float $sec) use (&$delays): void {
        $delays[] = $sec;
    },
    writer: static function (string $chunk) use (&$captured): void {
        $captured .= $chunk;
    },
);
assert_true($throttle->currentSpeed() === 1.0, 'default delivery speed 1.0');
assert_true($throttle->stepDown(1.0) === 0.9, 'stepDown 1.0 → 0.9');
assert_true($throttle->stepDown(0.5) === 0.4, 'stepDown 0.5 → 0.4');
assert_true($throttle->stepDown(0.1) === 0.1, 'stepDown floor 0.1');
assert_true($throttle->stepUp(0.1) === 0.2, 'stepUp 0.1 → 0.2');
assert_true($throttle->stepUp(1.0) === 1.0, 'stepUp ceiling 1.0');

$workers = [];
for ($i = 0; $i < 4; $i++) {
    $workers[] = new DeliveryThrottle($throttleFile, sleeper: static function (float $sec): void {});
    $workers[$i]->begin();
}
assert_true($throttle->currentSpeed() === 1.0, 'local responses stay full speed under concurrency');
foreach ($workers as $w) {
    $w->end();
}
assert_true($throttle->concurrent() === 0, 'all workers ended');

$delays = [];
$captured = '';
$throttle->send(str_repeat('A', 2000));
assert_true($captured === str_repeat('A', 2000), 'send preserves body');
assert_true(count($delays) === 0, 'cache/local delivery has no artificial delays');
@unlink($throttleFile);
@rmdir($tmpDir);

echo "== BellSchedule ==\n";
$t = BellSchedule::pairTime(1, 0);
assert_true($t['start'] === '8.00' && $t['end'] === '8.45', 'pair 1 mon');
$thu = BellSchedule::pairTime(7, 3);
assert_true($thu['start'] === '14.40', 'thursday pair 7 override');

echo "== FreeRooms Sunday-all-free ==\n";
$sunday = new DateTimeImmutable('2026-09-13'); // Sunday
assert_true(FreeRooms::liveCalendarDayIndex($sunday) === -1, 'Sunday live index -1');
assert_true(FreeRooms::resolveDayIndex(['when' => 'now'], $sunday) === null, 'Sunday now → null day');
assert_true(FreeRooms::isSundayNow(['when' => 'now'], $sunday), 'isSundayNow on Sunday');
$monday = new DateTimeImmutable('2026-09-14');
assert_true(FreeRooms::liveCalendarDayIndex($monday) === 0, 'Monday live index 0');
assert_true(!FreeRooms::isSundayNow(['when' => 'now'], $monday), 'not Sunday on Monday');
assert_true(FreeRooms::placeBusyAt([
    'pairs' => [
        ['day' => 0, 'weekOffset' => 0, 'pairNumber' => 1, 'subject' => 'Математика', 'status' => 'normal'],
    ],
], 0, 1) === true, 'busy at lesson 1');
assert_true(FreeRooms::placeBusyAt([
    'pairs' => [
        ['day' => 0, 'weekOffset' => 0, 'pairNumber' => 1, 'subject' => 'Урок снят', 'status' => 'removed'],
    ],
], 0, 1) === false, 'removed lesson not busy');

echo "== KeyboardLayout ==\n";
assert_true(KeyboardLayout::swap('ghj') === 'рюо' || KeyboardLayout::swap('ghj') !== 'ghj', 'swap latin');
$vars = KeyboardLayout::variants('9ис');
assert_true(count($vars) >= 1, 'variants non-empty');

echo "== SearchService::parseSearchResults ==\n";
$fixture = <<<'HTML'
<html><body>
<div class="find_block">
  <div><span class="type_find">Группа</span><a href="?cat=group&id=42">9ис-1</a></div>
  <div><span class="type_find">Преподаватель</span><a href="?cat=teacher&amp;id=7">Иванов И.И.</a></div>
  <div><a href="?cat=place&id=101">101</a></div>
</div></div>
</body></html>
HTML;
$items = SearchService::parseSearchResults($fixture);
assert_true(count($items) === 3, 'parsed 3 items');
assert_true($items[0]['type'] === 'group' && $items[0]['id'] === '42', 'group id');
assert_true($items[1]['type'] === 'teacher' && $items[1]['id'] === '7', 'teacher id');

echo "== TimetableParser ==\n";
$ttHtml = <<<'HTML'
<div id="left_week">
  <p class="date">1 — 6 сентября</p>
  <p class="today">нечётная</p>
  <table>
    <tr class="zamena"><th></th><th>нету замен</th><th>нету замен</th><th>нету замен</th><th>нету замен</th><th>нету замен</th><th>нету замен</th><th></th></tr>
    <tr>
      <td class="number">1</td>
      <td><!-- day="1" -->
        <div class="pair">
          <div class="subject"><a href="?cat=subject&id=1">Математика</a></div>
          <div class="left-column"><div class="teacher"><a href="?cat=teacher&id=2">Петров</a></div></div>
          <div class="right-column"><div class="place"><a href="?cat=place&id=3">215</a></div></div>
        </div>
      </td>
      <td class="empty-pair"></td>
      <td class="empty-pair"></td>
      <td class="empty-pair"></td>
      <td class="empty-pair"></td>
      <td class="empty-pair"></td>
      <td></td>
    </tr>
    <tr>
      <td class="number">2</td>
      <td><!-- day="1" -->
        <div class="pair added">
          <div class="subject"><a>Физика</a></div>
          <div class="left-column"><div class="teacher"><a>Сидоров</a></div></div>
          <div class="right-column"><div class="place"><a>301</a></div></div>
        </div>
      </td>
      <td class="empty-pair"></td>
      <td class="empty-pair"></td>
      <td class="empty-pair"></td>
      <td class="empty-pair"></td>
      <td class="empty-pair"></td>
      <td></td>
    </tr>
  </table>
</div>
<div id="right_week"><p class="date">8 — 13 сентября</p><table><tr><td class="number">1</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr></table></div>
HTML;

$parsed = TimetableParser::parse($ttHtml, '42', '9ис-1');
assert_true(($parsed['groupName'] ?? '') === '9ис-1', 'group name');
assert_true(count($parsed['pairs']) >= 2, 'pairs parsed');
assert_true(($parsed['currentWeek']['dateRange'] ?? '') === '1 — 6 сентября', 'date range');

$withRep = MergeRows::resolveDayPairs($parsed['pairs'], 0, true);
$withoutRep = MergeRows::resolveDayPairs($parsed['pairs'], 0, false);
assert_true(count($withRep) >= 2, 'with replacements shows added');
assert_true(count($withoutRep) === 1, 'without replacements hides added');

echo "== NameEnricher ==\n";
assert_true(NameEnricher::teachersLikelySame('МихалевичВ.Ю.', 'Михалевич Варвара Юрьевна'), 'teacher short↔full');
assert_true(NameEnricher::teachersLikelySame('Веренич К.В.', 'Веренич Кристина Валерьевна'), 'teacher spaced short↔full');
assert_true(!NameEnricher::teachersLikelySame('Шинкевич Е.А.', 'Веренич Кристина Валерьевна'), 'teacher mismatch');
assert_true(NameEnricher::subjectsLikelySame('БелЛит', 'Белорусская литература'), 'subject БелЛит↔full');
assert_true(!NameEnricher::subjectsLikelySame('БелЛит', 'Белорусский язык'), 'subject БелЛит≠язык');

$enrichedPair = [
    'pairNumber' => 1,
    'day' => 0,
    'subject' => 'ПрактПрограм',
    'teacher' => 'МихалевичВ.Ю.',
    'room' => '418',
    'group' => 'Т-494',
    'refs' => [
        'teachers' => [['id' => '100', 'name' => 'МихалевичВ.Ю.']],
        'subject' => ['id' => '107', 'name' => 'ПрактПрограм'],
        'group' => ['id' => '51', 'name' => 'Т-494'],
    ],
    'status' => 'normal',
    'weekOffset' => 0,
];
$fakeLessons = [[
    'dayOfWeek' => 1,
    'lessonNumber' => 1,
    'room' => '418',
    'subgroup' => '',
    'subject' => ['id' => 'x', 'name' => 'Учебная практика «Программирование»'],
    'teacher' => ['id' => 'y', 'name' => 'Михалевич Варвара Юрьевна'],
]];
$enricher = new NameEnricher();
$ref = new ReflectionClass($enricher);
$m = $ref->getMethod('matchLesson');
$m->setAccessible(true);
$idxMethod = $ref->getMethod('indexLessons');
$idxMethod->setAccessible(true);
$idx = $idxMethod->invoke($enricher, $fakeLessons);
$hit = $m->invoke($enricher, $enrichedPair, $idx);
assert_true(is_array($hit) && ($hit['subject']['name'] ?? '') === 'Учебная практика «Программирование»', 'lesson day+num+room match');

echo "== RaspTimetableBuilder ==\n";
assert_true(RaspTimetableBuilder::shortenTeacher('Иванов Иван Иванович') === 'Иванов И.И.', 'shorten 3-part FIO');
assert_true(RaspTimetableBuilder::shortenTeacher('Петров Пётр') === 'Петров П.', 'shorten 2-part FIO');
assert_true(RaspTimetableBuilder::shortenTeacher('Сидоров') === 'Сидоров', 'shorten single token');
assert_true(RaspTimetableBuilder::shortenTeacher('  ') === '', 'shorten empty');

$inlineLessons = [[
    'dayOfWeek' => 1,
    'lessonNumber' => 2,
    'room' => '418',
    'subject' => ['id' => 's1', 'name' => 'Математика'],
    'teacher' => ['id' => 't1', 'name' => 'Иванов Иван Иванович'],
    'group' => ['id' => 'g1', 'name' => 'Т-494'],
], [
    'dayOfWeek' => 2,
    'lessonNumber' => 1,
    'room' => '101',
    'subject' => ['id' => 's2', 'name' => 'Физика'],
    'teacher' => ['id' => 't2', 'name' => 'Петров Пётр'],
    'group' => ['id' => 'g1', 'name' => 'Т-494'],
]];
$builder = new RaspTimetableBuilder();
$refB = new ReflectionClass($builder);
$mLessons = $refB->getMethod('lessonsToTimetable');
$mLessons->setAccessible(true);
$built = $mLessons->invoke($builder, $inlineLessons, 'group', '51', 'Т-494');
assert_true(($built['source'] ?? '') === 'rasp.kbp.by', 'builder source flag');
assert_true(($built['groupName'] ?? '') === 'Т-494', 'builder group name');
assert_true(count($built['pairs'] ?? []) === 2, 'inline lessons → 2 pairs');
assert_true(($built['pairs'][0]['teacher'] ?? '') === 'Иванов И.И.', 'pair teacher shortened');
assert_true(($built['pairs'][0]['teacherFull'] ?? '') === 'Иванов Иван Иванович', 'pair teacherFull kept');
assert_true(($built['pairs'][0]['day'] ?? -1) === 0 && ($built['pairs'][0]['pairNumber'] ?? 0) === 2, 'Mon pair 2 mapped');
assert_true(($built['dayStartTimes'][0]['start'] ?? '') !== '', 'fillDayRanges set Monday start');
assert_true(($built['pairs'][0]['refs']['group']['id'] ?? '') === '51', 'group ref keeps kbp entity id');
assert_true(!isset($built['pairs'][0]['refs']['teachers'][0]['id']) || ($built['pairs'][0]['refs']['teachers'][0]['id'] ?? '') !== 't1', 'no rasp teacher id in refs');
assert_true(!isset($built['pairs'][0]['refs']['subject']['id']) || ($built['pairs'][0]['refs']['subject']['id'] ?? '') !== 's1', 'no rasp subject id in refs');
assert_true(($built['pairs'][0]['refs']['place']['id'] ?? '418') !== '418', 'place id is not raw room label');
$dr = (string) ($built['currentWeek']['dateRange'] ?? '');
assert_true($dr !== '' && !preg_match('/\d{2}\.\d{2}\.\d{4}/', $dr), 'dateRange uses kbp-style Russian months');

echo "== TimetableService week cache ==\n";
$now = time();
assert_true(TimetableService::isSameIsoWeek($now), 'now is same ISO week');
assert_true(TimetableService::isSameIsoWeek($now - 3600), '1h ago same week');
// ~10 days ago almost always another ISO week
assert_true(!TimetableService::isSameIsoWeek($now - 10 * 86400), '10 days ago different ISO week');

$sun = new DateTimeImmutable('2026-09-20', new DateTimeZone('Europe/Minsk'));
assert_true(
    !TimetableService::isDateRangeStillCurrent('14 — 19 сентября', $sun),
    'Sunday after Sat range → expired'
);
assert_true(
    TimetableService::isDateRangeStillCurrent('21 — 26 сентября', $sun),
    'Sunday before next week Sat → still current'
);
assert_true(
    !TimetableService::isDateRangeStillCurrent('15.09.2026 — 20.09.2026', $sun->modify('+1 day')),
    'numeric range expired after its Saturday'
);

$cachedSched = glob(dirname(__DIR__) . '/cache/rasp_sched_f_*.json') ?: [];
if ($cachedSched !== []) {
    $rawSched = file_get_contents($cachedSched[0]);
    $wrap = is_string($rawSched) ? json_decode($rawSched, true) : null;
    $rows = is_array($wrap) ? ($wrap['data'] ?? null) : null;
    if (is_array($rows) && $rows !== []) {
        $fromCache = $mLessons->invoke($builder, array_slice($rows, 0, 5), 'group', '51', 'Т-494');
        assert_true(count($fromCache['pairs'] ?? []) >= 1, 'cached rasp_sched → pairs');
    } else {
        assert_true(true, 'cached rasp_sched skip (empty)');
    }
} else {
    assert_true(true, 'cached rasp_sched skip (none)');
}

echo "\nPassed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
