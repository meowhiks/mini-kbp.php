<?php
declare(strict_types=1);

namespace MiniKbp;

/**
 * Merge short labels from kbp.by HTML parser with full names from rasp.kbp.by API.
 */
final class NameEnricher
{
    public const MAP_FILE = 'name_map_v1.json';

    public function __construct(
        private readonly RaspKbpClient $rasp = new RaspKbpClient(),
    ) {
    }

    /**
     * @param array<string, mixed> $data timetable payload from TimetableParser
     * @return array<string, mixed>
     */
    public function enrichTimetable(array $data, string $category, string $entityName = ''): array
    {
        $pairs = $data['pairs'] ?? null;
        if (!is_array($pairs) || $pairs === []) {
            return $data;
        }

        try {
            $lessons = $this->resolveApiLessons($data, $category, $entityName);
        } catch (\Throwable $e) {
            AppLog::warn('enrich.rasp_skip', ['error' => $e->getMessage()]);
            $lessons = [];
        }

        $map = $this->loadMap();
        $changed = false;

        if ($lessons !== []) {
            $index = $this->indexLessons($lessons);
            foreach ($pairs as &$pair) {
                if (!is_array($pair)) {
                    continue;
                }
                $hit = $this->matchLesson($pair, $index);
                if ($hit === null) {
                    continue;
                }
                $subjFull = RequestGuard::sanitizeLabel((string) (($hit['subject']['name'] ?? '') ?: ''));
                $teacherFull = RequestGuard::sanitizeLabel((string) (($hit['teacher']['name'] ?? '') ?: ''));
                $shortSubj = trim((string) ($pair['subject'] ?? ''));
                $shortTeacher = trim((string) ($pair['teacher'] ?? ''));

                if ($subjFull !== '') {
                    $pair['subjectFull'] = $subjFull;
                    if (isset($pair['refs']) && is_array($pair['refs']) && isset($pair['refs']['subject']) && is_array($pair['refs']['subject'])) {
                        $pair['refs']['subject']['nameFull'] = $subjFull;
                    }
                    if ($shortSubj !== '' && $shortSubj !== $subjFull) {
                        $map['subjects'][self::mapKey($shortSubj)] = $subjFull;
                        $changed = true;
                    }
                }
                if ($teacherFull !== '') {
                    $pair['teacherFull'] = $teacherFull;
                    if (isset($pair['refs']['teachers']) && is_array($pair['refs']['teachers'])) {
                        foreach ($pair['refs']['teachers'] as &$tref) {
                            if (!is_array($tref)) {
                                continue;
                            }
                            $tShort = trim((string) ($tref['name'] ?? ''));
                            if ($tShort !== '' && self::teachersLikelySame($tShort, $teacherFull)) {
                                $tref['nameFull'] = $teacherFull;
                            } elseif (count($pair['refs']['teachers']) === 1) {
                                $tref['nameFull'] = $teacherFull;
                            }
                        }
                        unset($tref);
                    }
                    if ($shortTeacher !== '' && $shortTeacher !== $teacherFull) {
                        // Prefer first teacher token for map when comma-joined
                        $firstShort = trim(explode(',', $shortTeacher)[0]);
                        if ($firstShort !== '') {
                            $map['teachers'][self::mapKey($firstShort)] = $teacherFull;
                            $changed = true;
                        }
                    }
                }
            }
            unset($pair);
        } else {
            // Fallback: catalog-only enrichment (no structural match)
            foreach ($pairs as &$pair) {
                if (!is_array($pair)) {
                    continue;
                }
                $shortSubj = trim((string) ($pair['subject'] ?? ''));
                $shortTeacher = trim((string) ($pair['teacher'] ?? ''));
                if ($shortSubj !== '' && empty($pair['subjectFull'])) {
                    $full = $map['subjects'][self::mapKey($shortSubj)] ?? null;
                    if (is_string($full) && $full !== '') {
                        $pair['subjectFull'] = $full;
                    }
                }
                if ($shortTeacher !== '' && empty($pair['teacherFull'])) {
                    $firstShort = trim(explode(',', $shortTeacher)[0]);
                    $full = $map['teachers'][self::mapKey($firstShort)] ?? $this->resolveTeacherFull($firstShort, $map);
                    if (is_string($full) && $full !== '') {
                        $pair['teacherFull'] = $full;
                    }
                }
            }
            unset($pair);
        }

        // Fill gaps from name map only (no full-dump network fetch)
        foreach ($pairs as &$pair) {
            if (!is_array($pair)) {
                continue;
            }
            if (empty($pair['teacherFull'])) {
                $firstShort = trim(explode(',', (string) ($pair['teacher'] ?? ''))[0]);
                if ($firstShort !== '') {
                    $full = $map['teachers'][self::mapKey($firstShort)] ?? null;
                    if ((!is_string($full) || $full === '') && $lessons !== []) {
                        // Build a tiny directory from this filtered lesson set
                        $dir = [];
                        foreach ($lessons as $row) {
                            if (!is_array($row) || !isset($row['teacher']) || !is_array($row['teacher'])) {
                                continue;
                            }
                            $tid = (string) ($row['teacher']['id'] ?? '');
                            $tname = RequestGuard::sanitizeLabel((string) ($row['teacher']['name'] ?? ''));
                            if ($tid !== '' && $tname !== '') {
                                $dir[$tid] = $tname;
                            }
                        }
                        $full = $this->matchTeacherInDirectory($firstShort, $dir);
                        if ($full !== null) {
                            $map['teachers'][self::mapKey($firstShort)] = $full;
                            $changed = true;
                        }
                    }
                    if (is_string($full) && $full !== '') {
                        $pair['teacherFull'] = $full;
                    }
                }
            }
            if (empty($pair['subjectFull'])) {
                $shortSubj = trim((string) ($pair['subject'] ?? ''));
                if ($shortSubj !== '') {
                    $full = $map['subjects'][self::mapKey($shortSubj)] ?? null;
                    if (is_string($full) && $full !== '') {
                        $pair['subjectFull'] = $full;
                    }
                }
            }
        }
        unset($pair);

        $data['pairs'] = $pairs;
        $filled = 0;
        foreach ($pairs as $p) {
            if (!is_array($p)) {
                continue;
            }
            if (!empty($p['subjectFull']) || !empty($p['teacherFull'])) {
                $filled++;
            }
        }
        // Only mark ok when at least one full name landed — otherwise UI can retry
        $data['enrichStatus'] = $filled > 0 ? 'ok' : 'failed';
        $data['enriched'] = $filled > 0;
        if ($changed) {
            $this->saveMap($map);
        }
        return $data;
    }

