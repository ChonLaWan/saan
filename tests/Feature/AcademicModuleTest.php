<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordLinkNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AcademicModuleTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/academic';
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // core still reads config school.term_id (default 1); keep it away from the ids these tests create unless a test says otherwise
        config(['school.term_id' => 987654]);
    }

    private function user(string $role): User
    {
        $this->n++;
        return User::forceCreate(['name' => 'Fictional '.$role.$this->n, 'email' => $role.$this->n.'@school.test', 'password' => 'Original-Password-2026', 'role' => $role,
            'school_id' => 1, 'is_active' => true, 'is_demo' => false, 'auth_version' => 1]);
    }

    private function as(string $role): self
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($this->user($role));
    }

    private function term(array $over = []): array
    {
        return $this->postJson(self::BASE.'/terms', $over + ['year' => 2569, 'term' => 1, 'starts_on' => '2026-05-12', 'ends_on' => '2026-10-10'])->assertCreated()->json();
    }

    private function periodsPayload(): array
    {
        return [['starts_at' => '08:30', 'ends_at' => '09:20'], ['starts_at' => '09:20', 'ends_at' => '10:10'], ['starts_at' => '10:10', 'ends_at' => '10:25', 'kind' => 'break'], ['starts_at' => '10:25', 'ends_at' => '11:15', 'kind' => 'class']];
    }

    private function savePeriods(?array $rows = null): array
    {
        $token = $this->getJson(self::BASE.'/periods')->assertOk()->json('token');
        return $this->putJson(self::BASE.'/periods', ['token' => $token, 'periods' => $rows ?? $this->periodsPayload()])->assertOk()->json();
    }

    /** Breaks are numbered 101.. so listing by number put the lunch break after the last class period; and the editor could not save that list again. */
    public function test_periods_are_listed_in_time_order_and_the_listed_rows_can_be_saved_again(): void
    {
        $this->as('admin');
        $this->savePeriods();
        $listed = $this->getJson(self::BASE.'/periods')->assertOk()->json();
        $this->assertSame(['08:30', '09:20', '10:10', '10:25'], array_column($listed['data'], 'starts_at'));
        $this->assertSame([1, 2, 101, 3], array_column($listed['data'], 'number'));
        $this->assertSame(['class', 'class', 'break', 'class'], array_column($listed['data'], 'kind'));
        $rows = array_map(fn ($r) => ['starts_at' => $r['starts_at'], 'ends_at' => $r['ends_at'], 'kind' => $r['kind']], $listed['data']);
        $rows[3]['ends_at'] = '11:20';
        $again = $this->putJson(self::BASE.'/periods', ['token' => $listed['token'], 'periods' => $rows])->assertOk()->json();
        $this->assertSame([1, 2, 101, 3], array_column($again['data'], 'number'));
        $this->assertSame('11:20', $again['data'][3]['ends_at']);
        $this->assertSame(4, DB::table('bell_periods')->count());
    }

    public function test_blank_school_wizard_end_to_end(): void
    {
        Notification::fake();
        $this->as('admin');
        $this->assertSame(0, DB::table('academic_terms')->count());
        $s = $this->getJson(self::BASE.'/setup')->assertOk()->json();
        $this->assertSame('term', $s['next']);
        $this->assertFalse($s['complete']);
        $this->assertSame(['term', 'periods', 'classrooms', 'teachers', 'homerooms', 'accounts'], array_column($s['steps'], 'key'));
        $this->assertTrue($s['steps'][5]['optional']);

        // 1 term: the first term becomes the current one
        $t = $this->term(['label' => 'ภาคเรียนทดสอบ']);
        $this->assertTrue($t['is_current']);
        $this->assertSame(1, $t['version']);
        $this->assertSame('periods', $this->getJson(self::BASE.'/setup')->json('next'));
        $this->assertSame($t['id'], \App\Support\Saan\TermScope::currentId());

        // 2 periods: class periods are numbered 1..n, break gets 101; the list comes back in time order (the break between periods 2 and 3)
        $p = $this->savePeriods();
        $this->assertSame([1, 2, 101, 3], array_column($p['data'], 'number'));
        $this->assertSame(['class', 'class', 'break', 'class'], array_column($p['data'], 'kind'));
        $this->assertSame('10:25', $p['data'][3]['starts_at']);
        $this->assertSame('classrooms', $this->getJson(self::BASE.'/setup')->json('next'));

        // 3 classrooms from a pattern
        $b = $this->postJson(self::BASE.'/classrooms/bulk', ['pattern' => 'ป.1-ป.2', 'sections' => 2])->assertCreated()->json();
        $this->assertSame(4, $b['created']);
        $this->assertSame(['ป.1/1', 'ป.1/2', 'ป.2/1', 'ป.2/2'], array_column($b['classrooms'], 'name'));
        $this->assertSame('ป.1', $b['classrooms'][0]['grade_level']);
        $this->assertSame(2, $b['classrooms'][1]['section']);
        $again = $this->postJson(self::BASE.'/classrooms/bulk', ['grades' => ['ป.1'], 'sections' => 3])->assertCreated()->json();
        $this->assertSame(1, $again['created']);
        $this->assertSame(['ป.1/1', 'ป.1/2'], $again['skipped']);
        $this->assertSame('teachers', $this->getJson(self::BASE.'/setup')->json('next'));

        // 4 teachers by paste
        $paste = "นาย สมชาย ใจดี, คณิตศาสตร์\nนางสาว มาลี รักเรียน, ภาษาไทย\nครู วิชัย ตัวอย่าง\n\nน.ส. สุดา ทดสอบ ศรีสวัสดิ์, วิทยาศาสตร์ ";
        $r = $this->postJson(self::BASE.'/teachers/bulk', ['text' => $paste])->assertCreated()->json();
        $this->assertCount(4, $r['created']);
        $this->assertSame(1, DB::table('users')->count()); // only the admin exists: teachers have no login until step 6
        $this->assertSame('homerooms', $this->getJson(self::BASE.'/setup')->json('next'));

        // 5 homerooms
        $rooms = $this->getJson(self::BASE.'/classrooms')->json('data');
        $teachers = $this->getJson(self::BASE.'/teachers')->json('data');
        $assign = [];
        foreach ($rooms as $i => $room) $assign[] = ['classroom_id' => $room['id'], 'version' => $room['version'], 'teacher_id' => $teachers[$i % count($teachers)]['id']];
        $h = $this->putJson(self::BASE.'/homerooms', ['assignments' => $assign])->assertOk()->json('data');
        $this->assertNotNull($h[0]['homeroom_teacher_name']);
        $this->assertSame(2, $h[0]['version']);
        $s = $this->getJson(self::BASE.'/setup')->json();
        $this->assertSame('summary', $s['next']);
        $this->assertTrue($s['complete']);
        $this->assertSame(0, $s['homerooms']['missing']);
        $this->assertSame(5, $s['classrooms']['count']);

        // 6 optional account for a teacher (invite link, no password shown)
        $tid = $teachers[0]['id'];
        $acc = $this->postJson(self::BASE.'/teachers/'.$tid.'/account', ['email' => 'Somchai@School.test'])->assertCreated()->json();
        $this->assertTrue($acc['invite_sent']);
        $this->assertTrue($acc['teacher']['has_account']);
        $this->assertStringNotContainsStringIgnoringCase('password', json_encode($acc));
        $this->assertSame(1, $this->getJson(self::BASE.'/setup')->json('accounts.teachers_with_account'));
    }

    public function test_validation_errors_overlap_and_duplicates(): void
    {
        $this->as('admin');
        $u = fn (array $o) => $this->postJson(self::BASE.'/terms', $o + ['year' => 2569, 'term' => 1, 'starts_on' => '2026-05-12', 'ends_on' => '2026-10-10']);
        $u(['year' => 2400])->assertUnprocessable()->assertJsonValidationErrors('year');
        $u(['year' => 2701])->assertUnprocessable();
        $u(['term' => 4])->assertUnprocessable()->assertJsonValidationErrors('term');
        $u(['starts_on' => '2026-10-10', 'ends_on' => '2026-05-12'])->assertUnprocessable();
        $u(['starts_on' => '2026-02-30'])->assertUnprocessable();
        $first = $this->term();
        $u(['term' => 2, 'starts_on' => '2026-10-01', 'ends_on' => '2027-03-01'])->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $u(['starts_on' => '2027-05-01', 'ends_on' => '2027-09-01'])->assertUnprocessable()->assertJsonValidationErrors('term');
        $second = $this->term(['term' => 2, 'starts_on' => '2026-11-01', 'ends_on' => '2027-03-01']);
        $this->assertFalse($second['is_current']);
        $this->putJson(self::BASE.'/terms/'.$second['id'], ['year' => 2569, 'term' => 2, 'starts_on' => '2026-10-10', 'ends_on' => '2027-03-01', 'version' => 1])->assertUnprocessable();
        $this->postJson(self::BASE.'/terms/'.$second['id'].'/current')->assertOk();
        $this->assertSame($second['id'], \App\Support\Saan\TermScope::currentId());
        $this->getJson(self::BASE.'/terms')->assertOk()->assertJsonCount(2, 'data');

        $p = fn (array $rows) => $this->putJson(self::BASE.'/periods', ['token' => $this->getJson(self::BASE.'/periods')->json('token'), 'periods' => $rows]);
        $p([['starts_at' => '8:30', 'ends_at' => '09:20']])->assertUnprocessable()->assertJsonValidationErrors('periods');
        $p([['starts_at' => '08:30', 'ends_at' => '09:20'], ['starts_at' => '09:00', 'ends_at' => '10:00']])->assertUnprocessable();
        $p([['starts_at' => '09:20', 'ends_at' => '08:30']])->assertUnprocessable();
        $p([['starts_at' => '10:00', 'ends_at' => '10:50'], ['starts_at' => '08:30', 'ends_at' => '09:20']])->assertUnprocessable();
        $p([['starts_at' => '25:00', 'ends_at' => '26:00']])->assertUnprocessable();
        $p([['starts_at' => '08:30', 'ends_at' => '09:20', 'kind' => 'break']])->assertUnprocessable();
        $p([['starts_at' => '08:30', 'ends_at' => '09:20', 'kind' => 'nap']])->assertUnprocessable();
        $p([])->assertUnprocessable();
        $this->assertSame(0, DB::table('bell_periods')->count());

        $this->postJson(self::BASE.'/classrooms', ['grade_level' => 'ป.4!!'])->assertUnprocessable()->assertJsonValidationErrors('grade_level');
        $this->postJson(self::BASE.'/classrooms', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(self::BASE.'/classrooms', ['name' => 'ห้อง', 'homeroom_teacher_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('homeroom_teacher_id');
        $this->postJson(self::BASE.'/classrooms/bulk', ['pattern' => 'ป.1-ป.6', 'sections' => 21])->assertUnprocessable();
        $this->postJson(self::BASE.'/classrooms/bulk', ['pattern' => ' , ', 'sections' => 1])->assertUnprocessable()->assertJsonValidationErrors('grades');
        $this->postJson(self::BASE.'/teachers', ['first_name' => ''])->assertUnprocessable();
        $this->postJson(self::BASE.'/teachers/bulk', ['text' => "\n \n"])->assertUnprocessable();
        $this->postJson(self::BASE.'/calendar', ['date' => '2026-11-12', 'kind' => 'party', 'title' => 'x'])->assertUnprocessable();
        $this->postJson(self::BASE.'/calendar', ['date' => '2026-13-12', 'kind' => 'event', 'title' => 'x'])->assertUnprocessable();
        $this->postJson(self::BASE.'/calendar', ['date' => '2026-11-12', 'kind' => 'event', 'title' => 'x', 'term_id' => 999])->assertUnprocessable();
        $this->assertSame(2, DB::table('academic_terms')->count());
        $this->assertGreaterThan(0, $first['id']);
    }

    public function test_classroom_name_duplicate_is_422_not_500_and_case_space_insensitive(): void
    {
        $this->as('admin');
        $a = $this->postJson(self::BASE.'/classrooms', ['name' => 'M.1/1'])->assertCreated()->json();
        foreach (['m.1/1', ' M.1 / 1 ', "M.1/\u{00A0}1", 'M. 1/1'] as $dup) {
            $this->postJson(self::BASE.'/classrooms', ['name' => $dup])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $this->postJson(self::BASE.'/classrooms', ['grade_level' => 'M.1', 'section' => 1])->assertUnprocessable();
        $b = $this->postJson(self::BASE.'/classrooms', ['name' => 'M.1/2'])->assertCreated()->json();
        $this->putJson(self::BASE.'/classrooms/'.$b['id'], ['name' => 'm.1/1', 'version' => $b['version']])->assertUnprocessable();
        $this->putJson(self::BASE.'/classrooms/'.$b['id'], ['name' => 'M.1/2', 'is_active' => false, 'version' => $b['version']])->assertOk()->assertJsonPath('is_active', false);
        $this->assertSame(2, DB::table('classrooms')->count());
        $this->assertSame('m.1/1', DB::table('classrooms')->where('id', $a['id'])->value('name_key'));
        $this->assertSame('ป.4/1', \Saan\Modules\Academic\Academic::key(" ป.4 /\t1 "));
        // the unique index itself rejects a duplicate key
        try {
            DB::table('classrooms')->insert(['school_id' => 1, 'name' => 'dup', 'name_key' => 'm.1/1']);
            $this->fail('unique index did not fire');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(2, DB::table('classrooms')->count());
        }
    }

    public function test_guard_turns_a_unique_race_into_422_but_not_other_constraint_failures(): void
    {
        $this->as('admin');
        $this->postJson(self::BASE.'/classrooms', ['name' => 'M.2/1'])->assertCreated();
        $guard = new \ReflectionMethod(\Saan\Modules\Academic\Academic::class, 'guard');
        $svc = app(\Saan\Modules\Academic\Academic::class);
        // a duplicate that slips past the application check: the insert hits the unique index inside guard()
        try {
            $guard->invoke($svc, fn () => DB::table('classrooms')->insert(['school_id' => 1, 'name' => 'x', 'name_key' => 'm.2/1']), 'name', 'ชื่อซ้ำ');
            $this->fail('guard did not convert the unique violation');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }
        // a foreign-key failure is a real error, not "duplicate name"
        $this->expectException(\Illuminate\Database\QueryException::class);
        $guard->invoke($svc, fn () => DB::table('classrooms')->insert(['school_id' => 1, 'name' => 'fk', 'name_key' => 'fk', 'homeroom_teacher_id' => 987654]), 'name', 'ชื่อซ้ำ');
    }

    public function test_version_conflicts_return_409_with_current_row(): void
    {
        $this->as('admin');
        $t = $this->term();
        $edit = ['year' => 2569, 'term' => 1, 'starts_on' => '2026-05-12', 'ends_on' => '2026-10-11'];
        $ok = $this->putJson(self::BASE.'/terms/'.$t['id'], $edit + ['version' => 1])->assertOk()->json();
        $this->assertSame(2, $ok['version']);
        $r = $this->putJson(self::BASE.'/terms/'.$t['id'], $edit + ['version' => 1])->assertStatus(409);
        $this->assertSame('2026-10-11', $r->json('current.ends_on'));
        $this->assertSame(2, $r->json('current.version'));
        $this->deleteJson(self::BASE.'/terms/'.$t['id'], ['version' => 1])->assertStatus(409);

        $c = $this->postJson(self::BASE.'/classrooms', ['name' => 'ป.5/1'])->assertCreated()->json();
        $this->putJson(self::BASE.'/classrooms/'.$c['id'], ['name' => 'ป.5/1', 'version' => 1])->assertOk();
        $this->putJson(self::BASE.'/classrooms/'.$c['id'], ['name' => 'ป.5/9', 'version' => 1])->assertStatus(409)->assertJsonPath('current.name', 'ป.5/1');
        $this->deleteJson(self::BASE.'/classrooms/'.$c['id'], ['version' => 1])->assertStatus(409);

        $tc = $this->postJson(self::BASE.'/teachers', ['prefix' => 'นาย', 'first_name' => 'ทดสอบ', 'last_name' => 'หนึ่ง'])->assertCreated()->json();
        $this->assertSame('นายทดสอบ หนึ่ง', $tc['name']);
        $this->putJson(self::BASE.'/teachers/'.$tc['id'], ['first_name' => 'ทดสอบ', 'last_name' => 'สอง', 'version' => 1])->assertOk();
        $this->putJson(self::BASE.'/teachers/'.$tc['id'], ['first_name' => 'ทดสอบ', 'last_name' => 'สาม', 'version' => 1])->assertStatus(409);

        $this->savePeriods();
        $this->putJson(self::BASE.'/periods', ['token' => 'stale', 'periods' => $this->periodsPayload()])->assertStatus(409)->assertJsonStructure(['message', 'current' => ['data', 'token']]);

        // all-or-nothing homeroom save: one stale row aborts the whole batch
        $room = $this->getJson(self::BASE.'/classrooms')->json('data.0');
        $this->postJson(self::BASE.'/classrooms', ['name' => 'ป.5/2'])->assertCreated();
        $room2 = collect($this->getJson(self::BASE.'/classrooms')->json('data'))->firstWhere('name', 'ป.5/2');
        $tid = $this->getJson(self::BASE.'/teachers')->json('data.0.id');
        $this->putJson(self::BASE.'/homerooms', ['assignments' => [
            ['classroom_id' => $room2['id'], 'version' => $room2['version'], 'teacher_id' => $tid],
            ['classroom_id' => $room['id'], 'version' => 1, 'teacher_id' => $tid],
        ]])->assertStatus(409);
        $this->assertNull(DB::table('classrooms')->where('id', $room2['id'])->value('homeroom_teacher_id'));
    }

    public function test_teacher_bulk_paste_parsing_dry_run_and_duplicates(): void
    {
        $this->as('admin');
        $text = "นาย สมชาย ใจดี, คณิตศาสตร์\nนายสมชาย  ใจดี, คณิตศาสตร์\nนางสาวมาลี รักเรียน,ภาษาไทย\nครู อรุณ\nนาง สมศรี ใจเย็น มากมี, ศิลปะ, พิเศษ\n   ";
        $dry = $this->postJson(self::BASE.'/teachers/bulk', ['text' => $text, 'dry_run' => true])->assertOk()->json();
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(0, DB::table('teachers')->count());
        $this->assertCount(4, $dry['created']);
        $this->assertSame([['line' => 2, 'text' => 'นายสมชาย  ใจดี, คณิตศาสตร์', 'reason' => 'ชื่อซ้ำ']], $dry['skipped']);
        $r = $this->postJson(self::BASE.'/teachers/bulk', ['text' => $text])->assertCreated()->json();
        $this->assertCount(4, $r['created']);
        $rows = DB::table('teachers')->orderBy('id')->get();
        $this->assertSame(['นายสมชาย ใจดี', 'นางสาวมาลี รักเรียน', 'ครูอรุณ', 'นางสมศรี ใจเย็น มากมี'], $rows->pluck('name')->all());
        $this->assertSame('คณิตศาสตร์', $rows[0]->subject_group);
        $this->assertSame('คณิตศาสตร์', $rows[0]->subject);
        $this->assertSame('', $rows[2]->subject);
        $this->assertNull($rows[2]->subject_group);
        $this->assertSame('มากมี', explode(' ', $rows[3]->last_name)[1]);
        $this->assertSame('ศิลปะ, พิเศษ', $rows[3]->subject_group);
        $this->assertSame('มาลี', $rows[1]->first_name);
        $this->assertSame('นางสาว', $rows[1]->prefix);
        // pasting the same list again creates nothing
        $again = $this->postJson(self::BASE.'/teachers/bulk', ['text' => $text])->assertOk()->json();
        $this->assertCount(0, $again['created']);
        $this->assertCount(5, $again['skipped']);
        $this->postJson(self::BASE.'/teachers', ['prefix' => 'นาย', 'first_name' => 'สมชาย', 'last_name' => 'ใจดี'])->assertUnprocessable();
    }

    public function test_teacher_account_uses_existing_invite_path(): void
    {
        Notification::fake();
        $admin = $this->user('admin');
        $this->actingAs($admin);
        $room = $this->postJson(self::BASE.'/classrooms', ['name' => 'ป.6/1'])->assertCreated()->json();
        $t = $this->postJson(self::BASE.'/teachers', ['prefix' => 'นาง', 'first_name' => 'ตัวอย่าง', 'last_name' => 'สมมติ'])->assertCreated()->json();
        $this->putJson(self::BASE.'/homerooms', ['assignments' => [['classroom_id' => $room['id'], 'version' => $room['version'], 'teacher_id' => $t['id']]]])->assertOk();
        $this->postJson(self::BASE.'/teachers/'.$t['id'].'/account', ['email' => 'not-an-email'])->assertUnprocessable();
        $this->postJson(self::BASE.'/teachers/'.$t['id'].'/account', ['email' => 'taken@school.test'])->assertCreated();
        $user = User::where('email', 'taken@school.test')->firstOrFail();
        $this->assertSame('teacher', $user->role);
        $this->assertSame($t['id'], (int) $user->teacher_id);
        $this->assertSame($room['id'], (int) $user->classroom_id);
        $this->assertFalse((bool) $user->is_demo);
        $this->assertSame($user->id, (int) DB::table('teachers')->where('id', $t['id'])->value('user_id'));
        Notification::assertSentTo($user, PasswordLinkNotification::class, fn ($n) => (fn () => $this->purpose)->call($n) === 'invite');
        Notification::assertCount(1);
        $this->assertDatabaseHas('audit_events', ['action' => 'account.created', 'entity_id' => $user->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'account.password_link_sent', 'entity_id' => $user->id]);
        // one account per teacher, one account per e-mail
        $this->postJson(self::BASE.'/teachers/'.$t['id'].'/account', ['email' => 'second@school.test'])->assertUnprocessable();
        $other = $this->postJson(self::BASE.'/teachers', ['first_name' => 'คนที่สอง'])->assertCreated()->json();
        $this->postJson(self::BASE.'/teachers/'.$other['id'].'/account', ['email' => 'TAKEN@school.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        // a teacher with an account cannot be deleted
        $this->deleteJson(self::BASE.'/teachers/'.$t['id'], ['version' => 2])->assertUnprocessable();
        // principal cannot create accounts (core rule: admin only)
        $this->as('principal')->postJson(self::BASE.'/teachers/'.$other['id'].'/account', ['email' => 'p@school.test'])->assertForbidden();
        $this->assertSame(0, User::where('email', 'p@school.test')->count());
    }

    public function test_role_restrictions(): void
    {
        $this->as('admin');
        $t = $this->term();
        $c = $this->postJson(self::BASE.'/classrooms', ['name' => 'ป.3/1'])->assertCreated()->json();
        $tc = $this->postJson(self::BASE.'/teachers', ['first_name' => 'ครูทดสอบ', 'subject_group' => 'ศิลปะ'])->assertCreated()->json();
        $this->assertTrue($tc['has_account'] === false);

        $this->app['auth']->forgetGuards();
        $this->app['auth']->forgetGuards();
        $this->getJson(self::BASE.'/terms')->assertUnauthorized();

        $this->as('teacher');
        foreach (['terms', 'periods', 'classrooms', 'calendar'] as $read) $this->getJson(self::BASE.'/'.$read)->assertOk();
        $list = $this->getJson(self::BASE.'/teachers')->assertOk()->json('data.0');
        $this->assertSame(['id', 'name', 'subject_group', 'is_active'], array_keys($list));
        $this->getJson(self::BASE.'/setup')->assertForbidden();
        $this->postJson(self::BASE.'/terms', ['year' => 2570, 'term' => 1, 'starts_on' => '2027-05-12', 'ends_on' => '2027-10-10'])->assertForbidden();
        $this->putJson(self::BASE.'/terms/'.$t['id'], ['year' => 2569, 'term' => 1, 'starts_on' => '2026-05-12', 'ends_on' => '2026-10-10', 'version' => 1])->assertForbidden();
        $this->postJson(self::BASE.'/terms/'.$t['id'].'/current')->assertForbidden();
        $this->putJson(self::BASE.'/periods', ['token' => 'x', 'periods' => $this->periodsPayload()])->assertForbidden();
        $this->postJson(self::BASE.'/classrooms', ['name' => 'x'])->assertForbidden();
        $this->postJson(self::BASE.'/classrooms/bulk', ['pattern' => 'ป.1', 'sections' => 1])->assertForbidden();
        $this->deleteJson(self::BASE.'/classrooms/'.$c['id'], ['version' => 1])->assertForbidden();
        $this->putJson(self::BASE.'/homerooms', ['assignments' => [['classroom_id' => $c['id'], 'version' => 1, 'teacher_id' => null]]])->assertForbidden();
        $this->postJson(self::BASE.'/teachers', ['first_name' => 'x'])->assertForbidden();
        $this->postJson(self::BASE.'/teachers/bulk', ['text' => 'x'])->assertForbidden();
        $this->postJson(self::BASE.'/teachers/'.$tc['id'].'/account', ['email' => 'a@b.test'])->assertForbidden();
        $this->postJson(self::BASE.'/calendar', ['date' => '2026-11-12', 'kind' => 'event', 'title' => 'x'])->assertForbidden();

        $this->as('editor');
        foreach (['setup', 'terms', 'periods', 'classrooms', 'teachers', 'calendar'] as $read) $this->getJson(self::BASE.'/'.$read)->assertForbidden();

        $this->as('principal');
        $this->getJson(self::BASE.'/setup')->assertOk();
        $this->postJson(self::BASE.'/classrooms', ['name' => 'ป.3/2'])->assertCreated();
        $this->postJson(self::BASE.'/calendar', ['date' => '2026-11-12', 'kind' => 'event', 'title' => 'กิจกรรมทดสอบ'])->assertCreated();
        $this->assertDatabaseHas('audit_events', ['action' => 'academic.classroom.created']);
    }

    public function test_school_scoping_hides_other_school_rows(): void
    {
        $this->as('admin');
        $now = now();
        $t2 = DB::table('academic_terms')->insertGetId(['school_id' => 2, 'year' => 2569, 'term' => 1, 'starts_on' => '2026-05-12', 'ends_on' => '2026-10-10']);
        $teacher2 = DB::table('teachers')->insertGetId(['school_id' => 2, 'name' => 'ครูโรงเรียนอื่น', 'subject' => '']);
        $room2 = DB::table('classrooms')->insertGetId(['school_id' => 2, 'name' => 'ป.4/1', 'homeroom_teacher_id' => $teacher2]);
        $p2 = DB::table('bell_periods')->insertGetId(['school_id' => 2, 'number' => 1, 'starts_at' => '07:00', 'ends_at' => '07:50']);
        $d2 = DB::table('acad_calendar_days')->insertGetId(['school_id' => 2, 'date' => '2026-11-12', 'kind' => 'event', 'title' => 'งานโรงเรียนอื่น', 'created_at' => $now, 'updated_at' => $now]);

        foreach (['terms', 'classrooms', 'teachers', 'calendar'] as $list) $this->getJson(self::BASE.'/'.$list)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame([], $this->getJson(self::BASE.'/periods')->json('data'));
        $this->assertSame(0, $this->getJson(self::BASE.'/setup')->json('classrooms.count'));
        // the same term, classroom and period numbers are free in this school
        $t1 = $this->term();
        $this->postJson(self::BASE.'/classrooms', ['name' => 'ป.4/1'])->assertCreated();
        $this->savePeriods();
        $this->assertSame(1, DB::table('bell_periods')->where('school_id', 2)->count());
        $this->assertSame('07:00', DB::table('bell_periods')->where('id', $p2)->value('starts_at'));
        // direct access to school 2 ids is 404
        $this->putJson(self::BASE.'/terms/'.$t2, ['year' => 2569, 'term' => 1, 'starts_on' => '2026-05-12', 'ends_on' => '2026-10-11', 'version' => 1])->assertNotFound();
        $this->deleteJson(self::BASE.'/terms/'.$t2, ['version' => 1])->assertNotFound();
        $this->postJson(self::BASE.'/terms/'.$t2.'/current')->assertNotFound();
        $this->putJson(self::BASE.'/classrooms/'.$room2, ['name' => 'x', 'version' => 1])->assertNotFound();
        $this->deleteJson(self::BASE.'/classrooms/'.$room2, ['version' => 1])->assertNotFound();
        $this->putJson(self::BASE.'/teachers/'.$teacher2, ['first_name' => 'x', 'version' => 1])->assertNotFound();
        $this->deleteJson(self::BASE.'/teachers/'.$teacher2, ['version' => 1])->assertNotFound();
        $this->postJson(self::BASE.'/teachers/'.$teacher2.'/account', ['email' => 'x@school.test'])->assertNotFound();
        $this->putJson(self::BASE.'/calendar/'.$d2, ['date' => '2026-11-12', 'kind' => 'event', 'title' => 'x', 'version' => 1])->assertNotFound();
        $this->deleteJson(self::BASE.'/calendar/'.$d2, ['version' => 1])->assertNotFound();
        // a teacher or term of school 2 cannot be used from school 1
        $room1 = $this->getJson(self::BASE.'/classrooms')->json('data.0');
        $this->putJson(self::BASE.'/classrooms/'.$room1['id'], ['name' => 'ป.4/1', 'homeroom_teacher_id' => $teacher2, 'version' => $room1['version']])->assertUnprocessable();
        $this->postJson(self::BASE.'/calendar', ['date' => '2026-11-13', 'kind' => 'event', 'title' => 'x', 'term_id' => $t2])->assertUnprocessable();
        $this->assertNotSame($t1['id'], $t2);
    }

    public function test_calendar_crud_and_delete_guards(): void
    {
        $this->as('admin');
        $t = $this->term();
        $d = $this->postJson(self::BASE.'/calendar', ['date' => '2026-07-28', 'kind' => 'holiday', 'title' => 'วันหยุดทดสอบ', 'term_id' => $t['id']])->assertCreated()->json();
        $this->postJson(self::BASE.'/calendar', ['date' => '2026-07-28', 'kind' => 'event', 'title' => 'วันหยุดทดสอบ'])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson(self::BASE.'/calendar', ['date' => '2026-07-28', 'kind' => 'event', 'title' => 'อีกรายการ'])->assertCreated();
        $this->postJson(self::BASE.'/calendar', ['date' => '2026-09-01', 'kind' => 'exam', 'title' => 'สอบกลางภาค'])->assertCreated();
        $this->getJson(self::BASE.'/calendar?from=2026-08-01&to=2026-09-30')->assertJsonCount(1, 'data');
        $this->getJson(self::BASE.'/calendar?term_id='.$t['id'])->assertJsonCount(1, 'data');
        $this->getJson(self::BASE.'/calendar')->assertJsonCount(3, 'data');
        $this->putJson(self::BASE.'/calendar/'.$d['id'], ['date' => '2026-07-29', 'kind' => 'no_class', 'title' => 'วันหยุดทดสอบ', 'version' => 1])->assertOk()->assertJsonPath('version', 2);
        $this->deleteJson(self::BASE.'/calendar/'.$d['id'], ['version' => 1])->assertStatus(409);
        $this->deleteJson(self::BASE.'/calendar/'.$d['id'], ['version' => 2])->assertOk();
        // deleting a term releases its calendar days instead of leaving a dangling reference
        $this->postJson(self::BASE.'/calendar', ['date' => '2026-08-12', 'kind' => 'holiday', 'title' => 'ผูกภาคเรียน', 'term_id' => $t['id']])->assertCreated();
        $this->deleteJson(self::BASE.'/terms/'.$t['id'], ['version' => 1])->assertOk();
        $this->assertSame(0, DB::table('acad_calendar_days')->whereNotNull('term_id')->count());
        $this->assertNull(\App\Support\Saan\TermScope::currentId());
    }

    public function test_term_declared_by_other_modules_cannot_be_deleted_while_they_hold_rows(): void
    {
        $this->as('admin');
        $t = $this->term();
        $id = DB::table('act_clubs')->insertGetId(['school_id' => 1, 'term_id' => $t['id'], 'name' => 'ชุมนุมหุ่นยนต์', 'name_key' => 'ชุมนุมหุ่นยนต์', 'created_at' => now(), 'updated_at' => now()]);
        $this->deleteJson(self::BASE.'/terms/'.$t['id'], ['version' => $t['version']])->assertUnprocessable()->assertJsonValidationErrors('term');
        $this->assertNotNull(DB::table('academic_terms')->find($t['id']));
        DB::table('act_clubs')->where('id', $id)->delete();
        $this->deleteJson(self::BASE.'/terms/'.$t['id'], ['version' => $t['version']])->assertOk();
    }

    public function test_term_with_assessment_exam_or_report_rows_cannot_be_deleted(): void
    {
        $this->as('admin');
        $t = $this->term();
        $tables = ['asm_courses' => ['subject_id' => 1, 'classroom_id' => 1, 'policy' => '{}', 'status' => 'open', 'version' => 1],
            'rpt_remarks' => ['student_id' => 1, 'remark' => 'x', 'version' => 1]];
        foreach ($tables as $table => $cols) {
            $id = DB::table($table)->insertGetId(['school_id' => 1, 'term_id' => $t['id'], 'created_at' => now(), 'updated_at' => now()] + $cols);
            $this->deleteJson(self::BASE.'/terms/'.$t['id'], ['version' => $t['version']])->assertUnprocessable()->assertJsonValidationErrors('term');
            DB::table($table)->where('id', $id)->delete();
        }
        $this->deleteJson(self::BASE.'/terms/'.$t['id'], ['version' => $t['version']])->assertOk();
    }

    public function test_calendar_title_key_migration_rekeys_rows_cut_at_150_characters(): void
    {
        $this->as('admin');
        $title = str_repeat('ก', 155);
        $key = mb_strtolower($title);
        DB::table('acad_calendar_days')->insert(['school_id' => 1, 'date' => '2026-08-01', 'kind' => 'event', 'title' => $title, 'title_key' => mb_substr($key, 0, 150), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        (require base_path('modules/academic/database/migrations/2026_11_10_100004_academic_rekey_calendar_titles.php'))->up();
        $this->assertSame($key, DB::table('acad_calendar_days')->value('title_key'));
        $this->postJson(self::BASE.'/calendar', ['date' => '2026-08-01', 'kind' => 'event', 'title' => $title])->assertUnprocessable()->assertJsonValidationErrors('title');
    }

    public function test_demo_data_stays_compatible_and_lesson_periods_are_protected(): void
    {
        config(['school.demo' => true]);
        $this->seed();
        $this->actingAs(User::where('role', 'admin')->first());
        $rooms = $this->getJson(self::BASE.'/classrooms')->assertOk()->json('data');
        $this->assertSame(['ป.4/1', 'ป.5/1', 'ม.1/1'], array_column($rooms, 'name'));
        $this->assertSame(['ป.4', 'ป.5', 'ม.1'], array_column($rooms, 'grade_level'));
        $this->assertSame(8, $rooms[0]['student_count']);
        $this->assertNotNull($rooms[0]['homeroom_teacher_name']);
        $this->assertSame(0, DB::table('classrooms')->whereNull('name_key')->count());
        $this->postJson(self::BASE.'/classrooms', ['name' => ' ป.4 / 1'])->assertUnprocessable();
        $this->assertCount(6, $this->getJson(self::BASE.'/teachers')->json('data'));
        $this->assertSame('summary', $this->getJson(self::BASE.'/setup')->json('next'));
        // lessons reference every seeded period: dropping one is refused, retiming in place is allowed and keeps ids
        $periods = $this->getJson(self::BASE.'/periods')->json();
        $this->assertCount(7, $periods['data']);
        $rows = array_map(fn ($p) => ['starts_at' => $p['starts_at'], 'ends_at' => $p['ends_at'], 'kind' => $p['kind']], $periods['data']);
        $this->putJson(self::BASE.'/periods', ['token' => $periods['token'], 'periods' => array_slice($rows, 0, 6)])->assertUnprocessable();
        $rows[0]['starts_at'] = '08:20';
        $new = $this->putJson(self::BASE.'/periods', ['token' => $periods['token'], 'periods' => $rows])->assertOk()->json();
        $this->assertSame(array_column($periods['data'], 'id'), array_column($new['data'], 'id'));
        $this->assertSame('08:20', $new['data'][0]['starts_at']);
        $this->assertSame(2, $new['data'][0]['version']);
        $this->assertSame(1, $new['data'][1]['version']);
        $this->assertSame(0, DB::table('lessons')->whereNotIn('period_id', array_column($new['data'], 'id'))->count());
        // a term and a classroom that lessons still use cannot be deleted
        $term = $this->getJson(self::BASE.'/terms')->json('data.0');
        $this->deleteJson(self::BASE.'/terms/'.$term['id'], ['version' => $term['version']])->assertUnprocessable();
        $this->deleteJson(self::BASE.'/classrooms/'.$rooms[0]['id'], ['version' => $rooms[0]['version']])->assertUnprocessable();
    }

    public function test_work_menu_and_assets_are_listed_per_role(): void
    {
        $this->as('admin');
        $this->get('/work')->assertOk()->assertSee('ตั้งค่าโรงเรียน')->assertSee('ปฏิทินโรงเรียน')->assertSee('/work#setup', false)->assertSee('/work#calendar', false)
            ->assertSee('assets/modules/academic/academic.js', false)->assertSee('assets/modules/academic/academic.css', false);
        $this->as('principal')->get('/work')->assertOk()->assertSee('ตั้งค่าโรงเรียน')->assertSee('ปฏิทินโรงเรียน');
        $this->as('teacher')->get('/work')->assertOk()->assertSee('ปฏิทินโรงเรียน')->assertDontSee('ตั้งค่าโรงเรียน');
        $this->as('editor')->get('/work')->assertOk()->assertDontSee('ปฏิทินโรงเรียน')->assertDontSee('ตั้งค่าโรงเรียน');
        $this->assertContains('academic', array_column(app(\App\Support\Modules\ModuleRegistry::class)->summary(), 'id'));
        $this->assertTrue(app(\App\Support\Modules\ModuleRegistry::class)->active('academic'));
    }

    public function test_term_that_core_still_uses_cannot_be_deleted_and_operations_state_survives(): void
    {
        $this->as('admin');
        $t = $this->term();
        config(['school.term_id' => $t['id']]);
        $this->deleteJson(self::BASE.'/terms/'.$t['id'], ['version' => $t['version']])->assertUnprocessable()->assertJsonValidationErrors('term');
        $this->assertSame(1, DB::table('academic_terms')->count());
        $this->getJson('/api/state')->assertOk();
        // editing the dates instead is the supported path
        $this->putJson(self::BASE.'/terms/'.$t['id'], ['year' => 2569, 'term' => 1, 'starts_on' => '2026-05-15', 'ends_on' => '2026-10-10', 'version' => $t['version']])->assertOk();
    }

    public function test_term_year_must_match_calendar_dates(): void
    {
        $this->as('admin');
        $bad = ['year' => 2569, 'term' => 1, 'starts_on' => '2030-05-01', 'ends_on' => '2030-09-01'];
        $this->postJson(self::BASE.'/terms', $bad)->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->postJson(self::BASE.'/terms', ['year' => 2569, 'term' => 2, 'starts_on' => '2027-01-10', 'ends_on' => '2027-03-30'])->assertCreated(); // late start inside the same academic year
        $this->assertSame(1, DB::table('academic_terms')->count());
    }

    public function test_current_term_uses_a_setting_version_for_409(): void
    {
        $this->as('admin');
        $a = $this->term();
        $b = $this->term(['term' => 2, 'starts_on' => '2026-11-01', 'ends_on' => '2027-03-01']);
        $v = $this->getJson(self::BASE.'/terms')->assertOk()->json('setting_version');
        $this->assertSame(1, $v); // the first term set the setting
        $ok = $this->postJson(self::BASE.'/terms/'.$b['id'].'/current', ['expected_version' => $v])->assertOk()->json();
        $this->assertSame(2, $ok['setting_version']);
        $this->assertTrue($ok['is_current']);
        // a second user holding the old version loses
        $r = $this->postJson(self::BASE.'/terms/'.$a['id'].'/current', ['expected_version' => $v])->assertStatus(409);
        $this->assertSame($b['id'], $r->json('current.current_term_id'));
        $this->assertSame(2, $r->json('current.version'));
        $this->assertSame($b['id'], \App\Support\Saan\TermScope::currentId());
        $this->postJson(self::BASE.'/terms/'.$a['id'].'/current', ['expected_version' => 2])->assertOk();
        // never set yet: null means "expect no setting"
        DB::table('saan_settings')->where('key', 'academic.current_term_id')->delete();
        $this->postJson(self::BASE.'/terms/'.$a['id'].'/current', ['expected_version' => 5])->assertStatus(409);
        $this->postJson(self::BASE.'/terms/'.$a['id'].'/current', ['expected_version' => null])->assertOk();
    }

    public function test_moving_a_homeroom_teacher_moves_the_account_classroom(): void
    {
        Notification::fake();
        $this->as('admin');
        config(['school.term_id' => $this->term()['id']]); // /api/state needs the core term row to exist
        $roomA = $this->postJson(self::BASE.'/classrooms', ['name' => 'ป.2/1'])->assertCreated()->json();
        $roomB = $this->postJson(self::BASE.'/classrooms', ['name' => 'ป.2/2'])->assertCreated()->json();
        $t1 = $this->postJson(self::BASE.'/teachers', ['first_name' => 'ครูเดิม', 'last_name' => 'ทดสอบ'])->assertCreated()->json();
        $t2 = $this->postJson(self::BASE.'/teachers', ['first_name' => 'ครูใหม่', 'last_name' => 'ทดสอบ'])->assertCreated()->json();
        $this->putJson(self::BASE.'/homerooms', ['assignments' => [['classroom_id' => $roomA['id'], 'version' => $roomA['version'], 'teacher_id' => $t1['id']]]])->assertOk();
        $this->postJson(self::BASE.'/teachers/'.$t1['id'].'/account', ['email' => 'old@school.test'])->assertCreated();
        $this->postJson(self::BASE.'/teachers/'.$t2['id'].'/account', ['email' => 'new@school.test'])->assertCreated();
        $u1 = User::where('email', 'old@school.test')->firstOrFail();
        $u2 = User::where('email', 'new@school.test')->firstOrFail();
        $this->assertSame($roomA['id'], (int) $u1->classroom_id);
        $this->assertNull($u2->classroom_id);
        $av1 = (int) $u1->auth_version;

        // a principal (not admin-only SchoolAccounts::save) moves the homeroom A: t1 -> t2
        $roomA = collect($this->getJson(self::BASE.'/classrooms')->json('data'))->firstWhere('name', 'ป.2/1');
        $this->as('principal')->putJson(self::BASE.'/homerooms', ['assignments' => [['classroom_id' => $roomA['id'], 'version' => $roomA['version'], 'teacher_id' => $t2['id']]]])->assertOk();
        $u1->refresh();
        $u2->refresh();
        $this->assertNull($u1->classroom_id);
        $this->assertSame($roomA['id'], (int) $u2->classroom_id);
        $this->assertGreaterThan($av1, (int) $u1->auth_version);
        $this->assertDatabaseHas('audit_events', ['action' => 'academic.homeroom.account_synced', 'entity_id' => $u1->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'academic.homeroom.account_synced', 'entity_id' => $u2->id]);
        // the former teacher no longer sees the old room in the operations state, the new one does
        $this->app['auth']->forgetGuards();
        $this->actingAs($u1->fresh())->getJson('/api/state')->assertOk()->assertJsonCount(0, 'classrooms');
        $this->app['auth']->forgetGuards();
        $this->actingAs($u2->fresh())->getJson('/api/state')->assertOk()->assertJsonCount(1, 'classrooms')->assertJsonPath('classrooms.0.id', $roomA['id']);

        // updateClassroom moves it too (PUT with homeroom_teacher_id), and clearing it detaches the account
        $this->as('admin');
        $roomA = collect($this->getJson(self::BASE.'/classrooms')->json('data'))->firstWhere('name', 'ป.2/1');
        $this->putJson(self::BASE.'/classrooms/'.$roomA['id'], ['name' => 'ป.2/1', 'homeroom_teacher_id' => $t1['id'], 'version' => $roomA['version']])->assertOk();
        $this->assertSame($roomA['id'], (int) $u1->fresh()->classroom_id);
        $this->assertNull($u2->fresh()->classroom_id);
        $roomA = collect($this->getJson(self::BASE.'/classrooms')->json('data'))->firstWhere('name', 'ป.2/1');
        $this->putJson(self::BASE.'/classrooms/'.$roomA['id'], ['name' => 'ป.2/1', 'homeroom_teacher_id' => null, 'version' => $roomA['version']])->assertOk();
        $this->assertNull($u1->fresh()->classroom_id);
        $this->assertNotNull($roomB['id']);
    }

    public function test_classroom_update_without_homeroom_field_keeps_it_and_inactive_homeroom_is_kept(): void
    {
        $this->as('admin');
        $room = $this->postJson(self::BASE.'/classrooms', ['name' => 'ป.3/3'])->assertCreated()->json();
        $t = $this->postJson(self::BASE.'/teachers', ['first_name' => 'ครูประจำ', 'last_name' => 'ชั้น'])->assertCreated()->json();
        $room = $this->putJson(self::BASE.'/homerooms', ['assignments' => [['classroom_id' => $room['id'], 'version' => $room['version'], 'teacher_id' => $t['id']]]])->assertOk()->json('data.0');
        // rename only: the homeroom teacher stays
        $r = $this->putJson(self::BASE.'/classrooms/'.$room['id'], ['name' => 'ป.3/3 ทดสอบ', 'version' => $room['version']])->assertOk()->json();
        $this->assertSame($t['id'], $r['homeroom_teacher_id']);
        // the teacher is deactivated; renaming again with the unchanged teacher is still accepted
        $this->putJson(self::BASE.'/teachers/'.$t['id'], ['first_name' => 'ครูประจำ', 'last_name' => 'ชั้น', 'is_active' => false, 'version' => $t['version']])->assertOk();
        $r = $this->putJson(self::BASE.'/classrooms/'.$room['id'], ['name' => 'ป.3/3', 'homeroom_teacher_id' => $t['id'], 'version' => $r['version']])->assertOk()->json();
        $this->assertSame($t['id'], $r['homeroom_teacher_id']);
        // but a different inactive teacher cannot be newly chosen
        $other = $this->postJson(self::BASE.'/teachers', ['first_name' => 'ปิดใช้งาน', 'last_name' => 'แล้ว'])->assertCreated()->json();
        $this->putJson(self::BASE.'/teachers/'.$other['id'], ['first_name' => 'ปิดใช้งาน', 'last_name' => 'แล้ว', 'is_active' => false, 'version' => $other['version']])->assertOk();
        $this->putJson(self::BASE.'/classrooms/'.$room['id'], ['name' => 'ป.3/3', 'homeroom_teacher_id' => $other['id'], 'version' => $r['version']])->assertUnprocessable();
    }

    public function test_teacher_parse_prefixes_tabs_and_unknown_first_token(): void
    {
        $svc = app(\Saan\Modules\Academic\Academic::class);
        $p = $svc->parseTeacherLine('ว่าที่ ร.ต. สมชาย ใจดี, สังคมศึกษา');
        $this->assertSame(['ว่าที่ ร.ต.', 'สมชาย', 'ใจดี', 'สังคมศึกษา'], [$p['prefix'], $p['first_name'], $p['last_name'], $p['subject_group']]);
        $p = $svc->parseTeacherLine('ว่าที่ร.ต.สมชาย ใจดี');
        $this->assertSame(['ว่าที่ร.ต.', 'สมชาย', 'ใจดี'], [$p['prefix'], $p['first_name'], $p['last_name']]);
        // the first token is not a known prefix: it is the first name, never a title
        $p = $svc->parseTeacherLine('สมศรี ใจเย็น มากมี');
        $this->assertNull($p['prefix']);
        $this->assertSame(['สมศรี', 'ใจเย็น มากมี'], [$p['first_name'], $p['last_name']]);
        // spreadsheet paste: tab between name and subject group
        $p = $svc->parseTeacherLine("นาย สมชาย ใจดี\tคณิตศาสตร์");
        $this->assertSame(['นาย', 'สมชาย', 'ใจดี', 'คณิตศาสตร์'], [$p['prefix'], $p['first_name'], $p['last_name'], $p['subject_group']]);
        $this->assertSame('นางสาว', $svc->parseTeacherLine('นางสาวมาลี รักเรียน')['prefix']);
        $this->assertSame('นาง', $svc->parseTeacherLine('นาง มาลี')['prefix']);
        $this->assertArrayHasKey('error', $svc->parseTeacherLine('นาย'));

        $this->as('admin');
        $r = $this->postJson(self::BASE.'/teachers/bulk', ['text' => "ว่าที่ ร.ต. วีระ ตัวอย่าง, สังคมศึกษา\nสุภาพ ทดสอบ\tภาษาอังกฤษ"])->assertCreated()->json();
        $this->assertCount(2, $r['created']);
        $rows = DB::table('teachers')->orderBy('id')->get();
        $this->assertSame('ภาษาอังกฤษ', $rows[1]->subject_group);
        $this->assertSame('ทดสอบ', $rows[1]->last_name);
    }

    public function test_teacher_identity_ignores_title_and_is_unique_in_the_database(): void
    {
        $this->as('admin');
        $a = $this->postJson(self::BASE.'/teachers', ['prefix' => 'นางสาว', 'first_name' => 'มาลี', 'last_name' => 'รักเรียน'])->assertCreated()->json();
        // married: a different title is the same person, not a new teacher
        $this->postJson(self::BASE.'/teachers', ['prefix' => 'นาง', 'first_name' => 'มาลี', 'last_name' => 'รักเรียน'])->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->postJson(self::BASE.'/teachers', ['first_name' => 'มาลี', 'last_name' => 'รักเรียน'])->assertUnprocessable();
        $this->putJson(self::BASE.'/teachers/'.$a['id'], ['prefix' => 'นาง', 'first_name' => 'มาลี', 'last_name' => 'รักเรียน', 'version' => $a['version']])->assertOk();
        $this->assertSame('มาลีรักเรียน', DB::table('teachers')->where('id', $a['id'])->value('name_key'));
        $bulk = $this->postJson(self::BASE.'/teachers/bulk', ['text' => "นาย มาลี รักเรียน\nนาย ใหม่ ทดสอบ"])->assertCreated()->json();
        $this->assertSame([1], array_column($bulk['skipped'], 'line'));
        $this->assertCount(1, $bulk['created']);
        // the unique index backs the code check (concurrent requests)
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('teachers')->insert(['school_id' => 1, 'name' => 'ซ้ำ', 'subject' => '', 'name_key' => 'มาลีรักเรียน']);
    }

    public function test_classrooms_sort_in_school_order(): void
    {
        $this->as('admin');
        foreach (['ม.1/1', 'อ.2/1', 'ป.10/1', 'ป.2/1', 'ป.2/2', 'อ.1/1', 'ม.3/1', 'พิเศษ'] as $n) $this->postJson(self::BASE.'/classrooms', ['name' => $n])->assertCreated();
        // names without a grade_level of their own: the legacy fill parses "ป.2/1" into grade/section, "พิเศษ" stays last
        $names = array_column($this->getJson(self::BASE.'/classrooms')->json('data'), 'name');
        $this->assertSame(['อ.1/1', 'อ.2/1', 'ป.2/1', 'ป.2/2', 'ป.10/1', 'ม.1/1', 'ม.3/1', 'พิเศษ'], $names);
    }

    public function test_calendar_duplicate_check_uses_a_normalised_key(): void
    {
        $this->as('admin');
        $d = $this->postJson(self::BASE.'/calendar', ['date' => '2026-12-01', 'kind' => 'event', 'title' => 'Open Day'])->assertCreated()->json();
        $this->postJson(self::BASE.'/calendar', ['date' => '2026-12-01', 'kind' => 'event', 'title' => ' open   DAY '])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson(self::BASE.'/calendar', ['date' => '2026-12-02', 'kind' => 'event', 'title' => 'open day'])->assertCreated();
        $this->assertSame('openday', DB::table('acad_calendar_days')->where('id', $d['id'])->value('title_key'));
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('acad_calendar_days')->insert(['school_id' => 1, 'date' => '2026-12-01', 'kind' => 'event', 'title' => 'x', 'title_key' => 'openday']);
    }

    public function test_migration_can_be_rerun_after_a_partial_failure(): void
    {
        $this->as('admin');
        $this->postJson(self::BASE.'/classrooms', ['name' => 'ป.1/1'])->assertCreated();
        $this->postJson(self::BASE.'/teachers', ['prefix' => 'นาย', 'first_name' => 'ซ้ำ', 'last_name' => 'รัน'])->assertCreated();
        $migration = require base_path('modules/academic/database/migrations/2026_11_06_210001_expand_academic_base.php');
        $migration->up(); // every column and index already exists: must not throw or change data
        $this->assertSame('ซ้ำรัน', DB::table('teachers')->value('name_key'));
        $this->assertSame(1, DB::table('classrooms')->count());
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasIndex('classrooms', 'classrooms_school_name_key'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasIndex('teachers', 'teachers_school_name_uq'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasIndex('acad_calendar_days', 'acad_calendar_day_title_key'));
    }

    public function test_front_end_assets_follow_the_house_rules(): void
    {
        $js = file_get_contents(public_path('assets/modules/academic/academic.js'));
        foreach (['SaanWork.register', 'SaanCore.esc', 'SaanCore.api', 'idempotencyKey'] as $needle) $this->assertStringContainsString($needle, $js);
        foreach (['setup', 'terms', 'periods', 'classrooms', 'teachers', 'calendar'] as $page) $this->assertMatchesRegularExpression("/register\\('$page'/", $js);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $js);
        $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $js.file_get_contents(public_path('assets/modules/academic/academic.css')));
        $this->assertStringContainsString('@media (max-width', file_get_contents(public_path('assets/modules/academic/academic.css')));
    }
}
