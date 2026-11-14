<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConnectorsModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        $this->as('admin');
    }

    private function as(string $role): static { return $this->actingAs(User::where('role', $role)->first()); }

    private const GENERIC = "เลขประจำตัวนักเรียน,คำนำหน้า,ชื่อ,นามสกุล,ชั้น,ห้อง,เลขที่,เพศ,วันเกิด,หมายเหตุ\n"
        ."DEMO-01,เด็กชาย,สมชาย,ใจดีสมมติ,ป.4,1,1,ชาย,12/05/2558,ทุนเรียนดี\n"
        ."DEMO-09,เด็กหญิง,สมหญิง,รักเรียนสมมติ,ป.5,1,2,หญิง,03/08/2557,\n"
        ."OLD-777,เด็กชาย,วิชัย,เก่าสมมติ,ป.6,2,3,ชาย,01/01/2556,\n";

    private function csvFile(string $bytes, string $name = 'legacy.csv'): UploadedFile { return UploadedFile::fake()->createWithContent($name, $bytes); }

    private function win874(string $utf8): string { return iconv('UTF-8', 'CP874//IGNORE', $utf8); }

    private function archive(string $label = 'ปีการศึกษา 2560', int $year = 2560): array
    {
        return $this->postJson('/api/connectors/archives', ['label' => $label, 'year_be' => $year, 'source' => 'ไฟล์สมมติ'])->assertCreated()->json();
    }

    private function importInto(int $id, string $bytes, string $kind = 'student', string $preset = 'generic_th', string $name = 'legacy.csv', array $extra = [])
    {
        return $this->post('/api/connectors/archives/'.$id.'/files', ['kind' => $kind, 'preset' => $preset, 'file' => $this->csvFile($bytes, $name)] + $extra, ['Accept' => 'application/json']);
    }

    private function live(): array
    {
        return array_map(fn ($t) => DB::table($t)->count(), ['students', 'classrooms', 'teachers', 'lessons', 'attendances', 'grade_entries', 'academic_terms']);
    }

    public function test_import_utf8_bom_and_windows_874_then_search_and_card(): void
    {
        $a = $this->archive();
        $r = $this->importInto($a['id'], "\xEF\xBB\xBF".self::GENERIC)->assertCreated()->json();
        $this->assertSame(3, $r['imported']);
        $this->assertSame(['หมายเหตุ'], $r['unmapped_columns']);

        $b = $this->archive('ปีการศึกษา 2559', 2559);
        $this->importInto($b['id'], $this->win874(self::GENERIC), 'student', 'generic_th', 'tis.csv')->assertCreated()->assertJsonPath('imported', 3);

        $hits = $this->getJson('/api/connectors/search?q='.urlencode('demo-01'))->assertOk()->json('data');
        $this->assertCount(2, $hits);
        $this->assertSame('สมชาย ใจดีสมมติ', $hits[0]['name']);
        $this->assertSame('ป.4/1', $hits[0]['classroom']);

        $byName = $this->getJson('/api/connectors/search?q='.urlencode('สมหญิง'))->assertOk()->json('data');
        $this->assertCount(2, $byName);
        $this->assertSame('DEMO-09', $byName[0]['code']);
        $this->getJson('/api/connectors/search?q=x')->assertStatus(422);

        $card = $this->getJson('/api/connectors/record?archive_id='.$a['id'].'&code=DEMO-01')->assertOk()->json();
        $this->assertSame('สมชาย ใจดีสมมติ', $card['name']);
        $this->assertArrayNotHasKey('extra', $card['groups']['student'][0]['data']); // unmapped columns are dropped, not kept as plaintext
        $this->assertStringNotContainsString('ทุนเรียนดี', (string) DB::table('con_rows')->pluck('data')->implode(''));
        $this->assertSame('M', $card['groups']['student'][0]['data']['sex']);
        $this->getJson('/api/connectors/record?archive_id='.$a['id'].'&code=NOPE')->assertNotFound();
    }

    public function test_grade_rows_and_csv_export_of_one_student(): void
    {
        $a = $this->archive();
        $this->importInto($a['id'], self::GENERIC)->assertCreated();
        $grades = "เลขประจำตัวนักเรียน,รหัสวิชา,ชื่อวิชา,ภาคเรียน,คะแนน,เกรด\nDEMO-01,ท14101,ภาษาไทย,1,85,4\nDEMO-01,ค14101,=คณิตศาสตร์,1,70,3\nDEMO-09,ท14101,ภาษาไทย,1,60,2\n";
        $this->importInto($a['id'], $grades, 'grade', 'generic_th', 'grades.csv')->assertCreated()->assertJsonPath('imported', 3);
        $this->importInto($a['id'], $grades, 'grade', 'generic_th', 'grades.csv')->assertStatus(409);

        $card = $this->getJson('/api/connectors/record?archive_id='.$a['id'].'&code=DEMO-01')->json();
        $this->assertCount(2, $card['groups']['grade']);

        $res = $this->get('/api/connectors/record/export?archive_id='.$a['id'].'&code=DEMO-01')->assertOk();
        $csv = $res->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('ท14101', $csv);
        $this->assertStringContainsString("'=คณิตศาสตร์", $csv); // formula neutralised
        $this->assertStringNotContainsString('DEMO-09', $csv);
        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));
    }

    public function test_dmc_like_preset(): void
    {
        $a = $this->archive();
        $dmc = "STUDENT_ID,PRENAME,FIRSTNAME,LASTNAME,SEX,BIRTHDATE,CLASS_LEVEL,CLASS_ROOM,CLASS_NO\nD-1,ด.ช.,ทดสอบ,หนึ่งสมมติ,1,25580101,ป.4,1,5\n";
        $this->importInto($a['id'], $dmc, 'student', 'dmc_like')->assertCreated();
        $card = $this->getJson('/api/connectors/record?archive_id='.$a['id'].'&code=D-1')->json();
        $d = $card['groups']['student'][0]['data'];
        $this->assertSame('M', $d['sex']);
        $this->assertSame('ป.4/1', $d['classroom']);
    }

    public function test_search_and_view_are_audited_and_live_data_is_untouched(): void
    {
        $before = $this->live();
        $a = $this->archive();
        $this->importInto($a['id'], self::GENERIC)->assertCreated();
        $this->getJson('/api/connectors/search?q=OLD-777')->assertOk();
        $this->getJson('/api/connectors/record?archive_id='.$a['id'].'&code=OLD-777')->assertOk();
        $this->get('/api/connectors/record/export?archive_id='.$a['id'].'&code=OLD-777')->assertOk();
        $this->assertSame($before, $this->live(), 'archives must never change live tables');
        $this->assertSame(0, DB::table('students')->where('code', 'OLD-777')->count());

        $uid = User::where('role', 'admin')->value('id');
        foreach (['connectors.searched', 'connectors.record.viewed', 'connectors.record.exported', 'connectors.file.imported', 'connectors.archive.created'] as $act) {
            $this->assertTrue(DB::table('audit_events')->where('action', $act)->where('actor_id', $uid)->exists(), $act);
        }
        $this->assertStringContainsString('OLD-777', (string) DB::table('audit_events')->where('action', 'connectors.searched')->value('detail'));
    }

    public function test_mapping_preview_stage_and_commit_through_registry(): void
    {
        $students = DB::table('students')->count();
        $csv = str_replace("DEMO-09", "NEW-009", self::GENERIC);
        $p = $this->post('/api/connectors/mapping/preview', ['preset' => 'generic_th', 'file' => $this->csvFile($this->win874($csv))], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(3, $p['summary']['rows']);
        $this->assertSame(1, $p['summary']['with_issues']); // OLD-777 sits in ป.6/2, which does not exist
        $this->assertContains('หมายเหตุ', $p['unmapped_columns']);
        $this->assertSame($students, DB::table('students')->count(), 'preview writes nothing');

        $s = $this->post('/api/connectors/mapping/runs', ['preset' => 'generic_th', 'file' => $this->csvFile($csv)], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->assertSame($students, DB::table('students')->count(), 'staging still writes no students');
        $this->assertSame('preview', $s['registry']['batch']['status']);
        $run = $s['run'];

        $done = $this->postJson('/api/connectors/mapping/runs/'.$run['id'].'/commit', ['version' => $run['version']])->assertOk()->json();
        $this->assertSame('committed', $done['registry']['batch']['status']);
        $this->assertSame($students + 1, DB::table('students')->count()); // NEW-009 is new, DEMO-01 already exists, OLD-777 has a room error
        $created = DB::table('students')->where('code', 'NEW-009')->first();
        $this->assertSame('สมหญิง', $created->first_name);
        $this->assertSame(2, (int) $created->classroom_id);
        $this->postJson('/api/connectors/mapping/runs/'.$run['id'].'/commit', ['version' => $run['version']])->assertStatus(409);
    }

    public function test_mapping_custom_columns_and_missing_code(): void
    {
        $csv = "ID นักเรียน,ชื่อ-สกุล,ห้องเรียน\nX-1,เด็กชายตัวอย่าง ทดสอบ,ป.4/1\n";
        $this->post('/api/connectors/mapping/preview', ['preset' => 'generic_th', 'file' => $this->csvFile($csv)], ['Accept' => 'application/json'])->assertStatus(422);
        $ok = $this->post('/api/connectors/mapping/preview', ['preset' => 'generic_th', 'mapping' => json_encode(['ID นักเรียน' => 'code']), 'file' => $this->csvFile($csv)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(0, $ok['summary']['with_issues']);
        $this->assertSame('X-1', $ok['sample'][0]['data']['code']);
        $this->assertSame('เด็กชายตัวอย่าง ทดสอบ', $ok['sample'][0]['data']['display_name']);
        $bad = $this->post('/api/connectors/mapping/preview', ['preset' => 'generic_th', 'mapping' => json_encode(['ID นักเรียน' => 'nonsense']), 'file' => $this->csvFile($csv)], ['Accept' => 'application/json']);
        $bad->assertStatus(422);
        $mapped = $this->post('/api/connectors/mapping/csv', ['preset' => 'generic_th', 'mapping' => json_encode(['ID นักเรียน' => 'code']), 'file' => $this->csvFile($csv)])->assertOk()->getContent();
        $this->assertStringContainsString('code,prefix,first_name,last_name,display_name', $mapped);
        $this->assertStringContainsString('X-1', $mapped);
    }

    public function test_teacher_lookup_setting_and_homeroom_scope(): void
    {
        $a = $this->archive();
        $this->importInto($a['id'], self::GENERIC)->assertCreated();

        $this->as('teacher');
        $this->getJson('/api/connectors/config')->assertOk()->assertJsonPath('teacher_lookup', false)->assertJsonPath('can_manage', false);
        $this->getJson('/api/connectors/search?q=DEMO-01')->assertForbidden();
        $this->putJson('/api/connectors/settings', ['teacher_lookup' => true])->assertForbidden();
        $this->getJson('/api/connectors/archives')->assertForbidden();
        $this->postJson('/api/connectors/archives', ['label' => 'x', 'year_be' => 2560])->assertForbidden();

        $this->as('principal')->putJson('/api/connectors/settings', ['teacher_lookup' => true])->assertForbidden();
        $this->as('admin')->putJson('/api/connectors/settings', ['teacher_lookup' => true])->assertOk();
        $this->assertTrue(DB::table('audit_events')->where('action', 'connectors.settings.updated')->exists());

        $this->as('teacher');
        $this->assertSame(['DEMO-01'], array_column($this->getJson('/api/connectors/search?q=DEMO-01')->assertOk()->json('data'), 'code'));
        $this->assertSame([], $this->getJson('/api/connectors/search?q=DEMO-09')->assertOk()->json('data')); // ป.5/1 is not this teacher's homeroom
        $this->assertSame([], $this->getJson('/api/connectors/search?q=OLD-777')->json('data'));
        $this->getJson('/api/connectors/record?archive_id='.$a['id'].'&code=DEMO-01')->assertOk();
        $this->getJson('/api/connectors/record?archive_id='.$a['id'].'&code=DEMO-09')->assertNotFound();
        $this->get('/api/connectors/record/export?archive_id='.$a['id'].'&code=DEMO-09')->assertNotFound();
        $this->post('/api/connectors/mapping/preview', ['preset' => 'generic_th', 'file' => $this->csvFile(self::GENERIC)], ['Accept' => 'application/json'])->assertForbidden();

        $this->as('admin')->putJson('/api/connectors/settings', ['teacher_lookup' => false])->assertOk();
        $this->as('teacher')->getJson('/api/connectors/search?q=DEMO-01')->assertForbidden();
    }

    public function test_sensitive_unmapped_columns_are_dropped_and_legacy_extra_never_reaches_teachers(): void
    {
        $csv = "เลขประจำตัวนักเรียน,ชื่อ,นามสกุล,ชั้น,ห้อง,เลขประจำตัวประชาชน,เบอร์โทรผู้ปกครอง,โรคประจำตัว\n"
            ."DEMO-01,สมชาย,ใจดีสมมติ,ป.4,1,1101700000002,0810000000,หอบหืด\n";
        $a = $this->archive();
        $r = $this->importInto($a['id'], $csv)->assertCreated()->json();
        $this->assertContains('เลขประจำตัวประชาชน', $r['dropped_columns']);
        $stored = (string) DB::table('con_rows')->pluck('data')->implode('');
        foreach (['1101700000002', '0810000000', 'หอบหืด'] as $secret) $this->assertStringNotContainsString($secret, $stored);

        // rows imported before the fix may still carry data.extra: managers see it, teachers never do (card and CSV)
        $row = DB::table('con_rows')->first();
        $d = json_decode($row->data, true); $d['extra'] = ['citizen' => '1101700000002'];
        DB::table('con_rows')->where('id', $row->id)->update(['data' => json_encode($d, JSON_UNESCAPED_UNICODE)]);
        $this->assertArrayHasKey('extra', $this->getJson('/api/connectors/record?archive_id='.$a['id'].'&code=DEMO-01')->assertOk()->json('groups.student.0.data'));
        $this->putJson('/api/connectors/settings', ['teacher_lookup' => true])->assertOk();
        $this->as('teacher');
        $card = $this->getJson('/api/connectors/record?archive_id='.$a['id'].'&code=DEMO-01')->assertOk()->json();
        $this->assertArrayNotHasKey('extra', $card['groups']['student'][0]['data']);
        $csvOut = $this->get('/api/connectors/record/export?archive_id='.$a['id'].'&code=DEMO-01')->assertOk()->getContent();
        $this->assertStringNotContainsString('1101700000002', $csvOut);
        $csvAll = $this->get('/api/connectors/record/export?code=DEMO-01')->assertOk()->getContent();
        $this->assertStringNotContainsString('1101700000002', $csvAll);
    }

    public function test_exact_code_search_short_circuits_and_name_search_still_works(): void
    {
        $a = $this->archive();
        $this->importInto($a['id'], self::GENERIC)->assertCreated();
        $this->assertSame(['DEMO-01'], array_column($this->getJson('/api/connectors/search?q=DEMO-01')->assertOk()->json('data'), 'code'));
        $this->assertSame(['OLD-777'], array_column($this->getJson('/api/connectors/search?q='.urlencode('วิชัย'))->assertOk()->json('data'), 'code'));
    }

    public function test_mapping_handoff_is_unavailable_when_registry_is_switched_off(): void
    {
        $this->assertTrue(\Saan\Modules\Connectors\MappingService::registryAvailable());
        config(['saan.modules.disable' => 'registry']);
        app()->forgetInstance(\App\Support\Modules\ModuleRegistry::class);
        $this->assertFalse(\Saan\Modules\Connectors\MappingService::registryAvailable());
        $this->getJson('/api/connectors/config')->assertOk()->assertJsonPath('registry_available', false);
    }

    public function test_lock_and_two_step_delete(): void
    {
        $a = $this->archive('ปีเก่า สมมติ');
        $this->importInto($a['id'], self::GENERIC)->assertCreated();
        $a = $this->getJson('/api/connectors/archives/'.$a['id'])->json();
        $this->assertSame(3, DB::table('con_rows')->where('archive_id', $a['id'])->count());

        $lock = $this->putJson('/api/connectors/archives/'.$a['id'], ['version' => $a['version'], 'locked' => true])->assertOk()->json();
        $this->assertTrue($lock['locked']);
        $this->deleteJson('/api/connectors/archives/'.$a['id'], ['version' => $lock['version'], 'confirm' => 'ลบ ปีเก่า สมมติ'])->assertStatus(409);
        $this->importInto($a['id'], self::GENERIC."X-1,เด็กชาย,เพิ่ม,ใหม่,ป.4,1,9,ชาย,01/01/2558,\n")->assertStatus(409);
        $this->putJson('/api/connectors/archives/'.$a['id'], ['version' => $lock['version'], 'label' => 'เปลี่ยนชื่อ'])->assertStatus(409);

        $this->as('principal')->putJson('/api/connectors/archives/'.$a['id'], ['version' => $lock['version'], 'locked' => false])->assertForbidden();
        $this->as('admin');
        $open = $this->putJson('/api/connectors/archives/'.$a['id'], ['version' => $lock['version'], 'locked' => false])->assertOk()->json();

        $this->deleteJson('/api/connectors/archives/'.$a['id'], ['version' => $open['version']])->assertStatus(422)->assertJsonPath('confirm_text', 'ลบ ปีเก่า สมมติ');
        $this->deleteJson('/api/connectors/archives/'.$a['id'], ['version' => $open['version'], 'confirm' => 'ลบ'])->assertStatus(422);
        $this->assertSame(3, DB::table('con_rows')->where('archive_id', $a['id'])->count());
        $this->deleteJson('/api/connectors/archives/'.$a['id'], ['version' => $a['version'], 'confirm' => 'ลบ ปีเก่า สมมติ'])->assertStatus(409); // stale version
        $this->deleteJson('/api/connectors/archives/'.$a['id'], ['version' => $open['version'], 'confirm' => 'ลบ ปีเก่า สมมติ'])->assertOk();
        $this->assertSame(0, DB::table('con_rows')->where('archive_id', $a['id'])->count());
        $this->assertSame(0, DB::table('con_archives')->where('id', $a['id'])->count());
        $this->assertTrue(DB::table('audit_events')->where('action', 'connectors.archive.deleted')->exists());
        $this->assertSame(0, DB::table('students')->where('code', 'OLD-777')->count());
    }

    public function test_duplicate_label_case_insensitive_and_year_validation(): void
    {
        $this->archive('Year A');
        $this->postJson('/api/connectors/archives', ['label' => '  year   a ', 'year_be' => 2561])->assertStatus(409);
        $this->postJson('/api/connectors/archives', ['label' => 'Y', 'year_be' => 1999])->assertUnprocessable();
        $b = $this->archive('Year B', 2561);
        $this->putJson('/api/connectors/archives/'.$b['id'], ['version' => $b['version'], 'label' => 'YEAR A'])->assertStatus(409);
        $key = $this->postJson('/api/connectors/archives', ['label' => 'Year C', 'year_be' => 2562], ['Idempotency-Key' => 'k1'])->assertCreated()->json('id');
        $this->assertSame($key, $this->postJson('/api/connectors/archives', ['label' => 'Year C', 'year_be' => 2562], ['Idempotency-Key' => 'k1'])->json('id'));
    }

    public function test_school_scoping(): void
    {
        $other = (int) DB::table('con_archives')->insertGetId(['school_id' => 2, 'label' => 'โรงเรียนอื่น', 'label_key' => 'โรงเรียนอื่น', 'year_be' => 2560, 'status' => 'ready',
            'locked' => false, 'row_count' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('con_rows')->insert(['school_id' => 2, 'archive_id' => $other, 'kind' => 'student', 'key1' => 'sch2-1', 'name_key' => 'นักเรียนโรงเรียนอื่น',
            'data' => json_encode(['code' => 'SCH2-1', 'name' => 'นักเรียนโรงเรียนอื่น'], JSON_UNESCAPED_UNICODE)]);
        $this->getJson('/api/connectors/search?q=SCH2-1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/connectors/search?q='.urlencode('โรงเรียนอื่น'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/connectors/record?archive_id='.$other.'&code=SCH2-1')->assertNotFound();
        $this->getJson('/api/connectors/archives/'.$other)->assertNotFound();
        $this->assertSame([], $this->getJson('/api/connectors/archives')->json('data'));
        $v = DB::table('con_archives')->where('id', $other)->value('version');
        $this->putJson('/api/connectors/archives/'.$other, ['version' => $v, 'locked' => true])->assertNotFound();
        $this->deleteJson('/api/connectors/archives/'.$other, ['version' => $v, 'confirm' => 'ลบ โรงเรียนอื่น'])->assertNotFound();
        $this->importInto($other, self::GENERIC)->assertNotFound();
        $this->assertSame(1, DB::table('con_rows')->where('archive_id', $other)->count());
    }

    public function test_classroom_header_and_preset_specific_headers(): void
    {
        $csv = "รหัส,ชื่อ,นามสกุล,classroom\nC-1,ตัวอย่าง,หนึ่ง,ป.4/1\n";
        $ok = $this->post('/api/connectors/mapping/preview', ['preset' => 'generic_th', 'file' => $this->csvFile($csv)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame('ป.4/1', $ok['sample'][0]['data']['classroom']);
        $this->assertSame(0, $ok['summary']['with_issues']);
        $this->assertSame('ป.4/1', \Saan\Modules\Connectors\Presets::classroom(['room' => 'ป.4/1']));
        $this->assertSame('classroom', \Saan\Modules\Connectors\Presets::lookup('generic_th')['classroom']);
        $this->assertSame('room', \Saan\Modules\Connectors\Presets::lookup('dmc_like')['classroom']);
        $dmc = "STUDENT_ID,FIRSTNAME,LASTNAME,CLASS_LEVEL,CLASS_ROOM\nD-2,ตัวอย่าง,สอง,ป.4,1\n";
        $d = $this->post('/api/connectors/mapping/preview', ['preset' => 'dmc_like', 'file' => $this->csvFile($dmc)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame('ป.4/1', $d['sample'][0]['data']['classroom']);
        // the archive importer behaves the same
        $a = $this->archive();
        $this->importInto($a['id'], $csv)->assertCreated();
        $this->assertSame('ป.4/1', $this->getJson('/api/connectors/record?archive_id='.$a['id'].'&code=C-1')->json('groups.student.0.data.classroom'));
    }

    public function test_custom_mapping_wins_over_preset_column(): void
    {
        $csv = "รหัส,ID นักเรียน,ชื่อ\nWRONG,X-9,ตัวอย่าง\n";
        $ok = $this->post('/api/connectors/mapping/preview', ['preset' => 'generic_th', 'mapping' => json_encode(['ID นักเรียน' => 'code']), 'file' => $this->csvFile($csv)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame('X-9', $ok['sample'][0]['data']['code']);
        $this->assertContains('รหัส', $ok['unmapped_columns']);
    }

    public function test_utf16_and_windows_874_edge_bytes(): void
    {
        $a = $this->archive();
        $this->importInto($a['id'], "\xFF\xFE".mb_convert_encoding(self::GENERIC, 'UTF-16LE', 'UTF-8'))->assertCreated()->assertJsonPath('imported', 3);
        // CP874 with bytes that have no character (0xDB, 0xFC) must be dropped, not fail the file
        $b = $this->archive('ปี 2559', 2559);
        $bytes = $this->win874("เลขประจำตัวนักเรียน,ชื่อ\nE-1,ตัวอย่าง\n");
        $bytes = substr_replace($bytes, "\xDB\xFC", strpos($bytes, "\n") + 4, 0);
        $this->importInto($b['id'], $bytes, 'student', 'generic_th', 'edge.csv')->assertCreated()->assertJsonPath('imported', 1);
        $this->assertSame('ตัวอย่าง', $this->getJson('/api/connectors/record?archive_id='.$b['id'].'&code=E-1')->json('name'));
    }

    public function test_row_numbers_follow_physical_lines(): void
    {
        $csv = "รหัส,ชื่อ,หมายเหตุ\nL-1,ก,\"บรรทัด\nสอง\nสาม\"\nL-2,,x\n";
        $p = $this->post('/api/connectors/mapping/preview', ['preset' => 'generic_th', 'file' => $this->csvFile($csv)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([2, 5], array_column($p['sample'], 'line'));
    }

    public function test_registry_handoff_keeps_codes_but_download_is_formula_safe(): void
    {
        $csv = "รหัส,ชื่อ,นามสกุล\n-123,ตัวอย่าง,หนึ่ง\n";
        $svc = app(\Saan\Modules\Connectors\MappingService::class);
        $m = $svc->map($csv, 'generic_th', []);
        $this->assertStringContainsString("\n-123,", $svc->toCsv($m, false));
        $this->assertStringContainsString("\n'-123,", $svc->toCsv($m));
        $dl = $this->post('/api/connectors/mapping/csv', ['preset' => 'generic_th', 'file' => $this->csvFile($csv)])->assertOk()->getContent();
        $this->assertStringContainsString("'-123", $dl);
    }

    public function test_search_by_code_with_underscore_and_like_chars_in_names(): void
    {
        $a = $this->archive();
        $this->importInto($a['id'], "เลขประจำตัวนักเรียน,ชื่อ,นามสกุล\nSTD_001,เด็กชาย,อันดับหนึ่ง\nSTD-002,ร้อย%เปอร์เซ็นต์,ทดสอบ\n")->assertCreated();
        $this->assertSame(['STD_001'], array_column($this->getJson('/api/connectors/search?q='.urlencode('std_001'))->assertOk()->json('data'), 'code'));
        $this->assertSame([], $this->getJson('/api/connectors/search?q='.urlencode('STD 00'))->json('data'));
        $this->assertSame(['STD-002'], array_column($this->getJson('/api/connectors/search?q='.urlencode('ร้อย%'))->assertOk()->json('data'), 'code'));
        $this->assertSame([], $this->getJson('/api/connectors/search?q='.urlencode('ร_อย'))->json('data'));
    }

    public function test_export_history_across_all_years_and_teacher_scope(): void
    {
        $a = $this->archive(); $b = $this->archive('ปีการศึกษา 2561', 2561);
        $this->importInto($a['id'], self::GENERIC)->assertCreated();
        $this->importInto($b['id'], self::GENERIC)->assertCreated();
        $all = $this->get('/api/connectors/record/export?code=DEMO-01')->assertOk()->getContent();
        $this->assertStringContainsString('ปีการศึกษา 2560', $all);
        $this->assertStringContainsString('ปีการศึกษา 2561', $all);
        $this->assertStringNotContainsString('DEMO-09', $all);
        $this->assertLessThan(strpos($all, '2561'), strpos($all, '2560'));
        $this->assertSame(1, substr_count($all, 'ปีการศึกษา 2560'));
        $this->get('/api/connectors/record/export?code=NOPE')->assertNotFound();
        $this->assertTrue(DB::table('audit_events')->where('action', 'connectors.record.exported')->where('detail', 'like', '%"scope":"all"%')->exists());

        $this->putJson('/api/connectors/settings', ['teacher_lookup' => true])->assertOk();
        $this->as('teacher');
        $this->get('/api/connectors/record/export?code=DEMO-01')->assertOk();
        $this->get('/api/connectors/record/export?code=DEMO-09')->assertNotFound();
    }

    public function test_teacher_scope_name_search_taught_class_and_left_students(): void
    {
        $a = $this->archive();
        $this->importInto($a['id'], self::GENERIC)->assertCreated();
        $this->putJson('/api/connectors/settings', ['teacher_lookup' => true])->assertOk();
        $teacher = User::where('role', 'teacher')->first();
        $this->as('teacher');
        $this->assertSame([], $this->getJson('/api/connectors/search?q='.urlencode('สมหญิง'))->assertOk()->json('data'));
        $this->assertNotEmpty($this->getJson('/api/connectors/search?q='.urlencode('สมชาย'))->json('data'));

        // teaching a class (lesson in the current term) is not a homeroom
        $room = (int) DB::table('students')->where('code', 'DEMO-09')->value('classroom_id');
        DB::table('lessons')->insert(['school_id' => (int) config('school.school_id'), 'term_id' => \App\Support\Saan\TermScope::currentId(), 'teacher_id' => $teacher->teacher_id,
            'classroom_id' => $room, 'period_id' => (int) DB::table('bell_periods')->value('id'), 'weekday' => 7, 'room' => 'T-TEST', 'subject' => 'ทดสอบ', 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/api/connectors/record?archive_id='.$a['id'].'&code=DEMO-09')->assertNotFound();
        $this->assertSame([], $this->getJson('/api/connectors/search?q=DEMO-09')->json('data'));

        // a homeroom student who has left is no longer visible
        $this->getJson('/api/connectors/record?archive_id='.$a['id'].'&code=DEMO-01')->assertOk();
        DB::table('students')->where('code', 'DEMO-01')->update(['status' => 'left']);
        $this->getJson('/api/connectors/record?archive_id='.$a['id'].'&code=DEMO-01')->assertNotFound();
        $this->assertSame([], $this->getJson('/api/connectors/search?q=DEMO-01')->json('data'));
    }

    public function test_bad_input_is_rejected(): void
    {
        $a = $this->archive();
        $this->importInto($a['id'], "เลขประจำตัวนักเรียน,ชื่อ\n")->assertStatus(422);
        $this->importInto($a['id'], "ชื่อ,นามสกุล\nก,ข\n")->assertStatus(422);
        $this->importInto($a['id'], "\x00\x01binary")->assertStatus(422);
        $this->importInto($a['id'], self::GENERIC, 'student', 'nope')->assertStatus(422);
        $this->importInto($a['id'], self::GENERIC, 'bogus')->assertStatus(422);
        $this->getJson('/api/connectors/search?q='.urlencode('%%'))->assertStatus(422);
        $this->assertSame(0, DB::table('con_rows')->count());
        $this->app['auth']->forgetGuards();
        auth()->logout();
        $this->getJson('/api/connectors/search?q=abc')->assertUnauthorized();
    }
}
