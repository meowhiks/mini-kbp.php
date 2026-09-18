<?php
declare(strict_types=1);

namespace MiniKbp;

/**
 * Build an iCalendar (ICS) feed from timetable pairs so Google/Apple can subscribe.
 */
final class IcalBuilder
{
    /** How many weeks ahead to expand the weekly pattern. */
    public const WEEKS_AHEAD = 4;

    /**
     * @param array<string, mixed> $timetable
     */
    public static function build(array $timetable, string $calendarName = ''): string
    {
        $name = RequestGuard::sanitizeLabel($calendarName !== '' ? $calendarName : (string) ($timetable['groupName'] ?? 'Расписание'));
        if ($name === '') {
            $name = 'Расписание КБиП';
        }

        $tz = new \DateTimeZone('Europe/Minsk');
        $now = new \DateTimeImmutable('now', $tz);
        $dow = (int) $now->format('N'); // 1=Mon … 7=Sun
        $thisMonday = $now->modify('-' . ($dow - 1) . ' days')->setTime(0, 0);

        $pairs = is_array($timetable['pairs'] ?? null) ? $timetable['pairs'] : [];
        $entityId = RequestGuard::sanitizeLabel((string) ($timetable['groupId'] ?? 'x'));
        $category = RequestGuard::sanitizeLabel((string) ($timetable['category'] ?? 'group'));

        $events = [];
        for ($week = 0; $week < self::WEEKS_AHEAD; $week++) {
            $weekMonday = $thisMonday->modify('+' . ($week * 7) . ' days');
            foreach ($pairs as $pair) {
                if (!is_array($pair)) {
                    continue;
                }
                $weekOffset = (int) ($pair['weekOffset'] ?? 0);
                $day = (int) ($pair['day'] ?? -1);
                $pairNumber = (int) ($pair['pairNumber'] ?? 0);
                $status = (string) ($pair['status'] ?? 'normal');
                $subject = trim((string) ($pair['subject'] ?? ''));

                if ($subject === '' || $subject === 'Урок снят') {
                    continue;
                }
                if ($status === 'removed' || $status === 'cancelled') {
                    continue;
                }
                // Current-week pattern repeats forward; next-Monday strip only for week=1
                if ($weekOffset === 0) {
                    if ($day < 0 || $day > 5) {
                        continue;
                    }
                    $date = $weekMonday->modify('+' . $day . ' days');
                } elseif ($weekOffset === 1 && $day === 0) {
                    if ($week !== 1) {
                        continue;
                    }
                    $date = $weekMonday; // already next week's Monday when week=1
                } else {
                    continue;
                }

                $bell = BellSchedule::pairTime($pairNumber, $day === 0 && $weekOffset === 1 ? 0 : $day);
                $startHm = self::parseBell($bell['start'] ?? '');
                $endHm = self::parseBell($bell['end'] ?? '');
                if ($startHm === null || $endHm === null) {
                    continue;
                }

                $dtStart = $date->setTime($startHm[0], $startHm[1], 0);
                $dtEnd = $date->setTime($endHm[0], $endHm[1], 0);
                $teacher = trim((string) ($pair['teacherFull'] ?? $pair['teacher'] ?? ''));
                $room = trim((string) ($pair['room'] ?? ''));
                $group = trim((string) ($pair['group'] ?? ''));

                $uid = sprintf(
                    'mkbp-%s-%s-w%s-d%s-p%s@mini-kbp.site',
                    self::icsToken($category),
                    self::icsToken($entityId),
                    $weekMonday->format('Ymd'),
                    $day,
                    $pairNumber
                );

                $summary = $subject;
                if ($status === 'added' || $status === 'replaced') {
                    $summary = '⚡ ' . $summary;
                }

                $descParts = [];
                if ($teacher !== '') {
                    $descParts[] = $teacher;
                }
                if ($group !== '' && $category !== 'group') {
                    $descParts[] = 'группа ' . $group;
                }
                if ($room !== '') {
                    $descParts[] = 'ауд. ' . $room;
                }
                $descParts[] = 'пара ' . $pairNumber;

                $events[] = [
                    'uid' => $uid,
                    'dtstart' => $dtStart,
                    'dtend' => $dtEnd,
                    'summary' => $summary,
                    'description' => implode(' · ', $descParts),
                    'location' => $room !== '' ? ('ауд. ' . $room) : '',
                ];
            }
        }

        $stamp = $now->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//MiniKBP//Timetable//RU',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::escapeText('КБиП · ' . $name),
            'X-WR-TIMEZONE:Europe/Minsk',
            'X-PUBLISHED-TTL:PT6H',
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
        ];
        $lines = array_merge($lines, self::vtimezoneLines());

        foreach ($events as $ev) {
            /** @var \DateTimeImmutable $ds */
            $ds = $ev['dtstart'];
            /** @var \DateTimeImmutable $de */
            $de = $ev['dtend'];
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $ev['uid'];
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'DTSTART;TZID=Europe/Minsk:' . $ds->format('Ymd\THis');
            $lines[] = 'DTEND;TZID=Europe/Minsk:' . $de->format('Ymd\THis');
            $lines[] = 'SUMMARY:' . self::escapeText((string) $ev['summary']);
            if ($ev['description'] !== '') {
                $lines[] = 'DESCRIPTION:' . self::escapeText((string) $ev['description']);
            }
            if ($ev['location'] !== '') {
                $lines[] = 'LOCATION:' . self::escapeText((string) $ev['location']);
            }
            $lines[] = 'STATUS:CONFIRMED';
            $lines[] = 'TRANSP:OPAQUE';
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines) . "\r\n";
    }

    /** @return list<string> */
    private static function vtimezoneLines(): array
    {
        // Belarus uses permanent UTC+3 (no DST) since 2011.
        return [
            'BEGIN:VTIMEZONE',
            'TZID:Europe/Minsk',
            'X-LIC-LOCATION:Europe/Minsk',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0300',
            'TZOFFSETTO:+0300',
            'TZNAME:+03',
            'DTSTART:19700101T000000',
            'END:STANDARD',
            'END:VTIMEZONE',
        ];
    }

    /** @return array{0:int,1:int}|null */
    private static function parseBell(string $raw): ?array
    {
        $raw = trim(str_replace(',', '.', $raw));
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^(\d{1,2})[.:](\d{2})$/', $raw, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
            return null;
        }
        return [$h, $min];
    }

    private static function escapeText(string $value): string
    {
        $value = str_replace(["\\", ";", ",", "\r\n", "\n", "\r"], ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"], $value);
        // Fold long lines at ~70 chars
        if (strlen($value) <= 70) {
            return $value;
        }
        $out = '';
        $chunk = 70;
        for ($i = 0; $i < strlen($value); $i += $chunk) {
            $part = substr($value, $i, $chunk);
            $out .= ($i === 0 ? $part : ("\r\n " . $part));
            $chunk = 69;
        }
        return $out;
    }

    private static function icsToken(string $value): string
    {
        $v = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $value) ?? 'x';
        $v = trim($v, '-');
        return $v !== '' ? substr($v, 0, 48) : 'x';
    }
}
