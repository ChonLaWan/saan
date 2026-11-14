<?php

namespace Saan\Modules\Connectors;

/** Reads a legacy CSV given as raw bytes: BOM, UTF-16 or Windows-874 (TIS-620) text, comma/semicolon/tab delimiter. */
class LegacyCsv
{
    public const MAX_ROWS = 50000;

    public static function decode(string $bytes): string
    {
        if (\App\Support\Saan\SpreadsheetReader::isXlsx($bytes)) return \App\Support\Saan\SpreadsheetReader::toCsv($bytes);   // .xlsx: first sheet
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) $bytes = substr($bytes, 3);
        if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            $text = mb_convert_encoding($bytes, 'UTF-8', 'UTF-16');
            return ltrim($text, "\u{FEFF}");
        }
        if ($bytes === '') abort(422, 'ไฟล์ว่างเปล่า');
        if (str_contains($bytes, "\0")) abort(422, 'ไฟล์ไม่ใช่ข้อความ CSV');
        if (mb_check_encoding($bytes, 'UTF-8')) return $bytes;
        // Windows-874 maps one byte to one character; unassigned bytes are dropped instead of failing the whole file
        $text = strtr($bytes, self::cp874Table());
        if ($text === '' || !mb_check_encoding($text, 'UTF-8')) abort(422, 'อ่านรหัสตัวอักษรของไฟล์ไม่ได้ (รองรับ UTF-8 และ Windows-874)');
        return $text;
    }

    /** Byte => UTF-8 table for Windows-874, independent of iconv/glibc. High bytes only; ASCII passes through. */
    private static function cp874Table(): array
    {
        static $t = null;
        if ($t !== null) return $t;
        $t = [];
        $special = [0x80 => 0x20AC, 0x85 => 0x2026, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014, 0xA0 => 0x00A0];
        for ($b = 0x80; $b <= 0xFF; $b++) {
            if (isset($special[$b])) $cp = $special[$b];
            elseif ($b >= 0xA1 && $b <= 0xFB && !($b >= 0xDB && $b <= 0xDE)) $cp = 0x0E00 + $b - 0xA0;
            else $cp = null;
            $t[chr($b)] = $cp === null ? '' : mb_chr($cp, 'UTF-8');
        }
        return $t;
    }

    /** @return array{headers: array<int,string>, rows: array<int,array<int,string>>, lines: array<int,int>} */
    public static function parse(string $bytes): array
    {
        $xlsx = \App\Support\Saan\SpreadsheetReader::isXlsx($bytes);
        $content = str_replace(["\r\n", "\r"], "\n", self::decode($bytes));
        $first = strtok($content, "\n") ?: '';
        $delim = ',';
        if (!$xlsx) foreach ([';', "\t"] as $d) if (substr_count($first, $d) > substr_count($first, $delim)) $delim = $d;
        $h = fopen('php://temp', 'r+');
        fwrite($h, $content); rewind($h);
        $headers = null; $rows = []; $lines = []; $physical = 0; $prev = 0;
        while (($r = fgetcsv($h, 0, $delim, '"', '')) !== false) {
            // physical line number of the record start: quoted cells may span several lines
            $line = $physical + 1;
            $pos = (int) ftell($h);
            $physical += substr_count(substr($content, $prev, max(0, $pos - $prev)), "\n");
            $prev = $pos;
            if ($r === [null]) continue;
            $r = array_map(fn ($c) => trim((string) $c), $r);
            if ($headers === null) { $headers = $r; continue; }
            if (!array_filter($r, fn ($c) => $c !== '')) continue;
            if (count($rows) >= self::MAX_ROWS) { fclose($h); abort(422, 'ไฟล์มีแถวเกิน '.self::MAX_ROWS.' แถว กรุณาแบ่งไฟล์'); }
            $rows[] = $r;
            $lines[] = $line;
        }
        fclose($h);
        if ($headers === null) abort(422, 'ไฟล์ไม่มีหัวตาราง');
        if (!$rows) abort(422, 'ไฟล์ไม่มีแถวข้อมูล');
        return ['headers' => $headers, 'rows' => $rows, 'lines' => $lines];
    }

    /** Neutralises spreadsheet formulas in exported cells. */
    public static function cell(mixed $v): string
    {
        $s = is_scalar($v) || $v === null ? (string) ($v ?? '') : json_encode($v, JSON_UNESCAPED_UNICODE);
        return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false ? "'".$s : $s;
    }

    public static function line(array $cells): string { return self::write(array_map([self::class, 'cell'], $cells)); }

    /** No formula protection: for machine-to-machine CSV (the registry importer), never for files a person opens in a spreadsheet. */
    public static function rawLine(array $cells): string { return self::write(array_map(fn ($c) => (string) ($c ?? ''), $cells)); }

    private static function write(array $cells): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, $cells, ',', '"', '');
        rewind($h);
        $out = stream_get_contents($h);
        fclose($h);
        return $out;
    }
}
