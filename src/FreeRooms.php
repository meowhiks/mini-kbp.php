<?php
declare(strict_types=1);

namespace MiniKbp;

/**
 * Free-room occupancy logic ported from mini-kbp-timetable/lib/client/freeRooms.ts.
 */
final class FreeRooms
{
    public const LESSON_MIN = 0;
    public const LESSON_MAX = 13;
    public const MAX_PLACES = 20;
    public const PLACE_CACHE_TTL = 21600; // 6 hours

    public function __construct(
        private readonly SearchService $search = new SearchService(),
        private readonly TimetableService $timetable = new TimetableService(),
    ) {
    }

    /**
     * Day index 0=Mon…5=Sat; null = Sunday (no classes) or invalid date.
     *
     * @param array{when: string, dateIso?: string|null, lessonNumber?: int|null} $filter
     */
    public static function resolveDayIndex(array $filter, ?\DateTimeImmutable $now = null): ?int
    {
        $now ??= new \DateTimeImmutable('now');
        $when = (string) ($filter['when'] ?? 'now');

        if ($when === 'now') {
            $live = self::liveCalendarDayIndex($now);
            return $live >= 0 ? $live : null;
        }

        if ($when === 'tomorrow') {
            $t = $now->modify('+1 day');
            $live = self::liveCalendarDayIndex($t);
            if ($live < 0) {
                $t = $t->modify('+1 day');
                $live = self::liveCalendarDayIndex($t);
            }
            return $live >= 0 ? $live : null;
        }

        $iso = trim((string) ($filter['dateIso'] ?? ''));
        if ($iso === '') {
            return null;
        }
        $d = self::parseIsoLocal($iso);
        if ($d === null) {
            return null;
        }
        $live = self::liveCalendarDayIndex($d);
        return $live >= 0 ? $live : null;
    }

    /** True when when=now and calendar day is Sunday (all rooms free). */
    public static function isSundayNow(array $filter, ?\DateTimeImmutable $now = null): bool
    {
        $when = (string) ($filter['when'] ?? '');
        return $when === 'now' && self::resolveDayIndex($filter, $now) === null
            && self::liveCalendarDayIndex($now ?? new \DateTimeImmutable('now')) < 0;
    }

    public static function resolveCurrentLessonNumber(int $dayIndex, ?\DateTimeImmutable $now = null): ?int
    {
        if ($dayIndex < 0 || $dayIndex > 5) {
            return null;
        }
        $now ??= new \DateTimeImmutable('now');
        $mins = ((int) $now->format('G')) * 60 + (int) $now->format('i');

        for ($n = self::LESSON_MIN; $n <= self::LESSON_MAX; $n++) {
            if ($n === 0) {
                continue; // no bell for «0»
            }
            $times = BellSchedule::pairTime($n, $dayIndex);
            $s = self::timeToMinutes($times['start']);
            $e = self::timeToMinutes($times['end']);
            if ($s === null || $e === null) {
                continue;
            }
            if ($mins >= $s && $mins < $e) {
                return $n;
            }
        }

        $best = null;
        $bestStart = PHP_INT_MAX;
        for ($n = 1; $n <= self::LESSON_MAX; $n++) {
            $times = BellSchedule::pairTime($n, $dayIndex);
            $s = self::timeToMinutes($times['start']);
            if ($s === null || $s < $mins) {
                continue;
            }
            if ($s < $bestStart) {
                $bestStart = $s;
                $best = $n;
            }
        }
        return $best;
    }