    /**
     * Enrich search-index rows with nameFull (teachers/subjects/groups from rasp).
     *
     * @param list<array{id: string, name: string, type: string, typeLabel: string}> $items
     * @return list<array{id: string, name: string, type: string, typeLabel: string, nameFull?: string}>
     */
    public function enrichSearchItems(array $items): array
    {
        $map = $this->loadMap();
        // Directories only from disk cache (may be empty) — never pull 1.4MB dump
        $teacherDir = $this->teacherDirectory();
        $subjectDir = $this->subjectDirectory();
        $groupNames = [];
        foreach ($this->rasp->fetchGroups() as $g) {
            $groupNames[self::mapKey($g['name'])] = $g['name'];
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? '');
            $name = (string) ($item['name'] ?? '');
            $full = '';
            if ($type === 'teacher') {
                $full = (string) ($map['teachers'][self::mapKey($name)] ?? '');
                if ($full === '') {
                    $full = (string) ($this->matchTeacherInDirectory($name, $teacherDir) ?? '');
                    if ($full !== '') {
                        $map['teachers'][self::mapKey($name)] = $full;
                    }
                }
            } elseif ($type === 'subject') {
                $full = (string) ($map['subjects'][self::mapKey($name)] ?? '');
                if ($full === '') {
                    $full = (string) ($this->matchSubjectInDirectory($name, $subjectDir) ?? '');
                    if ($full !== '') {
                        $map['subjects'][self::mapKey($name)] = $full;
                    }
                }
            } elseif ($type === 'group') {
                // Groups usually identical; keep API spelling if present
                $g = $groupNames[self::mapKey($name)] ?? '';
                if ($g !== '' && $g !== $name) {
                    $full = $g;
                }
            }
            if ($full !== '' && $full !== $name) {
                $item['nameFull'] = RequestGuard::sanitizeLabel($full);
            }
            // Canonical type labels (all 4 search facets)
            if (($item['typeLabel'] ?? '') === '' || ($item['typeLabel'] ?? '') === $type) {
                $item['typeLabel'] = match ($type) {
                    'group' => 'группа',
                    'teacher' => 'преподаватель',
                    'place' => 'аудитория',
                    'subject' => 'предмет',
                    default => (string) ($item['typeLabel'] ?? $type),
                };
            }
            $out[] = $item;
        }
        $this->saveMap($map);
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function resolveApiLessons(array $data, string $category, string $entityName): array
    {
        $name = trim($entityName);
        if ($name === '' || preg_match('/расписание/iu', $name)) {
            $name = trim((string) ($data['groupName'] ?? ''));
        }
        if ($name === '' || preg_match('/расписание/iu', $name)) {
            // Infer from first pair group label
            foreach ($data['pairs'] ?? [] as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $g = trim((string) (($p['refs']['group']['name'] ?? null) ?: ($p['group'] ?? '')));
                if ($g !== '') {
                    $name = $g;
                    break;
                }
            }
        }

        if ($category === 'group') {
            $gid = $this->rasp->findGroupIdByName($name);
            if ($gid === null) {
                return [];
            }
            return $this->rasp->filterSchedule(['groupId' => $gid]);
        }
        if ($category === 'place') {
            $room = $name !== '' ? $name : '';
            if ($room === '') {
                foreach ($data['pairs'] ?? [] as $p) {
                    if (is_array($p) && trim((string) ($p['room'] ?? '')) !== '') {
                        $room = (string) $p['room'];
                        break;
                    }
                }
            }
            if ($room === '') {
                return [];
            }
            return $this->rasp->filterSchedule(['room' => $room]);
        }
        if ($category === 'teacher') {
            $tid = $this->findApiTeacherId($name);
            if ($tid === null) {
                return [];
            }
            return $this->rasp->filterSchedule(['teacherId' => $tid]);
        }
        if ($category === 'subject') {
            // No subjectId filter on rasp API without full dump — use name map only
            return [];
        }
        return [];
    }

