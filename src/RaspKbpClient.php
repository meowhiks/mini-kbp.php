<?php
declare(strict_types=1);

namespace MiniKbp;

/**
 * JSON client for https://rasp.kbp.by (Next.js schedule API).
 * Used to enrich short kbp.by labels with full subject/teacher names.
 *
 * Prefer filtered /api/schedule?groupId|teacherId|room= — the full dump is ~1.4MB and often times out.
 */
final class RaspKbpClient
{
    public const CACHE_GROUPS = 'rasp_groups_v1.json';
    public const CACHE_SCHEDULE = 'rasp_schedule_v1.json';
    public const CACHE_TTL_SEC = 21600;

    private UpstreamRateLimiter $limiter;

    public function __construct(
        private readonly string $baseUrl = '',
        /** Filtered payloads are small; keep timeout moderate. */
        private readonly int $timeoutSec = 20,
        ?UpstreamRateLimiter $limiter = null,
    ) {
        $this->limiter = $limiter ?? new UpstreamRateLimiter(
            Bootstrap::cacheDir() . '/rasp_rate_limit.json'
        );
    }

    public static function apiBaseUrl(): string
    {
        return rtrim(getenv('RASP_KBP_API_URL') ?: 'https://rasp.kbp.by', '/');
    }

    /** @return list<array{id: string, name: string}> */
    public function fetchGroups(bool $forceRefresh = false): array
    {
        $cached = $this->readJsonCache(self::CACHE_GROUPS, self::CACHE_TTL_SEC);
        if (!$forceRefresh && is_array($cached)) {
            return $cached;
        }
        try {
            $raw = $this->getJson('/api/groups');
            $list = [];
            if (is_array($raw)) {
                foreach ($raw as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $id = trim((string) ($row['id'] ?? ''));
                    $name = RequestGuard::sanitizeLabel((string) ($row['name'] ?? ''));
                    if ($id === '' || $name === '') {
                        continue;
                    }
                    $list[] = ['id' => $id, 'name' => $name];
                }
            }
            $this->writeJsonCache(self::CACHE_GROUPS, $list);
            return $list;
        } catch (\Throwable $e) {
            AppLog::warn('rasp.groups_fail', ['error' => $e->getMessage()]);
            if (is_array($cached)) {
                return $cached;
            }
            $stale = $this->readJsonCache(self::CACHE_GROUPS, 14 * 24 * 3600);
            return is_array($stale) ? $stale : [];
        }
    }