    /**
     * @param array<string, mixed> $timetable
     */
    public static function placeBusyAt(array $timetable, int $dayIndex, ?int $lessonNumber): bool
    {
        $pairs = $timetable['pairs'] ?? [];
        if (!is_array($pairs)) {
            return false;
        }
        foreach ($pairs as $p) {
            if (!is_array($p)) {
                continue;
            }
            if (!MergeRows::pairMatchesDisplayDay($p, $dayIndex)) {
                continue;
            }
            if (!self::isActiveLesson(
                isset($p['subject']) ? (string) $p['subject'] : null,
                isset($p['status']) ? (string) $p['status'] : null
            )) {
                continue;
            }
            if ($lessonNumber === null) {
                return true;
            }
            if ((int) ($p['pairNumber'] ?? -1) === $lessonNumber) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{when: string, dateIso?: string|null, lessonNumber?: int|null} $filter
     * @return array{
     *   success: bool,
     *   hits: list<array{id: string, name: string, type: string}>,
     *   progress?: array{done: int, total: int},
     *   error?: string
     * }
     */
    public function find(array $filter, int $maxPlaces = self::MAX_PLACES): array
    {
        try {
            $index = $this->search->ensureIndex();
            $places = [];
            foreach ($index['items'] ?? [] as $item) {
                if (!is_array($item) || ($item['type'] ?? '') !== 'place') {
                    continue;
                }
                $places[] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'name' => (string) ($item['name'] ?? ''),
                    'type' => 'place',
                ];
                if (count($places) >= $maxPlaces) {
                    break;
                }
            }
            $places = array_values(array_filter($places, static fn($p) => $p['id'] !== ''));

            if ($places === []) {
                return ['success' => true, 'hits' => [], 'progress' => ['done' => 0, 'total' => 0]];
            }

            $now = new \DateTimeImmutable('now');
            $dayIndex = self::resolveDayIndex($filter, $now);
            $sundayNow = ($filter['when'] ?? '') === 'now' && $dayIndex === null
                && self::liveCalendarDayIndex($now) < 0;

            $lessonNumber = array_key_exists('lessonNumber', $filter)
                ? $filter['lessonNumber']
                : null;
            if (is_int($lessonNumber) && ($lessonNumber < self::LESSON_MIN || $lessonNumber > self::LESSON_MAX)) {
                $lessonNumber = null;
            }

            if (!$sundayNow && ($filter['when'] ?? '') === 'now'
                && ($lessonNumber === null || !is_int($lessonNumber))) {
                $lessonNumber = $dayIndex !== null ? self::resolveCurrentLessonNumber($dayIndex, $now) : null;
            }

            // Sunday / no classes for «now» without explicit lesson: all free
            if ($sundayNow) {
                $hits = self::sortHits($places);
                $total = count($hits);
                return [
                    'success' => true,
                    'hits' => $hits,
                    'progress' => ['done' => $total, 'total' => $total],
                ];
            }

            if ($dayIndex === null) {
                return [
                    'success' => true,
                    'hits' => [],
                    'progress' => ['done' => 0, 'total' => count($places)],
                ];
            }

            $hits = [];
            $done = 0;
            $total = count($places);

            foreach ($places as $place) {
                try {
                    $data = $this->loadPlaceTimetable($place['id'], $place['name']);
                    if ($data !== null && !self::placeBusyAt($data, $dayIndex, is_int($lessonNumber) ? $lessonNumber : null)) {
                        $hits[] = $place;
                    }
                } catch (\Throwable) {
                    // skip failed place
                }
                $done++;
            }

            return [
                'success' => true,
                'hits' => self::sortHits($hits),
                'progress' => ['done' => $done, 'total' => $total],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'hits' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @return array<string, mixed>|null */
    private function loadPlaceTimetable(string $id, string $name): ?array
    {
        $dir = Bootstrap::cacheDir() . '/places';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $id) ?? 'unknown';
        $path = $dir . '/' . $safe . '.json';

        if (is_file($path)) {
            $raw = file_get_contents($path);
            $cached = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($cached)
                && isset($cached['savedAt'], $cached['data'])
                && is_array($cached['data'])
                && (time() - (int) $cached['savedAt']) < self::PLACE_CACHE_TTL) {
                return $cached['data'];
            }
        }

        $result = $this->timetable->fetch('place', $id, $name);
        if (!$result['success'] || !isset($result['data']) || !is_array($result['data'])) {
            return null;
        }

        file_put_contents(
            $path,
            json_encode(
                ['savedAt' => time(), 'data' => $result['data']],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );

        return $result['data'];
    }

    /** Live calendar day: 0=Mon…5=Sat; Sunday → -1. */
    public static function liveCalendarDayIndex(\DateTimeInterface $date): int
    {
        $dow = (int) $date->format('w'); // 0=Sun
        if ($dow === 0) {
            return -1;
        }
        return $dow - 1;
    }

    public static function parseIsoLocal(string $iso): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($iso), $m)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[0]);
        if ($d === false) {
            return null;
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            return null;
        }
        return $d;
    }

    public static function timeToMinutes(string $t): ?int
    {
        if (!preg_match('/^(\d{1,2})[:.](\d{2})$/', trim($t), $m)) {
            return null;
        }
        return ((int) $m[1]) * 60 + (int) $m[2];
    }

    private static function isActiveLesson(?string $subject, ?string $status): bool
    {
        $s = trim((string) $subject);
        if ($s === '' || $s === 'Урок снят') {
            return false;
        }
        if (in_array((string) $status, ['removed', 'cancelled', 'empty'], true)) {
            return false;
        }
        return true;
    }

    /**
     * @param list<array{id: string, name: string, type: string}> $hits
     * @return list<array{id: string, name: string, type: string}>
     */
    private static function sortHits(array $hits): array
    {
        usort($hits, static function ($a, $b) {
            return strcoll($a['name'], $b['name']);
        });
        return $hits;
    }
}