    private function findApiTeacherId(string $shortOrFull): ?string
    {
        $dir = $this->teacherDirectory();
        $full = $this->matchTeacherInDirectory($shortOrFull, $dir);
        if ($full === null) {
            return null;
        }
        foreach ($dir as $id => $name) {
            if ($name === $full) {
                return $id;
            }
        }
        return null;
    }

    private function findApiSubjectId(string $shortOrFull): ?string
    {
        $map = $this->loadMap();
        $full = $map['subjects'][self::mapKey($shortOrFull)] ?? null;
        if (!is_string($full) || $full === '') {
            $full = $this->matchSubjectInDirectory($shortOrFull, $this->subjectDirectory());
        }
        if ($full === null || $full === '') {
            // maybe already full
            $full = $shortOrFull;
        }
        foreach ($this->subjectDirectory() as $id => $name) {
            if (self::normCompact($name) === self::normCompact($full)) {
                return $id;
            }
        }
        return null;
    }

    /**
     * @param list<array<string, mixed>> $lessons
     * @return array<string, list<array<string, mixed>>>
     */
    private function indexLessons(array $lessons): array
    {
        $idx = [];
        foreach ($lessons as $row) {
            if (!is_array($row)) {
                continue;
            }
            $day = (int) ($row['dayOfWeek'] ?? 0); // 1=Mon
            $num = (int) ($row['lessonNumber'] ?? 0);
            $room = RaspKbpClient::normRoom((string) ($row['room'] ?? ''));
            $key = $day . '|' . $num . '|' . $room;
            $idx[$key][] = $row;
            // also index without room for fallback
            $idx[$day . '|' . $num . '|'][] = $row;
        }
        return $idx;
    }