    /**
     * Full schedule dump — only from disk cache (never fetch over network here).
     * Full dump times out from many hosts; enrichment must use filterSchedule().
     *
     * @return list<array<string, mixed>>
     */
    public function fetchSchedule(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            // Intentionally no network full-dump; callers should use filterSchedule.
            AppLog::warn('rasp.schedule_full_skipped', ['reason' => 'use filterSchedule']);
        }
        $cached = $this->readJsonCache(self::CACHE_SCHEDULE, self::CACHE_TTL_SEC);
        if (is_array($cached)) {
            return $cached;
        }
        $stale = $this->readJsonCache(self::CACHE_SCHEDULE, 14 * 24 * 3600);
        return is_array($stale) ? $stale : [];
    }

    /**
     * Fetch a filtered lesson list from rasp.kbp.by (small JSON).
     *
     * @param array{groupId?: string, teacherId?: string, room?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function filterSchedule(array $filters): array
    {
        $groupId = trim((string) ($filters['groupId'] ?? ''));
        $teacherId = trim((string) ($filters['teacherId'] ?? ''));
        $room = trim((string) ($filters['room'] ?? ''));
        if ($groupId === '' && $teacherId === '' && $room === '') {
            return $this->fetchSchedule();
        }

        $query = [];
        if ($groupId !== '') {
            $query['groupId'] = $groupId;
        }
        if ($teacherId !== '') {
            $query['teacherId'] = $teacherId;
        }
        if ($room !== '') {
            $query['room'] = $room;
        }

        $cacheKey = 'rasp_sched_f_' . sha1(http_build_query($query)) . '.json';
        $cached = $this->readJsonCache($cacheKey, self::CACHE_TTL_SEC);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $path = '/api/schedule?' . http_build_query($query);
            $raw = $this->getJson($path);
            $list = is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
            // Defense: if API ignored filters and returned a huge dump, filter locally
            if (count($list) > 400) {
                $list = $this->applyLocalFilters($list, $groupId, $teacherId, $room);
            }
            $this->writeJsonCache($cacheKey, $list);
            return $list;
        } catch (\Throwable $e) {
            AppLog::warn('rasp.schedule_filter_fail', [
                'error' => $e->getMessage(),
                'query' => $query,
            ]);
            // Fallback: local filter of cached full dump (if any)
            $all = $this->fetchSchedule();
            if ($all === []) {
                return [];
            }
            return $this->applyLocalFilters($all, $groupId, $teacherId, $room);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function applyLocalFilters(array $rows, string $groupId, string $teacherId, string $room): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($groupId !== '' && (string) ($row['groupId'] ?? '') !== $groupId) {
                continue;
            }
            if ($teacherId !== '' && (string) ($row['teacherId'] ?? '') !== $teacherId) {
                continue;
            }
            if ($room !== '' && self::normRoom((string) ($row['room'] ?? '')) !== self::normRoom($room)) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    public function findGroupIdByName(string $name): ?string
    {
        $want = self::normCompact($name);
        if ($want === '') {
            return null;
        }
        foreach ($this->fetchGroups() as $g) {
            if (self::normCompact($g['name']) === $want) {
                return $g['id'];
            }
        }
        return null;
    }

    /**
     * Resolve teacher UUID by short or full name (scans schedule caches + full dump).
     * On miss, walks group schedules once (results cached via filterSchedule).
     */
    public function findTeacherIdByName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $hit = $this->matchTeacherInRows($name, $this->fetchSchedule());
        if ($hit !== null) {
            return $hit;
        }
        foreach ($this->cachedFilteredSchedules() as $rows) {
            $hit = $this->matchTeacherInRows($name, $rows);
            if ($hit !== null) {
                return $hit;
            }
        }
        foreach ($this->fetchGroups() as $g) {
            $gid = (string) ($g['id'] ?? '');
            if ($gid === '') {
                continue;
            }
            try {
                $rows = $this->filterSchedule(['groupId' => $gid]);
            } catch (\Throwable) {
                continue;
            }
            $hit = $this->matchTeacherInRows($name, $rows);
            if ($hit !== null) {
                return $hit;
            }
        }
        return null;
    }

    /**
     * Collect lessons for a subject short/full name (no subjectId filter on API).
     *
     * @return list<array<string, mixed>>
     */
    public function findLessonsBySubject(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }
        $out = [];
        $seen = [];
        $consume = function (array $rows) use (&$out, &$seen, $name): void {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $subj = trim((string) (($row['subject']['name'] ?? '') ?: ''));
                if ($subj === '' || !NameEnricher::subjectsLikelySame($name, $subj)) {
                    continue;
                }
                $lid = trim((string) ($row['id'] ?? ''));
                $key = $lid !== ''
                    ? $lid
                    : ((string) ($row['dayOfWeek'] ?? '') . '|' . (string) ($row['lessonNumber'] ?? '') . '|' . (string) ($row['groupId'] ?? '') . '|' . self::normRoom((string) ($row['room'] ?? '')));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $row;
            }
        };

        $consume($this->fetchSchedule());
        foreach ($this->cachedFilteredSchedules() as $rows) {
            $consume($rows);
        }
        if ($out !== []) {
            return $out;
        }
        // Cold cache: walk groups once (each filterSchedule is cached afterward)
        foreach ($this->fetchGroups() as $g) {
            $gid = (string) ($g['id'] ?? '');
            if ($gid === '') {
                continue;
            }
            try {
                $consume($this->filterSchedule(['groupId' => $gid]));
            } catch (\Throwable) {
                continue;
            }
        }
        return $out;
    }

    /** @return list<list<array<string, mixed>>> */
    private function cachedFilteredSchedules(): array
    {
        $out = [];
        $dir = Bootstrap::cacheDir();
        foreach (glob($dir . '/rasp_sched_f_*.json') ?: [] as $file) {
            $raw = file_get_contents($file);
            $cached = is_string($raw) ? json_decode($raw, true) : null;
            $rows = is_array($cached) ? ($cached['data'] ?? null) : null;
            if (is_array($rows) && $rows !== []) {
                $out[] = $rows;
            }
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function matchTeacherInRows(string $name, array $rows): ?string
    {
        $wantCompact = self::normCompact($name);
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['teacher']) || !is_array($row['teacher'])) {
                continue;
            }
            $id = trim((string) ($row['teacher']['id'] ?? ''));
            $full = trim((string) ($row['teacher']['name'] ?? ''));
            if ($id === '' || $full === '') {
                continue;
            }
            if ($wantCompact !== '' && self::normCompact($full) === $wantCompact) {
                return $id;
            }
            if (NameEnricher::teachersLikelySame($name, $full)) {
                return $id;
            }
        }
        return null;
    }

    public static function normRoom(string $room): string
    {
        return preg_replace('/\D+/', '', $room) ?? '';
    }

    public static function normCompact(string $value): string
    {
        $v = mb_strtolower(trim($value));
        $v = preg_replace('/\s+/u', '', $v) ?? '';
        return preg_replace('/[.,_()\-«»"\']/u', '', $v) ?? '';
    }

    /** @return mixed */
    private function getJson(string $path)
    {
        $url = ($this->baseUrl !== '' ? rtrim($this->baseUrl, '/') : self::apiBaseUrl()) . $path;
        $this->limiter->acquire();

        $t0 = microtime(true);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('rasp unavailable: curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => 'MiniKBP-PHP/1.0 (+https://mini-kbp.site)',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
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
            AppLog::error('rasp.fetch_fail', ['url' => $url, 'errno' => $errno, 'error' => $error, 'ms' => $ms]);
            throw new \RuntimeException('rasp unavailable: ' . ($error ?: 'empty body'));
        }
        if ($status < 200 || $status >= 300) {
            AppLog::error('rasp.fetch_http', ['url' => $url, 'status' => $status, 'ms' => $ms]);
            throw new \RuntimeException("rasp unavailable: HTTP {$status}");
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('rasp unavailable: invalid json');
        }
        AppLog::info('rasp.fetch_ok', ['url' => $url, 'bytes' => strlen($body), 'ms' => $ms]);
        return $decoded;
    }

    /** @return list<array<string, mixed>>|null */
    private function readJsonCache(string $file, int $maxAgeSec): ?array
    {
        $path = Bootstrap::cacheDir() . '/' . $file;
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        $cached = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($cached) || !isset($cached['savedAt'], $cached['data']) || !is_array($cached['data'])) {
            return null;
        }
        if ((time() - (int) $cached['savedAt']) >= $maxAgeSec) {
            return null;
        }
        return $cached['data'];
    }

    /** @param list<array<string, mixed>> $data */
    private function writeJsonCache(string $file, array $data): void
    {
        $path = Bootstrap::cacheDir() . '/' . $file;
        @file_put_contents($path, json_encode([
            'savedAt' => time(),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
