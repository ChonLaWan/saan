<?php

namespace Saan\Modules\WebNotices;

use Illuminate\Validation\ValidationException;

/**
 * Turns pasted spreadsheet text (TSV or CSV) into notice rows and refuses personal data:
 * unsupported or sensitive header columns, and cell values that look like ID numbers, phone numbers,
 * birth dates or e-mail addresses.
 */
class RowParser
{
    public const ALLOWED = ['order_no', 'display_name', 'classroom', 'group', 'note', 'applicant_no'];
    public const MAX_ROWS = 2000;
    private const LIMITS = ['display_name' => 160, 'classroom' => 60, 'group' => 80, 'note' => 255, 'applicant_no' => 12];

    private const HEADERS = [
        'order_no' => ['ลำดับ', 'ที่', 'เลขที่', 'no', 'order', 'orderno', '#'],
        'display_name' => ['ชื่อ', 'ชื่อสกุล', 'ชื่อนามสกุล', 'ชื่อและนามสกุล', 'รายชื่อ', 'name', 'displayname', 'fullname'],
        'classroom' => ['ห้อง', 'ชั้น', 'ห้องเรียน', 'ชั้นเรียน', 'ห้องชั้น', 'ชั้นห้อง', 'classroom', 'class', 'room'],
        'group' => ['กลุ่ม', 'สาย', 'แผนการเรียน', 'หมวด', 'group', 'track'],
        'note' => ['หมายเหตุ', 'note', 'notes', 'remark', 'remarks'],
        // the only column a visitor can search by number (NoticeSearch); not the row order. 4-12 digits, unique within the list.
        'applicant_no' => ['เลขที่สมัคร', 'เลขสมัคร', 'เลขที่นั่งสอบ', 'เลขที่นั่ง', 'เลขที่สอบ', 'applicantno', 'applicant', 'seatno', 'seat', 'examno'],
    ];
    private const SENSITIVE_THAI = ['โทร', 'เบอร์', 'มือถือ', 'บัตร', 'ประชาชน', 'เลขประจำตัว', 'วันเกิด', 'วันเดือนปีเกิด', 'อีเมล', 'ที่อยู่', 'ไลน์', 'รหัสนักเรียน', 'รหัสประจำตัว'];
    private const SENSITIVE_LATIN = ['phone', 'tel', 'mobile', 'idcard', 'nationalid', 'citizenid', 'birth', 'birthdate', 'dob', 'email', 'address', 'line', 'lineid', 'studentid'];

    private const MONTHS = 'มกราคม|กุมภาพันธ์|มีนาคม|เมษายน|พฤษภาคม|มิถุนายน|กรกฎาคม|สิงหาคม|กันยายน|ตุลาคม|พฤศจิกายน|ธันวาคม|ม\.ค\.|ก\.พ\.|มี\.ค\.|เม\.ย\.|พ\.ค\.|มิ\.ย\.|ก\.ค\.|ส\.ค\.|ก\.ย\.|ต\.ค\.|พ\.ย\.|ธ\.ค\.|Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|June?|July?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?';

    /** Header labels are normalised the same way for allowed and sensitive lookups. */
    public static function norm(string $label): string
    {
        return (string) preg_replace('/[\s\-_.\/]+/u', '', mb_strtolower(trim($label)));
    }

    public static function isSensitiveLabel(string $label): bool
    {
        $n = self::norm($label);
        if ($n === '') return false;
        if (in_array($n, self::SENSITIVE_LATIN, true)) return true;
        foreach (self::SENSITIVE_THAI as $t) if (str_contains($n, $t)) return true;
        return false;
    }

    public static function headerKey(string $label): ?string
    {
        $n = self::norm($label);
        foreach (self::HEADERS as $key => $names) if (in_array($n, $names, true)) return $key;
        return null;
    }