    /**
     * @param array<string, mixed> $pair
     * @param array<string, list<array<string, mixed>>> $index
     * @return array<string, mixed>|null
     */
    private function matchLesson(array $pair, array $index): ?array
    {
        $day = (int) ($pair['day'] ?? -1); // 0=Mon
        $num = (int) ($pair['pairNumber'] ?? 0);
        if ($day < 0 || $num < 1) {
            return null;
        }
        $apiDay = $day + 1;
        $room = RaspKbpClient::normRoom((string) ($pair['room'] ?? ''));
        $cands = $index[$apiDay . '|' . $num . '|' . $room] ?? [];
        if ($cands === []) {
            $cands = $index[$apiDay . '|' . $num . '|'] ?? [];
        }
        if ($cands === []) {
            return null;
        }
        if (count($cands) === 1) {
            return $cands[0];
        }
        // Prefer matching teacher initials / subject hint
        $shortTeacher = trim((string) ($pair['teacher'] ?? ''));
        foreach ($cands as $c) {
            $tf = (string) (($c['teacher']['name'] ?? '') ?: '');
            if ($shortTeacher !== '' && self::teachersLikelySame($shortTeacher, $tf)) {
                return $c;
            }
        }
        $subg = trim((string) ($pair['subgroup'] ?? ''));
        if ($subg !== '') {
            foreach ($cands as $c) {
                if ((string) ($c['subgroup'] ?? '') === $subg) {
                    return $c;
                }
            }
        }
        return $cands[0];
    }

    /** @return array{teachers: array<string, string>, subjects: array<string, string>} */
    private function loadMap(): array
    {
        $path = Bootstrap::cacheDir() . '/' . self::MAP_FILE;
        $empty = ['teachers' => [], 'subjects' => []];
        if (!is_file($path)) {
            return $empty;
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return $empty;
        }
        return [
            'teachers' => is_array($data['teachers'] ?? null) ? $data['teachers'] : [],
            'subjects' => is_array($data['subjects'] ?? null) ? $data['subjects'] : [],
        ];
    }

