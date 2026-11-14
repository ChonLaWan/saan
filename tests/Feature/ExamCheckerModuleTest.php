<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Saan\Modules\ExamChecker\Scoring;
use Tests\TestCase;

class ExamCheckerModuleTest extends TestCase
{
    use RefreshDatabase;

    private const B = '/api/exam-checker';

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        $this->asRole('admin');
    }

    private function asRole(string $role): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::where('role', $role)->first());
    }

    /** Creates an exam as the current user and opens it. */
    private function exam(array $over = [], bool $open = true): array
    {
        $e = $this->postJson(self::B.'/exams', $over + ['title' => 'สอบทดสอบ', 'kind' => 'quiz', 'question_count' => 4, 'answer_key' => '1234'])->assertCreated()->json('exam');
        if ($open) $e = $this->putJson(self::B.'/exams/'.$e['id'], ['version' => $e['version'], 'status' => 'open'])->assertOk()->json('exam');
        return $e;
    }

    private function save(int $examId, array $rows, array $extra = [])
    {
        return $this->postJson(self::B.'/exams/'.$examId.'/sheets', ['rows' => $rows] + $extra);
    }

    private function sheetRows(array $answers): array
    {
        $rows = [];
        foreach ($answers as $i => $a) $rows[] = ['student_code' => sprintf('DEMO-%02d', $i + 1), 'answers' => $a];
        return $rows;
    }

    public function test_parsing_thai_letters_digits_latin_and_key_syntax(): void
    {
        $this->assertSame(['1234', 4, null], Scoring::parseAnswers('กขคง', 4));
        $this->assertSame(['1234', 4, null], Scoring::parseAnswers('1 2 3 4', 4));
        $this->assertSame(['1234', 4, null], Scoring::parseAnswers('a,B,c,D', 4));
        $this->assertSame(['1-*3', 4, null], Scoring::parseAnswers('ก-*ค', 4));
        $this->assertSame(['1234', 4, null], Scoring::parseAnswers('๑๒๓๔', 4));
        $this->assertNotNull(Scoring::parseAnswers('E', 4)[2], 'E is outside 4 choices');
        $this->assertNotNull(Scoring::parseAnswers('1?', 4)[2]);
        [$key, $err] = Scoring::parseKey('ก(ขค)x ง', 4);
        $this->assertNull($err);
        $this->assertSame([1, [2, 3], 'cancelled', 4], $key);
        $this->assertSame('1(23)x4', Scoring::keyToString($key));
        $this->assertNotNull(Scoring::parseKey('1(2', 4)[1]);
    }

    public function test_scoring_with_cancelled_question_multi_accepted_and_thai_input(): void
    {
        $e = $this->exam(['answer_key' => 'ก(ขค)xง', 'points_per_question' => 2, 'sections' => [['label' => 'ส่วน ก', 'from' => 1, 'to' => 2], ['label' => 'ส่วน ข', 'from' => 3, 'to' => 4]]]);
        $this->assertEquals(8.0, $e['max_score']);
        $res = $this->save($e['id'], $this->sheetRows(['กคงง', '-*-ก', 'ABCA', 'กข-ง']))->assertOk()->json();
        $this->assertSame([], $res['errors']);
        $by = collect($res['saved'])->keyBy('student_code');
        $this->assertEquals(8.0, $by['DEMO-01']['score']);                       // ก, ค accepted, cancelled, ง
        $this->assertEquals(2.0, $by['DEMO-02']['score']);                       // only the cancelled question (blank still gets the point)
        $this->assertEquals(6.0, $by['DEMO-03']['score']);                       // A, B(accepted), cancelled; last one wrong
        $this->assertEquals(8.0, $by['DEMO-04']['score']);
        $this->assertSame('1(23)x4', $this->getJson(self::B.'/exams/'.$e['id'])->json('exam.answer_key'));
        $this->assertEquals(['ส่วน ก' => 4.0, 'ส่วน ข' => 4.0], collect($by['DEMO-01']['section_scores'])->pluck('score', 'label')->all());
    }

    public function test_invalid_length_and_characters_are_rejected_per_row(): void
    {
        $e = $this->exam();
        $res = $this->save($e['id'], [
            ['student_code' => 'DEMO-01', 'answers' => '123'], ['student_code' => 'DEMO-02', 'answers' => '12345'],
            ['student_code' => 'DEMO-03', 'answers' => '12x4'], ['student_code' => 'DEMO-04', 'answers' => '1235'],
            ['student_code' => 'NOPE-99', 'answers' => '1234'], ['student_code' => 'DEMO-05', 'answers' => '1234'],
        ])->assertOk()->json();
        $this->assertCount(5, $res['errors']);
        $this->assertCount(1, $res['saved']);
        $this->assertSame('DEMO-05', $res['saved'][0]['student_code']);
        $this->assertStringContainsString('ไม่ตรง', $res['errors'][0]['message']);
        $this->assertStringContainsString('ไม่พบรหัส', $res['errors'][4]['message']);
        $this->assertSame(1, DB::table('exm_sheets')->count());
        $this->putJson(self::B.'/sheets/'.$res['saved'][0]['id'], ['version' => 1, 'answers' => '12'])->assertUnprocessable();
    }

    public function test_rescore_after_key_change_is_one_audited_transaction(): void
    {
        $e = $this->exam();
        $this->save($e['id'], $this->sheetRows(['1234', '1233', '4321']))->assertOk();
        $this->assertEquals([4.0, 3.0, 0.0], DB::table('exm_sheets')->orderBy('id')->pluck('score')->map(fn ($v) => (float) $v)->all());
        $res = $this->putJson(self::B.'/exams/'.$e['id'], ['version' => $e['version'], 'answer_key' => '1233'])->assertOk()->json();
        $this->assertSame(['total' => 3, 'changed' => 2], $res['rescored']);
        $this->assertEquals([3.0, 4.0, 0.0], DB::table('exm_sheets')->orderBy('id')->pluck('score')->map(fn ($v) => (float) $v)->all());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'exam_checker.rescored')->count());
        $this->assertSame(2, DB::table('audit_events')->where('action', 'exam_checker.exam_updated')->count());   // open + key change
        // sheets bumped version when their score changed
        $this->assertSame([2, 2, 1], DB::table('exm_sheets')->orderBy('id')->pluck('version')->map(fn ($v) => (int) $v)->all());
        // cancelling a question gives everybody the point
        $r2 = $this->putJson(self::B.'/exams/'.$e['id'], ['version' => $res['exam']['version'], 'answer_key' => '123x'])->assertOk()->json();
        $this->assertSame(3, $r2['rescored']['total']);
        $this->assertEquals([4.0, 4.0, 1.0], DB::table('exm_sheets')->orderBy('id')->pluck('score')->map(fn ($v) => (float) $v)->all());
        // changing the question count with sheets is refused; removing the key too
        $this->putJson(self::B.'/exams/'.$e['id'], ['version' => $r2['exam']['version'], 'question_count' => 5, 'answer_key' => '12345'])->assertUnprocessable();
        $this->putJson(self::B.'/exams/'.$e['id'], ['version' => $r2['exam']['version'], 'answer_key' => ''])->assertUnprocessable();
        $this->putJson(self::B.'/exams/'.$e['id'], ['version' => $r2['exam']['version'], 'answer_key' => '123'])->assertUnprocessable();
    }

    public function test_item_analysis_matches_hand_computed_fixture(): void
    {
        $e = $this->exam();
        // scores: 4,4,4,3,3,3,1,1,2,1 ; n=10 -> groups of round(2.7)=3 : upper S1-S3, lower S7,S8,S10
        $answers = ['1234', '1234', '1234', '1231', '1224', '1334', '1111', '2211', '2-34', '2*14'];
        $this->save($e['id'], $this->sheetRows($answers))->assertOk();
        $a = $this->getJson(self::B.'/exams/'.$e['id'].'/analysis')->assertOk()->json();
        $this->assertSame(10, $a['sheets']);
        $this->assertSame(3, $a['group_size']);
        $i = $a['items'];
        $this->assertSame([0.7, 0.6, 0.6, 0.7], array_column($i, 'p'));
        $this->assertSame([7, 6, 6, 7], array_column($i, 'correct'));
        $this->assertEqualsWithDelta([0.6667, 0.6667, 1.0, 0.6667], array_column($i, 'd'), 0.00011);
        $this->assertSame([1, 6, 1, 0], $i[1]['choices']);   // q2 picks: 1->S7, 2->six, 3->S6, 4->none
        $this->assertSame(1, $i[1]['blank']);
        $this->assertSame(1, $i[1]['multiple']);
        $this->assertSame([3, 0, 0, 7], $i[3]['choices']);
        $this->assertSame('ปานกลาง', $i[0]['p_label']);
        $this->assertSame('ดีมาก', $i[2]['d_label']);
        $s = $this->getJson(self::B.'/exams/'.$e['id'].'/summary')->assertOk()->json();
        $this->assertSame(10, $s['overall']['n']);
        $this->assertEquals(2.6, $s['overall']['mean']);
        $this->assertEquals(1.26, $s['overall']['sd']);              // sample S.D. (n-1), like Excel STDEV
        $this->assertEquals(1.2, $s['overall']['sd_population']);
        $this->assertEquals(3, $s['overall']['median']);
        $this->assertEquals([1, 4], [$s['overall']['min'], $s['overall']['max']]);
        $this->assertSame(10, array_sum(array_column($s['distribution'], 'count')));
        $this->assertSame(3, $s['distribution'][9]['count']);   // 100%
        // per classroom: DEMO-01..08 are ป.4/1, DEMO-09,10 are ป.5/1
        $this->assertCount(2, $s['classrooms']);
        $this->assertCount(2, $a['classrooms']);
        $c1 = $this->getJson(self::B.'/exams/'.$e['id'].'/analysis?classroom_id=2')->assertOk()->json();
        $this->assertSame(2, $c1['sheets']);
        $this->assertSame(1, $c1['group_size']);
        $this->getJson(self::B.'/exams/'.$e['id'].'/summary?classroom_id=1')->assertOk()->assertJsonPath('overall.n', 8);
    }

    public function test_cancelled_question_is_excluded_from_item_analysis(): void
    {
        $e = $this->exam(['answer_key' => '12x4']);
        $this->save($e['id'], $this->sheetRows(['1234', '1111']))->assertOk();
        $it = $this->getJson(self::B.'/exams/'.$e['id'].'/analysis')->json('items');
        $this->assertTrue($it[2]['cancelled']);
        $this->assertNull($it[2]['p']);
        $this->assertNull($it[2]['d']);
    }

    public function test_version_conflicts_return_409(): void
    {
        $e = $this->exam();
        $this->putJson(self::B.'/exams/'.$e['id'], ['version' => $e['version'], 'title' => 'ชื่อใหม่'])->assertOk();
        $this->putJson(self::B.'/exams/'.$e['id'], ['version' => $e['version'], 'title' => 'ชื่อเก่า'])->assertStatus(409)->assertJsonStructure(['message', 'current']);
        $r = $this->save($e['id'], $this->sheetRows(['1234']))->assertOk()->json('saved.0');
        $this->putJson(self::B.'/sheets/'.$r['id'], ['version' => 1, 'answers' => '1111'])->assertOk()->assertJsonPath('sheet.score', 1);
        $this->putJson(self::B.'/sheets/'.$r['id'], ['version' => 1, 'answers' => '2222'])->assertStatus(409);
        $this->save($e['id'], [['student_code' => 'DEMO-01', 'answers' => '2222', 'version' => 1]])->assertStatus(409)->assertJsonPath('current.stale.0.version', 2);
        $this->assertSame('1111', DB::table('exm_sheets')->value('answers'));
        // an existing sheet needs a version or an explicit overwrite; code match ignores case and spaces
        $res = $this->save($e['id'], [['student_code' => ' demo-01 ', 'answers' => '3333']])->assertOk()->json();
        $this->assertCount(1, $res['errors']);
        $this->save($e['id'], [['student_code' => ' demo-01 ', 'answers' => '3333']], ['overwrite' => true])->assertOk()->assertJsonPath('updated', 1);
        $this->assertSame(1, DB::table('exm_sheets')->count());
        $this->deleteJson(self::B.'/sheets/'.$r['id'], ['version' => 1])->assertStatus(409);
        $this->deleteJson(self::B.'/sheets/'.$r['id'], ['version' => 3])->assertOk();
    }

    public function test_a_teacher_cannot_file_a_sheet_for_a_pupil_who_is_no_longer_studying(): void
    {
        $e = $this->exam();
        DB::table('students')->where('code', 'DEMO-01')->update(['status' => 'graduated']);
        $this->asRole('teacher');
        $res = $this->save($e['id'], $this->sheetRows(['1234']))->assertOk()->json();
        $this->assertSame([], $res['saved']);
        $this->assertSame('นักเรียนคนนี้ไม่ได้อยู่ในสถานะกำลังศึกษา', $res['errors'][0]['message']);
        $this->assertSame(0, DB::table('exm_sheets')->count());
        DB::table('students')->where('code', 'DEMO-01')->update(['status' => 'studying']);
        $this->save($e['id'], $this->sheetRows(['1234']))->assertOk()->assertJsonCount(1, 'saved');
    }

    public function test_import_over_the_row_limit_is_refused(): void
    {
        $e = $this->exam();
        $csv = "code,answers\n".str_repeat("DEMO-01,1234\n", \Saan\Modules\ExamChecker\Importer::MAX_ROWS + 1);
        $this->postJson(self::B.'/exams/'.$e['id'].'/import', ['csv' => $csv])->assertUnprocessable();
        $this->assertSame(0, DB::table('exm_sheets')->count());
    }
    public function test_teacher_scoping_and_visibility(): void
    {
        // classroom 4 has no lessons for the demo teacher, so it is outside TeacherScope
        DB::table('classrooms')->insert(['id' => 4, 'school_id' => 1, 'name' => 'ป.6/1', 'homeroom_teacher_id' => 5]);
        DB::table('students')->insert(['school_id' => 1, 'classroom_id' => 4, 'code' => 'ZZ-01', 'code_key' => 'zz-01', 'name' => 'นักเรียนห้องอื่น']);
        $admin = $this->exam(['title' => 'ข้อสอบกลาง']);
        $draft = $this->exam(['title' => 'ร่างของแอดมิน'], false);
        $this->save($admin['id'], [['student_code' => 'ZZ-01', 'answers' => '1234']])->assertOk()->assertJsonCount(1, 'saved');

        $this->asRole('teacher');
        $list = $this->getJson(self::B.'/exams')->assertOk()->json('data');
        $this->assertSame(['ข้อสอบกลาง'], array_column($list, 'title'));
        $this->getJson(self::B.'/exams/'.$draft['id'])->assertNotFound();
        $shown = $this->getJson(self::B.'/exams/'.$admin['id'])->assertOk()->json('exam');
        $this->assertArrayNotHasKey('answer_key', $shown);
        $this->putJson(self::B.'/exams/'.$admin['id'], ['version' => $admin['version'], 'title' => 'x'])->assertForbidden();
        $this->postJson(self::B.'/exams/'.$admin['id'].'/rescore')->assertForbidden();

        $res = $this->save($admin['id'], [['student_code' => 'DEMO-01', 'answers' => '1234'], ['student_code' => 'ZZ-01', 'answers' => '1234', 'version' => 1]])->assertOk()->json();
        $this->assertCount(1, $res['saved']);
        $this->assertSame('ไม่มีสิทธิ์บันทึกใบตอบของห้องนี้', $res['errors'][0]['message']);
        $this->getJson(self::B.'/exams/'.$admin['id'].'/roster?classroom_id=4')->assertForbidden();
        $this->getJson(self::B.'/exams/'.$admin['id'].'/roster?classroom_id=1')->assertOk()->assertJsonCount(8, 'data')->assertJsonPath('data.0.sheet.score', 4);
        $this->getJson(self::B.'/exams/'.$admin['id'].'/analysis?classroom_id=4')->assertForbidden();
        $this->assertSame(1, $this->getJson(self::B.'/exams/'.$admin['id'].'/analysis')->json('sheets'));   // ZZ-01 is invisible to her
        $this->assertCount(1, $this->getJson(self::B.'/exams/'.$admin['id'].'/sheets')->json('data'));
        $zz = DB::table('exm_sheets')->where('student_code', 'ZZ-01')->first();
        $this->putJson(self::B.'/sheets/'.$zz->id, ['version' => 1, 'answers' => '1111'])->assertForbidden();

        // a teacher manages her own exams (settings, key, rescore) but the classroom scope is never widened
        $mine = $this->exam(['title' => 'ของครู', 'answer_key' => '4321']);
        $own = $this->save($mine['id'], [['student_code' => 'ZZ-01', 'answers' => '4321']])->assertOk()->json();
        $this->assertSame([], $own['saved']);
        $this->assertSame('ไม่มีสิทธิ์บันทึกใบตอบของห้องนี้', $own['errors'][0]['message']);
        $this->getJson(self::B.'/exams/'.$mine['id'].'/roster?classroom_id=4')->assertForbidden();
        $this->assertNotContains(4, array_column($this->getJson(self::B.'/exams/'.$mine['id'].'/classrooms')->assertOk()->json('data'), 'id'));
        $this->getJson(self::B.'/exams/'.$mine['id'].'/analysis?classroom_id=4')->assertForbidden();
        $this->putJson(self::B.'/exams/'.$mine['id'], ['version' => $mine['version'], 'title' => 'ของครู 2'])->assertOk();
        $this->assertSame('4321', $this->getJson(self::B.'/exams/'.$mine['id'])->json('exam.answer_key'));
        // the parsed key is shared with every viewer of a non-draft exam so the grid can score live; the editable string is not
        $this->assertSame([1, 2, 3, 4], $this->getJson(self::B.'/exams/'.$admin['id'])->json('exam.key'));
        // the index counts only the classrooms she can see (the ZZ-01 sheet is hidden)
        $this->assertSame(1, collect($this->getJson(self::B.'/exams')->json('data'))->firstWhere('id', $admin['id'])['sheet_count']);
        // a non-staff role is refused by the role middleware
        User::where('role', 'teacher')->update(['role' => 'editor']);
        $this->asRole('editor');
        $this->getJson(self::B.'/exams')->assertForbidden();
        $this->postJson(self::B.'/exams', ['title' => 'x', 'kind' => 'quiz', 'question_count' => 4])->assertForbidden();
        // a logged-out request cannot enter
        $this->app['auth']->forgetGuards();
        $this->getJson(self::B.'/exams')->assertUnauthorized();
    }

    public function test_key_change_racing_with_a_save_is_refused(): void
    {
        $e = $this->exam();
        $stale = DB::table('exm_exams')->where('id', $e['id'])->first();      // what a save in flight read before the key change
        $this->putJson(self::B.'/exams/'.$e['id'], ['version' => $e['version'], 'answer_key' => '1233'])->assertOk();
        try {
            \Saan\Modules\ExamChecker\SheetService::save($stale, $this->sheetRows(['1233']));
            $this->fail('expected VersionConflict');
        } catch (\App\Support\Saan\VersionConflict $c) {
            $this->assertStringContainsString('เฉลย', $c->getMessage());
        }
        $this->assertSame(0, DB::table('exm_sheets')->count());
        // a fresh read saves and scores with the new key
        $this->save($e['id'], $this->sheetRows(['1233']))->assertOk()->assertJsonPath('saved.0.score', 4);
    }

    public function test_choices_change_revalidates_the_stored_key(): void
    {
        $e = $this->exam(['choices_per_question' => 6, 'answer_key' => '1235'], false);
        $this->putJson(self::B.'/exams/'.$e['id'], ['version' => $e['version'], 'choices_per_question' => 4])->assertUnprocessable();
        $this->putJson(self::B.'/exams/'.$e['id'], ['version' => $e['version'], 'choices_per_question' => 5])->assertOk();
    }

    public function test_unique_violation_becomes_409_and_other_schools_are_invisible(): void
    {
        $e = $this->exam();
        $fired = false;
        DB::listen(function ($q) use (&$fired, $e) {
            // a second machine saves DEMO-01 right after our "does it exist" lookup and before our insert
            if ($fired || !str_starts_with($q->sql, 'select') || !str_contains(str_replace(['"', '`'], '', $q->sql), 'from exm_sheets') || !str_contains($q->sql, 'student_code_key')) return;
            $fired = true;
            DB::table('exm_sheets')->insert(['school_id' => 1, 'exam_id' => $e['id'], 'student_code' => 'DEMO-01', 'student_code_key' => 'demo-01', 'display_name' => 'x',
                'answers' => '1111', 'score' => 1, 'source' => 'keyed', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->save($e['id'], $this->sheetRows(['1234']))->assertStatus(409);
        $this->assertTrue($fired);
        $this->assertSame(1, DB::table('exm_sheets')->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'exam_checker.sheets_saved')->count());

        $foreign = DB::table('exm_exams')->insertGetId(['school_id' => 2, 'title' => 'โรงเรียนอื่น', 'kind' => 'quiz', 'question_count' => 4, 'choices_per_question' => 4,
            'points_per_question' => 1, 'answer_key' => '[1,2,3,4]', 'status' => 'open', 'created_by' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $sheet = DB::table('exm_sheets')->insertGetId(['school_id' => 2, 'exam_id' => $foreign, 'student_code' => 'F-1', 'student_code_key' => 'f-1', 'display_name' => 'ต่างโรงเรียน',
            'answers' => '1234', 'score' => 4, 'source' => 'keyed', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson(self::B.'/exams/'.$foreign)->assertNotFound();
        $this->getJson(self::B.'/exams/'.$foreign.'/analysis')->assertNotFound();
        $this->getJson(self::B.'/exams/'.$foreign.'/roster?classroom_id=1')->assertNotFound();
        $this->putJson(self::B.'/sheets/'.$sheet, ['version' => 1, 'answers' => '1111'])->assertNotFound();
        $this->deleteJson(self::B.'/sheets/'.$sheet, ['version' => 1])->assertNotFound();
        $this->assertNotContains($foreign, array_column($this->getJson(self::B.'/exams?all_terms=1')->json('data'), 'id'));
    }

    public function test_idempotent_replay_does_not_save_twice(): void
    {
        $e = $this->exam();
        $h = ['Idempotency-Key' => 'exm-test-key-1'];
        $first = $this->withHeaders($h)->postJson(self::B.'/exams/'.$e['id'].'/sheets', ['rows' => $this->sheetRows(['1234'])])->assertOk();
        $second = $this->withHeaders($h)->postJson(self::B.'/exams/'.$e['id'].'/sheets', ['rows' => $this->sheetRows(['1234'])])->assertOk();
        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('created'), $second->json('created'));
        $this->assertSame(1, DB::table('exm_sheets')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'exam_checker.sheets_saved')->count());
    }

    public function test_csv_error_rows_use_physical_line_numbers_and_sd_is_sample(): void
    {
        $e = $this->exam();
        $csv = "student_code,answers\n\n\nDEMO-03,12\n\"DEMO-04\",\"1234\"\n";
        $r = $this->postJson(self::B.'/exams/'.$e['id'].'/import', ['csv' => $csv, 'dry_run' => true])->assertOk()->json();
        $this->assertSame(4, $r['errors'][0]['row']);
        $this->assertSame(1, $r['created']);
        $stats = \Saan\Modules\ExamChecker\Analytics::stats([4, 4, 4, 3, 3, 3, 1, 1, 2, 1], 4);
        $this->assertEquals(1.26, $stats['sd']);
        $this->assertEquals(1.2, $stats['sd_population']);
        $this->assertNull(\Saan\Modules\ExamChecker\Analytics::stats([3], 4)['sd']);
    }

    public function test_csv_duplicate_student_across_chunks_is_an_error_and_not_counted_twice(): void
    {
        $e = $this->exam();
        // 250 filler lines (unknown students, skipped) push the second DEMO-01 into the next 200-row chunk
        $csv = "student_code,answers\nDEMO-01,1234\n";
        for ($i = 0; $i < 250; $i++) $csv .= 'NOPE-'.$i.",1234\n";
        $csv .= "DEMO-01,4321\n";
        $dry = $this->postJson(self::B.'/exams/'.$e['id'].'/import', ['csv' => $csv, 'dry_run' => true, 'overwrite' => true])->assertOk()->json();
        $this->assertSame(1, $dry['created']);
        $dups = array_values(array_filter($dry['errors'], fn ($x) => $x['student_code'] === 'DEMO-01'));
        $this->assertCount(1, $dups);
        $this->assertStringContainsString('ซ้ำ', $dups[0]['message']);
        $this->postJson(self::B.'/exams/'.$e['id'].'/import', ['csv' => $csv, 'overwrite' => true])->assertOk();
        $this->assertSame('1234', DB::table('exm_sheets')->where('student_code', 'DEMO-01')->value('answers'));   // first line wins, not silently replaced
    }

    public function test_homeroom_does_not_widen_scope_for_a_past_term(): void
    {
        $old = DB::table('academic_terms')->insertGetId(['school_id' => 1, 'year' => 2551, 'term' => 1, 'starts_on' => '2008-05-15', 'ends_on' => '2008-09-30']);
        $this->asRole('teacher');
        $user = auth()->user();
        $room = (int) ($user->classroom_id ?: 1);
        DB::table('users')->where('id', $user->id)->update(['classroom_id' => $room]);
        $this->asRole('teacher');
        $this->assertContains($room, \Saan\Modules\ExamChecker\Access::scopeFor(null) ?? []);
        $this->assertNotContains($room, \Saan\Modules\ExamChecker\Access::scopeFor($old) ?? [], 'homeroom of today must not expose a past term');
        $this->asRole('admin');
        $this->assertNull(\Saan\Modules\ExamChecker\Access::scopeFor($old));
    }

    public function test_teacher_cannot_move_an_exam_to_a_past_term(): void
{
        $old = DB::table('academic_terms')->insertGetId(['school_id' => 1, 'year' => 2560, 'term' => 1, 'starts_on' => '2017-05-15', 'ends_on' => '2017-09-30']);
        $this->asRole('teacher');
        $this->postJson(self::B.'/exams', ['title' => 'x', 'kind' => 'quiz', 'question_count' => 4, 'answer_key' => '1234', 'term_id' => $old])->assertUnprocessable();
        $mine = $this->exam();
        $this->putJson(self::B.'/exams/'.$mine['id'], ['version' => $mine['version'], 'term_id' => $old])->assertUnprocessable();
        $this->asRole('admin');
        $this->putJson(self::B.'/exams/'.$mine['id'], ['version' => $mine['version'], 'term_id' => $old])->assertOk();
    }

    public function test_teacher_cannot_clear_or_change_the_term_of_an_exam(): void
    {
        $this->asRole('teacher');
        $mine = $this->exam();
        $this->putJson(self::B.'/exams/'.$mine['id'], ['version' => $mine['version'], 'term_id' => null])->assertUnprocessable();
        $made = $this->postJson(self::B.'/exams', ['title' => 'y', 'kind' => 'quiz', 'question_count' => 4, 'answer_key' => '1234', 'term_id' => null])->assertCreated();
        $this->assertNotNull(DB::table('exm_exams')->where('id', $made->json('exam.id'))->value('term_id'));
    }
    public function test_closed_and_draft_without_key_rules(): void
    {
        $nokey = $this->exam(['answer_key' => null], false);
        $this->putJson(self::B.'/exams/'.$nokey['id'], ['version' => $nokey['version'], 'status' => 'open'])->assertUnprocessable();
        $this->save($nokey['id'], $this->sheetRows(['1234']))->assertUnprocessable();
        $e = $this->exam();
        $this->save($e['id'], $this->sheetRows(['1234']))->assertOk();
        $closed = $this->putJson(self::B.'/exams/'.$e['id'], ['version' => $e['version'], 'status' => 'closed'])->assertOk()->json('exam');
        $this->save($e['id'], $this->sheetRows(['4321']), ['overwrite' => true])->assertUnprocessable();
        $this->deleteJson(self::B.'/exams/'.$e['id'], ['version' => $closed['version']])->assertUnprocessable();
        $this->deleteJson(self::B.'/exams/'.$nokey['id'], ['version' => $nokey['version']])->assertOk();
    }

    public function test_csv_import_plain_omr_dry_run_and_unknown_students(): void
    {
        $e = $this->exam();
        $plain = "student_code,answers\nDEMO-01,กขคง\nDEMO-02,\"1 2 3 3\"\nDEMO-03,12\nBAD-1,1234\n";
        $dry = $this->postJson(self::B.'/exams/'.$e['id'].'/import', ['csv' => $plain, 'dry_run' => true])->assertOk()->json();
        $this->assertSame(0, DB::table('exm_sheets')->count());
        $this->assertSame(2, $dry['created']);
        $this->assertCount(2, $dry['errors']);
        $r = $this->postJson(self::B.'/exams/'.$e['id'].'/import', ['csv' => $plain, 'allow_unknown' => true])->assertOk()->json();
        $this->assertSame(3, $r['created']);                       // BAD-1 allowed as unknown for admin
        $this->assertSame('csv', $r['format']);
        $this->assertCount(1, $r['errors']);                       // DEMO-03 too short
        $this->assertSame('csv', DB::table('exm_sheets')->where('student_code', 'DEMO-01')->value('source'));
        $this->assertNull(DB::table('exm_sheets')->where('student_code', 'BAD-1')->value('student_id'));

        // OMR format: semicolons, Thai header, one column per question, double mark and empty cell
        $omr = "\xEF\xBB\xBFรหัสนักเรียน;ชื่อ;Q1;Q2;Q3;Q4\nDEMO-04;ทดสอบ;A;B;C;D\nDEMO-05;ทดสอบ;A;AB;;D\n";
        $o = $this->postJson(self::B.'/exams/'.$e['id'].'/import', ['csv' => $omr])->assertOk()->json();
        $this->assertSame('omr_csv', $o['format']);
        $this->assertSame(2, $o['created']);
        $this->assertSame('1*-4', DB::table('exm_sheets')->where('student_code', 'DEMO-05')->value('answers'));
        $this->assertSame('omr_csv', DB::table('exm_sheets')->where('student_code', 'DEMO-05')->value('source'));
        // headerless positional file + re-import needs overwrite
        $again = $this->postJson(self::B.'/exams/'.$e['id'].'/import', ['csv' => "DEMO-04,4321\n"])->assertOk()->json();
        $this->assertCount(1, $again['errors']);
        $ow = $this->postJson(self::B.'/exams/'.$e['id'].'/import', ['csv' => "DEMO-04,4321\n", 'overwrite' => true])->assertOk()->json();
        $this->assertSame(1, $ow['updated']);
        $this->assertSame('4321', DB::table('exm_sheets')->where('student_code', 'DEMO-04')->value('answers'));
        // unknown codes are refused for teachers
        $this->asRole('teacher');
        $x = $this->exam(['answer_key' => '1234']);
        $t = $this->postJson(self::B.'/exams/'.$x['id'].'/import', ['csv' => "NOPE-1,1234\n", 'allow_unknown' => true])->assertOk()->json();
        $this->assertCount(1, $t['errors']);
    }

    public function test_export_slips_and_module_registration(): void
    {
        $e = $this->exam(['answer_key' => '1234', 'sections' => [['label' => 'ส่วนที่ 1', 'from' => 1, 'to' => 2]]]);
        $this->save($e['id'], $this->sheetRows(['1234', '1134']))->assertOk();
        $csv = $this->get(self::B.'/exams/'.$e['id'].'/export')->assertOk();
        $this->assertStringContainsString('DEMO-01', $csv->getContent());
        $this->assertStringContainsString('ส่วนที่ 1', $csv->getContent());
        $slip = $this->get('/exam-checker/exams/'.$e['id'].'/slips')->assertOk();
        $slip->assertSee('ใบแจ้งผลการสอบ')->assertSee('นักเรียนตัวอย่าง 02')->assertSee('ข้อที่ทำผิด (1 ข้อ)')->assertSee('ที่ตอบ');
        $this->get('/exam-checker/exams/'.$e['id'].'/slips?sheet='.DB::table('exm_sheets')->where('student_code', 'DEMO-01')->value('id'))->assertOk()->assertSee('ไม่มีข้อที่ผิด');
        $this->assertContains('exam-checker', array_column(app(\App\Support\Modules\ModuleRegistry::class)->summary(), 'id'));
        $this->get('/work')->assertOk()->assertSee('assets/modules/exam-checker/exams.js', false)->assertSee('ตรวจข้อสอบ');
        $this->assertGreaterThan(0, DB::table('audit_events')->where('action', 'exam_checker.exported')->count());
    }

    public function test_saving_50_sheets_stays_within_query_budget(): void
    {
        $rows = [];
        for ($i = 1; $i <= 60; $i++) DB::table('students')->insert(['school_id' => 1, 'classroom_id' => 1, 'code' => sprintf('Q-%03d', $i), 'code_key' => sprintf('q-%03d', $i), 'name' => 'นักเรียน '.$i]);
        $warm = $this->exam(['title' => 'warm up']);
        $this->save($warm['id'], [['student_code' => 'Q-060', 'answers' => '1234']])->assertOk();   // primes schema caches
        $e = $this->exam(['question_count' => 40, 'answer_key' => str_repeat('1234', 10)]);
        for ($i = 1; $i <= 50; $i++) $rows[] = ['student_code' => sprintf('Q-%03d', $i), 'answers' => substr(str_repeat('1234', 10), $i % 4).str_repeat('1', $i % 4)];
        DB::flushQueryLog();
        DB::enableQueryLog();
        $res = $this->save($e['id'], $rows)->assertOk()->json();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(50, $res['created']);
        $this->assertLessThanOrEqual(10, $n, 'queries used: '.$n);
        // updating the same 50 sheets (with their versions) is also constant
        $again = array_map(fn ($s) => ['student_code' => $s['student_code'], 'answers' => str_repeat('1234', 10), 'version' => $s['version']], $res['saved']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $upd = $this->save($e['id'], $again)->assertOk()->json();
        $n2 = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(10, $n2, 'update queries used: '.$n2);
        $this->assertSame(array_sum(array_map(fn ($i) => $i % 4 === 0 ? 0 : 1, range(1, 50))), $upd['updated']);
        $this->assertSame(0, DB::table('exm_sheets')->where('exam_id', $e['id'])->where('score', '!=', 40)->count());
    }

    public function test_teacher_path_stays_within_query_budget(): void
    {
        for ($i = 1; $i <= 60; $i++) DB::table('students')->insert(['school_id' => 1, 'classroom_id' => 1, 'code' => sprintf('Q-%03d', $i), 'code_key' => sprintf('q-%03d', $i), 'name' => 'นักเรียน '.$i]);
        $e = $this->exam(['question_count' => 40, 'answer_key' => str_repeat('1234', 10)]);   // made by admin
        $this->asRole('teacher');
        $this->save($e['id'], [['student_code' => 'Q-060', 'answers' => str_repeat('1234', 10)]])->assertOk();   // primes schema caches
        $rows = [];
        for ($i = 1; $i <= 50; $i++) $rows[] = ['student_code' => sprintf('Q-%03d', $i), 'answers' => substr(str_repeat('1234', 10), $i % 4).str_repeat('1', $i % 4)];
        DB::flushQueryLog();
        DB::enableQueryLog();
        $res = $this->save($e['id'], $rows)->assertOk()->json();
        $n = count(DB::getQueryLog());
        // core's TeacherScope costs a fixed number of queries (homeroom, hasTable, lessons, classroom check) that the module cannot cache
        DB::flushQueryLog();
        \App\Support\Saan\TeacherScope::classroomIds(null, DB::table('exm_exams')->where('id', $e['id'])->value('term_id'));
        $scopeCost = count(DB::getQueryLog()) - 1;   // minus the term_id lookup above
        DB::disableQueryLog();
        $this->assertSame(50, $res['created']);
        $this->assertCount(50, $res['saved']);
        $this->assertLessThanOrEqual(10, $n - $scopeCost, 'module queries used: '.($n - $scopeCost).' (total '.$n.', scope '.$scopeCost.')');
        $again = array_map(fn ($s) => ['student_code' => $s['student_code'], 'answers' => str_repeat('1234', 10), 'version' => $s['version']], $res['saved']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $upd = $this->save($e['id'], $again)->assertOk()->json();
        $n2 = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(10, $n2 - $scopeCost, 'teacher update queries used: '.$n2.' (scope '.$scopeCost.')');
        $this->assertEquals(40.0, collect($upd['saved'])->firstWhere('student_code', 'Q-001')['score']);
        $this->assertSame(2, collect($upd['saved'])->firstWhere('student_code', 'Q-001')['version']);
        $this->assertSame(0, DB::table('exm_sheets')->where('exam_id', $e['id'])->where('score', '!=', 40)->count());
    }

    public function test_rescoring_and_analysing_2000_sheets_is_fast(): void
    {
        $key = str_repeat('1234', 25);
        $e = $this->exam(['question_count' => 100, 'answer_key' => $key]);
        $rows = [];
        for ($i = 0; $i < 2000; $i++) {
            $a = '';
            for ($q = 0; $q < 100; $q++) $a .= (string) (($i * 7 + $q * 3) % 4 + 1);
            $rows[] = ['school_id' => 1, 'exam_id' => $e['id'], 'student_id' => null, 'student_code' => 'S'.$i, 'student_code_key' => 's'.$i, 'display_name' => 'ทดสอบ '.$i,
                'classroom_id' => 1 + $i % 3, 'classroom_label' => 'ห้อง '.(1 + $i % 3), 'answers' => $a, 'score' => 0, 'section_scores' => null, 'source' => 'csv',
                'recorded_by' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $c) DB::table('exm_sheets')->insert($c);
        $t = microtime(true);
        $res = $this->postJson(self::B.'/exams/'.$e['id'].'/rescore')->assertOk()->json();
        $took = microtime(true) - $t;
        $this->assertSame(2000, $res['total']);
        $this->assertGreaterThanOrEqual(900, $res['changed']);
        $this->assertLessThan(10, $took, 'rescore took '.$took.'s');
        $newKey = str_repeat('4321', 25);
        $t = microtime(true);
        $r2 = $this->putJson(self::B.'/exams/'.$e['id'], ['version' => $e['version'], 'answer_key' => $newKey])->assertOk()->json('rescored');
        $this->assertSame(2000, $r2['total']);
        $this->assertLessThan(10, microtime(true) - $t);
        $t = microtime(true);
        $a = $this->getJson(self::B.'/exams/'.$e['id'].'/analysis')->assertOk()->json();
        $this->assertSame(2000, $a['sheets']);
        $this->assertCount(100, $a['items']);
        $this->assertCount(3, $a['classrooms']);
        $this->assertLessThan(10, microtime(true) - $t);
        $this->assertSame(2000, $this->getJson(self::B.'/exams/'.$e['id'].'/summary')->json('overall.n'));
    }
}
