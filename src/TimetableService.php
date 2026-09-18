<?php
declare(strict_types=1);

namespace MiniKbp;

final class TimetableService
{
    public const CACHE_TTL_SEC = 21600;

    /** Serve expired cache when kbp.by is down (SpaceWeb often blocks egress). */
    public const STALE_MAX_AGE_SEC = 14 * 24 * 3600;

    public function __construct(
        private readonly KbpClient $client = new KbpClient(),
        private readonly NameEnricher $enricher = new NameEnricher(),
        private readonly RaspTimetableBuilder $raspBuilder = new RaspTimetableBuilder(),
    ) {
    }

    /** @return array{success: bool, data?: array<string, mixed>, error?: string, code?: string, stale?: bool} */
    public function fetch(string $category, string $id, string $name = ''): array
    {
        $category = RequestGuard::category($category) ?? '';
        $id = RequestGuard::entityId($id) ?? '';
        if ($category === '' || $id === '') {
            return ['success' => false, 'error' => 'Некорректные параметры'];
        }
        $name = RequestGuard::displayName($name);

        $cachePath = Bootstrap::cacheDir() . '/tt_' . $category . '_' . $id . '.json';
        $fresh = $this->readCache($cachePath, self::CACHE_TTL_SEC);
        if ($fresh !== null) {
            $data = $fresh['data'];
            if ($name !== '') {
                $data['groupName'] = $name;
            }
            // Re-enrich when missing or previously failed (false-positive enriched flag)
            if (self::needsEnrich($data)) {
                $data = $this->enricher->enrichTimetable($data, $category, $name !== '' ? $name : (string) ($data['groupName'] ?? ''));
                $data = $this->sanitizeData($data);
                @file_put_contents($cachePath, json_encode([
                    'savedAt' => $fresh['savedAt'] ?? time(),
                    'data' => $data,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            return ['success' => true, 'data' => $data];
        }

        try {
            $html = $this->client->fetchTimetable($category, $id);
            $extracted = RequestGuard::sanitizeLabel($this->extractTitle($html));
            if (preg_match('/секундочк/iu', $extracted)) {
                return $this->fallbackAfterKbpFail($category, $id, $name, $cachePath, 'kbp unavailable: placeholder page');
            }
            $title = $name !== '' ? $name : ($extracted !== '' ? $extracted : "{$category}-{$id}");
            $data = TimetableParser::parse($html, $id, $title);
            $data['category'] = $category;
            // Never overwrite a good cache with an empty parse (upstream markup glitch / soft block)
            $parsedPairs = $data['pairs'] ?? null;
            if (!is_array($parsedPairs) || $parsedPairs === []) {
                return $this->fallbackAfterKbpFail($category, $id, $name, $cachePath, 'empty timetable parse');
            }
            $data = $this->enricher->enrichTimetable($data, $category, $name !== '' ? $name : $title);
            $data = $this->sanitizeData($data);
            if (isset($data['currentWeek']['dateRange'])) {
                $data['currentWeek']['dateRange'] = RequestGuard::sanitizeLabel(
                    (string) $data['currentWeek']['dateRange']
                );
            }
            if (isset($data['nextWeekMonday']['dateRange'])) {
                $data['nextWeekMonday']['dateRange'] = RequestGuard::sanitizeLabel(
                    (string) $data['nextWeekMonday']['dateRange']
                );
            }

            @file_put_contents($cachePath, json_encode([
                'savedAt' => time(),
                'data' => $data,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return ['success' => true, 'data' => $data];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if ($msg === 'upstream busy') {
                return ['success' => false, 'error' => 'upstream busy'];
            }
            return $this->fallbackAfterKbpFail($category, $id, $name, $cachePath, $msg);
        }
    }

    /**
     * Priority after kbp.by failure: rasp.kbp.by live → stale kbp cache → error.
     *
     * @return array{success: bool, data?: array<string, mixed>, error?: string, code?: string, stale?: bool}
     */
    private function fallbackAfterKbpFail(
        string $category,
        string $id,
        string $name,
        string $cachePath,
        string $reason,
    ): array {
        $rasp = $this->tryRaspFallback($category, $id, $name, $cachePath, $reason);
        if ($rasp !== null) {
            return $rasp;
        }

        $stale = $this->readCache($cachePath, self::STALE_MAX_AGE_SEC);
        if ($stale !== null) {
            $data = $stale['data'];
            if ($name !== '') {
                $data['groupName'] = $name;
            }
            if (self::needsEnrich($data)) {
                $data = $this->enricher->enrichTimetable($data, $category, $name !== '' ? $name : (string) ($data['groupName'] ?? ''));
                $data = $this->sanitizeData($data);
            }
            AppLog::warn('tt.stale_fallback', [
                'cat' => $category,
                'id' => $id,
                'reason' => $reason,
            ]);
            return ['success' => true, 'data' => $data, 'stale' => true];
        }

        if (str_starts_with($reason, 'kbp unavailable')
            || str_contains($reason, 'kbp.by request failed')
            || str_contains($reason, 'kbp.by HTTP')
            || $reason === 'empty timetable parse'
            || str_contains($reason, 'placeholder page')) {
            return ['success' => false, 'error' => $reason, 'code' => 'kbp_unavailable'];
        }
        return ['success' => false, 'error' => $reason];
    }

    /**
     * @return array{success: true, data: array<string, mixed>}|null
     */
    private function tryRaspFallback(
        string $category,
        string $id,
        string $name,
        string $cachePath,
        string $reason,
    ): ?array {
        try {
            $data = $this->raspBuilder->build($category, $id, $name);
        } catch (\Throwable $e) {
            AppLog::warn('tt.rasp_fallback_error', [
                'cat' => $category,
                'id' => $id,
                'error' => $e->getMessage(),
                'reason' => $reason,
            ]);
            return null;
        }
        if ($data === null || !is_array($data['pairs'] ?? null) || $data['pairs'] === []) {
            return null;
        }
        $data = $this->sanitizeData($data);
        @file_put_contents($cachePath, json_encode([
            'savedAt' => time(),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        AppLog::info('tt.rasp_fallback', [
            'cat' => $category,
            'id' => $id,
            'pairs' => count($data['pairs']),
            'reason' => $reason,
        ]);
        return ['success' => true, 'data' => $data];
    }

    /** @param array<string, mixed> $data */
    public static function needsEnrich(array $data): bool
    {
        if (($data['source'] ?? '') === 'rasp.kbp.by') {
            return false;
        }
        if (($data['enrichStatus'] ?? '') === 'ok') {
            return false;
        }
        if (empty($data['enriched'])) {
            return true;
        }
        // Legacy: enriched=true but no full names actually present
        foreach ($data['pairs'] ?? [] as $p) {
            if (!is_array($p)) {
                continue;
            }
            if (!empty($p['subjectFull']) || !empty($p['teacherFull'])) {
                return false;
            }
        }
        return true;
    }

    /** @return array{savedAt:int,data:array<string,mixed>}|null */
    private function readCache(string $cachePath, int $maxAgeSec): ?array
    {
        if (!is_file($cachePath)) {
            return null;
        }
        $raw = file_get_contents($cachePath);
        $cached = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($cached) || !isset($cached['savedAt'], $cached['data']) || !is_array($cached['data'])) {
            return null;
        }
        $pairs = $cached['data']['pairs'] ?? null;
        if (!is_array($pairs) || count($pairs) === 0) {
            // Don't treat empty shells as usable cache
            return null;
        }
        if ((time() - (int) $cached['savedAt']) >= $maxAgeSec) {
            return null;
        }
        return ['savedAt' => (int) $cached['savedAt'], 'data' => $cached['data']];
    }

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    private function sanitizeData(array $data): array
    {
        if (isset($data['groupName']) && is_string($data['groupName'])) {
            $data['groupName'] = RequestGuard::sanitizeLabel($data['groupName']);
        }
        if (isset($data['pairs']) && is_array($data['pairs'])) {
            $clean = [];
            foreach ($data['pairs'] as $pair) {
                if (!is_array($pair)) {
                    continue;
                }
                foreach (['subject', 'subjectFull', 'teacher', 'teacherFull', 'room', 'group', 'status'] as $k) {
                    if (isset($pair[$k]) && is_string($pair[$k])) {
                        $pair[$k] = RequestGuard::sanitizeLabel($pair[$k]);
                    }
                }
                if (isset($pair['refs']) && is_array($pair['refs'])) {
                    if (isset($pair['refs']['subject']['nameFull']) && is_string($pair['refs']['subject']['nameFull'])) {
                        $pair['refs']['subject']['nameFull'] = RequestGuard::sanitizeLabel($pair['refs']['subject']['nameFull']);
                    }
                    if (isset($pair['refs']['teachers']) && is_array($pair['refs']['teachers'])) {
                        foreach ($pair['refs']['teachers'] as $i => $tref) {
                            if (is_array($tref) && isset($tref['nameFull']) && is_string($tref['nameFull'])) {
                                $pair['refs']['teachers'][$i]['nameFull'] = RequestGuard::sanitizeLabel($tref['nameFull']);
                            }
                        }
                    }
                }
                $clean[] = $pair;
            }
            $data['pairs'] = $clean;
        }
        return $data;
    }

    private function extractTitle(string $html): string
    {
        if (preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
    }
}
