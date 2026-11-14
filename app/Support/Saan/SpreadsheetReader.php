<?php

namespace App\Support\Saan;

use XMLReader;
use ZipArchive;

/**
 * Minimal, safe .xlsx reader (no dependency): ZipArchive + XMLReader.
 *
 * - zip bomb guards: entry count, sum of declared sizes, per-entry ratio, and a hard cap on bytes actually inflated
 * - XML: LIBXML_NONET, no entity substitution, DOCTYPE/ENTITY declarations and UTF-16 parts are rejected; malformed XML is a 422
 * - shared strings, inline strings, numbers, booleans, dates (by cell style), formulas use the cached value
 * - zero-padded number formats ("00000", "0-0000-00000-00-0") keep their displayed leading zeros, like a CSV export would
 * - sparse cells are placed by their reference (C5); rows/columns/cells beyond the limits fail with 422
 * - rows are streamed: only the current row is buffered, so memory stays flat
 * Rows come back as lists of trimmed strings (each ending at its last non-empty cell, like fgetcsv), so downstream code is unchanged.
 */
final class SpreadsheetReader
{
    public const MAX_ROWS = 20000;
    public const MAX_COLS = 200;
    public const MAX_CELLS = 1000000;
    public const MAX_TOTAL_BYTES = 52428800;   // 50 MB uncompressed
    public const MAX_ENTRIES = 2000;
    public const MAX_SHARED = 300000;          // shared strings (a school roster is a few thousand)
    public const MAX_SHARED_BYTES = 16777216;  // 16 MB of shared string text
    public const MAX_STYLES = 20000;           // cellXfs and custom number formats
    private const RATIO_FROM = 2097152;        // ratio guard applies to entries above 2 MB
    private const MAX_RATIO = 100;
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_REL_STRICT = 'http://purl.oclc.org/ooxml/officeDocument/relationships';

    private int $budget = self::MAX_TOTAL_BYTES;
    private int $cellCount = 0;
    /** @var array<int,?string> cellXfs index => zero-pad mask such as "00000" */
    private array $masks = [];

    public static function isXlsx(string $bytes): bool
    {
        return str_starts_with($bytes, "PK\x03\x04");
    }

