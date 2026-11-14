<?php

namespace Saan\Modules\ExamChecker;

/**
 * Lenient CSV reader for answer sheets.
 *
 * Format 1, plain ("csv"):      student_code,answers[,name]       e.g.  DEMO-01,13241
 * Format 2, OMR scanner ("omr_csv"): a header row, one row per sheet. Columns (header names are matched
 *   case-insensitively, spaces/underscores/dashes ignored):
 *     code   : student_code | code | id | student_id | sid | รหัส | รหัสนักเรียน | เลขประจำตัว | candidate_id
 *     name   : name | student_name | full_name | ชื่อ | ชื่อสกุล         (optional)
 *     answers: either ONE column  answers | answer | ans | คำตอบ  (string such as "ABCD..." or "1 3 2 4 ...")
 *              or one column PER QUESTION  q1,q2,...  |  1,2,...  |  ข้อ1,ข้อ2,...  |  item1,...
 *              per-question cells: A-F / ก-ฉ / 1-6; empty = blank; two or more marks in one cell = multiple ("*").
 * Without a recognised header row, column 1 is the code and column 2 the answers. Delimiter , ; or tab; UTF-8 (Windows-874 is converted).
 */
final class Importer
{
    private const CODE = ['studentcode', 'code', 'id', 'studentid', 'sid', 'candidateid', 'examineeid', 'รหัส', 'รหัสนักเรียน', 'เลขประจำตัว', 'เลขประจำตัวนักเรียน', 'รหัสผู้สอบ'];
    private const NAME = ['name', 'studentname', 'fullname', 'ชื่อ', 'ชื่อสกุล', 'ชื่อนามสกุล'];
    private const ANS = ['answers', 'answer', 'ans', 'responses', 'คำตอบ'];
    public const MAX_ROWS = 5000;

    /** @return array{rows:array,errors:array,format:string,source:string} */
    public static function parse(string $content, int $questions, int $choices): array
    {
        $table = self::table($content);
        if (!$table) abort(422, 'ไฟล์ว่างเปล่า');
        $first = array_map([self::class, 'norm'], $table[0]['cells']);
        $codeCol = self::find($first, self::CODE);
        $nameCol = self::find($first, self::NAME);
        $ansCol = self::find($first, self::ANS);
        $qCols = [];
        foreach ($first as $i => $h) if (preg_match('/^(?:q|ข้อ|item|no|question)?0*(\d{1,3})$/u', $h, $m) && $i !== $codeCol && (int) $m[1] >= 1 && (int) $m[1] <= $questions) $qCols[(int) $m[1]] = $i;
        if ($codeCol === null && count($qCols) < 3) $qCols = [];
        $header = $codeCol !== null || $ansCol !== null || $qCols;
        if ($header) {
            array_shift($table);
            $codeCol ??= 0;
            if ($ansCol === null && !$qCols) abort(422, 'ไม่พบคอลัมน์คำตอบ (answers หรือ q1..q'.$questions.')');
        } else { $codeCol = 0; $ansCol = 1; $nameCol = 2 < count($table[0]['cells']) ? 2 : null; }
        if (count($table) > self::MAX_ROWS) abort(422, 'ไฟล์มีมากกว่า '.self::MAX_ROWS.' แถว กรุณาแบ่งไฟล์');
        $omr = $header && $qCols && $ansCol === null;

        $rows = []; $errors = [];
        foreach ($table as $entry) {
            $line = $entry['line'];          // physical line in the file, blank lines included
            $cells = $entry['cells'];
            $code = trim((string) ($cells[$codeCol] ?? ''));
            if ($omr) {
                $a = '';
                for ($q = 1; $q <= $questions; $q++) {
                    $cell = isset($qCols[$q]) ? trim((string) ($cells[$qCols[$q]] ?? '')) : '';
                    $a .= self::mark($cell);
                }
            } else $a = (string) ($cells[$ansCol] ?? '');
            if ($code === '' && trim($a) === '') continue;
            $rows[] = ['line' => $line, 'student_code' => $code, 'answers' => $a, 'display_name' => $nameCol !== null ? trim((string) ($cells[$nameCol] ?? '')) : ''];
        }
        return ['rows' => $rows, 'errors' => $errors, 'format' => $omr ? 'omr_csv' : 'csv', 'source' => $omr ? 'omr_csv' : 'csv'];
    }

    /** One OMR cell: empty = blank, one mark = that choice, several marks = multiple. */
    private static function mark(string $cell): string
    {
        $t = Scoring::tokens($cell);
        if (!$t) return '-';
        if (count($t) > 1) {
            $blank = array_filter($t, fn ($x) => !in_array($x, ['-', '_', '.', '0'], true));
            return count($blank) > 1 ? '*' : ($blank ? array_values($blank)[0] : '-');
        }
        return $t[0];
    }

    private static function norm(string $h): string
    {
        return preg_replace('/[\s_\-.()\x{FEFF}]+/u', '', mb_strtolower(trim($h))) ?? '';
    }

    private static function find(array $headers, array $names): ?int
    {
        foreach ($headers as $i => $h) if (in_array($h, $names, true)) return $i;
        return null;
    }

    /** @return array<int,array{line:int,cells:array<int,string>}> non-blank records with their original line number */
    private static function table(string $content): array
    {
        $xlsx = \App\Support\Saan\SpreadsheetReader::isXlsx($content);
        if ($xlsx) $content = \App\Support\Saan\SpreadsheetReader::toCsv($content);   // .xlsx: first sheet
        if (str_starts_with($content, "\xEF\xBB\xBF")) $content = substr($content, 3);
        if (!mb_check_encoding($content, 'UTF-8')) {
            $conv = function_exists('iconv') ? @iconv('CP874', 'UTF-8//IGNORE', $content) : false;
            if ($conv === false || $conv === '') abort(422, 'ไฟล์ต้องเป็น UTF-8 หรือ Windows-874');
            $content = $conv;
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $firstLine = strtok($content, "\n") ?: '';
        $delim = ',';
        if (!$xlsx) foreach ([';', "\t"] as $d) if (substr_count($firstLine, $d) > substr_count($firstLine, $delim)) $delim = $d;
        $h = fopen('php://temp', 'r+');
        fwrite($h, $content); rewind($h);
        $out = [];
        $lineNo = 1;
        while (true) {
            $pos = ftell($h);
            if (($r = fgetcsv($h, 0, $delim, '"', '')) === false) break;
            $start = $lineNo;
            $lineNo += substr_count(substr($content, $pos, ftell($h) - $pos), "\n");   // quoted cells may span lines
            if ($r === [null]) continue;
            $r = array_map(fn ($c) => trim((string) $c), $r);
            if (!array_filter($r, fn ($c) => $c !== '')) continue;
            $out[] = ['line' => $start, 'cells' => $r];
            if (count($out) > self::MAX_ROWS + 1) { fclose($h); abort(422, 'ไฟล์มีมากกว่า '.self::MAX_ROWS.' แถว กรุณาแบ่งไฟล์'); } // header + MAX_ROWS: stop reading instead of parsing the rest
        }
        fclose($h);
        return $out;
    }
}