    /** Returns the sensitive pattern name found in a cell value, or null. */
    public static function sensitiveValue(string $value): ?string
    {
        // Thai and fullwidth digits are folded to ASCII first so they cannot bypass the checks below.
        $value = strtr($value, ['๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4', '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9']);
        $value = (string) preg_replace_callback('/[\x{FF10}-\x{FF19}]/u', fn ($m) => (string) (mb_ord($m[0]) - 0xFF10), $value);
        if (preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/u', $value)) return 'อีเมล';
        if (preg_match('/(?<!\d)\d(?:[\s-]?\d){12}(?!\d)/', $value)) return 'เลขประจำตัวประชาชน';
        if (preg_match('/(?<!\d)(?:\+66|0)[\s.-]?\d{1,2}[\s.-]?\d{3}[\s.-]?\d{4}(?!\d)/', $value)) return 'เบอร์โทรศัพท์';
        if (preg_match('/(?<!\d)\d{1,2}\s*[\/.-]\s*\d{1,2}\s*[\/.-]\s*(?:(?:19|20|25)\d{2}|\d{2})(?!\d)/', $value)
            || preg_match('/(?<!\d)(?:19|20|25)\d{2}-\d{1,2}-\d{1,2}(?!\d)/', $value)
            || preg_match('/(?<!\d)\d{1,2}\s*(?:'.self::MONTHS.')\s*(?:19|20|25)?\d{2,4}/iu', $value)) return 'วันเกิด/วันที่';
        return null;
    }

    /**
     * @param list<string> $listColumns columns enabled on the list, in display order
     * @return list<array{order_no:int,display_name:string,classroom:?string,group_label:?string,note:?string,applicant_no:?string}>
     */
    public static function parse(string $text, array $listColumns): array
    {
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", $text));
        $lines = array_values(array_filter(explode("\n", $text), fn ($l) => trim($l) !== ''));
        if (!$lines) self::fail('ไม่พบข้อมูลที่วาง');
        $delim = str_contains($lines[0], "\t") ? "\t" : ',';
        $table = array_map(fn ($l) => self::cells($l, $delim), $lines);

        $map = null; // index => column key
        $first = $table[0];
        $isHeader = false;
        foreach ($first as $cell) if (self::headerKey($cell) !== null || self::isSensitiveLabel($cell)) $isHeader = true;
        if (!$isHeader) self::fail('กรุณาวางพร้อมแถวหัวตาราง (ลำดับ ชื่อ ห้อง กลุ่ม หมายเหตุ) เพื่อยืนยันว่าแต่ละคอลัมน์คืออะไร');
        if ($isHeader) {
            while ($first && trim((string) end($first)) === '') array_pop($first);
            $map = [];
            foreach ($first as $i => $cell) {
                if (self::isSensitiveLabel($cell)) self::fail('ไม่อนุญาตคอลัมน์ข้อมูลส่วนบุคคล: '.trim($cell));
                $key = self::headerKey($cell);
                if ($key === null) self::fail('ไม่รองรับคอลัมน์: '.($cell === '' ? '(ว่าง)' : trim($cell)).' (รองรับเฉพาะ ลำดับ ชื่อ ห้อง กลุ่ม หมายเหตุ เลขที่สมัคร/เลขที่นั่งสอบ)');
                if (in_array($key, $map, true)) self::fail('คอลัมน์ซ้ำ: '.trim($cell));
                if (!in_array($key, $listColumns, true)) self::fail('คอลัมน์ "'.trim($cell).'" ยังไม่ได้เปิดใช้ในรายการนี้ กรุณาเปิดใช้ก่อนแล้ววางใหม่');
                $map[$i] = $key;
            }
            array_shift($table);
        }
        if (!in_array('display_name', $map, true)) self::fail('ต้องมีคอลัมน์ชื่อ');
        if (!$table) self::fail('มีเฉพาะหัวตาราง ไม่มีรายชื่อ');
        if (count($table) > self::MAX_ROWS) self::fail('วางได้ไม่เกิน '.self::MAX_ROWS.' แถวต่อรายการ');

        $rows = []; $seq = 0; $seen = [];
        foreach ($table as $n => $cells) {
            $line = $n + 1 + ($isHeader ? 1 : 0);
            while (count($cells) > count($map) && trim((string) end($cells)) === '') array_pop($cells);
            if (count($cells) > count($map)) self::fail("แถวที่ $line มีคอลัมน์มากกว่าที่กำหนด");
            $rec = ['order_no' => null, 'display_name' => '', 'classroom' => null, 'group' => null, 'note' => null, 'applicant_no' => null];
            foreach ($map as $i => $key) {
                $v = (string) preg_replace('/[\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]/u', ' ', (string) ($cells[$i] ?? '')); // zero-width separators from Word/Excel become real spaces
                $v = trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $v));
                if ($key === 'display_name') $v = trim((string) preg_replace('/\s+/u', ' ', $v));
                if ($v !== '' && ($hit = self::sensitiveValue($v))) self::fail("แถวที่ $line มีข้อมูลที่เหมือน$hit ซึ่งไม่อนุญาตให้ประกาศ กรุณาลบออกแล้ววางใหม่");
                if ($key === 'applicant_no' && $v !== '') {
                    $v = strtr($v, ['๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4', '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9']);
                    if (!preg_match('/^\d{4,12}$/D', $v)) self::fail("แถวที่ $line เลขที่สมัคร/เลขที่นั่งสอบต้องเป็นตัวเลข 4-12 หลัก");
                }
                if ($key !== 'order_no' && isset(self::LIMITS[$key]) && mb_strlen($v) > self::LIMITS[$key]) self::fail("แถวที่ $line ยาวเกินกำหนด");
                $rec[$key] = $v === '' ? null : $v;
            }
            if ($rec['display_name'] === null) self::fail("แถวที่ $line ไม่มีชื่อ");
            if ($rec['order_no'] !== null) {
                if (!preg_match('/^\d{1,5}$/D', $rec['order_no'])) self::fail("แถวที่ $line ลำดับต้องเป็นตัวเลขไม่เกิน 5 หลัก");
                $order = (int) $rec['order_no'];
            } else {
                $order = ++$seq;
            }
            $seq = max($seq, $order);
            $rows[] = ['order_no' => $order, 'display_name' => $rec['display_name'], 'classroom' => $rec['classroom'], 'group_label' => $rec['group'], 'note' => $rec['note'], 'applicant_no' => $rec['applicant_no']];
            if ($rec['applicant_no'] !== null) {
                if (isset($seen[$rec['applicant_no']])) self::fail("แถวที่ $line เลขที่สมัคร/เลขที่นั่งสอบซ้ำกับแถวที่ ".$seen[$rec['applicant_no']]);
                $seen[$rec['applicant_no']] = $line;
            }
        }
        return $rows;
    }

    private static function cells(string $line, string $delim): array
    {
        if ($delim === "\t") return array_map(fn ($c) => trim($c, " \t\"") , explode("\t", $line));
        return array_map(fn ($c) => (string) $c, str_getcsv($line, ',', '"', ''));
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['text' => [$message]]);
    }
}