    /** @return array<int,array<int,string>> rows 1..last non-blank row (blank rows kept as [] so row numbers match Excel) */
    public static function rows(string $bytes, ?string $sheet = null): array
    {
        $out = [];
        (new self())->scan($bytes, $sheet, function (int $rowNo, array $cells) use (&$out) {
            for ($i = count($out) + 1; $i < $rowNo; $i++) $out[] = [];
            $line = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $c => $t) $line[$c] = $t;
            $out[] = $line;
        });
        return $out;
    }

    /** CSV text (UTF-8, comma) with one line per spreadsheet row, so line numbers equal Excel row numbers. */
    public static function toCsv(string $bytes, ?string $sheet = null): string
    {
        $h = fopen('php://temp/maxmemory:8388608', 'r+');
        $last = 0;
        try {
            (new self())->scan($bytes, $sheet, function (int $rowNo, array $cells) use ($h, &$last) {
                if ($rowNo > $last + 1) fwrite($h, str_repeat("\n", $rowNo - $last - 1));
                $line = array_fill(0, max(array_keys($cells)) + 1, '');
                foreach ($cells as $c => $t) $line[$c] = str_replace(["\r\n", "\r", "\n"], ' ', $t);
                fputcsv($h, $line, ',', '"', '');
                $last = $rowNo;
            });
            rewind($h);
            return (string) stream_get_contents($h);
        } finally {
            fclose($h);
        }
    }

    /** Streams non-empty rows in ascending order: $on(rowNo, [colIndex => text]). */
    private function scan(string $bytes, ?string $sheet, callable $on): void
    {
        if (!self::isXlsx($bytes)) abort(422, 'ไฟล์ไม่ใช่ Excel (.xlsx)');
        if (!class_exists(ZipArchive::class) || !class_exists(XMLReader::class)) abort(422, 'เซิร์ฟเวอร์ยังไม่รองรับไฟล์ Excel (ต้องเปิด PHP zip และ xmlreader) กรุณาบันทึกเป็น CSV UTF-8');
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) abort(422, 'อ่านไฟล์ Excel ไม่ได้');
        $zip = new ZipArchive();
        $opened = false;
        $prevErr = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            file_put_contents($tmp, $bytes);
            if ($zip->open($tmp, ZipArchive::RDONLY) !== true) abort(422, 'ไฟล์ Excel เสียหายหรือไม่ใช่ .xlsx');
            $opened = true;
            $this->guard($zip);
            if ($zip->locateName('xl/workbook.xml') === false) abort(422, 'ไฟล์ไม่ใช่ Excel (.xlsx)');
            $wb = $this->entry($zip, 'xl/workbook.xml');
            $path = $this->sheetPath($zip, $wb, $sheet);
            $date1904 = (bool) preg_match('/<(?:\w+:)?workbookPr\b[^>]*\bdate1904\s*=\s*"(?:1|true)"/i', $wb);
            $formats = $this->styles($zip);
            $shared = $this->sharedStrings($zip);
            $this->sheetRows($zip, $path, $shared, $formats, $date1904, $on);
        } finally {
            if ($opened) $zip->close();
            @unlink($tmp);
            libxml_clear_errors();
            libxml_use_internal_errors($prevErr);
        }
    }

    private function done(XMLReader $r): void
    {
        $r->close();
        if (libxml_get_errors()) { libxml_clear_errors(); abort(422, 'ไฟล์ Excel เสียหาย'); }
    }

    private function guard(ZipArchive $zip): void
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) abort(422, 'ไฟล์ Excel ไม่ถูกต้อง');
        $sum = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) abort(422, 'ไฟล์ Excel เสียหาย');
            $name = (string) $st['name'];
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) abort(422, 'ไฟล์ Excel ไม่ปลอดภัย');
            $sum += (int) $st['size'];
            if ($sum > self::MAX_TOTAL_BYTES) abort(422, 'ไฟล์ Excel ใหญ่ผิดปกติเมื่อแตกไฟล์ (เกิน 50 MB)');
            $size = (int) $st['size']; $comp = max(1, (int) $st['comp_size']);
            if ($size > self::RATIO_FROM && $size / $comp > self::MAX_RATIO) abort(422, 'ไฟล์ Excel มีอัตราบีบอัดผิดปกติ');
        }
    }

    /** Reads one entry, counting real inflated bytes against the shared budget. */
    private function entry(ZipArchive $zip, string $name): string
    {
        $h = $zip->getStream($name);
        if ($h === false) return '';
        $out = '';
        while (!feof($h)) {
            $chunk = fread($h, 65536);
            if ($chunk === false) break;
            $this->budget -= strlen($chunk);
            if ($this->budget < 0) { fclose($h); abort(422, 'ไฟล์ Excel ใหญ่ผิดปกติเมื่อแตกไฟล์ (เกิน 50 MB)'); }
            $out .= $chunk;
        }
        fclose($h);
        return $out;
    }

    private function xml(string $xml): XMLReader
    {
        $bad = $xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false
            || str_starts_with($xml, "\xFF\xFE") || str_starts_with($xml, "\xFE\xFF") || str_contains(substr($xml, 0, 8), "\0");
        if ($bad) abort(422, 'ไฟล์ Excel ไม่ปลอดภัยหรือเสียหาย');
        libxml_clear_errors();
        $r = new XMLReader();
        if (!$r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) abort(422, 'ไฟล์ Excel เสียหาย');
        return $r;
    }

    private static function noDoctype(XMLReader $r): void
    {
        if ($r->nodeType === XMLReader::DOC_TYPE) abort(422, 'ไฟล์ Excel ไม่ปลอดภัยหรือเสียหาย');
    }

    private function sheetPath(ZipArchive $zip, string $wb, ?string $want): string
    {
        $sheets = [];
        $r = $this->xml($wb);
        while ($r->read()) {
            self::noDoctype($r);
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'sheet') {
                $rid = $r->getAttributeNs('id', self::NS_REL);
                if ($rid === null || $rid === '') $rid = $r->getAttributeNs('id', self::NS_REL_STRICT);
                $sheets[] = [
                    'name' => (string) $r->getAttribute('name'),
                    'rid' => (string) ($rid ?? ''),
                    'hidden' => in_array(strtolower((string) $r->getAttribute('state')), ['hidden', 'veryhidden'], true),
                ];
            }
        }
        $this->done($r);
        if (!$sheets) abort(422, 'ไม่พบแผ่นงานในไฟล์ Excel');
        $named = $want !== null && $want !== '';
        $pick = null;
        if ($named) {
            foreach ($sheets as $s) if (mb_strtolower(trim($s['name'])) === mb_strtolower(trim($want))) { $pick = $s; break; }
            if (!$pick) abort(422, 'ไม่พบแผ่นงานชื่อ '.mb_substr($want, 0, 60));
        } else {
            foreach ($sheets as $s) if (!$s['hidden']) { $pick = $s; break; }
            $pick ??= $sheets[0];
        }
        $rels = $this->entry($zip, 'xl/_rels/workbook.xml.rels');
        $target = null;
        if ($rels !== '' && $pick['rid'] !== '') {
            $rr = $this->xml($rels);
            while ($rr->read()) {
                self::noDoctype($rr);
                if ($rr->nodeType === XMLReader::ELEMENT && $rr->localName === 'Relationship' && $rr->getAttribute('Id') === $pick['rid']) { $target = (string) $rr->getAttribute('Target'); break; }
            }
            $this->done($rr);
        }
        if ($target === null || $target === '') {
            if ($named) abort(422, 'ไม่พบข้อมูลแผ่นงานในไฟล์ Excel');
            $target = 'worksheets/sheet1.xml';
        }
        $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
        $parts = [];
        foreach (explode('/', $path) as $p) { if ($p === '..') array_pop($parts); elseif ($p !== '.' && $p !== '') $parts[] = $p; }
        $path = implode('/', $parts);
        if ($zip->locateName($path) === false) abort(422, 'ไม่พบข้อมูลแผ่นงานในไฟล์ Excel');
        return $path;
    }

    /** @return array<int,string> cellXfs index => 'date'|'time'|'datetime'|''  (also fills $this->masks) */
    private function styles(ZipArchive $zip): array
    {
        $xml = $this->entry($zip, 'xl/styles.xml');
        if ($xml === '') return [];
        $custom = []; $xfs = [];
        $r = $this->xml($xml);
        $inXfs = false;
        while ($r->read()) {
            self::noDoctype($r);
            if ($r->nodeType === XMLReader::ELEMENT) {
                if ($r->localName === 'numFmt') { $custom[(int) $r->getAttribute('numFmtId')] = mb_substr((string) $r->getAttribute('formatCode'), 0, 255); if (count($custom) > self::MAX_STYLES) abort(422, 'ไฟล์ Excel มีรูปแบบเซลล์มากเกินไป'); }
                elseif ($r->localName === 'cellXfs') $inXfs = true;
                elseif ($r->localName === 'xf' && $inXfs) { $xfs[] = (int) $r->getAttribute('numFmtId'); if (count($xfs) > self::MAX_STYLES) abort(422, 'ไฟล์ Excel มีรูปแบบเซลล์มากเกินไป'); }
            } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'cellXfs') $inXfs = false;
        }
        $this->done($r);
        $out = [];
        foreach ($xfs as $i => $id) {
            $code = $custom[$id] ?? null;
            $out[$i] = $this->formatKind($id, $code);
            $this->masks[$i] = $out[$i] === '' && $code !== null ? self::padMask($code) : null;
        }
        return $out;
    }

    /** Returns the literal digit mask for formats made only of 0 and dashes/spaces ("00000", "0-0000-00000-00-0"), else null. */
    private static function padMask(string $code): ?string
    {
        $m = preg_replace('/\[[^\]]*\]/u', '', $code) ?? '';
        $m = preg_replace('/"([^"]*)"/u', '$1', $m) ?? '';
        $m = preg_replace('/\\\\(.)/u', '$1', $m) ?? '';
        if (!preg_match('/^[0\- ]+$/', $m) || !str_contains($m, '0')) return null;
        return substr_count($m, '0') > 1 ? $m : null;
    }

    private static function applyMask(string $mask, float $n): ?string
    {
        if (!is_finite($n) || $n < 0 || floor($n) !== $n || $n >= 1e15) return null;
        $zeros = substr_count($mask, '0');
        $digits = str_pad((string) (int) $n, $zeros, '0', STR_PAD_LEFT);
        $extra = strlen($digits) - $zeros;
        $out = ''; $pos = strlen($digits);
        for ($i = strlen($mask) - 1; $i >= 0; $i--) {
            if ($mask[$i] === '0') { $pos--; $out = $digits[$pos].$out; }
            else $out = $mask[$i].$out;
        }
        return ($extra > 0 ? substr($digits, 0, $extra) : '').$out;
    }

    private function formatKind(int $id, ?string $code): string
    {
        if ($code === null) {
            if (($id >= 14 && $id <= 17) || $id === 22) return $id === 22 ? 'datetime' : 'date';
            if (($id >= 18 && $id <= 21) || ($id >= 45 && $id <= 47)) return 'time';
            return '';
        }
        $c = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\.|_.|\*./u', '', $code) ?? '';
        if (preg_match('/^\s*general\s*$/i', $c) || $c === '') return '';
        $hasTime = (bool) preg_match('/[hs]/i', $c) || stripos($c, 'am/pm') !== false;
        if (preg_match('/[ydb]/i', $c)) return $hasTime ? 'datetime' : 'date';
        if ($hasTime) return 'time';
        return '';
    }

    /** @return array<int,string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $this->entry($zip, 'xl/sharedStrings.xml');
        if ($xml === '') return [];
        $r = $this->xml($xml);
        $out = []; $inSi = false; $buf = ''; $n = 0; $bytes = 0;
        $ok = $r->read();
        while ($ok) {
            self::noDoctype($r);
            if ($r->nodeType === XMLReader::ELEMENT) {
                if ($r->localName === 'si') { $inSi = true; $buf = ''; if (++$n > self::MAX_SHARED) abort(422, 'ไฟล์ Excel มีข้อความร่วมมากเกินไป'); if ($r->isEmptyElement) { $out[] = ''; $inSi = false; } }
                elseif ($inSi && $r->localName === 'rPh') { $ok = $r->next(); continue; }
                elseif ($inSi && $r->localName === 't') { $buf .= $r->readString(); if ($bytes + strlen($buf) > self::MAX_SHARED_BYTES) abort(422, 'ไฟล์ Excel มีข้อความร่วมใหญ่เกินไป'); }
            } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'si') { $bytes += strlen($buf); $out[] = $buf; $inSi = false; }
            $ok = $r->read();
        }
        $this->done($r);
        return $out;
    }

    private function sheetRows(ZipArchive $zip, string $path, array $shared, array $formats, bool $date1904, callable $on): void
    {
        $r = $this->xml($this->entry($zip, $path));
        $seqRow = 0; $emitted = 0;
        $ok = $r->read();
        while ($ok) {
            self::noDoctype($r);
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'row') {
                $seqRow++;
                $rowNo = (int) ($r->getAttribute('r') ?: $seqRow);
                $seqRow = $rowNo;
                $cells = [];
                if (!$r->isEmptyElement) {
                    $depth = $r->depth; $seqCol = -1;
                    while ($r->read()) {
                        if ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'row' && $r->depth === $depth) break;
                        if ($r->nodeType !== XMLReader::ELEMENT || $r->localName !== 'c') continue;
                        $ref = (string) $r->getAttribute('r');
                        $col = $ref !== '' ? self::colIndex($ref) : $seqCol + 1;
                        $seqCol = $col;
                        $type = (string) $r->getAttribute('t');
                        $style = (int) $r->getAttribute('s');
                        $val = null; $inline = '';
                        if (!$r->isEmptyElement) {
                            $cd = $r->depth;
                            while ($r->read()) {
                                if ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'c' && $r->depth === $cd) break;
                                if ($r->nodeType !== XMLReader::ELEMENT) continue;
                                if ($r->localName === 'v') $val = $r->readString();
                                elseif ($r->localName === 'is') {
                                    $id = $r->depth;
                                    $iok = $r->read();
                                    while ($iok) {
                                        if ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'is' && $r->depth === $id) break;
                                        if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'rPh') { $iok = $r->next(); continue; }
                                        if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 't') $inline .= $r->readString();
                                        $iok = $r->read();
                                    }
                                }
                            }
                        }
                        $text = $this->cellText($type, $val, $inline, $style, $shared, $formats, $date1904);
                        if ($text === '') continue;
                        if ($col >= self::MAX_COLS) abort(422, 'ไฟล์มีคอลัมน์เกิน '.self::MAX_COLS.' คอลัมน์');
                        if ($rowNo > self::MAX_ROWS) abort(422, 'ไฟล์มีแถวเกิน '.self::MAX_ROWS.' แถว กรุณาแบ่งไฟล์');
                        if (++$this->cellCount > self::MAX_CELLS) abort(422, 'ไฟล์มีข้อมูลมากเกินไป กรุณาแบ่งไฟล์');
                        $cells[$col] = $text;
                    }
                }
                if ($cells) {
                    if ($rowNo <= $emitted) abort(422, 'ไฟล์ Excel เสียหาย (ลำดับแถวผิดปกติ)');
                    ksort($cells);
                    $on($rowNo, $cells);
                    $emitted = $rowNo;
                }
            }
            $ok = $r->read();
        }
        $this->done($r);
    }

    private function cellText(string $type, ?string $val, string $inline, int $style, array $shared, array $formats, bool $date1904): string
    {
        switch ($type) {
            case 's':
                $v = $val === null ? '' : trim($val);
                return $v === '' || !ctype_digit($v) ? '' : trim($shared[(int) $v] ?? '');
            case 'inlineStr': return trim($inline !== '' ? $inline : (string) $val);
            case 'str': return trim((string) $val);
            case 'b': return $val === null || $val === '' ? '' : (trim($val) === '1' || strtolower(trim($val)) === 'true' ? 'TRUE' : 'FALSE');
            case 'e': return '';
            case 'd':   // ISO 8601 date cell
                $v = trim((string) $val);
                return preg_match('/^(\d{4}-\d{2}-\d{2})(?:T00:00(?::00(?:\.0+)?)?)?Z?$/', $v, $m) ? $m[1] : str_replace('T', ' ', substr($v, 0, 16));
        }
        if ($val === null || trim($val) === '' || !is_numeric(trim($val))) return trim((string) $val);
        $n = (float) trim($val);
        $kind = $formats[$style] ?? '';
        if ($kind !== '') return $this->serial($n, $kind, $date1904);
        $mask = $this->masks[$style] ?? null;
        if ($mask !== null && ($p = self::applyMask($mask, $n)) !== null) return $p;
        return self::number(trim($val), $n);
    }

    private static function number(string $raw, float $n): string
    {
        if (is_finite($n) && floor($n) === $n && abs($n) < 1e15) return (string) (int) $n;
        $s = sprintf('%.15G', $n);
        if (str_contains($s, 'E')) $s = rtrim(rtrim(sprintf('%.15F', $n), '0'), '.');
        return $s;
    }

    private function serial(float $n, string $kind, bool $date1904): string
    {
        $days = (int) floor($n);
        $frac = $n - $days;
        $secs = (int) round($frac * 86400);
        if ($secs >= 86400) { $secs -= 86400; $days++; }
        $time = gmdate('H:i', $secs);
        if ($kind === 'time') return $time;
        if ($date1904) $days += 1462;
        if ($days < 1) return $kind === 'datetime' && $secs ? $time : '';
        $unix = ($days - ($days < 60 ? 25568 : 25569)) * 86400;   // 1900 leap-year bug: serials 1..59 are one day off the 1899-12-30 base
        $d = gmdate('Y-m-d', $unix);
        return $kind === 'datetime' && $secs > 0 ? $d.' '.$time : $d;
    }

    private static function colIndex(string $ref): int
    {
        $n = 0;
        for ($i = 0, $l = strlen($ref); $i < $l; $i++) {
            $c = ord($ref[$i]);
            if ($c >= 97 && $c <= 122) $c -= 32;
            if ($c < 65 || $c > 90) break;
            $n = $n * 26 + ($c - 64);
            if ($n > 20000) break;
        }
        return max(0, $n - 1);
    }
}
