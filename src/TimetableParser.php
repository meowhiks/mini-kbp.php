<?php
declare(strict_types=1);

namespace MiniKbp;

/**
 * Port of lib/client/kbpApi.ts parseTimetableHtml.
 */
final class TimetableParser
{
    private const WEEK_DAYS = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];

    /** @return array<string, mixed> */
    public static function parse(string $html, string $entityId, string $entityName): array
    {
        $data = [
            'groupId' => $entityId,
            'groupName' => $entityName,
            'pairs' => [],
            'dayStartTimes' => array_fill(0, 6, ['start' => '', 'end' => '']),
            'dayReplacementStatus' => array_fill(0, 6, [
                'label' => '', 'hasChanges' => false, 'noChanges' => false, 'unknown' => true,
            ]),
            'hasNextWeekMonday' => false,
            'hasNextWeek' => false,
        ];

        $segments = [];
        $leftBlock = self::extractWeekBlock($html, 'left_week');
        $rightBlock = self::extractWeekBlock($html, 'right_week');

        if ($leftBlock !== null) {
            $leftTable = self::extractScheduleTable($leftBlock);
            if ($leftTable !== null) {
                $segments[] = ['content' => $leftTable, 'weekOffset' => 0];
                $data['currentWeek'] = self::parseWeekMeta($leftBlock);
                self::parseZamenaForDays($leftTable, [0, 1, 2, 3, 4, 5], $data);
            }
        }

        if ($rightBlock !== null) {
            $rightTable = self::extractScheduleTable($rightBlock);
            if ($rightTable !== null) {
                $segments[] = ['content' => $rightTable, 'weekOffset' => 1];
                $data['nextWeekMonday'] = self::parseWeekMeta($rightBlock);
                $data['hasNextWeekMonday'] = true;
                $data['dayStartTimes'][] = ['start' => '', 'end' => ''];
                $data['dayReplacementStatus'][] = [
                    'label' => '', 'hasChanges' => false, 'noChanges' => false, 'unknown' => true,
                ];
                self::parseZamenaForDays($rightTable, [6], $data);
            }
        }

        if ($segments === []) {
            return $data;
        }

        $data['hasNextWeek'] = (bool) $data['hasNextWeekMonday'];

        foreach ($segments as $seg) {
            self::parseTableSegment($seg['content'], (int) $seg['weekOffset'], $data);
        }

        self::fillDayRanges($data);
        return $data;
    }

    private static function extractWeekBlock(string $html, string $weekId): ?string
    {
        if ($weekId === 'left_week') {
            if (preg_match('/<div[^>]*id=["\']left_week["\'][^>]*>([\s\S]*?)<div[^>]*id=["\']right_week["\']/i', $html, $m)) {
                return $m[1];
            }
            return null;
        }
        if (preg_match('/<div[^>]*id=["\']right_week["\'][^>]*>([\s\S]*)/i', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    private static function extractScheduleTable(string $weekBlock): ?string
    {
        if (!preg_match('/<table[^>]*>([\s\S]*?)<\/table>/i', $weekBlock, $m)) {
            return null;
        }
        $content = $m[1];
        if (str_contains($content, 'pair-number') || str_contains($content, 'day="')) {
            return $content;
        }
        return null;
    }

    /** @return array{dateRange: string, weekLabel: string} */
    private static function parseWeekMeta(string $weekBlock): array
    {
        $dateRange = '';
        $weekLabel = '';
        if (preg_match('/<p[^>]*class=["\']date["\'][^>]*>([^<]*)<\/p>/i', $weekBlock, $m)) {
            $dateRange = trim($m[1]);
        }
        if (preg_match('/<p[^>]*class=["\']today["\'][^>]*>([^<]*)<\/p>/i', $weekBlock, $m)) {
            $weekLabel = trim($m[1]);
        }
        return ['dateRange' => $dateRange, 'weekLabel' => $weekLabel];
    }

    /** @param list<int> $dayIndices @param array<string, mixed> $data */
    private static function parseZamenaForDays(string $tableContent, array $dayIndices, array &$data): void
    {
        if (!preg_match('/<tr[^>]*class="[^"]*zamena[^"]*"[^>]*>([\s\S]*?)<\/tr>/i', $tableContent, $row)) {
            return;
        }
        preg_match_all('/<th[^>]*>([\s\S]*?)<\/th>/i', $row[1], $cells);
        $replacementCells = $cells[1] ?? [];
        foreach ($dayIndices as $i => $storeIndex) {
            $cellContent = $replacementCells[$i + 1] ?? '';
            $plain = trim(preg_replace('/\s+/', ' ', strip_tags($cellContent)) ?? '');
            $hasChanges = (bool) preg_match('/показать\s+замены/iu', $plain);
            // «Замен нет», «Нет замен», «Нету замен»
            $noChanges = (bool) preg_match('/(?:нету?\s+замен|замен\s+нет|нет\s+замен)/iu', $plain);
            $data['dayReplacementStatus'][$storeIndex] = [
                'label' => $plain,
                'hasChanges' => $hasChanges,
                'noChanges' => $noChanges,
                'unknown' => !$hasChanges && !$noChanges,
            ];
        }
    }

    /** @param array<string, mixed> $data */
    private static function parseTableSegment(string $tableContent, int $weekOffset, array &$data): void
    {
        preg_match_all('/<tr[^>]*>([\s\S]*?)<\/tr>/', $tableContent, $rows);
        foreach ($rows[1] as $rowContent) {
            if (!preg_match('/<td[^>]*class="[^"]*number[^"]*"[^>]*>(\d+)<\/td>/', $rowContent, $pn)) {
                continue;
            }
            $pairNumber = (int) $pn[1];
            preg_match_all('/<td[^>]*>([\s\S]*?)<\/td>/', $rowContent, $dayCells);
            if (count($dayCells[1]) < 8) {
                continue;
            }

            for ($cellIndex = 1; $cellIndex < count($dayCells[1]) - 1; $cellIndex++) {
                $cellContent = $dayCells[1][$cellIndex];
                $dayIndex = null;
                if (preg_match('/<!--[^>]*day="(\d+)"[^>]*-->/', $cellContent, $dm)) {
                    $dayFromComment = (int) $dm[1];
                    if ($dayFromComment >= 1 && $dayFromComment <= 6) {
                        $dayIndex = $dayFromComment - 1;
                    }
                }
                if ($dayIndex === null) {
                    $dayIndex = $cellIndex - 1;
                    if ($dayIndex < 0 || $dayIndex > 5) {
                        continue;
                    }
                }
                if ($weekOffset === 1 && $dayIndex !== 0) {
                    continue;
                }
                if (str_contains($cellContent, 'empty-pair') && !str_contains($cellContent, 'pair')) {
                    continue;
                }

                self::extractPairsFromCell($cellContent, $pairNumber, $dayIndex, $weekOffset, $data);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private static function extractPairsFromCell(
        string $cellContent,
        int $pairNumber,
        int $dayIndex,
        int $weekOffset,
        array &$data
    ): void {
        $pairStartIndex = 0;
        $iterations = 0;
        while ($pairStartIndex < strlen($cellContent) && $iterations < 100) {
            $iterations++;
            $slice = substr($cellContent, $pairStartIndex);
            if (!preg_match('/<div[^>]*class="([^"]*)"[^>]*>/i', $slice, $pairStartMatch, PREG_OFFSET_CAPTURE)) {
                break;
            }
            $pairStartPos = $pairStartIndex + (int) $pairStartMatch[0][1];
            $pairClasses = $pairStartMatch[1][0];
            $pairTagStart = $pairStartPos + strlen($pairStartMatch[0][0]);

            if (!str_contains($pairClasses, 'pair')) {
                $pairStartIndex = $pairTagStart + 1;
                continue;
            }

            $depth = 1;
            $pos = $pairTagStart;
            $pairEndPos = -1;
            $depthIterations = 0;
            while ($pos < strlen($cellContent) && $depth > 0 && $depthIterations < 1000) {
                $depthIterations++;
                $nextDivOpen = strpos($cellContent, '<div', $pos);
                $nextDivClose = strpos($cellContent, '</div>', $pos);
                if ($nextDivClose === false) {
                    break;
                }
                if ($nextDivOpen !== false && $nextDivOpen < $nextDivClose) {
                    $depth++;
                    $pos = $nextDivOpen + 4;
                } else {
                    $depth--;
                    if ($depth === 0) {
                        $pairEndPos = $nextDivClose;
                        break;
                    }
                    $pos = $nextDivClose + 6;
                }
            }
            if ($pairEndPos === -1) {
                $pairStartIndex = $pairTagStart + 1;
                continue;
            }

            $pairContent = substr($cellContent, $pairTagStart, $pairEndPos - $pairTagStart);
            if (trim($pairContent) === '') {
                $pairStartIndex = $pairEndPos + 6;
                continue;
            }

            $pairData = [
                'pairNumber' => $pairNumber,
                'day' => $dayIndex,
                'dayName' => self::WEEK_DAYS[$dayIndex],
                'subject' => '',
                'teacher' => '',
                'room' => '',
                'group' => '',
                'refs' => ['teachers' => []],
                'status' => 'normal',
                'weekOffset' => $weekOffset,
            ];

            if (preg_match('/<div[^>]*class="[^"]*subject[^"]*"[^>]*>[\s\S]*?<a[^>]*>([^<]+)<\/a>/i', $pairContent, $sm)) {
                $pairData['subject'] = trim($sm[1]);
            }
            if (preg_match('/<div[^>]*class="[^"]*subject[^"]*"[^>]*>[\s\S]*?<a[^>]*href="[^"]*\?cat=subject(?:&amp;|&)id=(\d+)[^"]*"[^>]*>([^<]+)<\/a>/i', $pairContent, $sr)) {
                $pairData['refs']['subject'] = ['id' => $sr[1], 'name' => trim($sr[2]) ?: $pairData['subject']];
            }

            self::parseLeftColumn($pairContent, $pairData);
            self::parseRightColumn($pairContent, $pairData);

            if (str_contains($pairClasses, 'added')) {
                $pairData['status'] = 'added';
            } elseif (str_contains($pairClasses, 'replaced')) {
                $pairData['status'] = 'replaced';
            } elseif (str_contains($pairClasses, 'removed')) {
                $pairData['status'] = 'removed';
            } elseif (str_contains($pairClasses, 'cancelled')) {
                $pairData['status'] = 'cancelled';
            }

            if ($pairData['subject'] !== '') {
                if ($weekOffset === 1) {
                    $pairData['isNextWeekMonday'] = true;
                }
                $data['pairs'][] = $pairData;
            }
            $pairStartIndex = $pairEndPos + 6;
        }
    }

    /** @param array<string, mixed> $pairData */
    private static function parseLeftColumn(string $pairContent, array &$pairData): void
    {
        if (!preg_match('/<div[^>]*class="[^"]*left-column[^"]*"[^>]*>/i', $pairContent, $m, PREG_OFFSET_CAPTURE)) {
            return;
        }
        $tagStart = (int) $m[0][1] + strlen($m[0][0]);
        $end = self::findClosingDiv($pairContent, $tagStart);
        if ($end < 0) {
            return;
        }
        $left = substr($pairContent, $tagStart, $end - $tagStart);
        $teachers = [];
        if (preg_match_all('/<div[^>]*class="[^"]*teacher[^"]*"[^>]*>([\s\S]*?)<\/div>/i', $left, $tdivs)) {
            foreach ($tdivs[1] as $teacherDiv) {
                if (preg_match_all('/<a[^>]*>([^<]+)<\/a>/i', $teacherDiv, $links)) {
                    foreach ($links[1] as $name) {
                        $name = trim($name);
                        if ($name !== '' && $name !== '&nbsp;') {
                            $teachers[] = $name;
                        }
                    }
                }
                if (preg_match_all('/<a[^>]*href="[^"]*\?cat=teacher(?:&amp;|&)id=(\d+)[^"]*"[^>]*>([^<]*)<\/a>/i', $teacherDiv, $refs)) {
                    foreach ($refs[1] as $i => $id) {
                        $name = trim($refs[2][$i] ?? '');
                        if ($id !== '' && $name !== '') {
                            $pairData['refs']['teachers'][] = ['id' => $id, 'name' => $name];
                        }
                    }
                }
            }
        }
        $pairData['teacher'] = implode(', ', $teachers);
    }

    /** @param array<string, mixed> $pairData */
    private static function parseRightColumn(string $pairContent, array &$pairData): void
    {
        if (!preg_match('/<div[^>]*class="[^"]*right-column[^"]*"[^>]*>/i', $pairContent, $m, PREG_OFFSET_CAPTURE)) {
            return;
        }
        $tagStart = (int) $m[0][1] + strlen($m[0][0]);
        $end = self::findClosingDiv($pairContent, $tagStart);
        if ($end < 0) {
            return;
        }
        $right = substr($pairContent, $tagStart, $end - $tagStart);

        if (preg_match('/<div[^>]*class="[^"]*place[^"]*"[^>]*>([\s\S]*?)<\/div>/i', $right, $place)) {
            if (preg_match_all('/<a[^>]*>([^<]+)<\/a>/i', $place[1], $links)) {
                foreach ($links[1] as $room) {
                    $room = trim($room);
                    if ($room !== '' && $room !== '&nbsp;') {
                        $pairData['room'] = $room;
                        break;
                    }
                }
            }
            if (preg_match('/<a[^>]*href="[^"]*\?cat=place(?:&amp;|&)id=(\d+)[^"]*"[^>]*>([^<]+)<\/a>/i', $place[1], $pr)) {
                $pairData['refs']['place'] = ['id' => $pr[1], 'name' => trim($pr[2]) ?: $pairData['room']];
            }
        }

        if (preg_match('/<div[^>]*class="[^"]*group[^"]*"[^>]*>([\s\S]*?)<\/div>/i', $right, $group)) {
            if (preg_match('/<a[^>]*>([^<]+)<\/a>/i', $group[1], $gn)) {
                $pairData['group'] = trim($gn[1]);
            }
            if (preg_match('/<a[^>]*href="[^"]*\?cat=group(?:&amp;|&)id=(\d+)[^"]*"[^>]*>([^<]+)<\/a>/i', $group[1], $gr)) {
                $pairData['refs']['group'] = ['id' => $gr[1], 'name' => trim($gr[2]) ?: $pairData['group']];
            }
        }
    }

    private static function findClosingDiv(string $html, int $start): int
    {
        $depth = 1;
        $pos = $start;
        $iters = 0;
        while ($pos < strlen($html) && $depth > 0 && $iters < 500) {
            $iters++;
            $open = strpos($html, '<div', $pos);
            $close = strpos($html, '</div>', $pos);
            if ($close === false) {
                return -1;
            }
            if ($open !== false && $open < $close) {
                $depth++;
                $pos = $open + 4;
            } else {
                $depth--;
                if ($depth === 0) {
                    return $close;
                }
                $pos = $close + 6;
            }
        }
        return -1;
    }

    /** @param array<string, mixed> $data */
    public static function fillDayRanges(array &$data): void
    {
        $fill = static function (int $dayIndex, callable $filter) use (&$data): void {
            $dayPairs = array_values(array_filter($data['pairs'], $filter));
            if ($dayPairs === []) {
                return;
            }
            $first = $dayPairs[0];
            $last = $dayPairs[0];
            foreach ($dayPairs as $p) {
                if ($p['pairNumber'] < $first['pairNumber']) {
                    $first = $p;
                }
                if ($p['pairNumber'] > $last['pairNumber']) {
                    $last = $p;
                }
            }
            $bellDay = $dayIndex === 6 ? 0 : $dayIndex;
            $firstTime = BellSchedule::pairTime((int) $first['pairNumber'], $bellDay);
            $lastTime = BellSchedule::pairTime((int) $last['pairNumber'], $bellDay);
            $data['dayStartTimes'][$dayIndex] = ['start' => $firstTime['start'], 'end' => $lastTime['end']];
        };

        for ($dayIndex = 0; $dayIndex < 6; $dayIndex++) {
            $fill($dayIndex, static function (array $p) use ($dayIndex): bool {
                if (($p['weekOffset'] ?? 0) !== 0 || ($p['day'] ?? -1) !== $dayIndex) {
                    return false;
                }
                $subject = trim((string) ($p['subject'] ?? ''));
                if ($subject === '' || $subject === 'Урок снят') {
                    return false;
                }
                $status = $p['status'] ?? 'normal';
                if ($status === 'removed' || $status === 'cancelled') {
                    return false;
                }
                return in_array($status, ['added', 'normal', 'replaced', ''], true) || $status === null;
            });
        }

        if (!empty($data['hasNextWeekMonday'])) {
            $fill(6, static function (array $p): bool {
                if (($p['weekOffset'] ?? 0) !== 1 || ($p['day'] ?? -1) !== 0) {
                    return false;
                }
                $subject = trim((string) ($p['subject'] ?? ''));
                if ($subject === '' || $subject === 'Урок снят') {
                    return false;
                }
                $status = $p['status'] ?? 'normal';
                if ($status === 'removed' || $status === 'cancelled') {
                    return false;
                }
                return true;
            });
        }
    }
}
