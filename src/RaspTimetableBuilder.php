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
        $path = Bootstrap::cacheDir() . '/search_index_v1.json';
        if (!is_file($path)) {
            return '';
        }
        $raw = file_get_contents($path);
        $cached = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($cached) || !is_array($cached['items'] ?? null)) {
            return '';
        }
        foreach ($cached['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((string) ($item['type'] ?? '') === $category && (string) ($item['id'] ?? '') === $entityId) {
                $n = RequestGuard::sanitizeLabel((string) ($item['nameFull'] ?? $item['name'] ?? ''));
                if ($n !== '') {
                    return $n;
                }
            }
        }
        return '';
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
            $subjId = trim((string) (($row['subject']['id'] ?? $row['subjectId'] ?? '') ?: ''));
            $teacherId = trim((string) (($row['teacher']['id'] ?? $row['teacherId'] ?? '') ?: ''));
            $groupApiId = trim((string) (($row['group']['id'] ?? $row['groupId'] ?? '') ?: ''));

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
            if ($subjId !== '') {
                $pair['refs']['subject'] = ['id' => $subjId, 'name' => $subjFull, 'nameFull' => $subjFull];
            }
            if ($teacherId !== '' && ($teacherShort !== '' || $teacherFull !== '')) {
                $pair['refs']['teachers'][] = [
                    'id' => $teacherId,
                    'name' => $teacherShort !== '' ? $teacherShort : $teacherFull,
                    'nameFull' => $teacherFull,
                ];
            }
            if ($groupApiId !== '' && $groupName !== '') {
                $pair['refs']['group'] = [
                    'id' => $category === 'group' ? $entityId : $groupApiId,
                    'name' => $groupName,
                ];
            }
            if ($room !== '') {
                $pair['refs']['place'] = [
                    'id' => $category === 'place' ? $entityId : $room,
                    'name' => $room,
                ];
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
            return $monday->format('d.m.Y') . ' — ' . $saturday->format('d.m.Y');
        } catch (\Throwable) {
            return '';
        }
    }
}
