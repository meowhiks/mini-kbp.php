<?php
declare(strict_types=1);

namespace MiniKbp;

/** Shared SEO helpers for SSR schedule pages + sitemap / llms.txt. */
final class Seo
{
    public const SITE = 'https://mini-kbp.site';
    public const SITE_NAME = 'Мини КБиП';
    public const TITLE_SUFFIX = ' — Мини КБиП';

    /** Types we expose to crawlers (subjects are thin / noisy). */
    public const INDEX_TYPES = ['group', 'teacher', 'place'];

    /** @var array<string, string> */
    public const TYPE_RU = [
        'group' => 'группа',
        'teacher' => 'преподаватель',
        'place' => 'аудитория',
        'subject' => 'предмет',
    ];

    /** @var list<string> */
    public const DAYS = [
        'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота',
    ];

    public static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function typeRu(string $type): string
    {
        return self::TYPE_RU[$type] ?? $type;
    }

    public static function raspPath(string $type, string $id, string $name = ''): string
    {
        $path = '/rasp/' . rawurlencode($type) . '/' . rawurlencode($id);
        $slug = self::slug($name);
        if ($slug !== '') {
            $path .= '/' . rawurlencode($slug);
        }
        return $path;
    }

    public static function raspUrl(string $type, string $id, string $name = ''): string
    {
        return self::SITE . self::raspPath($type, $id, $name);
    }

    public static function appUrl(string $type, string $id, string $name = ''): string
    {
        $q = http_build_query([
            'tt_type' => $type,
            'tt_id' => $id,
            'tt_name' => $name,
        ], '', '&', PHP_QUERY_RFC3986);
        return self::SITE . '/?' . $q;
    }

    public static function slug(string $name): string
    {
        $name = RequestGuard::sanitizeLabel($name);
        if ($name === '') {
            return '';
        }
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y',
            'к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f',
            'х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        ];
        $lower = mb_strtolower($name, 'UTF-8');
        $out = '';
        $len = mb_strlen($lower, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($lower, $i, 1, 'UTF-8');
            if (isset($map[$ch])) {
                $out .= $map[$ch];
            } elseif (preg_match('/[a-z0-9]/', $ch)) {
                $out .= $ch;
            } else {
                $out .= '-';
            }
        }
        $out = preg_replace('/-+/', '-', $out) ?? '';
        $out = trim($out, '-');
        if (strlen($out) > 60) {
            $out = rtrim(substr($out, 0, 60), '-');
        }
        return $out;
    }

    /**
     * @return list<array{id: string, name: string, type: string, typeLabel: string}>
     */
    public static function indexableItems(): array
    {
        try {
            $index = (new SearchService())->ensureIndex(false);
        } catch (\Throwable) {
            $path = Bootstrap::cacheDir() . '/search_index_v1.json';
            if (!is_file($path)) {
                return [];
            }
            $raw = file_get_contents($path);
            $index = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($index)) {
                return [];
            }
        }
        $items = $index['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $type = (string) ($it['type'] ?? '');
            $id = (string) ($it['id'] ?? '');
            $name = (string) ($it['name'] ?? '');
            if (!in_array($type, self::INDEX_TYPES, true)) {
                continue;
            }
            if (RequestGuard::category($type) === null || RequestGuard::entityId($id) === null) {
                continue;
            }
            if ($name === '') {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => $name,
                'type' => $type,
                'typeLabel' => (string) ($it['typeLabel'] ?? self::typeRu($type)),
            ];
        }
        return $out;
    }

    /**
     * Group pairs by day index 0..5 (current week only).
     *
     * @param list<array<string, mixed>> $pairs
     * @return array<int, list<array<string, mixed>>>
     */
    public static function pairsByDay(array $pairs): array
    {
        $by = array_fill(0, 6, []);
        foreach ($pairs as $p) {
            if (!is_array($p)) {
                continue;
            }
            $week = (int) ($p['weekOffset'] ?? 0);
            if ($week !== 0) {
                continue;
            }
            $day = (int) ($p['day'] ?? -1);
            if ($day < 0 || $day > 5) {
                continue;
            }
            $by[$day][] = $p;
        }
        foreach ($by as &$dayPairs) {
            usort($dayPairs, static fn ($a, $b) => ((int) ($a['pairNumber'] ?? 0)) <=> ((int) ($b['pairNumber'] ?? 0)));
        }
        unset($dayPairs);
        return $by;
    }

    public static function pageTitle(string $name, string $type): string
    {
        $ru = self::typeRu($type);
        return "Расписание — {$name} ({$ru})" . self::TITLE_SUFFIX;
    }

    public static function pageDescription(string $name, string $type): string
    {
        $ru = self::typeRu($type);
        return "Расписание занятий КБиП для «{$name}» ({$ru}): пары по дням недели, аудитории и преподаватели. Неофициальный сервис Мини КБиП.";
    }
}
