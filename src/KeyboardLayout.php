<?php
declare(strict_types=1);

namespace MiniKbp;

final class KeyboardLayout
{
    private const EN_TO_RU = [
        'q' => 'й', 'w' => 'ц', 'e' => 'у', 'r' => 'к', 't' => 'е',
        'y' => 'н', 'u' => 'г', 'i' => 'ш', 'o' => 'щ', 'p' => 'з',
        '[' => 'х', ']' => 'ъ', 'a' => 'ф', 's' => 'ы', 'd' => 'в',
        'f' => 'а', 'g' => 'п', 'h' => 'р', 'j' => 'о', 'k' => 'л',
        'l' => 'д', ';' => 'ж', "'" => 'э', 'z' => 'я', 'x' => 'ч',
        'c' => 'с', 'v' => 'м', 'b' => 'и', 'n' => 'т', 'm' => 'ь',
        ',' => 'б', '.' => 'ю', '`' => 'ё',
    ];

    public static function swap(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        $hasCyr = (bool) preg_match('/[а-яёА-ЯЁ]/u', $value);
        $hasLat = (bool) preg_match('/[a-zA-Z]/', $value);
        $ruToEn = array_flip(self::EN_TO_RU);

        if ($hasCyr && !$hasLat) {
            return self::mapChars($value, $ruToEn);
        }
        if ($hasLat && !$hasCyr) {
            return self::mapChars($value, self::EN_TO_RU);
        }

        $viaEn = self::mapChars($value, self::EN_TO_RU);
        return $viaEn === $value ? self::mapChars($value, $ruToEn) : $viaEn;
    }

    /** @return list<string> */
    public static function variants(string $query): array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return [];
        }
        $swapped = self::swap($trimmed);
        return $swapped === $trimmed ? [$trimmed] : [$trimmed, $swapped];
    }

    /** @param array<string, string> $table */
    private static function mapChars(string $input, array $table): string
    {
        $out = '';
        $len = mb_strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($input, $i, 1);
            $lower = mb_strtolower($ch);
            if (!isset($table[$lower])) {
                $out .= $ch;
                continue;
            }
            $mapped = $table[$lower];
            $out .= $ch === $lower ? $mapped : mb_strtoupper($mapped);
        }
        return $out;
    }
}
