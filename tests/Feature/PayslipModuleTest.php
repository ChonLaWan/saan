<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Saan\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Saan\Modules\Payslip\Links;
use Saan\Modules\Payslip\Notifications\PayslipNotification;
use Saan\Modules\Payslip\Payees;
use Saan\Modules\Payslip\Vault;
use Tests\TestCase;

/** F2 payslips (internal module "payslip"). Mock school: โรงเรียนต้นธารวิทยา; every person and 13-digit id below is invented (ids start with 0, so none can be real). */
class PayslipModuleTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;
    private string $disk = '';
    private const NID_A = '0123456789012'; // invented, check digit does not match
    private const NID_B = '0123456789013';
    private const NID_OK = '0000000000001'; // invented, check digit matches (prefix 0 is never issued)
    private const HEAD = ['รหัส', 'เงินเดือน', 'ค่าตำแหน่ง', 'หักกู้', 'ภาษี', 'ประกันสังคม', 'กองทุน', 'สุทธิ'];
    private const GOOD = ['T001', '15234.56', '3000.00', '1111.11', '500.50', '750.00', '300.00', '15572.95'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->disk = sys_get_temp_dir().DIRECTORY_SEPARATOR.'saan-payslip-'.bin2hex(random_bytes(6));
        mkdir($this->disk, 0777, true);
        config(['filesystems.disks.local.root' => $this->disk]);
        $this->seed();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->disk !== '' && is_dir($this->disk)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->disk, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            @rmdir($this->disk);
        }
    }

    // ---------------------------------------------------------------- helpers

    private function user(string $role, string $name = ''): User
    {
        $this->n++;
        return User::forceCreate(['name' => $name ?: 'ครูสมมติ '.$role.$this->n, 'email' => $role.$this->n.'@tonthan.test', 'password' => 'Original-Password-2026', 'role' => $role,
            'school_id' => 1, 'is_active' => true, 'is_demo' => false, 'auth_version' => 1]);
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($u);
    }

    private function officer(): User
    {
        $u = $this->user('teacher', 'เจ้าหน้าที่การเงินสมมติ');
        Settings::put('payslip.officer_user_ids', [$u->id]);
        $this->officerUser = $u;
        $this->as($u);
        return $u;
    }

    public ?User $officerUser = null;

    /** @return array{0:User,1:int} account and payee id (created by the officer) */
    private function payee(string $code, string $name = '', ?string $nid = null, ?string $bank = null, string $role = 'teacher'): array
    {
        $u = $this->user($role, $name);
        $me = $this->officerUser ?? $this->officer();
        $this->as($me);
        $body = ['user_id' => $u->id, 'payee_code' => $code];
        if ($nid) $body['national_id'] = $nid;
        if ($bank) $body += ['bank_name' => 'ธนาคารสมมติ', 'bank_account' => $bank];
        $id = $this->postJson('/api/payslip/payees', $body)->assertCreated()->json('id');
        return [$u, (int) $id];
    }

    private function period(int $year = 2026, int $month = 10, string $label = '', ?string $pay = null): int
    {
        return (int) $this->postJson('/api/payslip/periods', ['year' => $year, 'month' => $month, 'label' => $label, 'pay_date' => $pay ?? sprintf('%d-%02d-25', $year, $month)])->assertCreated()->json('id');
    }

    private function mapping(array $over = []): void
    {
        $m = array_merge(['key_type' => 'code', 'key_col' => 'รหัส', 'earnings' => [['col' => 'เงินเดือน', 'name' => 'เงินเดือน'], ['col' => 'ค่าตำแหน่ง', 'name' => 'ค่าตำแหน่ง']],
            'deductions' => [['col' => 'หักกู้', 'name' => 'หักเงินกู้']], 'tax_col' => 'ภาษี', 'sso_col' => 'ประกันสังคม', 'pf_col' => 'กองทุน', 'net_col' => 'สุทธิ'], $over);
        $this->putJson('/api/payslip/mapping', ['mapping' => $m])->assertOk();
    }

    private function csv(array $rows, ?array $head = null, string $name = 'payroll.csv'): UploadedFile
    {
        $h = fopen('php://temp', 'r+');
        foreach (array_merge([$head ?? self::HEAD], $rows) as $r) fputcsv($h, $r, ',', '"', '');
        rewind($h);
        return UploadedFile::fake()->createWithContent($name, "\xEF\xBB\xBF".stream_get_contents($h));
    }

    private function pdf(string $name, string $extra = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n".$extra."\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    private function stage(int $period, array $rows, ?array $head = null)
    {
        return $this->post('/api/payslip/periods/'.$period.'/imports', ['file' => $this->csv($rows, $head)], ['Accept' => 'application/json']);
    }

    /** Imports rows and confirms; returns the check view. */
    private function load(int $period, array $rows): array
    {
        $c = $this->stage($period, $rows)->assertCreated()->json();
        $this->postJson('/api/payslip/imports/'.$c['import_id'].'/confirm', ['version' => $c['version']])->assertOk();
        return $c;
    }

    private function slipIdOf(int $payee, int $period): int
    {
        return (int) DB::table('psl_slips')->where('payee_id', $payee)->where('period_id', $period)->where('status', 'active')->value('id');
    }

    // ---------------------------------------------------------------- permissions

    public function test_only_the_payroll_officer_reaches_officer_endpoints(): void
    {
        $officer = $this->officer();
        $period = $this->period();
        [$staff] = $this->payee('T001');
        $admin = $this->user('admin');
        $principal = $this->user('principal');
        $teacher = $this->user('teacher');
        foreach ([$teacher, $principal, $admin, $staff] as $u) {
            $this->as($u);
            $this->getJson('/api/payslip/payees')->assertForbidden();
            $this->getJson('/api/payslip/payees/candidates')->assertForbidden();
            $this->postJson('/api/payslip/payees', ['user_id' => $u->id, 'payee_code' => 'Z1'])->assertForbidden();
            $this->postJson('/api/payslip/periods', ['year' => 2026, 'month' => 9, 'pay_date' => '2026-09-25'])->assertForbidden();
            $this->getJson('/api/payslip/periods/'.$period)->assertForbidden();
            $this->getJson('/api/payslip/mapping')->assertForbidden();
            $this->putJson('/api/payslip/mapping', ['mapping' => []])->assertForbidden();
            $this->post('/api/payslip/periods/'.$period.'/imports', ['file' => $this->csv([self::GOOD])], ['Accept' => 'application/json'])->assertForbidden();
            $this->post('/api/payslip/periods/'.$period.'/pdfs', ['files' => [$this->pdf('T001.pdf')]], ['Accept' => 'application/json'])->assertForbidden();
            $this->postJson('/api/payslip/periods/'.$period.'/publish', ['version' => 1])->assertForbidden();
            $this->postJson('/api/payslip/periods/'.$period.'/cancel', ['version' => 1, 'reason' => 'ทดสอบ'])->assertForbidden();
            $this->postJson('/api/payslip/slips/1/void', ['version' => 1, 'reason' => 'ทดสอบ'])->assertForbidden();
            $this->postJson('/api/payslip/certificates/generate', ['year' => 2026])->assertForbidden();
            $this->getJson('/api/payslip/certificates/1/print')->assertForbidden();
        }
        // summaries: admin and principal yes (counts and totals), a plain teacher no
        $this->as($admin)->getJson('/api/payslip/periods')->assertOk();
        $this->as($principal)->getJson('/api/payslip/periods')->assertOk();
        $this->as($principal)->getJson('/api/payslip/certificates')->assertOk()->assertJsonPath('rows', null);
        $this->as($teacher)->getJson('/api/payslip/periods')->assertForbidden();
        $this->as($teacher)->getJson('/api/payslip/certificates')->assertForbidden();
        // the role alone never makes an officer, and the officer is whoever the admin listed
        $this->assertFalse(\Saan\Modules\Payslip\Support::isOfficer($admin));
        $this->as($officer)->getJson('/api/payslip/payees')->assertOk();
    }

    public function test_guests_are_refused(): void
    {
        $this->getJson('/api/payslip/me')->assertUnauthorized();
        $this->getJson('/api/payslip/my/slips')->assertUnauthorized();
        $this->getJson('/api/payslip/periods')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- payees and encryption

    public function test_payee_identifiers_are_encrypted_hashed_and_shown_masked(): void
    {
        $this->officer();
        [$u, $id] = $this->payee('T001', 'ครูสมชาย ต้นธาร', self::NID_A, '1234567890');
        $row = DB::table('psl_payees')->find($id);
        $this->assertStringStartsWith('enc1:', $row->nid_enc);
        $this->assertStringStartsWith('enc1:', $row->bank_enc);
        $this->assertStringNotContainsString(self::NID_A, json_encode($row));
        $this->assertStringNotContainsString('1234567890', json_encode($row));
        $this->assertNotSame(self::NID_A, $row->nid_hash);
        $this->assertSame(64, strlen($row->nid_hash));
        $this->assertSame(self::NID_A, Vault::open($row->nid_enc));
        $list = $this->getJson('/api/payslip/payees')->assertOk();
        $this->assertStringNotContainsString(self::NID_A, $list->getContent());
        $this->assertStringNotContainsString('1234567890', $list->getContent());
        $list->assertJsonPath('data.0.nid_masked', 'x-xxxx-xxxx9-01-2')->assertJsonPath('data.0.bank_masked', 'xxxxxx7890')->assertJsonPath('data.0.nid_warning', true);
        // the same national id cannot belong to two payees; the same code is a conflict
        $other = $this->user('teacher');
        $this->postJson('/api/payslip/payees', ['user_id' => $other->id, 'payee_code' => 'T002', 'national_id' => '0-1234-56789-01-2'])->assertUnprocessable();
        $this->postJson('/api/payslip/payees', ['user_id' => $other->id, 'payee_code' => 't001'])->assertConflict();
        $this->postJson('/api/payslip/payees', ['user_id' => $other->id, 'payee_code' => 'T002', 'national_id' => '12345'])->assertUnprocessable();
        // a checksum-valid id carries no warning; edit keeps the id when the field is left out
        [, $ok] = $this->payee('T003', 'ครูสมหญิง ต้นธาร', self::NID_OK);
        $this->assertFalse(collect($this->getJson('/api/payslip/payees')->json('data'))->firstWhere('id', $ok)['nid_warning']);
        $v = (int) DB::table('psl_payees')->where('id', $ok)->value('version');
        $this->putJson('/api/payslip/payees/'.$ok, ['version' => $v, 'payee_code' => 'T003X'])->assertOk()->assertJsonPath('nid_masked', 'x-xxxx-xxxx0-00-1');
        $this->putJson('/api/payslip/payees/'.$ok, ['version' => $v, 'payee_code' => 'T003Y'])->assertConflict();
        $this->assertSame(self::NID_OK, Payees::nid(Payees::find($ok)));
    }

    // ---------------------------------------------------------------- import: mapping and check screen

    public function test_mapping_is_validated_and_reused(): void
    {
        $this->officer();
        $this->assertNull($this->getJson('/api/payslip/mapping')->json('mapping'));
        $this->mapping();
        $this->getJson('/api/payslip/mapping')->assertOk()->assertJsonPath('mapping.net_col', 'สุทธิ')->assertJsonPath('mapping.earnings.1.col', 'ค่าตำแหน่ง');
        $bad = fn (array $o) => $this->putJson('/api/payslip/mapping', ['mapping' => array_merge($this->goodMapping(), $o)])->assertUnprocessable();
        $bad(['net_col' => 'เงินเดือน']);                 // one column cannot play two roles
        $bad(['earnings' => []]);                         // at least one earning column
        $bad(['key_type' => 'passport']);
        $bad(['net_col' => '']);
        $bad(['earnings' => [['col' => 'เงินเดือน'], ['col' => 'เงินเดือน']]]);
    }

    private function goodMapping(): array
    {
        return ['key_type' => 'code', 'key_col' => 'รหัส', 'earnings' => [['col' => 'เงินเดือน', 'name' => 'เงินเดือน']], 'deductions' => [], 'tax_col' => 'ภาษี', 'sso_col' => 'ประกันสังคม', 'pf_col' => 'กองทุน', 'net_col' => 'สุทธิ'];
    }

    public function test_check_screen_counts_totals_and_refuses_rows_that_do_not_add_up(): void
    {
        $this->officer();
        [, $a] = $this->payee('T001');
        [, $b] = $this->payee('T002');
        $this->payee('T003');
        $this->payee('T004');
        $this->payee('T005');
        $this->mapping();
        $period = $this->period();
        $rows = [
            self::GOOD,
            ['T002', '20000', '0', '0', '0', '750', '0', '19250.00'],
            ['T003', '10000', '0', '0', '0', '0', '0', '9999.99'],      // net != earnings - deductions
            ['T004', 'abc', '0', '0', '0', '0', '0', '100'],            // not a number
            ['X999', '10000', '0', '0', '0', '0', '0', '10000'],        // nobody has this code
            ['T005', '5000', '0', '0', '0', '0', '0', '5000'],
            ['t005', '5000', '0', '0', '0', '0', '0', '5000'],          // same payee twice (code compare ignores case)
            ['', '', '', '', '', '', '', ''],
        ];
        $c = $this->stage($period, $rows)->assertCreated()->json();
        $this->assertSame(['rows' => 7, 'matched' => 6, 'unmatched' => 1, 'duplicated' => 2, 'refused' => 2, 'accepted' => 2], $c['counts']);
        $this->assertSame(['earnings' => 3823456, 'deductions' => 341161, 'net' => 3482295], $c['totals']);
        $this->assertTrue($c['can_confirm']);
        $reasons = collect($c['problems'])->mapWithKeys(fn ($p) => [$p['line'] => $p['reason']])->all();
        $this->assertSame('mismatch', $reasons[4]);
        $this->assertSame('invalid', $reasons[5]);
        $this->assertSame('unmatched', $reasons[6]);
        $this->assertSame('duplicate_in_file', $reasons[7]);
        $this->assertSame('duplicate_in_file', $reasons[8]);
        // nothing is a slip before the officer confirms
        $this->assertSame(0, DB::table('psl_slips')->count());
        $this->assertSame(2, DB::table('psl_imports')->value('rows_ok'));
        $this->postJson('/api/payslip/imports/'.$c['import_id'].'/confirm', ['version' => $c['version']])->assertOk();
        $this->assertSame(2, DB::table('psl_slips')->where('period_id', $period)->count());
        $this->assertGreaterThan(0, $this->slipIdOf($a, $period));
        $this->assertGreaterThan(0, $this->slipIdOf($b, $period));
        // a second confirm, and a second import into a published period, are refused
        $this->postJson('/api/payslip/imports/'.$c['import_id'].'/confirm', ['version' => $c['version'] + 1])->assertUnprocessable();
        $p = DB::table('psl_periods')->find($period);
        $this->postJson('/api/payslip/periods/'.$period.'/publish', ['version' => $p->version])->assertOk();
        $this->stage($period, [self::GOOD])->assertUnprocessable();
        // totals of the period come from the slips
        $this->getJson('/api/payslip/periods')->assertJsonPath('data.0.totals.net', 3482295)->assertJsonPath('data.0.totals.gross', 3823456);
    }

    public function test_a_payee_that_already_has_a_slip_in_the_period_is_reported_as_duplicate(): void
    {
        $this->officer();
        $this->payee('T001');
        $this->mapping();
        $period = $this->period();
        $this->load($period, [self::GOOD]);
        $c = $this->stage($period, [self::GOOD])->assertCreated()->json();
        $this->assertSame(1, $c['counts']['duplicated']);
        $this->assertSame('duplicate_existing', $c['problems'][0]['reason']);
        $this->assertFalse($c['can_confirm']);
    }

    public function test_import_matches_by_national_id_without_storing_or_echoing_it(): void
    {
        $this->officer();
        $this->payee('T001', '', self::NID_A);
        $this->mapping(['key_type' => 'national_id', 'key_col' => 'เลขประจำตัว']);
        $period = $this->period();
        $head = array_merge(['เลขประจำตัว'], array_slice(self::HEAD, 1));
        $rows = [array_merge(['0-1234-56789-01-2'], array_slice(self::GOOD, 1)), array_merge([self::NID_B], array_slice(self::GOOD, 1))];
        $res = $this->stage($period, $rows, $head)->assertCreated();
        $c = $res->json();
        $this->assertSame(1, $c['counts']['accepted']);
        $this->assertSame(1, $c['counts']['unmatched']);
        $this->assertSame('x-xxxx-xxxx9-01-3', $c['problems'][0]['key']);
        foreach ([self::NID_A, self::NID_B, '0-1234-56789-01-2'] as $needle) $this->assertStringNotContainsString($needle, $res->getContent());
        $this->assertStringNotContainsString(self::NID_B, json_encode(DB::table('psl_imports')->get()));
        $this->postJson('/api/payslip/imports/'.$c['import_id'].'/confirm', ['version' => $c['version']])->assertOk();
        $this->assertStringNotContainsString(self::NID_A, json_encode(DB::table('psl_slips')->get()).json_encode(DB::table('psl_periods')->get()).json_encode(DB::table('psl_imports')->get()));
    }

    public function test_import_rejects_a_file_without_the_mapped_columns_and_non_text_files(): void
    {
        $this->officer();
        $this->payee('T001');
        $this->mapping();
        $period = $this->period();
        $this->stage($period, [['T001', '1']], ['รหัส', 'อื่น'])->assertUnprocessable()->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'เงินเดือน'));
        $bin = UploadedFile::fake()->createWithContent('payroll.csv', "\xFF\xFE\x00\x01 binary \x00");
        $this->post('/api/payslip/periods/'.$period.'/imports', ['file' => $bin], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/payslip/periods/'.$period.'/imports', ['file' => UploadedFile::fake()->createWithContent('payroll.csv', '')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/payslip/periods/'.$period.'/imports', [], ['Accept' => 'application/json'])->assertUnprocessable();
        // no mapping saved yet in a fresh school setting -> asked to set it first
        DB::table('saan_settings')->where('key', 'payslip.import_map')->delete();
        $this->stage($period, [self::GOOD])->assertUnprocessable();
    }

    public function test_xlsx_files_are_read_too(): void
    {
        $this->officer();
        $this->payee('T001');
        $this->mapping();
        $period = $this->period();
        $zip = tempnam(sys_get_temp_dir(), 'psl').'.xlsx';
        $z = new \ZipArchive();
        $z->open($zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $cell = fn (string $ref, string $v) => '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars($v).'</t></is></c>';
        $row = fn (int $n, array $vals) => '<row r="'.$n.'">'.implode('', array_map(fn ($i, $v) => $cell(chr(65 + $i).$n, $v), array_keys($vals), $vals)).'</row>';
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$row(1, self::HEAD).$row(2, self::GOOD).'</sheetData></worksheet>');
        $z->close();
        $file = new UploadedFile($zip, 'payroll.xlsx', null, null, true);
        $c = $this->post('/api/payslip/periods/'.$period.'/imports', ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json();
        @unlink($zip);
        $this->assertSame(1, $c['counts']['accepted']);
        $this->assertSame(1557295, $c['totals']['net']);
    }

    // ---------------------------------------------------------------- amounts are encrypted at rest

    public function test_amounts_bank_and_identifiers_are_not_readable_in_the_database(): void
    {
        $this->officer();
        [, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร', self::NID_A, '9876543210');
        $this->mapping();
        $period = $this->period();
        $this->load($period, [self::GOOD]);
        $this->pdfUploadCheck($period);
        $dump = '';
        foreach (['psl_payees', 'psl_periods', 'psl_slips', 'psl_imports', 'psl_certs'] as $t) $dump .= json_encode(DB::table($t)->get(), JSON_UNESCAPED_UNICODE);
        foreach (['15234.56', '1523456', '15,234.56', '15572.95', '1557295', '3000.00', '300000', '1111.11', '500.50', '9876543210', self::NID_A, 'เงินเดือน', 'หักเงินกู้'] as $needle) {
            $this->assertStringNotContainsString($needle, $dump, $needle);
        }
        $slip = DB::table('psl_slips')->where('payee_id', $a)->where('kind', 'data')->first();
        $this->assertStringStartsWith('enc1:', $slip->data_enc);
        $data = Vault::openJson($slip->data_enc);
        $this->assertSame(1557295, $data['net']);
        $this->assertSame(1823456, $data['gross']);
        $this->assertSame('xxxxxx3210', $data['bank']);
        $this->assertStringStartsWith('enc1:', DB::table('psl_periods')->where('id', $period)->value('totals_enc'));
        // a value that does not decrypt (wrong key, tampering) reads as nothing, never as somebody else's data
        $this->assertNull(Vault::open('enc1:garbage'));
        $this->assertNull(Vault::open('15234.56'));
    }

    private function pdfUploadCheck(int $period): void
    {
        $this->payee('T002');
        $this->post('/api/payslip/periods/'.$period.'/pdfs', ['files' => [$this->pdf('T002.pdf', '% marker-PSL-PDF-BYTES')]], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('stored', 1);
        $path = DB::table('psl_slips')->where('kind', 'pdf')->value('file_path');
        $raw = Storage::disk('local')->get($path);
        $this->assertStringNotContainsString('%PDF', $raw);
        $this->assertStringNotContainsString('marker-PSL-PDF-BYTES', $raw);
    }

    // ---------------------------------------------------------------- PDF upload

    public function test_pdf_upload_matches_by_file_name_and_sniffs_the_content(): void
    {
        $this->officer();
        [, $a] = $this->payee('T001');
        [, $b] = $this->payee('T002');
        $period = $this->period();
        $files = [
            $this->pdf('T001.pdf'),
            $this->pdf('T002_ตุลาคม.pdf'),                                                     // code before an underscore
            UploadedFile::fake()->createWithContent('T003.pdf', 'this is a text file renamed to .pdf'), // not a PDF
            $this->pdf('Z999.pdf'),                                                            // nobody has this code
            UploadedFile::fake()->createWithContent('T001dup.pdf', "%PDF-1.4\n%%EOF"),         // name not a code and no separator: never a partial token
            $this->pdf('t001.pdf'),                                                            // same payee again
            UploadedFile::fake()->createWithContent('T002.pdf', "%PDF-1.4\n/JavaScript (app.alert(1))\n%%EOF"), // active content
            UploadedFile::fake()->createWithContent('T001.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')), // an image
        ];
        $r = $this->post('/api/payslip/periods/'.$period.'/pdfs', ['files' => $files], ['Accept' => 'application/json'])->assertCreated();
        $res = collect($r->json('files'))->mapWithKeys(fn ($f) => [$f['name'] => $f['result']])->all();
        $this->assertSame('stored', $res['T001.pdf']);
        $this->assertSame('stored', $res['T002_ตุลาคม.pdf']);
        $this->assertSame('refused', $res['T003.pdf']);
        $this->assertSame('unmatched', $res['Z999.pdf']);
        $this->assertSame('unmatched', $res['T001dup.pdf']);
        $this->assertSame('duplicate', $res['t001.pdf']);
        $this->assertSame('refused', $res['T002.pdf']);
        $this->assertSame('refused', $res['T001.png']);
        $this->assertSame(2, $r->json('stored'));
        $this->assertSame(2, DB::table('psl_slips')->where('kind', 'pdf')->count());
        $this->assertGreaterThan(0, $this->slipIdOf($a, $period));
        // too big is refused, and a non-upload is a validation error
        config(['saan.storage.max_upload_mb' => 1]);
        $big = UploadedFile::fake()->createWithContent('T002.pdf', "%PDF-1.4\n".str_repeat('x', 1048577)."\n%%EOF");
        $this->post('/api/payslip/periods/'.$period.'/pdfs', ['files' => [$big]], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('files.0.result', 'refused');
        $this->post('/api/payslip/periods/'.$period.'/pdfs', [], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame($b, (int) DB::table('psl_slips')->where('kind', 'pdf')->orderBy('id')->skip(1)->value('payee_id'));
    }

    public function test_uploaded_pdf_is_served_to_its_owner_as_an_attachment_with_safe_headers(): void
    {
        $this->officer();
        [$u, $a] = $this->payee('T001');
        $period = $this->period();
        $body = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% unique-owner-bytes\n%%EOF\n";
        $this->post('/api/payslip/periods/'.$period.'/pdfs', ['files' => [UploadedFile::fake()->createWithContent('T001.pdf', $body)]], ['Accept' => 'application/json'])->assertCreated();
        $slip = $this->slipIdOf($a, $period);
        $v = DB::table('psl_periods')->where('id', $period)->value('version');
        $this->postJson('/api/payslip/periods/'.$period.'/publish', ['version' => $v])->assertOk();
        $this->as($u);
        $r = $this->get('/api/payslip/my/slips/'.$slip.'/download')->assertOk();
        $this->assertSame($body, $r->getContent());
        $this->assertStringStartsWith('attachment;', $r->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $r->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('T001', (string) $r->headers->get('Content-Disposition')); // generated name, never the client's
        // a PDF slip has no print view, a data slip has no download
        $this->get('/api/payslip/my/slips/'.$slip.'/print')->assertNotFound();
    }

    // ---------------------------------------------------------------- period lifecycle

    public function test_a_period_can_be_cancelled_before_publish_and_not_after(): void
    {
        $this->officer();
        [$u, $a] = $this->payee('T001');
        $this->mapping();
        $draft = $this->period(2026, 9);
        $this->load($draft, [self::GOOD]);
        $this->post('/api/payslip/periods/'.$draft.'/pdfs', ['files' => [$this->pdf('T001.pdf')]], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('files.0.result', 'duplicate');
        $other = $this->period(2026, 8);
        $this->post('/api/payslip/periods/'.$other.'/pdfs', ['files' => [$this->pdf('T001.pdf')]], ['Accept' => 'application/json'])->assertCreated();
        $path = DB::table('psl_slips')->where('period_id', $other)->value('file_path');
        $this->assertTrue(Storage::disk('local')->exists($path));
        $v = DB::table('psl_periods')->where('id', $other)->value('version');
        $this->postJson('/api/payslip/periods/'.$other.'/cancel', ['version' => $v, 'reason' => 'x'])->assertUnprocessable();       // reason too short
        $this->postJson('/api/payslip/periods/'.$other.'/cancel', ['version' => $v, 'reason' => 'อัปโหลดผิดเดือน'])->assertOk()->assertJsonPath('period.status', 'cancelled');
        $this->assertSame(0, DB::table('psl_slips')->where('period_id', $other)->count());
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertSame('อัปโหลดผิดเดือน', DB::table('psl_periods')->where('id', $other)->value('cancel_reason'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'payslip.period.cancelled')->count());
        // nothing more can be done in a cancelled period
        $this->stage($other, [self::GOOD])->assertUnprocessable();
        $this->postJson('/api/payslip/periods/'.$other.'/publish', ['version' => $v + 1])->assertUnprocessable();
        // publish, then the whole period can no longer be cancelled
        $dv = DB::table('psl_periods')->where('id', $draft)->value('version');
        $this->postJson('/api/payslip/periods/'.$draft.'/publish', ['version' => $dv])->assertOk()->assertJsonPath('period.status', 'published');
        $this->postJson('/api/payslip/periods/'.$draft.'/cancel', ['version' => $dv + 1, 'reason' => 'ขอยกเลิกทั้งงวด'])->assertUnprocessable();
        $this->assertSame('published', DB::table('psl_periods')->where('id', $draft)->value('status'));
        // an empty period cannot be published
        $empty = $this->period(2026, 7);
        $this->postJson('/api/payslip/periods/'.$empty.'/publish', ['version' => 1])->assertUnprocessable();
        // a month can have a second period only under another name
        $this->postJson('/api/payslip/periods', ['year' => 2026, 'month' => 7, 'label' => '', 'pay_date' => '2026-07-25'])->assertConflict();
        $this->postJson('/api/payslip/periods', ['year' => 2026, 'month' => 7, 'label' => 'โบนัส', 'pay_date' => '2026-07-30'])->assertCreated();
        $this->postJson('/api/payslip/periods', ['year' => 2026, 'month' => 2, 'label' => '', 'pay_date' => '2026-02-31'])->assertUnprocessable();
    }

    public function test_draft_slips_are_invisible_to_recipients_until_publish(): void
    {
        $this->officer();
        [$u, $a] = $this->payee('T001');
        $this->mapping();
        $period = $this->period();
        $this->load($period, [self::GOOD]);
        $slip = $this->slipIdOf($a, $period);
        $this->as($u);
        $this->getJson('/api/payslip/my/slips')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/payslip/my/slips/'.$slip)->assertNotFound();
        $this->as($this->officerUser);
        $v = DB::table('psl_periods')->where('id', $period)->value('version');
        $this->postJson('/api/payslip/periods/'.$period.'/publish', ['version' => $v])->assertOk();
        $this->as($u);
        $this->getJson('/api/payslip/my/slips')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $slip);
    }

    public function test_an_officer_cannot_replace_or_void_a_slip_of_their_own_account(): void
    {
        $me = $this->officer();
        [, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร');
        $this->mapping();
        $period = $this->period();
        $this->load($period, [self::GOOD]);
        DB::table('psl_payees')->where('id', $a)->update(['user_id' => $me->id]); // the payee row is the officer's own (linked earlier by another officer)
        $slip = $this->slipIdOf($a, $period);
        $sv = (int) DB::table('psl_slips')->where('id', $slip)->value('version');
        $body = ['version' => $sv, 'reason' => 'แก้ยอดของตัวเอง', 'earnings' => [['name' => 'เงินเดือน', 'amount' => '99999']], 'net' => '99999'];
        $this->postJson('/api/payslip/slips/'.$slip.'/replace', $body)->assertForbidden();
        $this->postJson('/api/payslip/slips/'.$slip.'/void', ['version' => $sv, 'reason' => 'ยกเลิกของตัวเอง'])->assertForbidden();
        $this->assertSame('active', DB::table('psl_slips')->where('id', $slip)->value('status'));
        $this->assertSame(1, DB::table('psl_slips')->where('payee_id', $a)->count(), 'no replacement row was written');
    }

    public function test_a_published_slip_is_voided_with_a_reason_and_replaced(): void
    {
        $this->officer();
        [$u, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร');
        $this->mapping();
        $period = $this->period();
        $this->load($period, [self::GOOD]);
        $pv = DB::table('psl_periods')->where('id', $period)->value('version');
        $this->postJson('/api/payslip/periods/'.$period.'/publish', ['version' => $pv])->assertOk();
        $old = $this->slipIdOf($a, $period);
        $sv = DB::table('psl_slips')->where('id', $old)->value('version');
        // replace with amounts that do not add up -> refused, the old slip stays
        $new = ['version' => $sv, 'reason' => 'แก้ยอดหักกู้', 'earnings' => [['name' => 'เงินเดือน', 'amount' => '15,234.56'], ['name' => 'ค่าตำแหน่ง', 'amount' => '3000']],
            'deductions' => [['name' => 'หักเงินกู้', 'amount' => '2,000.00']], 'tax' => '500.50', 'sso' => '750', 'pf' => '300', 'net' => '14,684.06'];
        $this->postJson('/api/payslip/slips/'.$old.'/replace', array_merge($new, ['net' => '1.00']))->assertUnprocessable();
        $this->assertSame('active', DB::table('psl_slips')->where('id', $old)->value('status'));
        // a reason is mandatory
        $this->postJson('/api/payslip/slips/'.$old.'/replace', array_merge($new, ['reason' => '']))->assertUnprocessable();
        $res = $this->postJson('/api/payslip/slips/'.$old.'/replace', $new)->assertCreated();
        $newId = (int) $res->json('id');
        $this->assertSame($old, (int) $res->json('replaces_id'));
        $this->assertSame('voided', DB::table('psl_slips')->where('id', $old)->value('status'));
        $this->assertSame('แก้ยอดหักกู้', DB::table('psl_slips')->where('id', $old)->value('void_reason'));
        $this->assertNull(DB::table('psl_slips')->where('id', $old)->value('active_key'));
        $this->assertSame(1468406, Vault::openJson(DB::table('psl_slips')->where('id', $newId)->value('data_enc'))['net']);
        $audit = DB::table('audit_events')->where('action', 'payslip.slip.voided')->first();
        $this->assertSame((int) $old, (int) $audit->entity_id);
        $this->assertStringContainsString('แก้ยอดหักกู้', $audit->detail);
        // the recipient sees only the replacement; the voided id is gone
        $this->as($u);
        $this->getJson('/api/payslip/my/slips')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $newId);
        $this->getJson('/api/payslip/my/slips/'.$old)->assertNotFound();
        $this->getJson('/api/payslip/my/slips/'.$newId)->assertOk()->assertJsonPath('data.net', 1468406);
        // period totals follow
        $this->as($this->officerUser);
        $this->getJson('/api/payslip/periods')->assertJsonPath('data.0.totals.net', 1468406)->assertJsonPath('data.0.voided', 1);
        // void without replacement; double void refused; stale version conflicts
        $nv = DB::table('psl_slips')->where('id', $newId)->value('version');
        $this->postJson('/api/payslip/slips/'.$newId.'/void', ['version' => $nv + 5, 'reason' => 'ผิดคน'])->assertConflict();
        $this->postJson('/api/payslip/slips/'.$newId.'/void', ['version' => $nv, 'reason' => 'ผิดคน'])->assertOk()->assertJsonPath('status', 'voided');
        $this->postJson('/api/payslip/slips/'.$newId.'/void', ['version' => $nv + 1, 'reason' => 'ผิดคน'])->assertUnprocessable();
        $this->postJson('/api/payslip/slips/'.$newId.'/void', ['version' => $nv, 'reason' => 'x'])->assertUnprocessable();
    }

    public function test_a_pdf_slip_can_be_replaced_by_another_pdf(): void
    {
        $this->officer();
        [$u, $a] = $this->payee('T001');
        $period = $this->period();
        $this->post('/api/payslip/periods/'.$period.'/pdfs', ['files' => [$this->pdf('T001.pdf', '% first')]], ['Accept' => 'application/json'])->assertCreated();
        $pv = DB::table('psl_periods')->where('id', $period)->value('version');
        $this->postJson('/api/payslip/periods/'.$period.'/publish', ['version' => $pv])->assertOk();
        $old = $this->slipIdOf($a, $period);
        $oldPath = DB::table('psl_slips')->where('id', $old)->value('file_path');
        $sv = DB::table('psl_slips')->where('id', $old)->value('version');
        $this->post('/api/payslip/slips/'.$old.'/replace', ['version' => $sv, 'reason' => 'ไฟล์ผิด', 'file' => UploadedFile::fake()->createWithContent('x.txt', 'not a pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame('active', DB::table('psl_slips')->where('id', $old)->value('status'));
        $this->post('/api/payslip/slips/'.$old.'/replace', ['version' => $sv, 'reason' => 'ไฟล์ผิด', 'file' => $this->pdf('x.pdf', '% second')], ['Accept' => 'application/json'])->assertCreated();
        $this->assertFalse(Storage::disk('local')->exists($oldPath));
        $this->assertSame(0, (int) DB::table('psl_slips')->where('id', $old)->value('file_size'), 'a deleted file no longer counts against the storage quota');
        $new = $this->slipIdOf($a, $period);
        $this->assertNotSame($old, $new);
        $this->as($u);
        $this->assertStringContainsString('% second', $this->get('/api/payslip/my/slips/'.$new.'/download')->assertOk()->getContent());
    }

    // ---------------------------------------------------------------- recipient view and IDOR

    private function twoPublished(): array
    {
        $this->officer();
        [$ua, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร', self::NID_A, '1112223334');
        [$ub, $b] = $this->payee('T002', 'ครูสมหญิง ต้นธาร', self::NID_B);
        $this->mapping();
        $p1 = $this->period(2026, 9);
        $this->load($p1, [self::GOOD, ['T002', '20000', '0', '0', '0', '750', '0', '19250.00']]);
        $p2 = $this->period(2026, 10);
        $this->post('/api/payslip/periods/'.$p2.'/pdfs', ['files' => [$this->pdf('T001.pdf'), $this->pdf('T002.pdf')]], ['Accept' => 'application/json'])->assertCreated();
        foreach ([$p1, $p2] as $p) $this->postJson('/api/payslip/periods/'.$p.'/publish', ['version' => DB::table('psl_periods')->where('id', $p)->value('version')])->assertOk();
        return [$ua, $a, $ub, $b, $p1, $p2];
    }

    public function test_a_recipient_sees_only_their_own_slips_on_every_endpoint(): void
    {
        [$ua, $a, $ub, $b, $p1, $p2] = $this->twoPublished();
        $aData = $this->slipIdOf($a, $p1); $aPdf = $this->slipIdOf($a, $p2);
        $bData = $this->slipIdOf($b, $p1); $bPdf = $this->slipIdOf($b, $p2);
        $this->as($ua);
        $list = $this->getJson('/api/payslip/my/slips')->assertOk();
        $this->assertSame([$aPdf, $aData], array_column($list->json('data'), 'id'));
        $this->assertStringNotContainsString('15,234.56', $list->getContent()); // the list carries no amount
        $this->getJson('/api/payslip/my/slips/'.$aData)->assertOk()->assertJsonPath('data.net', 1557295);
        $this->get('/api/payslip/my/slips/'.$aData.'/print')->assertOk();
        $this->get('/api/payslip/my/slips/'.$aPdf.'/download')->assertOk();
        // every endpoint, every id of the other person (and ids that do not exist): the same 404
        foreach ([$bData, $bPdf, 99999] as $foreign) {
            $this->getJson('/api/payslip/my/slips/'.$foreign)->assertNotFound();
            $this->get('/api/payslip/my/slips/'.$foreign.'/print')->assertNotFound();
            $this->get('/api/payslip/my/slips/'.$foreign.'/download')->assertNotFound();
        }
        $this->as($ub);
        foreach ([$aData, $aPdf] as $foreign) {
            $this->getJson('/api/payslip/my/slips/'.$foreign)->assertNotFound();
            $this->get('/api/payslip/my/slips/'.$foreign.'/print')->assertNotFound();
            $this->get('/api/payslip/my/slips/'.$foreign.'/download')->assertNotFound();
        }
        $this->getJson('/api/payslip/my/slips/'.$bData)->assertOk()->assertJsonPath('data.net', 1925000);
        // an account that was never linked to a payee has nothing, on every endpoint
        $stranger = $this->user('teacher');
        $this->as($stranger);
        $this->getJson('/api/payslip/me')->assertOk()->assertJsonPath('has_payee', false);
        foreach ([$aData, $bData] as $id) {
            $this->getJson('/api/payslip/my/slips/'.$id)->assertNotFound();
            $this->get('/api/payslip/my/slips/'.$id.'/print')->assertNotFound();
        }
        $this->getJson('/api/payslip/my/slips')->assertNotFound();
        $this->getJson('/api/payslip/my/certificates')->assertNotFound();
        // the officer reads other people's rows only through officer screens and cannot open a recipient view of someone else
        $this->as($this->officerUser);
        $this->getJson('/api/payslip/my/slips/'.$aData)->assertNotFound();
    }

    public function test_voided_slips_and_other_schools_never_open(): void
    {
        [$ua, $a, , $b, $p1] = $this->twoPublished();
        $slip = $this->slipIdOf($a, $p1);
        // same ids but another school: a payee row of school 2 must not be reachable
        $foreign = DB::table('psl_slips')->where('id', $slip)->first();
        DB::table('psl_periods')->where('id', $p1)->update(['school_id' => 2]);
        $this->as($ua)->getJson('/api/payslip/my/slips/'.$slip)->assertNotFound();
        DB::table('psl_periods')->where('id', $p1)->update(['school_id' => 1]);
        DB::table('psl_slips')->where('id', $slip)->update(['status' => 'voided']);
        $this->getJson('/api/payslip/my/slips/'.$slip)->assertNotFound();
        $this->assertNotNull($foreign);
    }

    public function test_every_open_is_audited_without_amounts(): void
    {
        [$ua, $a, , , $p1, $p2] = $this->twoPublished();
        $data = $this->slipIdOf($a, $p1); $pdf = $this->slipIdOf($a, $p2);
        DB::table('audit_events')->delete();
        $this->as($ua);
        $this->getJson('/api/payslip/my/slips/'.$data)->assertOk();
        $this->get('/api/payslip/my/slips/'.$data.'/print')->assertOk();
        $this->get('/api/payslip/my/slips/'.$pdf.'/download')->assertOk();
        $rows = DB::table('audit_events')->where('action', 'payslip.open')->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['view', 'print', 'download'], $rows->map(fn ($r) => json_decode($r->detail, true)['via'])->all());
        $this->assertSame([$data, $data, $pdf], $rows->map(fn ($r) => (int) $r->entity_id)->all());
        foreach ($rows as $r) {
            $this->assertSame((int) $ua->id, (int) $r->actor_id);
            $this->assertSame('psl_slips', $r->entity);
            $this->assertNotNull($r->created_at);
            foreach (['15234', '1523456', '15572', '1557295', '3000', '1111', '500', '750', '300', '1112223334', 'เงินเดือน'] as $needle) $this->assertStringNotContainsString($needle, $r->detail.$r->entity, $needle);
        }
        // a refused open writes nothing
        $before = DB::table('audit_events')->count();
        $this->getJson('/api/payslip/my/slips/99999')->assertNotFound();
        $this->assertSame($before, DB::table('audit_events')->count());
        // across the whole life cycle no audit detail ever carries an amount
        foreach (DB::table('audit_events')->where('action', 'like', 'payslip.%')->get() as $r) $this->assertDoesNotMatchRegularExpression('/\d{1,3}(,\d{3})+\.\d{2}|\d+\.\d{2}\b/', (string) $r->detail);
    }

    public function test_print_view_is_watermarked_with_recipient_and_time(): void
    {
        [$ua, $a, , , $p1] = $this->twoPublished();
        $slip = $this->slipIdOf($a, $p1);
        $this->as($ua);
        $html = $this->get('/api/payslip/my/slips/'.$slip.'/print')->assertOk()->getContent();
        $this->assertGreaterThanOrEqual(9, substr_count($html, 'ครูสมชาย ต้นธาร &middot;'));
        $this->assertMatchesRegularExpression('/\d{2}:\d{2}:\d{2}/', $html);
        $this->assertStringContainsString('class="wm"', $html);
        $this->assertStringContainsString('15,572.95', $html);
        $this->assertStringContainsString('size:A5', $html);
        $this->assertStringContainsString('xxxxxx3334', $html);       // bank account masked on the slip
        $this->assertStringNotContainsString('1112223334', $html);
        $r = $this->get('/api/payslip/my/slips/'.$slip.'/print');
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
    }

    // ---------------------------------------------------------------- 50 tawi

    public function test_50_tawi_totals_equal_the_sum_of_the_year_slips_and_recipients_see_only_their_own(): void
    {
        $this->officer();
        [$ua, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร', self::NID_A);
        [$ub, $b] = $this->payee('T002', 'ครูสมหญิง ต้นธาร', self::NID_B);
        $this->mapping();
        $months = [[2026, 1], [2026, 2], [2026, 3]];
        $sum = ['gross' => 0, 'tax' => 0, 'sso' => 0, 'pf' => 0];
        foreach ($months as $i => [$y, $m]) {
            $p = $this->period($y, $m);
            $sal = 20000 + $i * 1000;
            $rows = [['T001', (string) $sal, '0', '0', '500.50', '750.00', '300.00', number_format($sal - 500.50 - 750 - 300, 2, '.', '')], ['T002', '12000', '0', '0', '0', '600.00', '0', '11400.00']];
            $this->load($p, $rows);
            $this->postJson('/api/payslip/periods/'.$p.'/publish', ['version' => DB::table('psl_periods')->where('id', $p)->value('version')])->assertOk();
            $sum['gross'] += $sal * 100; $sum['tax'] += 50050; $sum['sso'] += 75000; $sum['pf'] += 30000;
        }
        // not counted: another year and a draft period (a PDF slip blocks the certificate instead: see the pdf_partial test)
        $old = $this->period(2025, 12); $this->load($old, [self::GOOD]);
        $this->postJson('/api/payslip/periods/'.$old.'/publish', ['version' => DB::table('psl_periods')->where('id', $old)->value('version')])->assertOk();
        $draft = $this->period(2026, 11); $this->load($draft, [self::GOOD]);

        $gen = $this->postJson('/api/payslip/certificates/generate', ['year' => 2026])->assertCreated();
        $this->assertSame(['issued' => 2, 'updated' => 0, 'unchanged' => 0, 'voided' => 0], $gen->json('result'));
        $this->assertSame(1, DB::table('psl_certs')->where('payee_id', $a)->where('year', 2026)->count());
        $cert = DB::table('psl_certs')->where('payee_id', $a)->where('year', 2026)->first();
        $this->assertStringStartsWith('enc1:', $cert->data_enc);
        $data = Vault::openJson($cert->data_enc);
        $this->assertSame($sum, array_intersect_key($data, $sum));
        $this->assertSame(3, $data['slips']);
        $this->assertSame(63000 * 100, $data['gross']);
        // summary: the officer gets rows, totals are the sum of both people
        $s = $this->getJson('/api/payslip/certificates?year=2026')->assertOk();
        $this->assertSame(2, $s->json('summary.issued'));
        $this->assertSame($sum['gross'] + 3 * 1200000, $s->json('summary.gross'));
        $this->assertSame($sum['tax'], $s->json('summary.tax'));
        $this->assertCount(2, $s->json('rows'));
        $this->assertSame(0, $s->json('summary.stale'));
        // generating again changes nothing
        $this->assertSame(['issued' => 0, 'updated' => 0, 'unchanged' => 2, 'voided' => 0], $this->postJson('/api/payslip/certificates/generate', ['year' => 2026])->assertCreated()->json('result'));
        // recipient: own certificate only, masked id on screen, full id and payer data on the printed copy
        $this->as($ua);
        $mine = $this->getJson('/api/payslip/my/certificates')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(2569, $mine['year_be']);
        $view = $this->getJson('/api/payslip/my/certificates/'.$mine['id'])->assertOk();
        $view->assertJsonPath('income_type', '40(1)')->assertJsonPath('gross', $sum['gross'])->assertJsonPath('tax', $sum['tax'])->assertJsonPath('sso', $sum['sso'])->assertJsonPath('pf', $sum['pf']);
        $this->assertSame('x-xxxx-xxxx9-01-2', $view->json('payee.national_id'));
        $this->assertStringNotContainsString(self::NID_A, $view->getContent());
        $html = $this->get('/api/payslip/my/certificates/'.$mine['id'].'/print')->assertOk()->getContent();
        $this->assertStringContainsString('0-1234-56789-01-2', $html);
        $this->assertStringContainsString('63,000.00', $html);
        $this->assertStringContainsString('1,501.50', $html);
        $this->assertStringContainsString('ครูสมชาย ต้นธาร', $html);
        $this->assertStringContainsString('class="wm"', $html);
        // the other person's certificate: 404 on view and print; nothing for a stranger
        $bCert = (int) DB::table('psl_certs')->where('payee_id', $b)->value('id');
        $this->getJson('/api/payslip/my/certificates/'.$bCert)->assertNotFound();
        $this->get('/api/payslip/my/certificates/'.$bCert.'/print')->assertNotFound();
        $this->as($ub)->getJson('/api/payslip/my/certificates/'.$mine['id'])->assertNotFound();
        $this->as($this->user('teacher'))->getJson('/api/payslip/my/certificates/'.$mine['id'])->assertNotFound();
        $this->assertSame(2, DB::table('audit_events')->where('action', 'payslip.cert.open')->count());
        $this->assertStringNotContainsString('630000', (string) DB::table('audit_events')->where('action', 'payslip.cert.open')->pluck('detail')->implode(''));
        // the officer prints the full id for anybody; a summary reader (principal) cannot
        $this->as($this->officerUser)->get('/api/payslip/certificates/'.$bCert.'/print')->assertOk()->assertSee('0-1234-56789-01-3');
        $this->as($this->user('principal'))->get('/api/payslip/certificates/'.$bCert.'/print')->assertForbidden();
        $pr = $this->as($this->user('principal'))->getJson('/api/payslip/certificates?year=2026')->assertOk();
        $this->assertNull($pr->json('rows'));
        $this->assertStringNotContainsString('ครูสมชาย', $pr->getContent());
        // a slip voided afterwards makes the certificate stale until it is generated again
        $this->as($this->officerUser);
        $slip = $this->slipIdOf($a, DB::table('psl_periods')->where('year', 2026)->where('month', 3)->value('id'));
        $this->postJson('/api/payslip/slips/'.$slip.'/void', ['version' => DB::table('psl_slips')->where('id', $slip)->value('version'), 'reason' => 'จ่ายผิดงวด'])->assertOk();
        $this->assertSame(1, $this->getJson('/api/payslip/certificates?year=2026')->json('summary.stale'));
        $this->assertSame(['issued' => 0, 'updated' => 1, 'unchanged' => 1, 'voided' => 0], $this->postJson('/api/payslip/certificates/generate', ['year' => 2026])->json('result'));
        $this->assertSame(2, Vault::openJson(DB::table('psl_certs')->where('id', $cert->id)->value('data_enc'))['slips']);
    }

    public function test_50_tawi_uses_payer_data_from_settings(): void
    {
        $this->officer();
        [$ua, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร', self::NID_OK);
        $this->mapping();
        $p = $this->period(2026, 1); $this->load($p, [self::GOOD]);
        $this->postJson('/api/payslip/periods/'.$p.'/publish', ['version' => DB::table('psl_periods')->where('id', $p)->value('version')])->assertOk();
        $this->postJson('/api/payslip/certificates/generate', ['year' => 2026])->assertCreated();
        $admin = $this->user('admin');
        $this->as($admin);
        foreach ([['payslip.payer_name', 'โรงเรียนต้นธารวิทยา'], ['payslip.payer_tax_id', '0994000000001'], ['payslip.payer_address', '99 หมู่ 1 ตำบลต้นธาร อำเภอสมมติ']] as [$k, $v]) {
            $this->putJson('/api/module-settings', ['key' => $k, 'value' => $v, 'version' => 0])->assertOk();
        }
        $this->putJson('/api/module-settings', ['key' => 'payslip.payer_tax_id', 'value' => '12345', 'version' => 1])->assertUnprocessable(); // 13 digits only
        $cert = (int) DB::table('psl_certs')->value('id');
        $html = $this->as($ua)->get('/api/payslip/my/certificates/'.$cert.'/print')->assertOk()->getContent();
        $this->assertStringContainsString('โรงเรียนต้นธารวิทยา', $html);
        $this->assertStringContainsString('0994000000001', $html);
        $this->assertStringContainsString('99 หมู่ 1 ตำบลต้นธาร', $html);
        $this->assertStringContainsString('0-0000-00000-00-1', $html);
    }

    // ---------------------------------------------------------------- central service links

    public function test_central_service_links_render_from_settings_and_refuse_non_https(): void
    {
        $admin = $this->user('admin');
        $teacher = $this->user('teacher');
        $this->as($teacher);
        $this->assertSame([], $this->getJson('/api/payslip/me')->assertOk()->json('links'));
        $this->assertStringNotContainsString('บริการบุคลากร', $this->get('/work')->assertOk()->getContent());
        $this->as($admin);
        $set = fn (string $k, string $v, int $ver = 0) => $this->putJson('/api/module-settings', ['key' => $k, 'value' => $v, 'version' => $ver]);
        $set('payslip.link_pension_url', 'https://pension.example.go.th/home?lang=th')->assertOk();
        $set('payslip.link_pension_label', 'ตรวจสอบบำนาญ')->assertOk();
        $set('payslip.link_salary_url', 'https://salary.example.go.th/')->assertOk();
        // http, javascript:, data:, credentials and blanks inside are refused at save time
        foreach (['http://pension.example.go.th/', 'javascript:alert(1)', 'data:text/html,x', 'ftp://x.example.org', 'https://', 'https://a b.example.org', "https://x.example.org/\"onmouseover=1"] as $bad) {
            $set('payslip.link_pension_url', $bad, 1)->assertUnprocessable();
        }
        $this->as($teacher);
        $links = $this->getJson('/api/payslip/me')->assertOk()->json('links');
        $this->assertSame([['key' => 'pension', 'label' => 'ตรวจสอบบำนาญ', 'url' => 'https://pension.example.go.th/home?lang=th'], ['key' => 'salary', 'label' => 'บริการเงินเดือนข้าราชการ (ระบบส่วนกลาง)', 'url' => 'https://salary.example.go.th/']], $links);
        $work = $this->get('/work')->assertOk()->getContent();
        $this->assertStringContainsString('https:\/\/pension.example.go.th\/home?lang=th', $work);
        $this->assertStringContainsString('"services"', $work);
        // a value that got into the database some other way (an old import, a manual edit) is still never rendered unless it is a clean https URL
        foreach (['http://evil.example.org/', 'javascript:alert(1)', 'https://user:pass@x.example.org/', "https://x.example.org/<script>", 'HTTPS://ok.example.org/'] as $bad) {
            Settings::put('payslip.link_pension_url', $bad);
            $urls = array_column(Links::all(), 'url');
            $this->assertNotContains($bad, $urls, $bad);
        }
        Settings::put('payslip.link_pension_url', '');
        $this->assertSame(['salary'], array_column(Links::all(), 'key'));
        $this->assertNull(Links::safeUrl('https://127.0.0.1/')); // a bare IP address is not accepted: central services have host names
    }

    // ---------------------------------------------------------------- mail notice

    public function test_publish_can_notify_by_mail_without_amounts(): void
    {
        Notification::fake();
        $this->officer();
        [$ua, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร');
        $this->payee('T002', 'ครูสมหญิง ต้นธาร');
        $this->mapping();
        $p = $this->period(2026, 10);
        $this->load($p, [self::GOOD]);
        $v = DB::table('psl_periods')->where('id', $p)->value('version');
        $this->postJson('/api/payslip/periods/'.$p.'/publish', ['version' => $v, 'notify' => true])->assertOk()->assertJsonPath('mails', 1);
        Notification::assertSentTo($ua, PayslipNotification::class, function (PayslipNotification $n) use ($ua) {
            $mail = $n->toMail($ua);
            $html = (string) $mail->render();
            $this->assertStringContainsString('ตุลาคม 2569', $html);
            $this->assertStringContainsString('/work#payslip', $html);
            foreach (['15,234', '15572', '1,557', '3,000', 'บาท'] as $needle) $this->assertStringNotContainsString($needle, $html, $needle);
            $this->assertStringStartsWith(rtrim((string) config('app.url'), '/'), $mail->actionUrl);
            return true;
        });
        Notification::assertCount(1); // T002 has no slip: no mail
        // without the flag nothing is sent
        $p2 = $this->period(2026, 11); $this->load($p2, [self::GOOD]);
        $this->postJson('/api/payslip/periods/'.$p2.'/publish', ['version' => DB::table('psl_periods')->where('id', $p2)->value('version')])->assertOk()->assertJsonPath('mails', 0);
        Notification::assertCount(1);
    }

    // ---------------------------------------------------------------- retention

    public function test_retention_prune_removes_old_periods_files_and_certificates(): void
    {
        $this->officer();
        [$ua, $a] = $this->payee('T001');
        $this->mapping();
        $oldPeriod = $this->period(2014, 5, '', '2014-05-31');
        $this->load($oldPeriod, [self::GOOD]);
        $oldPdf = $this->period(2013, 6, '', '2013-06-30');
        $this->post('/api/payslip/periods/'.$oldPdf.'/pdfs', ['files' => [$this->pdf('T001.pdf')]], ['Accept' => 'application/json'])->assertCreated();
        $path = DB::table('psl_slips')->where('period_id', $oldPdf)->value('file_path');
        $recent = $this->period(2026, 10);
        $this->load($recent, [self::GOOD]);
        foreach ([$oldPeriod, $oldPdf, $recent] as $p) $this->postJson('/api/payslip/periods/'.$p.'/publish', ['version' => DB::table('psl_periods')->where('id', $p)->value('version')])->assertOk();
        $this->postJson('/api/payslip/certificates/generate', ['year' => 2014])->assertCreated();
        $this->postJson('/api/payslip/certificates/generate', ['year' => 2026])->assertCreated();
        DB::table('psl_imports')->insert(['school_id' => 1, 'period_id' => $recent, 'created_by' => 1, 'status' => 'staged', 'payload_enc' => 'x', 'file_sha256' => str_repeat('a', 64), 'version' => 1, 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)]);
        DB::table('psl_imports')->insert(['school_id' => 1, 'period_id' => $recent, 'created_by' => 1, 'status' => 'confirmed', 'payload_enc' => 'x', 'file_sha256' => str_repeat('b', 64), 'version' => 1, 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)]);
        $this->assertSame(2, DB::table('psl_certs')->count());

        // dry run reports, deletes nothing
        $this->artisan('saan:payslip-prune', ['--dry-run' => true])->expectsOutputToContain('(dry-run)')->assertSuccessful();
        $this->assertSame(3, DB::table('psl_periods')->count());
        $this->assertTrue(Storage::disk('local')->exists($path));
        // retention 0 keeps every period
        Settings::put('payslip.retention_years', 0);
        $this->artisan('saan:payslip-prune')->assertSuccessful();
        $this->assertSame(3, DB::table('psl_periods')->count());
        $this->assertSame(0, DB::table('psl_imports')->where('status', 'staged')->count(), 'a stale staged import is dropped');
        $this->assertSame(1, DB::table('psl_imports')->where('status', 'confirmed')->where('created_at', '<', now()->subDays(2))->count(), 'an old CONFIRMED import is the record of what was imported and stays');
        // default (10 years): the two 2014 periods go with their slips, files and the 2014 certificate
        Settings::put('payslip.retention_years', 10);
        $this->artisan('saan:payslip-prune')->expectsOutputToContain('periods=2')->assertSuccessful();
        $this->assertSame([$recent], DB::table('psl_periods')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(1, DB::table('psl_slips')->count());
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertSame([2026], DB::table('psl_certs')->pluck('year')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(1, DB::table('psl_payees')->count(), 'payees are never pruned');
        // --years overrides the setting
        $this->artisan('saan:payslip-prune', ['--years' => 1])->assertSuccessful();
        $this->assertSame(1, DB::table('psl_periods')->count());
        $this->artisan('saan:payslip-prune', ['--years' => 0])->assertSuccessful();
        $this->assertSame(1, DB::table('psl_periods')->count());
        // the other school's data is not touched
        DB::table('psl_periods')->insert(['school_id' => 2, 'year' => 2000, 'month' => 1, 'label' => '', 'pay_date' => '2000-01-31', 'status' => 'published', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('saan:payslip-prune')->assertSuccessful();
        $this->assertSame(1, DB::table('psl_periods')->where('school_id', 2)->count());
    }

    public function test_deleting_a_staff_account_does_not_delete_slips(): void
    {
        $this->officer();
        [$ua, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร');
        $this->mapping();
        $p = $this->period(2026, 10);
        $this->load($p, [self::GOOD]);
        $this->postJson('/api/payslip/periods/'.$p.'/publish', ['version' => DB::table('psl_periods')->where('id', $p)->value('version')])->assertOk();
        $this->postJson('/api/payslip/certificates/generate', ['year' => 2026])->assertCreated();
        DB::table('users')->where('id', $ua->id)->delete();
        $this->assertSame(1, DB::table('psl_slips')->count());
        $this->assertSame(1, DB::table('psl_certs')->count());
        $this->assertSame(1, DB::table('psl_payees')->count());
        $row = collect($this->as($this->officerUser)->getJson('/api/payslip/payees')->json('data'))->first();
        $this->assertSame('ครูสมชาย ต้นธาร', $row['name']);
        $this->assertSame(1, $this->getJson('/api/payslip/periods/'.$p)->assertOk()->json('period.slips'));
    }

    // ---------------------------------------------------------------- nothing reaches the public side, no shared dashboard

    public function test_payroll_tables_are_never_exportable_and_the_module_is_internal(): void
    {
        foreach (['psl_payees', 'psl_periods', 'psl_slips', 'psl_imports', 'psl_certs'] as $t) {
            $this->assertTrue(\App\Support\Saan\Projection::isForbiddenTable($t), $t);
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable($t), $t);
        }
        $json = json_decode((string) file_get_contents(base_path('modules/payslip/module.json')), true);
        $this->assertSame('internal', $json['edition']);
        $this->assertArrayNotHasKey('public_projection', $json);
        $this->assertFileDoesNotExist(base_path('modules/payslip/routes/public.php'));
        $mods = app(\App\Support\Modules\ModuleRegistry::class)->all();
        $this->assertSame('internal', $mods['payslip']['edition']);
        foreach (\App\Support\Saan\Projection::declared(false, true) as $e) $this->assertStringStartsNotWith('psl_', $e['table']);
        // none of its routes is allowed on the public edition
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) if (str_starts_with($route->uri(), 'api/payslip')) $this->assertTrue(\App\Support\Saan\Edition::blockedInPublic('/'.$route->uri()), $route->uri());
    }

    public function test_payroll_data_is_in_no_shared_dashboard(): void
    {
        $this->officer();
        [$ua, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร', self::NID_A);
        $this->mapping();
        $p = $this->period(2026, 10);
        $this->load($p, [self::GOOD]);
        $this->postJson('/api/payslip/periods/'.$p.'/publish', ['version' => DB::table('psl_periods')->where('id', $p)->value('version')])->assertOk();
        foreach ([$this->officerUser, $this->user('admin'), $this->user('principal'), $ua] as $u) {
            $this->as($u);
            foreach (['/api/state', '/api/operations/catalogue', '/work'] as $url) {
                $body = $this->get($url, ['Accept' => 'application/json'])->getContent();
                foreach (['15,234.56', '1523456', '15572.95', '1557295', self::NID_A] as $needle) $this->assertStringNotContainsString($needle, (string) $body, $url.' '.$needle);
            }
        }
    }

    public function test_idempotency_key_replays_the_confirm_without_duplicating_slips(): void
    {
        $this->officer();
        $this->payee('T001');
        $this->mapping();
        $p = $this->period();
        $c = $this->stage($p, [self::GOOD])->assertCreated()->json();
        $h = ['Idempotency-Key' => 'psl-confirm-1'];
        $one = $this->postJson('/api/payslip/imports/'.$c['import_id'].'/confirm', ['version' => $c['version']], $h)->assertOk();
        $two = $this->postJson('/api/payslip/imports/'.$c['import_id'].'/confirm', ['version' => $c['version']], $h)->assertOk();
        $two->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($one->json('counts'), $two->json('counts'));
        $this->assertSame(1, DB::table('psl_slips')->count());
        $this->assertSame(1, (int) DB::table('audit_events')->where('action', 'payslip.import.confirmed')->count());
    }

    // ---------------------------------------------------------------- review fixes (stale certificate, idempotency cache, pay_date year, PDF months, prune, rebind, audit)

    private function publishedPeriod(int $year, int $month, ?string $pay = null, array $rows = [self::GOOD]): int
    {
        $p = $this->period($year, $month, '', $pay);
        $this->load($p, $rows);
        $this->postJson('/api/payslip/periods/'.$p.'/publish', ['version' => DB::table('psl_periods')->where('id', $p)->value('version')])->assertOk();
        return $p;
    }

    private function voidSlipOf(int $payee, int $period, string $reason = 'จ่ายผิดงวด'): void
    {
        $s = $this->slipIdOf($payee, $period);
        $this->postJson('/api/payslip/slips/'.$s.'/void', ['version' => DB::table('psl_slips')->where('id', $s)->value('version'), 'reason' => $reason])->assertOk();
    }

    public function test_a_stale_certificate_cannot_be_opened_or_printed_by_the_recipient_or_the_officer(): void
    {
        $this->officer();
        [$u, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร', self::NID_A);
        $this->mapping();
        $this->publishedPeriod(2026, 1);
        $p2 = $this->publishedPeriod(2026, 2);
        $this->postJson('/api/payslip/certificates/generate', ['year' => 2026])->assertCreated();
        $certId = (int) DB::table('psl_certs')->where('payee_id', $a)->value('id');
        $this->as($u);
        $this->get('/api/payslip/my/certificates/'.$certId.'/print')->assertOk()->assertSee('36,469.12');
        // a slip voided after generation: the old two-month figure must not open anywhere
        $this->as($this->officerUser);
        $this->voidSlipOf($a, $p2);
        $this->as($u);
        $this->getJson('/api/payslip/my/certificates')->assertJsonCount(0, 'data');
        $this->assertSame([], $this->getJson('/api/payslip/me')->json('years'));
        $this->getJson('/api/payslip/my/certificates/'.$certId)->assertStatus(409);
        $r = $this->get('/api/payslip/my/certificates/'.$certId.'/print')->assertStatus(409);
        $this->assertStringNotContainsString('36,469.12', $r->getContent());
        $this->as($this->officerUser);
        $this->get('/api/payslip/certificates/'.$certId.'/print')->assertStatus(409);
        // regenerated: only the remaining month
        $this->postJson('/api/payslip/certificates/generate', ['year' => 2026])->assertCreated();
        $this->as($u);
        $html = $this->get('/api/payslip/my/certificates/'.$certId.'/print')->assertOk()->getContent();
        $this->assertStringContainsString('18,234.56', $html);
        $this->assertStringNotContainsString('36,469.12', $html);
        // a period published after generation also makes it stale until generated again
        $this->as($this->officerUser);
        $this->publishedPeriod(2026, 12);
        $this->as($u);
        $this->get('/api/payslip/my/certificates/'.$certId.'/print')->assertStatus(409);
        $this->as($this->officerUser)->postJson('/api/payslip/certificates/generate', ['year' => 2026])->assertCreated();
        $this->as($u)->get('/api/payslip/my/certificates/'.$certId.'/print')->assertOk()->assertSee('36,469.12');
    }

    public function test_a_withdrawn_certificate_is_not_printed_by_anybody(): void
    {
        $this->officer();
        [$u, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร', self::NID_A);
        $this->mapping();
        $p = $this->publishedPeriod(2026, 1);
        $this->postJson('/api/payslip/certificates/generate', ['year' => 2026])->assertCreated();
        $certId = (int) DB::table('psl_certs')->where('payee_id', $a)->value('id');
        $this->voidSlipOf($a, $p);
        $this->assertSame(1, $this->postJson('/api/payslip/certificates/generate', ['year' => 2026])->json('result.voided'));
        $this->assertSame('void', DB::table('psl_certs')->where('id', $certId)->value('status'));
        $this->get('/api/payslip/certificates/'.$certId.'/print')->assertStatus(422);
        $this->as($u)->get('/api/payslip/my/certificates/'.$certId.'/print')->assertNotFound();
    }

    public function test_the_certificate_year_is_the_year_the_money_was_paid(): void
    {
        $this->officer();
        [$u, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร', self::NID_A);
        $this->mapping();
        $this->publishedPeriod(2026, 11);
        $this->publishedPeriod(2026, 12, '2027-01-05'); // December salary paid in January
        $s26 = $this->getJson('/api/payslip/certificates?year=2026')->assertOk();
        $this->assertSame(1, $s26->json('rows.0.slips'));
        $this->assertSame(1823456, $s26->json('summary.gross'));
        $s27 = $this->getJson('/api/payslip/certificates?year=2027')->assertOk();
        $this->assertSame(1, $s27->json('rows.0.slips'));
        $this->assertSame(1823456, $s27->json('summary.gross'));
        $this->assertSame([2027, 2026], $s27->json('years'));
        $this->assertSame(['issued' => 1, 'updated' => 0, 'unchanged' => 0, 'voided' => 0], $this->postJson('/api/payslip/certificates/generate', ['year' => 2027])->json('result'));
        $this->assertSame(1, Vault::openJson(DB::table('psl_certs')->where('year', 2027)->value('data_enc'))['slips']);
    }

    public function test_a_payee_with_a_pdf_slip_in_the_year_gets_no_certificate_and_the_summary_says_so(): void
    {
        $this->officer();
        [$u, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร', self::NID_A);
        [$ub, $b] = $this->payee('T002', 'ครูสมหญิง ต้นธาร', self::NID_B);
        $this->mapping();
        $this->publishedPeriod(2026, 7, null, [self::GOOD, ['T002', '12000', '0', '0', '0', '600.00', '0', '11400.00']]);
        $this->postJson('/api/payslip/certificates/generate', ['year' => 2026])->assertCreated();
        $certA = (int) DB::table('psl_certs')->where('payee_id', $a)->value('id');
        $this->as($u)->get('/api/payslip/my/certificates/'.$certA.'/print')->assertOk();
        // January to June of T001 came as a PDF
        $this->as($this->officerUser);
        $pdf = $this->period(2026, 6);
        $this->post('/api/payslip/periods/'.$pdf.'/pdfs', ['files' => [$this->pdf('T001.pdf')]], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson('/api/payslip/periods/'.$pdf.'/publish', ['version' => DB::table('psl_periods')->where('id', $pdf)->value('version')])->assertOk();
        $s = $this->getJson('/api/payslip/certificates?year=2026')->assertOk();
        $this->assertSame(1, $s->json('summary.pdf_partial'));
        $this->assertSame(0, $s->json('summary.stale'));
        $row = collect($s->json('rows'))->firstWhere('payee_id', $a);
        $this->assertSame('pdf_partial', $row['status']);
        $this->assertSame(1, $row['pdf_slips']);
        // the old certificate no longer opens, generate withdraws it and never certifies T001 again
        $this->as($u)->get('/api/payslip/my/certificates/'.$certA.'/print')->assertStatus(409);
        $this->as($this->officerUser);
        $this->assertSame(['issued' => 0, 'updated' => 0, 'unchanged' => 1, 'voided' => 1], $this->postJson('/api/payslip/certificates/generate', ['year' => 2026])->json('result'));
        $this->assertSame('void', DB::table('psl_certs')->where('payee_id', $a)->value('status'));
        $this->assertSame('issued', DB::table('psl_certs')->where('payee_id', $b)->value('status'));
    }

    public function test_void_replace_and_pdf_upload_keep_amounts_and_file_names_out_of_the_idempotency_cache(): void
    {
        $this->officer();
        [$u, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร');
        [$ub, $b] = $this->payee('T002', 'ครูสมหญิง ต้นธาร');
        $this->mapping();
        $p = $this->publishedPeriod(2026, 10, null, [self::GOOD, ['T002', '12000', '0', '0', '0', '600.00', '0', '11400.00']]);
        $name = fn (string $path, string $key) => 'idem:'.sha1((int) $this->officerUser->id.'|POST|'.$path.'|'.(int) config('school.school_id').'|'.$key);
        $assertNoPlain = function (string $path, string $key) use ($name) {
            $hit = Cache::get($name($path, $key));
            $this->assertNotNull($hit);
            $this->assertTrue($hit['rebuild']);
            $this->assertSame('', $hit['body']);
            $this->assertStringNotContainsString('1823456', json_encode($hit));
        };
        // void
        $sa = $this->slipIdOf($a, $p);
        $va = DB::table('psl_slips')->where('id', $sa)->value('version');
        $h = ['Idempotency-Key' => 'psl-void-1'];
        $one = $this->postJson('/api/payslip/slips/'.$sa.'/void', ['version' => $va, 'reason' => 'ผิดคน'], $h)->assertOk();
        $assertNoPlain('api/payslip/slips/'.$sa.'/void', 'psl-void-1');
        $two = $this->postJson('/api/payslip/slips/'.$sa.'/void', ['version' => $va, 'reason' => 'ผิดคน'], $h)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame('voided', $two->json('status'));
        $this->assertSame($one->json('version'), $two->json('version'));
        // replace
        $sb = $this->slipIdOf($b, $p);
        $vb = DB::table('psl_slips')->where('id', $sb)->value('version');
        $body = ['version' => $vb, 'reason' => 'แก้ยอด', 'earnings' => [['name' => 'เงินเดือน', 'amount' => '12000']], 'deductions' => [], 'tax' => '0', 'sso' => '600', 'pf' => '0', 'net' => '11400'];
        $h = ['Idempotency-Key' => 'psl-repl-1'];
        $one = $this->postJson('/api/payslip/slips/'.$sb.'/replace', $body, $h)->assertCreated();
        $assertNoPlain('api/payslip/slips/'.$sb.'/replace', 'psl-repl-1');
        $two = $this->postJson('/api/payslip/slips/'.$sb.'/replace', $body, $h)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($one->json('id'), $two->json('id'));
        $this->assertSame($sb, (int) $two->json('replaces_id'));
        $this->assertSame(3, DB::table('psl_slips')->count());
        // pdf upload: the file name (a person's name) is not cached
        $d = $this->period(2026, 11);
        $files = ['files' => [$this->pdf('T001.pdf')]];
        $h = ['Accept' => 'application/json', 'Idempotency-Key' => 'psl-pdf-1'];
        $this->post('/api/payslip/periods/'.$d.'/pdfs', $files, $h)->assertCreated();
        $hit = Cache::get($name('api/payslip/periods/'.$d.'/pdfs', 'psl-pdf-1'));
        $this->assertTrue($hit['rebuild']);
        $this->assertStringNotContainsString('T001', json_encode($hit));
        $this->post('/api/payslip/periods/'.$d.'/pdfs', $files, $h)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('stored', 1);
        $this->assertSame(1, DB::table('psl_slips')->where('period_id', $d)->count());
    }

    public function test_a_payee_can_be_relinked_to_a_new_account_only_when_the_old_one_is_gone_and_the_change_is_audited(): void
    {
        $this->officer();
        [$u, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร', self::NID_A, '1112223334');
        [$other, $o] = $this->payee('T002', 'ครูสมหญิง ต้นธาร');
        $this->mapping();
        $p = $this->publishedPeriod(2026, 10);
        $new = $this->user('teacher', 'ครูสมชาย ต้นธาร (บัญชีใหม่)');
        $ver = fn () => (int) DB::table('psl_payees')->where('id', $a)->value('version');
        // the old account is still active: refused
        $this->putJson('/api/payslip/payees/'.$a, ['version' => $ver(), 'user_id' => $new->id])->assertUnprocessable();
        // the new account must not have a payee already
        DB::table('users')->where('id', $u->id)->delete();
        $this->putJson('/api/payslip/payees/'.$a, ['version' => $ver(), 'user_id' => $other->id])->assertUnprocessable();
        $this->putJson('/api/payslip/payees/'.$a, ['version' => $ver(), 'user_id' => 999999])->assertUnprocessable();
        // the old account is gone: relink works, history follows
        $this->putJson('/api/payslip/payees/'.$a, ['version' => $ver(), 'user_id' => $new->id])->assertOk()->assertJsonPath('user_id', $new->id)->assertJsonPath('name', 'ครูสมชาย ต้นธาร (บัญชีใหม่)');
        $audit = DB::table('audit_events')->where('action', 'payslip.payee.updated')->orderByDesc('id')->first();
        $d = json_decode($audit->detail, true);
        $this->assertSame($u->id, $d['from_user_id']);
        $this->assertSame($new->id, $d['to_user_id']);
        $this->as($new);
        $slips = $this->getJson('/api/payslip/my/slips')->assertOk()->assertJsonCount(1, 'data')->json('data');
        $this->getJson('/api/payslip/my/slips/'.$slips[0]['id'])->assertOk();
        // an inactive old account also counts as gone
        $this->as($this->officerUser);
        $third = $this->user('teacher', 'ครูสมชาย ต้นธาร (บัญชีสาม)');
        DB::table('users')->where('id', $new->id)->update(['is_active' => false]);
        $this->putJson('/api/payslip/payees/'.$a, ['version' => $ver(), 'user_id' => $third->id])->assertOk()->assertJsonPath('user_id', $third->id);
    }

    public function test_payee_edit_audit_names_the_fields_not_the_values_and_an_officer_cannot_edit_their_own_payee(): void
    {
        $this->officer();
        [$u, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร', self::NID_A, '1112223334');
        $ver = fn (int $id) => (int) DB::table('psl_payees')->where('id', $id)->value('version');
        $this->putJson('/api/payslip/payees/'.$a, ['version' => $ver($a), 'bank_account' => '9998887776', 'national_id' => self::NID_B])->assertOk();
        $detail = (string) DB::table('audit_events')->where('action', 'payslip.payee.updated')->orderByDesc('id')->value('detail');
        $this->assertEqualsCanonicalizing(['bank_account', 'national_id'], json_decode($detail, true)['fields']);
        $this->assertStringNotContainsString('9998887776', $detail);
        $this->assertStringNotContainsString(self::NID_B, $detail);
        $this->putJson('/api/payslip/payees/'.$a, ['version' => $ver($a), 'payee_code' => 'T001B'])->assertOk();
        $this->assertSame(['payee_code'], json_decode((string) DB::table('audit_events')->where('action', 'payslip.payee.updated')->orderByDesc('id')->value('detail'), true)['fields']);
        // the officer's own payee row: only another officer may change it
        $first = $this->officerUser;
        $this->postJson('/api/payslip/payees', ['user_id' => $first->id, 'payee_code' => 'OFF1', 'bank_account' => '1234567890'])->assertForbidden(); // not even creating it (store used to skip the rule)
        $second = $this->user('teacher', 'เจ้าหน้าที่การเงินสำรอง');
        Settings::put('payslip.officer_user_ids', [$first->id, $second->id]);
        $mine = (int) $this->as($second)->postJson('/api/payslip/payees', ['user_id' => $first->id, 'payee_code' => 'OFF1', 'bank_account' => '1234567890'])->assertCreated()->json('id');
        $this->as($first)->putJson('/api/payslip/payees/'.$mine, ['version' => $ver($mine), 'bank_account' => '5554443332'])->assertForbidden();
        // re-linking someone else's payee (old account switched off) to the officer's own account is refused too
        [$gone, $goneId] = $this->payee('T900', 'ครูที่ลาออก (สมมติ)', self::NID_A, '7778889990');
        DB::table('users')->where('id', $gone->id)->update(['is_active' => false]);
        $this->as($first)->putJson('/api/payslip/payees/'.$goneId, ['version' => $ver($goneId), 'user_id' => $first->id])->assertForbidden();
        $this->assertSame($gone->id, (int) DB::table('psl_payees')->where('id', $goneId)->value('user_id'));
        $this->as($second)->putJson('/api/payslip/payees/'.$mine, ['version' => $ver($mine), 'bank_account' => '5554443332'])->assertOk();
    }

    // ---------------------------------------------------------------- security audit fixes (2026-11-13)

    public function test_an_officer_cannot_make_their_own_slip_through_import_or_pdf_upload(): void
    {
        $first = $this->officer();
        $second = $this->user('teacher', 'เจ้าหน้าที่การเงินสำรอง');
        Settings::put('payslip.officer_user_ids', [$first->id, $second->id]);
        [$u, $other] = $this->payee('T001', 'ครูสมชาย ต้นธาร', self::NID_A);
        $mine = (int) $this->as($second)->postJson('/api/payslip/payees', ['user_id' => $first->id, 'payee_code' => 'OFF1'])->assertCreated()->json('id');
        $this->as($first);
        $this->mapping();
        $period = $this->period();
        $own = self::GOOD; $own[0] = 'OFF1';
        $r = $this->stage($period, [$own, self::GOOD])->assertCreated();
        $this->assertSame(1, $r->json('counts.accepted'));
        $this->assertSame(['own_payee'], array_column($r->json('problems'), 'reason'));
        $files = $this->post('/api/payslip/periods/'.$period.'/pdfs', ['files' => [$this->pdf('OFF1.pdf')]], ['Accept' => 'application/json'])->assertCreated();
        $files->assertJsonPath('stored', 0)->assertJsonPath('files.0.result', 'refused');
        $this->assertSame(0, DB::table('psl_slips')->where('payee_id', $mine)->count());
        // a row staged by the other officer is refused at confirm when the confirming officer is the payee
        $this->as($second)->mapping();
        $p2 = $this->period(2026, 11);
        $imp = $this->stage($p2, [$own])->assertCreated();
        $this->assertSame(1, $imp->json('counts.accepted'));
        $this->as($first)->postJson('/api/payslip/imports/'.$imp->json('import_id').'/confirm', ['version' => $imp->json('version')])->assertForbidden();
        $this->assertSame(0, DB::table('psl_slips')->where('payee_id', $mine)->count());
    }

    public function test_pdf_name_is_bound_to_the_longest_delimiter_bounded_payee_code(): void
    {
        $this->officer();
        $this->payee('T1', 'ครูสมชาย ต้นธาร', self::NID_A);
        $up = fn (int $period, string $name) => $this->post('/api/payslip/periods/'.$period.'/pdfs', ['files' => [$this->pdf($name)]], ['Accept' => 'application/json'])->assertCreated();
        $p1 = $this->period();
        // never a partial token
        $up($p1, 'T10.pdf')->assertJsonPath('stored', 0)->assertJsonPath('files.0.result', 'unmatched');
        $up($p1, 'T1x_2569_01.pdf')->assertJsonPath('stored', 0)->assertJsonPath('files.0.result', 'unmatched');
        // the usual code_year_month name: only T1 is on file, so a numeric second token is not part of a code
        $up($p1, 'T1_2569_01.pdf')->assertJsonPath('stored', 1)->assertJsonPath('files.0.payee_code', 'T1');
        // once T1_2 exists too, the longest code that ends at a delimiter wins; other delimiters (- . space) bound a code as well
        $this->payee('T1_2', 'ครูสมหญิง ต้นธาร');
        $p2 = $this->period(2026, 11);
        $up($p2, 'T1_2_Jan.pdf')->assertJsonPath('stored', 1)->assertJsonPath('files.0.payee_code', 'T1_2');
        $up($p2, 'T1-2569.01 สลิป.pdf')->assertJsonPath('stored', 1)->assertJsonPath('files.0.payee_code', 'T1');
        $this->assertSame('T1', \Saan\Modules\Payslip\Pdfs::payeeFor('T1_20.pdf')->payee_code); // T1_20 is not T1_2
    }
    // ---------------------------------------------------------------- audit fixes (2026-11-07)

    public function test_retention_prune_keeps_a_calendar_year_whole_so_its_certificate_stays_valid(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-11-07 10:00:00');
        $this->officer();
        [$u, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร', self::NID_A);
        $this->mapping();
        $this->publishedPeriod(2016, 3, null, [self::GOOD]);
        $this->publishedPeriod(2016, 12, null, [self::GOOD]);
        $this->postJson('/api/payslip/certificates/generate', ['year' => 2016])->assertCreated();
        $this->artisan('saan:payslip-prune', ['--years' => 10])->assertSuccessful();
        $this->assertSame(2, DB::table('psl_periods')->count(), 'a year at the cutoff is kept whole');
        $cert = DB::table('psl_certs')->where('year', 2016)->first();
        $this->assertNotNull($cert);
        $this->assertTrue(\Saan\Modules\Payslip\Certificates::isCurrent($cert), 'the certificate still equals its slips');
        $this->assertSame(2, \Saan\Modules\Payslip\Certificates::compute(2016)[$a]['slips']);
        \Illuminate\Support\Carbon::setTestNow('2027-01-02 10:00:00');
        $this->artisan('saan:payslip-prune', ['--years' => 10])->assertSuccessful();
        $this->assertSame(0, DB::table('psl_periods')->count(), 'the whole year goes together');
        $this->assertSame(0, DB::table('psl_certs')->where('year', 2016)->count(), 'with its certificate');
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_an_unreadable_slip_makes_the_year_partial_instead_of_an_understated_certificate(): void
    {
        $this->officer();
        [$u, $a] = $this->payee('T001', 'ครูสมชาย ต้นธาร', self::NID_A);
        $this->mapping();
        $p1 = $this->publishedPeriod(2025, 1, null, [self::GOOD]);
        $this->publishedPeriod(2025, 2, null, [self::GOOD]);
        $this->postJson('/api/payslip/certificates/generate', ['year' => 2025])->assertCreated();
        $this->assertSame(2, \Saan\Modules\Payslip\Certificates::compute(2025)[$a]['slips']);
        DB::table('psl_slips')->where('period_id', $p1)->where('payee_id', $a)->update(['data_enc' => 'enc1:broken']);
        $t = \Saan\Modules\Payslip\Certificates::compute(2025)[$a];
        $this->assertSame(1, $t['bad']);
        $s = $this->getJson('/api/payslip/certificates?year=2025')->assertOk();
        $this->assertSame(1, $s->json('summary.unreadable'));
        $this->assertSame('unreadable', $s->json('rows.0.status'));
        $this->assertFalse(\Saan\Modules\Payslip\Certificates::isCurrent(DB::table('psl_certs')->where('year', 2025)->first()));
        $this->postJson('/api/payslip/certificates/generate', ['year' => 2025])->assertCreated();
        $this->assertSame('void', DB::table('psl_certs')->where('year', 2025)->value('status'), 'no certificate is kept or issued from partial data');
        $this->assertSame(1, \Saan\Modules\Payslip\Slips::recompute($p1)['unreadable_slips']);
    }

    public function test_every_import_problem_reason_has_a_thai_label_on_the_check_screen(): void
    {
        $src = (string) file_get_contents(base_path('modules/payslip/src/Importer.php')).(string) file_get_contents(base_path('modules/payslip/src/Slips.php'));
        preg_match_all("/\\\$problem\\('([a-z_]+)'\\)|'error' => '([a-z_]+)'|'(duplicate_[a-z_]+)'/", $src, $m); // the duplicate reasons are chosen by a ternary
        $reasons = array_values(array_unique(array_filter(array_merge($m[1], $m[2], $m[3]))));
        $this->assertContains('own_payee', $reasons);
        $js = (string) file_get_contents(public_path('assets/modules/payslip/payslip.js'));
        $this->assertSame(1, preg_match('/const REASON = \{(.*?)\};/s', $js, $block));
        foreach ($reasons as $r) $this->assertMatchesRegularExpression('/\b'.$r.": '[^']*[\\x{0E00}-\\x{0E7F}]/u", $block[1], "reason {$r} has no Thai label in payslip.js");
    }
}
