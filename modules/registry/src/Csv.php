<?php

namespace Saan\Modules\Registry;

/** Small CSV reader/writer: UTF-8 (BOM tolerated), comma/semicolon/tab delimiter, formula-safe output. */
class Csv
{
    public static function headerKey(string $h): string
    {
        return preg_replace('/[\s_\-.()\x{FEFF}]+/u', '', mb_strtolower(trim($h)));
    }

    /** @return array{headers: array<int,string>, rows: array<int,array<int,string>>, line_numbers: array<int,int>} */
    public static function parse(string $content): array
    {
        $xlsx = \App\Support\Saan\SpreadsheetReader::isXlsx($content);
        if ($xlsx) $content = \App\Support\Saan\SpreadsheetReader::toCsv($content);   // .xlsx: first sheet, one CSV line per sheet row
        if (str_starts_with($content, "\xEF\xBB\xBF")) $content = substr($content, 3);
        if ($content === '' || !mb_check_encoding($content, 'UTF-8')) abort(422, 'ไฟล์ต้องเป็นตัวอักษร UTF-8 (บันทึกจาก Excel แบบ CSV UTF-8)');
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $first = strtok($content, "\n") ?: '';
        $delim = ',';
        if (!$xlsx) foreach ([';', "\t"] as $d) if (substr_count($first, $d) > substr_count($first, $delim)) $delim = $d;
        $h = fopen('php://temp', 'r+');
        fwrite($h, $content); rewind($h);
        $headers = null; $rows = []; $lines = []; $line = 0;
        while (($r = fgetcsv($h, 0, $delim, '"', '')) !== false) {
            $line++;
            if ($r === [null] || ($headers !== null && !array_filter(array_map(fn ($c) => trim((string) $c), $r), fn ($c) => $c !== ''))) continue;
            if ($headers === null) { $headers = array_map(fn ($c) => trim((string) $c), $r); continue; }
            $rows[] = array_map(fn ($c) => trim((string) $c), $r);
            $lines[] = $line;
        }
        fclose($h);
        if ($headers === null) abort(422, 'ไฟล์ไม่มีหัวตาราง');
        return ['headers' => $headers, 'rows' => $rows, 'line_numbers' => $lines];
    }

    /** Neutralises spreadsheet formulas (CSV injection) in exported cells. */
    public static function cell(mixed $v): string
    {
        $s = (string) ($v ?? '');
        return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false ? "'".$s : $s;
    }

    public static function line(array $cells): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, array_map([self::class, 'cell'], $cells), ',', '"', '');
        rewind($h);
        $out = stream_get_contents($h);
        fclose($h);
        return $out;
    }
}