    /** @param array{teachers: array<string, string>, subjects: array<string, string>} $map */
    private function saveMap(array $map): void
    {
        $path = Bootstrap::cacheDir() . '/' . self::MAP_FILE;
        @file_put_contents($path, json_encode([
            'savedAt' => time(),
            'teachers' => $map['teachers'],
            'subjects' => $map['subjects'],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> id => full name */
    private function teacherDirectory(): array
    {
        $dir = [];
        foreach ($this->rasp->fetchSchedule() as $row) {
            if (!is_array($row) || !isset($row['teacher']) || !is_array($row['teacher'])) {
                continue;
            }
            $id = (string) ($row['teacher']['id'] ?? '');
            $name = RequestGuard::sanitizeLabel((string) ($row['teacher']['name'] ?? ''));
            if ($id !== '' && $name !== '') {
                $dir[$id] = $name;
            }
        }
        return $dir;
    }

    /** @return array<string, string> id => full name */
    private function subjectDirectory(): array
    {
        $dir = [];
        foreach ($this->rasp->fetchSchedule() as $row) {
            if (!is_array($row) || !isset($row['subject']) || !is_array($row['subject'])) {
                continue;
            }
            $id = (string) ($row['subject']['id'] ?? '');
            $name = RequestGuard::sanitizeLabel((string) ($row['subject']['name'] ?? ''));
            if ($id !== '' && $name !== '') {
                $dir[$id] = $name;
            }
        }
        return $dir;
    }

    /** @param array<string, string> $dir */
    private function matchTeacherInDirectory(string $short, array $dir): ?string
    {
        $key = self::teacherKey($short);
        if ($key === null) {
            // surname-only
            $sur = self::normCompact($short);
            $hits = [];
            foreach ($dir as $full) {
                $parts = preg_split('/\s+/u', trim($full)) ?: [];
                if ($parts !== [] && self::normCompact($parts[0]) === $sur) {
                    $hits[] = $full;
                }
            }
            return count($hits) === 1 ? $hits[0] : null;
        }
        [$surname, $initials] = $key;
        foreach ($dir as $full) {
            $fk = self::teacherKeyFromFull($full);
            if ($fk !== null && $fk[0] === $surname && ($initials === '' || $fk[1] === $initials || str_starts_with($fk[1], $initials))) {
                return $full;
            }
        }
        return null;
    }

    /** @param array<string, string> $dir */
    private function matchSubjectInDirectory(string $short, array $dir): ?string
    {
        $compact = self::normCompact($short);
        if ($compact === '') {
            return null;
        }
        // Exact compact match
        foreach ($dir as $full) {
            if (self::normCompact($full) === $compact) {
                return $full;
            }
        }
        // Acronym / letter-skeleton: all letters of short appear in order in full (latin+cyr)
        $best = null;
        $bestScore = 0;
        foreach ($dir as $full) {
            $score = self::abbrevScore($compact, self::normCompact($full));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $full;
            }
        }
        // Require reasonably confident acronym hit
        if ($best !== null && $bestScore >= 0.72 && mb_strlen($compact) >= 3) {
            return $best;
        }
        return null;
    }

    private function resolveTeacherFull(string $short, array $map): ?string
    {
        $hit = $map['teachers'][self::mapKey($short)] ?? null;
        return is_string($hit) && $hit !== '' ? $hit : null;
    }

    public static function mapKey(string $name): string
    {
        return RaspKbpClient::normCompact($name);
    }

    public static function teachersLikelySame(string $short, string $full): bool
    {
        $a = self::teacherKey($short);
        $b = self::teacherKeyFromFull($full);
        if ($a === null || $b === null) {
            return self::normCompact($short) !== '' && str_contains(self::normCompact($full), self::normCompact($short));
        }
        if ($a[0] !== $b[0]) {
            return false;
        }
        if ($a[1] === '' || $b[1] === '') {
            return true;
        }
        return $a[1] === $b[1] || str_starts_with($b[1], $a[1]) || str_starts_with($a[1], $b[1]);
    }

    /** Short kbp label (e.g. БелЛит) ↔ full rasp subject title. */
    public static function subjectsLikelySame(string $short, string $full): bool
    {
        $a = self::normCompact($short);
        $b = self::normCompact($full);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b || str_contains($b, $a)) {
            return true;
        }
        // CamelCase chunks: «БелЛит» → Белорусская + литература
        if (preg_match_all('/\p{Lu}\p{Ll}*/u', trim($short), $tm) && count($tm[0]) >= 2) {
            $words = preg_split('/\s+/u', trim(preg_replace('/[«»"\']/u', '', $full) ?? $full)) ?: [];
            $wi = 0;
            $ok = true;
            foreach ($tm[0] as $tok) {
                $tokN = self::normCompact($tok);
                if ($tokN === '') {
                    continue;
                }
                $found = false;
                for ($j = $wi; $j < count($words); $j++) {
                    $w = self::normCompact((string) $words[$j]);
                    if ($w !== '' && str_starts_with($w, $tokN)) {
                        $wi = $j + 1;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }
        return self::abbrevScore($a, $b) >= 0.55 && mb_strlen($a) >= 3;
    }

    /** @return array{0: string, 1: string}|null */
    public static function teacherKey(string $name): ?array
    {
        $n = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        // "ФамилияИ.О." / "Фамилия И.О." / "Фамилия И. О."
        if (preg_match('/^([A-Za-zА-Яа-яЁё\-]+)\s*([A-Za-zА-Яа-яЁё])\.\s*([A-Za-zА-Яа-яЁё])\.?\s*$/u', $n, $m)) {
            return [mb_strtolower($m[1]), mb_strtolower($m[2] . $m[3])];
        }
        if (preg_match('/^([A-Za-zА-Яа-яЁё\-]+)\s*([A-Za-zА-Яа-яЁё])\.\s*$/u', $n, $m)) {
            return [mb_strtolower($m[1]), mb_strtolower($m[2])];
        }
        // Already full FIO
        return self::teacherKeyFromFull($n);
    }

    /** @return array{0: string, 1: string}|null */
    public static function teacherKeyFromFull(string $name): ?array
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        if (count($parts) >= 3) {
            return [mb_strtolower($parts[0]), mb_strtolower(mb_substr($parts[1], 0, 1) . mb_substr($parts[2], 0, 1))];
        }
        if (count($parts) === 2) {
            return [mb_strtolower($parts[0]), mb_strtolower(mb_substr($parts[1], 0, 1))];
        }
        return null;
    }

    private static function abbrevScore(string $short, string $full): float
    {
        if ($short === '' || $full === '') {
            return 0.0;
        }
        if (str_contains($full, $short)) {
            return 1.0;
        }
        $si = 0;
        $len = mb_strlen($short);
        $flen = mb_strlen($full);
        for ($fi = 0; $fi < $flen && $si < $len; $fi++) {
            if (mb_substr($full, $fi, 1) === mb_substr($short, $si, 1)) {
                $si++;
            }
        }
        if ($si < $len) {
            return 0.0;
        }
        // Prefer shorter full names for same coverage (acronym-like)
        return min(1.0, ($len / max(3, $flen)) * 2.2);
    }

    private static function normCompact(string $value): string
    {
        return RaspKbpClient::normCompact($value);
    }
}
