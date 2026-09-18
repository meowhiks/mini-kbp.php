<?php
declare(strict_types=1);

namespace MiniKbp;

final class MergeRows
{
    private const CANCELLED = 'Урок снят';

    public static function pairMatchesDisplayDay(array $pair, int $dayIndex): bool
    {
        $weekOffset = (int) ($pair['weekOffset'] ?? 0);
        $day = (int) ($pair['day'] ?? -1);
        if ($dayIndex <= 5) {
            return $weekOffset === 0 && $day === $dayIndex;
        }
        // dayIndex 6 = next Monday
        return $weekOffset === 1 && $day === 0;
    }

    /** @param list<array<string, mixed>> $allPairs @return list<array<string, mixed>> */
    public static function resolveDayPairs(array $allPairs, int $dayIndex, bool $showReplacements): array
    {
        $filtered = [];
        foreach ($allPairs as $p) {
            if (!self::pairMatchesDisplayDay($p, $dayIndex)) {
                continue;
            }
            $status = (string) ($p['status'] ?? 'normal');
            if ($showReplacements) {
                if ($status === 'removed' || $status === 'cancelled') {
                    continue;
                }
            } elseif ($status === 'added') {
                continue;
            }
            if (trim((string) ($p['subject'] ?? '')) === self::CANCELLED) {
                $p['teacher'] = '';
                $p['teacherFull'] = '';
                $p['room'] = '';
                $p['group'] = '';
                if (isset($p['refs']) && is_array($p['refs'])) {
                    $p['refs']['teachers'] = [];
                    unset($p['refs']['place'], $p['refs']['group']);
                }
            }
            $filtered[] = $p;
        }

        usort($filtered, static fn($a, $b) => ((int) $a['pairNumber']) <=> ((int) $b['pairNumber']));
        return self::mergeSameSubject($filtered);
    }

    /** @param list<array<string, mixed>> $pairs @return list<array<string, mixed>> */
    private static function mergeSameSubject(array $pairs): array
    {
        $order = [];
        $buckets = [];
        foreach ($pairs as $pair) {
            $key = ((int) $pair['pairNumber']) . '::' . mb_strtolower(trim((string) ($pair['subject'] ?? '')));
            if (!isset($buckets[$key])) {
                $order[] = $key;
                $buckets[$key] = [];
            }
            $buckets[$key][] = $pair;
        }

        $out = [];
        foreach ($order as $key) {
            $group = $buckets[$key];
            $base = $group[0];
            $lines = [];
            $seen = [];
            foreach ($group as $p) {
                $teacherRef = $p['refs']['teachers'][0] ?? null;
                $teacherShort = (string) (($teacherRef['name'] ?? null) ?: ($p['teacher'] ?? ''));
                $teacherFull = (string) (($teacherRef['nameFull'] ?? null) ?: ($p['teacherFull'] ?? ''));
                $line = [
                    'group' => self::ref((string) ($p['group'] ?? ''), $p['refs']['group']['id'] ?? null),
                    'teacher' => self::ref($teacherShort, $teacherRef['id'] ?? null),
                    'room' => self::ref((string) ($p['room'] ?? ''), $p['refs']['place']['id'] ?? null),
                ];
                if ($teacherFull !== '') {
                    $line['teacherFull'] = $teacherFull;
                }
                if (!$line['group'] && !$line['teacher'] && !$line['room']) {
                    continue;
                }
                $lk = mb_strtolower(($line['group']['label'] ?? '') . '|' . ($line['teacher']['label'] ?? '') . '|' . ($line['room']['label'] ?? ''));
                if (isset($seen[$lk])) {
                    continue;
                }
                $seen[$lk] = true;
                $lines[] = $line;
            }
            $first = $lines[0] ?? null;
            $base['teacher'] = $first['teacher']['label'] ?? $base['teacher'];
            $base['room'] = $first['room']['label'] ?? $base['room'];
            $base['group'] = $first['group']['label'] ?? ($base['group'] ?? '');
            if (!empty($first['teacherFull'])) {
                $base['teacherFull'] = $first['teacherFull'];
            }
            $base['lines'] = $lines;
            $out[] = $base;
        }
        return $out;
    }

    /** @return array{label: string, id?: string}|null */
    private static function ref(string $label, ?string $id): ?array
    {
        $trimmed = trim($label);
        if ($trimmed === '') {
            return null;
        }
        return $id ? ['label' => $trimmed, 'id' => $id] : ['label' => $trimmed];
    }
}
