<?php

namespace Saan\Modules\ExamChecker;

/**
 * Parsing of typed answers / answer keys and the scoring rule. Pure functions, no database.
 *
 * Accepted characters (case-insensitive, whitespace , ; | ignored):
 *   choices  Thai ก ข ค ง จ ฉ  |  digits 1-6 (Thai digits too)  |  letters A-F
 *   blank    - _ . 0          (stored as '-')
 *   multiple *                (answers only, stored as '*', never correct)
 * Answer key only:
 *   cancelled question  x X # ×     (everybody gets the point, blank included)
 *   several accepted    (23) or [23]  (any of these choices is correct)
 */
final class Scoring
{
    public const LETTERS = ['ก', 'ข', 'ค', 'ง', 'จ', 'ฉ'];
    private const THAI = ['ก' => 1, 'ข' => 2, 'ฃ' => 2, 'ค' => 3, 'ฅ' => 3, 'ง' => 4, 'จ' => 5, 'ฉ' => 6,
        '๑' => 1, '๒' => 2, '๓' => 3, '๔' => 4, '๕' => 5, '๖' => 6];
    private const BLANK = ['-' => 1, '_' => 1, '.' => 1, '0' => 1, '๐' => 1, '–' => 1];

    /** @return string[] */
    public static function tokens(string $s): array
    {
        $s = preg_replace('/[\s,;|\x{200B}\x{FEFF}]+/u', '', $s) ?? '';
        return $s === '' ? [] : (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    public static function choice(string $ch): ?int
    {
        if (isset(self::THAI[$ch])) return self::THAI[$ch];
        if ($ch >= '1' && $ch <= '6' && strlen($ch) === 1) return (int) $ch;
        if (strlen($ch) === 1) {
            $o = ord(strtoupper($ch));
            if ($o >= 65 && $o <= 70) return $o - 64;
        }
        return null;
    }

    /** @return array{0:string,1:int,2:?string} normalised answers, token count, error */
    public static function parseAnswers(string $s, int $choices): array
    {
        $out = '';
        foreach (self::tokens($s) as $i => $t) {
            if (isset(self::BLANK[$t])) { $out .= '-'; continue; }
            if ($t === '*') { $out .= '*'; continue; }
            $c = self::choice($t);
            if ($c === null) return [$out, $i + 1, 'อักขระ "'.$t.'" ตำแหน่งที่ '.($i + 1).' ไม่ถูกต้อง'];
            if ($c > $choices) return [$out, $i + 1, 'ข้อ '.($i + 1).' มีตัวเลือกเกินจำนวนที่กำหนด ('.$choices.' ตัวเลือก)'];
            $out .= (string) $c;
        }
        return [$out, strlen($out), null];
    }

    /** @return array{0:array,1:?string} key entries, error */
    public static function parseKey(string $s, int $choices): array
    {
        $tokens = self::tokens($s);
        $entries = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $no = count($entries) + 1;
            if ($t === '(' || $t === '[') {
                $set = [];
                for ($i++; $i < $n && $tokens[$i] !== ')' && $tokens[$i] !== ']'; $i++) {
                    $c = self::choice($tokens[$i]);
                    if ($c === null || $c > $choices) return [$entries, 'ข้อ '.$no.' มีตัวเลือกในวงเล็บไม่ถูกต้อง'];
                    $set[$c] = $c;
                }
                if ($i >= $n) return [$entries, 'ข้อ '.$no.' วงเล็บไม่ปิด'];
                sort($set);
                if (!$set) return [$entries, 'ข้อ '.$no.' วงเล็บว่าง'];
                $entries[] = count($set) === 1 ? $set[0] : array_values($set);
            } elseif (in_array($t, ['x', 'X', '#', '×'], true)) {
                $entries[] = 'cancelled';
            } else {
                $c = self::choice($t);
                if ($c === null) return [$entries, 'อักขระ "'.$t.'" ในข้อ '.$no.' ไม่ถูกต้อง'];
                if ($c > $choices) return [$entries, 'ข้อ '.$no.' มีตัวเลือกเกินจำนวนที่กำหนด ('.$choices.' ตัวเลือก)'];
                $entries[] = $c;
            }
        }
        return [$entries, null];
    }

    /** Validates a key sent as JSON (list). @return array{0:array,1:?string} */
    public static function normalizeKey(array $key, int $choices): array
    {
        $out = [];
        foreach (array_values($key) as $i => $e) {
            if ($e === 'cancelled') { $out[] = 'cancelled'; continue; }
            $list = is_array($e) ? $e : [$e];
            $set = [];
            foreach ($list as $c) {
                if (!is_int($c) && !(is_string($c) && ctype_digit($c))) return [[], 'เฉลยข้อ '.($i + 1).' ไม่ถูกต้อง'];
                $c = (int) $c;
                if ($c < 1 || $c > $choices) return [[], 'เฉลยข้อ '.($i + 1).' ไม่ถูกต้อง'];
                $set[$c] = $c;
            }
            if (!$set) return [[], 'เฉลยข้อ '.($i + 1).' ไม่ถูกต้อง'];
            sort($set);
            $out[] = count($set) === 1 ? $set[0] : array_values($set);
        }
        return [$out, null];
    }

    public static function keyToString(?array $key): string
    {
        $s = '';
        foreach ($key ?? [] as $e) $s .= $e === 'cancelled' ? 'x' : (is_array($e) ? '('.implode('', $e).')' : (string) $e);
        return $s;
    }

    public static function keyIsComplete(?array $key, int $count): bool
    {
        return is_array($key) && count($key) === $count;
    }
}
