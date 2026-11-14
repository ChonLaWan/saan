<?php

namespace Saan\Modules\WebNotices;

/**
 * Search-own-name mode: normalises what the visitor typed and matches it against the cells the visitor is allowed to see.
 * A query is either an exact applicant / seat number or at least MIN_CHARS characters of text; the answer is capped at MAX_RESULTS rows.
 * A number is matched ONLY against the applicant_no column (never against the row order 1..N, which would let a bot walk the list one row at a time),
 * needs at least MIN_DIGITS digits, and is refused when the list has no such column.
 */
final class NoticeSearch
{
    public const MIN_CHARS = 3;
    public const MAX_RESULTS = 5;
    public const MAX_INPUT = 80;
    public const MIN_DIGITS = 4;
    public const MAX_DIGITS = 12;
    public const HIDDEN_NAME = 'พบเลขนี้ในประกาศ (พิมพ์เลขพร้อมชื่อของตนเองเพื่อดูชื่อ)';

    /** Strips zero-width characters (U+200B-U+200D, U+FEFF), folds Thai and fullwidth digits to ASCII, collapses spaces, lowercases. */
    public static function normalize(string $q): string
    {
        $q = strtr($q, ['๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4', '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9']);
        $q = (string) preg_replace_callback('/[\x{FF10}-\x{FF19}]/u', fn ($m) => (string) (mb_ord($m[0]) - 0xFF10), $q);
        $q = (string) preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $q);
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $q)));
    }

    /** @return array{kind:string,value:string}|null null = too short or not usable */
    public static function parse(mixed $raw, bool $numbers = false): ?array
    {
        if (!is_string($raw) || strlen($raw) > self::MAX_INPUT * 4) return null;
        $n = self::normalize($raw);
        if ($n === '' || mb_strlen($n) > self::MAX_INPUT) return null;
        // number + at least MIN_CHARS characters of the name (a second factor only the person knows): the only way a number returns the cells as they are shown
                $two = null; // [digits, name part]
        if ($numbers) {
            if (preg_match('/^(\d{'.self::MIN_DIGITS.','.self::MAX_DIGITS.'}) (.+)$/Du', $n, $a)) $two = [$a[1], $a[2]];
            elseif (preg_match('/^(.+) (\d{'.self::MIN_DIGITS.','.self::MAX_DIGITS.'})$/Du', $n, $b)) $two = [$b[2], $b[1]];
        }
        if ($two !== null && mb_strlen($two[1]) >= self::MIN_CHARS && !NameMask::isTitle($two[1]) && !preg_match('/^\d+$/D', $two[1])) return ['kind' => 'number', 'value' => $two[0], 'name' => $two[1]];
if (preg_match('/^\d+$/D', $n)) return $numbers && strlen($n) >= self::MIN_DIGITS && strlen($n) <= self::MAX_DIGITS ? ['kind' => 'number', 'value' => $n] : null;
        if (mb_strlen($n) < self::MIN_CHARS || NameMask::isTitle($n)) return null;
        return ['kind' => 'text', 'value' => $n];
    }

    /**
     * @param list<array<string,mixed>> $rows cells exactly as they would be shown (masked or full as allowed)
     * @return array{rows:list<array>,more:bool}
     */
    public static function match(array $rows, array $query, bool $maskNumberOnly = false): array
    {
        $out = [];
        $more = false;
        foreach ($rows as $cells) {
            $hit = $query['kind'] === 'number'
                ? isset($cells['applicant_no']) && self::normalize((string) $cells['applicant_no']) === $query['value']
                    && (!isset($query['name']) || str_contains(self::normalize((string) ($cells['display_name'] ?? '')), $query['name']))
                : str_contains(self::normalize((string) ($cells['display_name'] ?? '')), $query['value']);
            if (!$hit) continue;
            // a bare number is one factor only: sequential numbers must not walk a list into names. No part of the name comes back (a masked "first name + initial"
            // would hand a bot the very text it needs for the number + name query that returns the full cells), only that the number is on the list.
            if ($maskNumberOnly && $query['kind'] === 'number' && !isset($query['name']) && isset($cells['display_name'])) $cells['display_name'] = self::HIDDEN_NAME;
            if (count($out) >= self::MAX_RESULTS) { $more = true; break; }
            $out[] = $cells;
        }
        return ['rows' => $out, 'more' => $more];
    }
}
