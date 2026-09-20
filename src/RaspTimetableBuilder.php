<?php
declare(strict_types=1);

namespace MiniKbp;

/**
 * Build a TimetableParser-shaped payload from rasp.kbp.by JSON
 * when kbp.by HTML is unavailable. No replacement statuses in the API.
 */
final class RaspTimetableBuilder
{
    private const WEEK_DAYS = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];

    /** @var list<array<string, mixed>>|null */
    private ?array $searchIndexItems = null;

    public function __construct(
        private readonly RaspKbpClient $rasp = new RaspKbpClient(),
    ) {
    }

    /**
     * @return array<string, mixed>|null null when rasp cannot produce a timetable
     */
    public function build(string $category, string $entityId, string $entityName = ''): ?array
    {
        $category = RequestGuard::category($category) ?? '';
        $entityId = RequestGuard::entityId($entityId) ?? '';
        if ($category === '' || $entityId === '') {
            return null;
        }

        $name = $this->resolveEntityName($category, $entityId, $entityName);
        $lessons = $this->fetchLessons($category, $name, $entityId);
        if ($lessons === []) {
            return null;
        }

        return $this->lessonsToTimetable($lessons, $category, $entityId, $name !== '' ? $name : "{$category}-{$entityId}");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchLessons(string $category, string $name, string $entityId): array
    {
        try {
            if ($category === 'group') {
                if ($name === '') {
                    return [];
                }
                $gid = $this->rasp->findGroupIdByName($name);
                if ($gid === null) {
                    return [];
                }
                return $this->rasp->filterSchedule(['groupId' => $gid]);
            }
            if ($category === 'place') {
                $room = $name !== '' ? $name : $entityId;
                $room = preg_replace('/^ауд\.?\s*/iu', '', $room) ?? $room;
                if (RaspKbpClient::normRoom($room) === '') {
                    return [];
                }
                return $this->rasp->filterSchedule(['room' => $room]);
            }
            if ($category === 'teacher') {
                if ($name === '') {
                    return [];
                }
                $tid = $this->rasp->findTeacherIdByName($name);
                if ($tid === null) {
                    return [];
                }
                return $this->rasp->filterSchedule(['teacherId' => $tid]);
            }
            if ($category === 'subject') {
                if ($name === '') {
                    return [];
                }
                return $this->rasp->findLessonsBySubject($name);
            }
        } catch (\Throwable $e) {
            AppLog::warn('rasp.tt_build_fail', [
                'cat' => $category,
                'id' => $entityId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
        return [];
    }

    private function resolveEntityName(string $category, string $entityId, string $entityName): string
    {
        $name = RequestGuard::displayName($entityName);
        if ($name !== '' && !preg_match('/расписание/iu', $name)) {
            return $name;
        }
        $fromIndex = $this->nameFromSearchIndex($category, $entityId);
        return $fromIndex !== '' ? $fromIndex : $name;
    }

    private function nameFromSearchIndex(string $category, string $entityId): string
    {
        foreach ($this->searchIndexItems() as $item) {
            if ((string) ($item['type'] ?? '') === $category && (string) ($item['id'] ?? '') === $entityId) {
                $n = RequestGuard::sanitizeLabel((string) ($item['nameFull'] ?? $item['name'] ?? ''));
                if ($n !== '') {
                    return $n;
                }
            }
        }
        return '';
    }

    /** Resolve kbp.by entity id from search index by display name (never rasp API ids). */
    private function idFromSearchIndex(string $category, string $name): string
    {
        $name = RequestGuard::sanitizeLabel($name);
        if ($name === '') {
            return '';
        }
        $norm = mb_strtolower(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $fallback = '';
        foreach ($this->searchIndexItems() as $item) {
            if ((string) ($item['type'] ?? '') !== $category) {
                continue;
            }
            $id = RequestGuard::entityId((string) ($item['id'] ?? '')) ?? '';
            if ($id === '') {
                continue;
            }
            $candidates = [
                (string) ($item['name'] ?? ''),
                (string) ($item['nameFull'] ?? ''),
            ];
            foreach ($candidates as $cand) {
                $cand = RequestGuard::sanitizeLabel($cand);
                if ($cand === '') {
                    continue;
                }
                if (mb_strtolower(preg_replace('/\s+/u', ' ', $cand) ?? $cand) === $norm) {
                    return $id;
                }
            }
            // Soft match for rooms like "101" vs "ауд. 101"
            if ($category === 'place' && $fallback === '') {
                $roomNorm = preg_replace('/^ауд\.?\s*/iu', '', $norm) ?? $norm;
                foreach ($candidates as $cand) {
                    $cand = RequestGuard::sanitizeLabel($cand);
                    $candNorm = mb_strtolower(preg_replace('/\s+/u', ' ', $cand) ?? $cand);
                    $candRoom = preg_replace('/^ауд\.?\s*/iu', '', $candNorm) ?? $candNorm;
                    if ($candRoom !== '' && $candRoom === $roomNorm) {
                        $fallback = $id;
                        break;
                    }
                }
            }
        }
        return $fallback;
    }

    /** @return list<array<string, mixed>> */
    private function searchIndexItems(): array
    {
        if ($this->searchIndexItems !== null) {
            return $this->searchIndexItems;
        }
        $path = Bootstrap::cacheDir() . '/search_index_v1.json';
        if (!is_file($path)) {
            $this->searchIndexItems = [];
            return $this->searchIndexItems;
        }
        $raw = file_get_contents($path);
        $cached = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($cached) || !is_array($cached['items'] ?? null)) {
            $this->searchIndexItems = [];
            return $this->searchIndexItems;
        }
        $items = [];
        foreach ($cached['items'] as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }
        $this->searchIndexItems = $items;
        return $this->searchIndexItems;
    }

    /**
     * @param list<array<string, mixed>> $lessons
     * @return array<string, mixed>
     */
    private function lessonsToTimetable(array $lessons, string $category, string $entityId, string $entityName): array
    {
        $data = [
            'groupId' => $entityId,
            'groupName' => $entityName,
            'category' => $category,
            'pairs' => [],
            'dayStartTimes' => array_fill(0, 6, ['start' => '', 'end' => '']),
            'dayReplacementStatus' => array_fill(0, 6, [
                'label' => '', 'hasChanges' => false, 'noChanges' => false, 'unknown' => true,
            ]),
            'hasNextWeekMonday' => false,
            'hasNextWeek' => false,
            'currentWeek' => [
                'dateRange' => self::currentWeekDateRange(),
                'weekLabel' => '',
            ],
            'source' => 'rasp.kbp.by',
            'enriched' => true,
            'enrichStatus' => 'ok',
        ];

        $pairs = [];
        foreach ($lessons as $row) {
            if (!is_array($row)) {
                continue;
            }
            $apiDay = (int) ($row['dayOfWeek'] ?? 0);
            $pairNumber = (int) ($row['lessonNumber'] ?? 0);
            if ($apiDay < 1 || $apiDay > 6 || $pairNumber < 1) {
                continue;
            }
            $day = $apiDay - 1;
            $subjFull = RequestGuard::sanitizeLabel((string) (($row['subject']['name'] ?? '') ?: ''));
            $teacherFull = RequestGuard::sanitizeLabel((string) (($row['teacher']['name'] ?? '') ?: ''));
            $room = RequestGuard::sanitizeLabel((string) ($row['room'] ?? ''));
            $groupName = RequestGuard::sanitizeLabel((string) (($row['group']['name'] ?? '') ?: ''));
            if ($subjFull === '') {
                continue;
            }
            $teacherShort = self::shortenTeacher($teacherFull);
            // Never put rasp.kbp.by numeric IDs into client refs — navigation uses kbp.by IDs.
            $kbpSubjectId = $this->idFromSearchIndex('subject', $subjFull);
            $kbpTeacherId = $teacherFull !== '' ? $this->idFromSearchIndex('teacher', $teacherFull) : '';
            if ($kbpTeacherId === '' && $teacherShort !== '') {
                $kbpTeacherId = $this->idFromSearchIndex('teacher', $teacherShort);
            }
            $kbpGroupId = $groupName !== '' ? $this->idFromSearchIndex('group', $groupName) : '';
            $kbpPlaceId = $room !== '' ? $this->idFromSearchIndex('place', $room) : '';

            $pair = [
                'pairNumber' => $pairNumber,
                'day' => $day,
                'dayName' => self::WEEK_DAYS[$day],
                'subject' => $subjFull,
                'subjectFull' => $subjFull,
                'teacher' => $teacherShort !== '' ? $teacherShort : $teacherFull,
                'teacherFull' => $teacherFull,
                'room' => $room,
                'group' => $groupName,
                'status' => 'normal',
                'weekOffset' => 0,
                'refs' => [
                    'teachers' => [],
                ],
            ];
            if ($subjFull !== '') {
                $subjRef = ['name' => $subjFull, 'nameFull' => $subjFull];
                if ($category === 'subject') {
                    $subjRef['id'] = $entityId;
                } elseif ($kbpSubjectId !== '') {
                    $subjRef['id'] = $kbpSubjectId;
                }
                $pair['refs']['subject'] = $subjRef;
            }
            if ($teacherShort !== '' || $teacherFull !== '') {
                $tref = [
                    'name' => $teacherShort !== '' ? $teacherShort : $teacherFull,
                    'nameFull' => $teacherFull,
                ];
                if ($category === 'teacher') {
                    $tref['id'] = $entityId;
                } elseif ($kbpTeacherId !== '') {
                    $tref['id'] = $kbpTeacherId;
                }
                $pair['refs']['teachers'][] = $tref;
            }
            if ($groupName !== '') {
                $gref = ['name' => $groupName];
                if ($category === 'group') {
                    $gref['id'] = $entityId;
                } elseif ($kbpGroupId !== '') {
                    $gref['id'] = $kbpGroupId;
                }
                $pair['refs']['group'] = $gref;
            }
            if ($room !== '') {
                $pref = ['name' => $room];
                if ($category === 'place') {
                    $pref['id'] = $entityId;
                } elseif ($kbpPlaceId !== '') {
                    $pref['id'] = $kbpPlaceId;
                }
                $pair['refs']['place'] = $pref;
            }
            $pairs[] = $pair;
        }

        usort($pairs, static function (array $a, array $b): int {
            $c = ((int) $a['day']) <=> ((int) $b['day']);
            if ($c !== 0) {
                return $c;
            }
            return ((int) $a['pairNumber']) <=> ((int) $b['pairNumber']);
        });

        $data['pairs'] = $pairs;
        TimetableParser::fillDayRanges($data);
        return $data;
    }

    public static function shortenTeacher(string $full): string
    {
        $full = trim(preg_replace('/\s+/u', ' ', $full) ?? $full);
        if ($full === '') {
            return '';
        }
        $parts = preg_split('/\s+/u', $full) ?: [];
        if (count($parts) >= 3) {
            $i1 = mb_substr($parts[1], 0, 1);
            $i2 = mb_substr($parts[2], 0, 1);
            return $parts[0] . ' ' . $i1 . '.' . $i2 . '.';
        }
        if (count($parts) === 2) {
            $i1 = mb_substr($parts[1], 0, 1);
            return $parts[0] . ' ' . $i1 . '.';
        }
        return $full;
    }

    public static function currentWeekDateRange(): string
    {
        try {
            $tz = new \DateTimeZone('Europe/Minsk');
            $now = new \DateTimeImmutable('now', $tz);
            $dow = (int) $now->format('N');
            $monday = $now->modify('-' . ($dow - 1) . ' days')->setTime(0, 0);
            $saturday = $monday->modify('+5 days');
            $months = [
                1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
                5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
                9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
            ];
            $d1 = (int) $monday->format('j');
            $d2 = (int) $saturday->format('j');
            $m1 = (int) $monday->format('n');
            $m2 = (int) $saturday->format('n');
            if ($m1 === $m2) {
                return $d1 . ' — ' . $d2 . ' ' . $months[$m1];
            }
            return $d1 . ' ' . $months[$m1] . ' — ' . $d2 . ' ' . $months[$m2];
        } catch (\Throwable) {
            return '';
        }
    }
}
