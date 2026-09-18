<?php
declare(strict_types=1);

namespace MiniKbp;

/** Input bounds / sanitization for public API params. */
final class RequestGuard
{
    public const MAX_ID_LEN = 64;
    public const MAX_NAME_LEN = 200;
    public const MAX_QUERY_LEN = 100;

    /** @var list<string> */
    public const CATEGORIES = ['group', 'teacher', 'place', 'subject'];

    public static function category(string $raw): ?string
    {
        $cat = strtolower(trim($raw));
        return in_array($cat, self::CATEGORIES, true) ? $cat : null;
    }

    public static function entityId(string $raw): ?string
    {
        $id = trim($raw);
        if ($id === '' || strlen($id) > self::MAX_ID_LEN) {
            return null;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $id)) {
            return null;
        }
        return $id;
    }

    public static function displayName(string $raw): string
    {
        $name = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (strlen($name) > self::MAX_NAME_LEN) {
            $name = substr($name, 0, self::MAX_NAME_LEN);
        }
        return $name;
    }

    public static function searchQuery(string $raw): string
    {
        $q = trim($raw);
        if (strlen($q) > self::MAX_QUERY_LEN) {
            $q = substr($q, 0, self::MAX_QUERY_LEN);
        }
        return $q;
    }

    /** Strip tags / control chars from upstream-derived labels before JSON. */
    public static function sanitizeLabel(string $raw): string
    {
        $s = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        return trim($s);
    }
}
