<?php
declare(strict_types=1);

namespace MiniKbp;

final class SearchService
{
    private const INDEX_FILE = 'search_index_v1.json';
    private const DAY_MS = 86400;

    /** All four facets MiniKBP search supports (rasp.kbp.by API only filters 3). */
    public const SEARCH_TYPES = ['group', 'teacher', 'place', 'subject'];

    public function __construct(
        private readonly KbpClient $client = new KbpClient(),
        private readonly NameEnricher $enricher = new NameEnricher(),
    ) {
    }

    /** @return list<array{id: string, name: string, type: string, typeLabel: string, nameFull?: string}> */
    public function search(string $query, bool $forceRefresh = false): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $index = $this->ensureIndex($forceRefresh);
        return $this->scoreItems($index['items'] ?? [], $query);
    }

    /** @return array{savedAt: int, items: list<array<string, string>>, count: int} */
    public function ensureIndex(bool $forceRefresh = false): array
    {
        $path = Bootstrap::cacheDir() . '/' . self::INDEX_FILE;
        if (!$forceRefresh && is_file($path)) {
            $raw = file_get_contents($path);
            $cached = is_string($raw) ? json_decode($raw, true) : null;
            // Empty index is never "fresh" — a failed upstream parse must not poison search for a day.
            if (is_array($cached) && isset($cached['items'], $cached['savedAt'])
                && is_array($cached['items']) && $cached['items'] !== []
                && (time() - (int) $cached['savedAt']) < self::DAY_MS) {
                $items = $cached['items'];
                if (!$this->indexHasFullNames($items) || !$this->indexHasAllTypes($items)) {
                    $items = $this->enricher->enrichSearchItems($items);
                    if ($items !== []) {
                        $cached['items'] = $items;
                        $cached['count'] = count($items);
                        @file_put_contents($path, json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    }
                }
                return $cached;
            }
        }

        $html = $this->client->fetchSearchIndex();
        $items = self::parseSearchResults($html);
        $items = $this->enricher->enrichSearchItems($items);
        $payload = [
            'savedAt' => time(),
            'items' => $items,
            'count' => count($items),
        ];
        // Never overwrite a good index with an empty parse (kbp.by soft-fail / block page).
        if ($items !== []) {
            file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return $payload;
        }
        if (is_file($path)) {
            $raw = file_get_contents($path);
            $cached = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($cached) && !empty($cached['items']) && is_array($cached['items'])) {
                return $cached;
            }
        }
        return $payload;
    }

    /** @return list<array{id: string, name: string, type: string, typeLabel: string}> */
    public static function parseSearchResults(string $html): array
    {
        $results = [];
        $seen = [];
        if ($html === '') {
            return $results;
        }

        $findBlock = $html;
        if (preg_match('/class="find_block"[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            // Take a large slice after find_block open — nested </div> would truncate a non-greedy match.
            $start = (int) $m[0][1] + strlen($m[0][0]);
            $findBlock = substr($html, $start, 800000);
        }

        if (!preg_match_all(
            '/<div[^>]*>\s*(?:<span class="type_find">([^<]+)<\/span>\s*)?<a[^>]*href="[^"]*\?cat=(group|teacher|place|subject)(?:&amp;|&)id=([^"&]+)[^"]*">([^<]+)<\/a>\s*<\/div>/iu',
            $findBlock,
            $matches,
            PREG_SET_ORDER
        )) {
            return $results;
        }

        foreach ($matches as $match) {
            $typeLabel = RequestGuard::sanitizeLabel(trim($match[1] ?? ''));
            $type = trim($match[2]);
            $id = trim($match[3]);
            $name = RequestGuard::sanitizeLabel(trim($match[4]));
            if (RequestGuard::entityId($id) === null) {
                continue;
            }
            if (!in_array($type, self::SEARCH_TYPES, true)) {
                continue;
            }
            $key = "{$type}:{$id}";
            if (isset($seen[$key]) || $type === '' || $id === '' || $name === '') {
                continue;
            }
            $seen[$key] = true;
            $results[] = [
                'id' => $id,
                'name' => $name,
                'type' => $type,
                'typeLabel' => $typeLabel !== '' ? $typeLabel : match ($type) {
                    'group' => 'группа',
                    'teacher' => 'преподаватель',
                    'place' => 'аудитория',
                    'subject' => 'предмет',
                    default => $type,
                },
            ];
        }
        return $results;
    }

    /**
     * @param list<array{id: string, name: string, type: string, typeLabel: string, nameFull?: string}> $items
     * @return list<array{id: string, name: string, type: string, typeLabel: string, nameFull?: string}>
     */
    private function scoreItems(array $items, string $query): array
    {
        $variants = KeyboardLayout::variants($query);
        $scored = [];
        foreach ($items as $item) {
            if (!in_array((string) ($item['type'] ?? ''), self::SEARCH_TYPES, true)) {
                continue;
            }
            $names = array_values(array_filter([
                (string) ($item['name'] ?? ''),
                (string) ($item['nameFull'] ?? ''),
            ], static fn($n) => $n !== ''));
            $score = 0;
            foreach ($names as $nameIdx => $name) {
                $n = self::normalize($name);
                foreach ($variants as $variant) {
                    $q = self::normalize($variant);
                    if ($q === '') {
                        continue;
                    }
                    $hit = 0;
                    if ($n === $q) {
                        $hit = 1000;
                    } elseif (str_starts_with($n, $q)) {
                        $hit = 400;
                    } elseif (str_contains($n, $q)) {
                        $hit = 220;
                    }
                    if ($hit > 0) {
                        $hit += max(0, 100 - abs(mb_strlen($name) - mb_strlen($variant)));
                        // Prefer short-label exact hits slightly, but allow full-name discovery
                        if ($nameIdx > 0) {
                            $hit += 15;
                        }
                        $score = max($score, $hit);
                    }
                }
            }
            if ($score > 0) {
                // Mild type diversity boost so subjects aren't buried under places
                $typeBoost = match ($item['type'] ?? '') {
                    'group' => 8,
                    'teacher' => 6,
                    'subject' => 5,
                    'place' => 4,
                    default => 0,
                };
                $scored[] = ['item' => $item, 'score' => $score + $typeBoost];
            }
        }
        usort($scored, static function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return strcmp($a['item']['name'], $b['item']['name']);
        });
        return array_map(static fn($x) => $x['item'], array_slice($scored, 0, 50));
    }

    /** @param list<array<string, mixed>> $items */
    private function indexHasFullNames(array $items): bool
    {
        foreach ($items as $item) {
            if (!empty($item['nameFull'])) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array<string, mixed>> $items */
    private function indexHasAllTypes(array $items): bool
    {
        $seen = [];
        foreach ($items as $item) {
            $t = (string) ($item['type'] ?? '');
            if ($t !== '') {
                $seen[$t] = true;
            }
        }
        foreach (self::SEARCH_TYPES as $t) {
            if (!isset($seen[$t])) {
                return false;
            }
        }
        return true;
    }

    private static function normalize(string $value): string
    {
        $v = mb_strtolower($value);
        $v = preg_replace('/\s+/u', '', $v) ?? '';
        return preg_replace('/[.,_()\-«»"\']/u', '', $v) ?? '';
    }
}
