<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Saan\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

class SpreadsheetReaderTest extends TestCase
{
    use RefreshDatabase;

    private function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26).$s;
        return $s;
    }

    /**
     * Builds a small .xlsx in memory. Cells: string -> shared string, int|float -> number, null -> skipped (sparse),
     * ['d' => serial] -> date-styled number, ['b' => bool], ['i' => text] -> inline string, ['f' => '1+1', 'v' => 2] -> formula with cached value.
     * A row given as ['@5' => [...cells]] is placed at that row number.
     */
    private function xlsx(array $rows, array $extra = [], array $sheets = []): string
    {
        $shared = [];
        $sharedIdx = function (string $s) use (&$shared) { $k = array_search($s, $shared, true); if ($k === false) { $shared[] = $s; $k = count($shared) - 1; } return $k; };
        $sheetXml = function (array $rows) use ($sharedIdx) {
            $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            $n = 0;
            foreach ($rows as $key => $cells) {
                $n = is_string($key) && $key[0] === '@' ? (int) substr($key, 1) : $n + 1;
                $x .= '<row r="'.$n.'">';
                foreach ($cells as $c => $v) {
                    if ($v === null) continue;
                    $ref = $this->col($c).$n;
                    if (is_string($v)) $x .= '<c r="'.$ref.'" t="s"><v>'.$sharedIdx($v).'</v></c>';
                    elseif (is_int($v) || is_float($v)) $x .= '<c r="'.$ref.'"><v>'.$v.'</v></c>';
                    elseif (isset($v['d'])) $x .= '<c r="'.$ref.'" s="1"><v>'.$v['d'].'</v></c>';
                    elseif (isset($v['z'])) $x .= '<c r="'.$ref.'" s="2"><v>'.$v['z'].'</v></c>';
                    elseif (isset($v['id'])) $x .= '<c r="'.$ref.'" s="3"><v>'.$v['id'].'</v></c>';
                    elseif (isset($v['th'])) $x .= '<c r="'.$ref.'" s="4"><v>'.$v['th'].'</v></c>';
                    elseif (isset($v['s'])) $x .= '<c r="'.$ref.'" s="1"/>';
                    elseif (isset($v['raw'])) $x .= $v['raw'];
                    elseif (isset($v['b'])) $x .= '<c r="'.$ref.'" t="b"><v>'.($v['b'] ? 1 : 0).'</v></c>';
                    elseif (isset($v['i'])) $x .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars($v['i'], ENT_XML1).'</t></is></c>';
                    elseif (isset($v['f'])) $x .= '<c r="'.$ref.'"><f>'.$v['f'].'</f><v>'.$v['v'].'</v></c>';
                }
                $x .= '</row>';
            }
            return $x.'</sheetData></worksheet>';
        };
        $sheets = $sheets ?: ['Sheet1' => $rows];
        $path = tempnam(sys_get_temp_dir(), 'xt');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $wb = ''; $rels = ''; $i = 0;
        foreach ($sheets as $name => $r) {
            $i++;
            $zip->addFromString("xl/worksheets/sheet$i.xml", $sheetXml($r));
            $wb .= '<sheet name="'.htmlspecialchars($name, ENT_XML1).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
            $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$wb.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="3"><numFmt numFmtId="164" formatCode="00000"/><numFmt numFmtId="165" formatCode="0\-0000\-00000\-00\-0"/><numFmt numFmtId="166" formatCode="[$-th-TH]mmmm bbbb"/></numFmts><cellXfs count="5"><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="164"/><xf numFmtId="165"/><xf numFmtId="166"/></cellXfs></styleSheet>');
        $ss = '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        foreach ($shared as $s) $ss .= '<si><t xml:space="preserve">'.htmlspecialchars($s, ENT_XML1).'</t></si>';
        $zip->addFromString('xl/sharedStrings.xml', $ss.'</sst>');
        foreach ($extra as $n => $content) $zip->addFromString($n, $content);
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);
        return $bytes;
    }

    private function serial(string $ymd): int
    {
        return (int) (new \DateTimeImmutable('1899-12-30'))->diff(new \DateTimeImmutable($ymd))->days;
    }

    private function httpStatus(callable $f): int
    {
        try { $f(); } catch (HttpException $e) { return $e->getStatusCode(); }
        return 200;
    }

    public function test_thai_text_numbers_booleans_dates_inline_and_formula(): void
    {
        $rows = SpreadsheetReader::rows($this->xlsx([
            ['รหัสนักเรียน', 'ชื่อ-สกุล', 'วันเกิด', 'คะแนน', 'ล่าม'],
            ['REG-1', ' สมชาย ทดสอบดี ', ['d' => $this->serial('2016-07-01')], 12.5, ['b' => true]],
            [['i' => 'REG-2'], 'มาลี & <ตัวอย่าง>', '2559-03-15', 0.1, ['b' => false]],
            ['REG-3', 'สมศักดิ์', ['d' => 0], ['f' => 'A1+1', 'v' => 7], 30000000000],
        ]));
        $this->assertSame(['รหัสนักเรียน', 'ชื่อ-สกุล', 'วันเกิด', 'คะแนน', 'ล่าม'], $rows[0]);
        $this->assertSame(['REG-1', 'สมชาย ทดสอบดี', '2016-07-01', '12.5', 'TRUE'], $rows[1]);
        $this->assertSame(['REG-2', 'มาลี & <ตัวอย่าง>', '2559-03-15', '0.1', 'FALSE'], $rows[2], 'Buddhist-year text is kept as is');
        $this->assertSame('7', $rows[3][3], 'formula cell uses the cached value');
        $this->assertSame('30000000000', $rows[3][4]);
        $this->assertSame('', $rows[3][2], 'date serial 0 is blank');
    }

    public function test_sparse_cells_and_blank_rows_keep_their_positions(): void
    {
        $rows = SpreadsheetReader::rows($this->xlsx([
            ['a', null, 'c'],
            '@5' => [2 => 'C5 value', 4 => 'E5'],
        ]));
        $this->assertCount(5, $rows);
        $this->assertSame(['a', '', 'c'], $rows[0], 'rows end at their last non-empty cell, like fgetcsv');
        $this->assertSame([], $rows[1]);
        $this->assertSame(['', '', 'C5 value', '', 'E5'], $rows[4]);
        $csv = SpreadsheetReader::toCsv($this->xlsx([['x', 'y'], '@4' => ['p', 'q,r']]));
        $this->assertSame("x,y\n\n\np,\"q,r\"\n", $csv, 'one CSV line per sheet row');
        $semi = SpreadsheetReader::toCsv($this->xlsx([['รหัส;เลขที่;ห้อง', 'ชื่อ']]));
        $this->assertSame("รหัส;เลขที่;ห้อง,ชื่อ\n", $semi);
    }

    public function test_named_sheet_and_first_sheet_default(): void
    {
        $bytes = $this->xlsx([], [], ['นักเรียน' => [['h'], ['one']], 'Other' => [['h2'], ['two']]]);
        $this->assertSame('one', SpreadsheetReader::rows($bytes)[1][0]);
        $this->assertSame('two', SpreadsheetReader::rows($bytes, 'other')[1][0]);
        $this->assertSame(422, $this->httpStatus(fn () => SpreadsheetReader::rows($bytes, 'missing')));
    }

    public function test_limits_and_non_xlsx_files_are_rejected(): void
    {
        $this->assertSame(422, $this->httpStatus(fn () => SpreadsheetReader::rows("a,b\n1,2\n")));
        $zip = tempnam(sys_get_temp_dir(), 'xt');
        $z = new ZipArchive(); $z->open($zip, ZipArchive::OVERWRITE); $z->addFromString('hello.txt', 'x'); $z->close();
        $plain = file_get_contents($zip); unlink($zip);
        $this->assertSame(422, $this->httpStatus(fn () => SpreadsheetReader::rows($plain)), 'a zip without a workbook');
        $this->assertSame(422, $this->httpStatus(fn () => SpreadsheetReader::rows("PK\x03\x04garbage")));
        $this->assertSame(422, $this->httpStatus(fn () => SpreadsheetReader::rows($this->xlsx(['@20001' => ['late']]))), 'too many rows');
        $this->assertSame(422, $this->httpStatus(fn () => SpreadsheetReader::rows($this->xlsx([array_fill(0, 201, 'x')]))), 'too many columns');
        // empty styled cells far to the right are not content
        $styled = SpreadsheetReader::rows($this->xlsx([[0 => 'ok', 250 => ['s' => 1], 251 => ['raw' => '<c r="IR1" s="1"/>']]]));
        $this->assertSame([['ok']], $styled, 'styled empty cells beyond column 200 are not content');
        $xxe = $this->xlsx([['a']], ['xl/sharedStrings.xml' => '<?xml version="1.0"?><!DOCTYPE sst [<!ENTITY x SYSTEM "file:///etc/passwd">]><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>&x;</t></si></sst>']);
        $this->assertSame(422, $this->httpStatus(fn () => SpreadsheetReader::rows($xxe)), 'DOCTYPE/entities are refused');
    }

    public function test_zip_bomb_is_rejected(): void
    {
        // high ratio: 3 MB of zeros deflates to a few KB
        $ratio = $this->xlsx([['a']], ['xl/media/bomb.bin' => str_repeat("\0", 3 * 1048576)]);
        $this->assertLessThan(100000, strlen($ratio));
        $this->assertSame(422, $this->httpStatus(fn () => SpreadsheetReader::rows($ratio)));
        // two entries each below the ratio threshold are fine
        $big = $this->xlsx([['a']], ['xl/media/a.bin' => str_repeat("\0", 1900000), 'xl/media/b.bin' => str_repeat("\0", 1900000)]);
        $this->assertSame('a', SpreadsheetReader::rows($big)[0][0], 'small entries below the guard thresholds are fine');
        // declared-size sum above 50 MB: a single 53 MB entry
        $huge = $this->xlsx([['a']], ['xl/media/a.bin' => str_repeat("\0", 53 * 1048576)]);
        $this->assertLessThan(200000, strlen($huge));
        $this->assertSame(422, $this->httpStatus(fn () => SpreadsheetReader::rows($huge)));
        // central directory that lies about the size: shared strings inflate to 53 MB but declare 1000 bytes
        $lie = $this->xlsx([['a']], ['xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.str_repeat(' ', 53 * 1048576).'</sst>']);
        $this->assertLessThan(200000, strlen($lie));
        $patched = $this->patchDeclaredSize($lie, 'xl/sharedStrings.xml', 1000);
        $this->assertNotSame($lie, $patched, 'central directory was patched');
        $this->assertSame(422, $this->httpStatus(fn () => SpreadsheetReader::rows($patched)));
    }

    private function patchDeclaredSize(string $zip, string $entry, int $size): string
    {
        $pos = 0;
        while (($pos = strpos($zip, "PK\x01\x02", $pos)) !== false) {
            $nameLen = unpack('v', substr($zip, $pos + 28, 2))[1];
            if (substr($zip, $pos + 46, $nameLen) === $entry) return substr_replace($zip, pack('V', $size), $pos + 24, 4);
            $pos += 4;
        }
        return $zip;
    }

    public function test_malformed_xml_is_422_not_partial_rows_or_500(): void
    {
        $sheet = fn (string $body) => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$body;
        $truncated = $this->xlsx([['a']], ['xl/worksheets/sheet1.xml' => $sheet('<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>b</t></is></c>')]);
        $mismatch = $this->xlsx([['a']], ['xl/worksheets/sheet1.xml' => $sheet('<row r="1"><c r="A1"><v>1</v></row></sheetData></worksheet>')]);
        $badShared = $this->xlsx([['a']], ['xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>x</si></sst>']);
        foreach ([$truncated, $mismatch, $badShared] as $bytes) {
            $this->assertSame(422, $this->httpStatus(fn () => SpreadsheetReader::rows($bytes)));
            $this->assertSame(422, $this->httpStatus(fn () => SpreadsheetReader::toCsv($bytes)));
        }
        config(['school.demo' => true]);
        $this->seed();
        $this->actingAs(User::where('role', 'admin')->first());
        foreach ([$truncated, $mismatch] as $bytes) {
            $f = UploadedFile::fake()->createWithContent('students.xlsx', $bytes);
            $this->post('/api/registry/import/batches', ['kind' => 'students', 'file' => $f], ['Accept' => 'application/json'])->assertStatus(422);
        }
    }

    public function test_zero_padded_formats_dates_1904_buddhist_formats_and_iso_dates(): void
    {
        $rows = SpreadsheetReader::rows($this->xlsx([[['z' => 123], ['id' => 1234567890123], ['th' => 42552], ['z' => 123456], 123]]));
        $this->assertSame('00123', $rows[0][0], 'format 00000 keeps leading zeros like a CSV export');
        $this->assertSame('1-2345-67890-12-3', $rows[0][1]);
        $this->assertSame('2016-07-01', $rows[0][2], 'Buddhist-era format token b is a date format');
        $this->assertSame('123456', $rows[0][3], 'longer than the mask keeps all digits');
        $this->assertSame('123', $rows[0][4]);
        $wb1904 = '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr date1904="1"/><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $r1904 = SpreadsheetReader::rows($this->xlsx([[['d' => 1], ['d' => 0]]], ['xl/workbook.xml' => $wb1904]));
        $this->assertSame(['1904-01-02'], [$r1904[0][0]], 'date1904 base');
        $this->assertSame('1904-01-01', SpreadsheetReader::rows($this->xlsx([[['d' => 0], 'x']], ['xl/workbook.xml' => $wb1904]))[0][0], 'serial 0 with date1904 is 1904-01-01');
        $iso = SpreadsheetReader::rows($this->xlsx([[['raw' => '<c r="A1" t="d"><v>2016-07-01T00:00:00</v></c>'], ['raw' => '<c r="B1" t="d"><v>2016-07-01T08:30:00</v></c>']]]));
        $this->assertSame('2016-07-01', $iso[0][0]);
        $this->assertSame('2016-07-01 08:30', $iso[0][1]);
    }

    public function test_cell_edge_cases_shared_without_v_inline_rph_and_strict_hidden_sheets(): void
    {
        $rows = SpreadsheetReader::rows($this->xlsx([[
            'HEADER0',
            ['raw' => '<c r="B1" t="s" s="3"/>'],
            ['raw' => '<c r="C1" t="inlineStr"><is><t>x</t><rPh sb="0" eb="1"><t>ignored</t></rPh></is></c>'],
            ['raw' => '<c r="D1" t="inlineStr"><is><t>y</t></is></c>'],
        ]]));
        $this->assertSame(['HEADER0', '', 'x', 'y'], $rows[0], 'empty shared-string cell is blank; rPh does not swallow the next cell');
        $strict = '<?xml version="1.0"?><workbook xmlns="http://purl.oclc.org/ooxml/spreadsheetml/main" xmlns:r="http://purl.oclc.org/ooxml/officeDocument/relationships"><sheets><sheet name="H" sheetId="1" state="hidden" r:id="rId1"/><sheet name="B" sheetId="2" r:id="rId2"/></sheets></workbook>';
        $bytes = $this->xlsx([], ['xl/workbook.xml' => $strict], ['A' => [['SHEET-A']], 'B' => [['SHEET-B']]]);
        $this->assertSame('SHEET-B', SpreadsheetReader::rows($bytes)[0][0], 'hidden first sheet is skipped by default');
        $this->assertSame('SHEET-B', SpreadsheetReader::rows($bytes, 'B')[0][0], 'strict namespace relationship ids resolve');
        $noRels = $this->xlsx([], ['xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>'], ['A' => [['SHEET-A']], 'B' => [['SHEET-B']]]);
        $this->assertSame(422, $this->httpStatus(fn () => SpreadsheetReader::rows($noRels, 'B')), 'named sheet never falls back to sheet1');
    }

    public function test_large_sheet_streams_with_bounded_memory_and_cell_budget(): void
    {
        $rows = [];
        for ($r = 0; $r < 2000; $r++) { $line = []; for ($c = 0; $c < 200; $c++) $line[$c] = $r * 1000 + $c + 1; $rows[] = $line; }
        $bytes = $this->xlsx($rows);
        unset($rows);
        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $csv = SpreadsheetReader::toCsv($bytes);
        $peak = memory_get_peak_usage() - $before;
        $this->assertSame(2000, substr_count($csv, "\n"));
        $this->assertLessThan(120 * 1048576, $peak, 'peak extra memory for 400k cells stays below 120 MB (was ~400 MB when fully buffered)');
        // above the cell budget
        $many = [];
        for ($r = 0; $r < 5100; $r++) $many[] = array_fill(0, 200, 1);
        $this->assertSame(422, $this->httpStatus(fn () => SpreadsheetReader::rows($this->xlsx($many))), 'more than 1,000,000 cells');
    }

    public function test_connectors_and_exam_checker_accept_xlsx(): void
    {
        config(['school.demo' => true]);
        $this->seed();
        $this->actingAs(User::where('role', 'admin')->first());
        $head = ['เลขประจำตัวนักเรียน', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'ชั้น', 'ห้อง', 'เลขที่', 'เพศ', 'วันเกิด', 'หมายเหตุ'];
        $x = $this->xlsx([$head, ['X-01', 'เด็กชาย', 'สมชาย', 'ใจดีสมมติ', 'ป.4', '1', '1', 'ชาย', ['d' => $this->serial('2015-05-12')], 'ทุน;เรียน']]);
        $p = $this->post('/api/connectors/mapping/preview', ['preset' => 'generic_th', 'file' => UploadedFile::fake()->createWithContent('legacy.xlsx', $x)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(1, $p['summary']['rows']);
        $this->assertSame('X-01', $p['sample'][0]['data']['code']);

        $exam = $this->postJson('/api/exam-checker/exams', ['title' => 'สอบทดสอบ', 'kind' => 'quiz', 'question_count' => 4, 'answer_key' => '1234'])->assertCreated()->json('exam');
        $this->putJson('/api/exam-checker/exams/'.$exam['id'], ['version' => $exam['version'], 'status' => 'open'])->assertOk();
        $sheet = $this->xlsx([['student_code', 'answers'], ['DEMO-01', 1234], ['DEMO-02', '1234']]);
        $r = $this->post('/api/exam-checker/exams/'.$exam['id'].'/import', ['file' => UploadedFile::fake()->createWithContent('answers.xlsx', $sheet), 'dry_run' => 1], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(2, count($r['preview']) + count($r['errors']), 'both xlsx rows were read');
    }

    public function test_registry_import_preview_from_xlsx(): void
    {
        config(['school.demo' => true]);
        $this->seed();
        $this->actingAs(User::where('role', 'admin')->first());
        $bytes = $this->xlsx([
            ['รหัสนักเรียน', 'คำนำหน้า', 'ชื่อ-สกุล', 'เพศ', 'วันเกิด', 'สถานะสัญชาติ', 'ห้องเรียน', 'ต้องการล่าม'],
            ['REG-902', 'เด็กชาย', 'สมชาย ทดสอบดี', 'ชาย', '15/03/2559', 'ไทย', 'ป.4/1', 'ไม่ใช่'],
            ['REG-903', 'เด็กหญิง', 'Mya Thu', 'หญิง', ['d' => $this->serial('2016-07-01')], 'ต่างชาติ', 'ป.5/1', 'ใช่'],
            [],
            ['REG-904', 'เด็กชาย', 'ไร้สัญชาติ ตัวอย่าง', 'ชาย', '2559-01-20', 'ไร้สัญชาติ', null, 'ไม่ใช่'],
        ]);
        $file = UploadedFile::fake()->createWithContent('students.xlsx', $bytes);
        $res = $this->post('/api/registry/import/batches', ['kind' => 'students', 'file' => $file], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->assertSame('preview', $res['batch']['status']);
        $this->assertSame(3, $res['batch']['summary']['rows']);
        $this->assertSame(0, $res['batch']['summary']['error']);
        $this->assertSame(3, $res['batch']['summary']['create']);
        $byCode = collect($res['rows'])->keyBy(fn ($r) => $r['data']['code']);
        $this->assertSame('2016-07-01', $byCode['REG-903']['data']['birth_date'], 'date serial becomes an ISO date');
        $this->assertSame('2016-03-15', $byCode['REG-902']['data']['birth_date']);
        $this->assertSame([2, 3, 5], collect($res['rows'])->pluck('row_no')->all(), 'row numbers match the Excel rows');
        $this->assertNull(DB::table('students')->where('code', 'REG-902')->first(), 'preview must not write');
        // a bad zip posted as an import is a 422, not a 500
        $bad = UploadedFile::fake()->createWithContent('bad.xlsx', "PK\x03\x04not really");
        $this->post('/api/registry/import/batches', ['kind' => 'students', 'file' => $bad], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
