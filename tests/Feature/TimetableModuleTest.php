<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Saan\Modules\Timetable\Timetable;
use Tests\TestCase;

class TimetableModuleTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/timetable';

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        DB::table('lessons')->delete(); // deterministic: every scenario builds its own lessons
        // term 1 (seeded) is current and open; term 2 ended in 2025 (closed); term 3 is a fresh empty term
        DB::table('academic_terms')->insert([
            ['id' => 2, 'school_id' => 1, 'year' => 2568, 'term' => 2, 'starts_on' => '2025-06-01', 'ends_on' => '2025-10-31'],
            ['id' => 3, 'school_id' => 1, 'year' => 2569, 'term' => 2, 'starts_on' => '2026-11-01', 'ends_on' => '2027-03-31'],
        ]);
        $this->as('admin');
    }

    private function as(string $role): self
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs(User::where('role', $role)->firstOrFail());
    }

    private function body(array $o = []): array
    {
        return $o + ['term_id' => 1, 'teacher_id' => 1, 'classroom_id' => 1, 'period_id' => 1, 'weekday' => 1, 'room' => 'A101', 'subject' => 'คณิตศาสตร์'];
    }

    private function make(array $o = []): array
    {
        return $this->postJson(self::API.'/lessons', $this->body($o))->assertCreated()->json();
    }

    private function raw(array $o): int
    {
        return DB::table('lessons')->insertGetId($o + ['school_id' => 1, 'term_id' => 1, 'teacher_id' => 1, 'classroom_id' => 1, 'period_id' => 1, 'weekday' => 1,
            'room' => 'X', 'subject' => 'ทดสอบ', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ------------------------------------------------------------------ OPS-002
    public function test_ops002_old_term_lesson_stays_in_its_term(): void
    {
        $l = $this->make(['term_id' => 2, 'confirm' => true]);
        $this->assertSame(2, $l['term_id']);
        // closed term: refused without confirm
        $edit = $this->body(['term_id' => 2, 'subject' => 'ภาษาไทย', 'version' => $l['version']]);
        $this->putJson(self::API.'/lessons/'.$l['id'], $edit)->assertStatus(409)->assertJsonPath('code', 'term_closed');
        $this->assertSame('คณิตศาสตร์', DB::table('lessons')->where('id', $l['id'])->value('subject'));
    }

    public function test_ops002_never_moves_row_into_current_term_and_override_is_audited(): void
    {
        $l = $this->make(['term_id' => 2, 'confirm' => true]);
        // naming the current term for a term-2 lesson is refused, the row is untouched
        $this->putJson(self::API.'/lessons/'.$l['id'], $this->body(['term_id' => 1, 'subject' => 'moved', 'version' => $l['version'], 'confirm' => true]))->assertUnprocessable();
        $this->assertSame(2, (int) DB::table('lessons')->where('id', $l['id'])->value('term_id'));
        // admin edit with confirm keeps term 2 and writes an audit row flagged as an override
        $r = $this->putJson(self::API.'/lessons/'.$l['id'], $this->body(['term_id' => 2, 'subject' => 'ภาษาไทย', 'version' => $l['version'], 'confirm' => true]))->assertOk()->json();
        $this->assertSame(2, $r['term_id']);
        $this->assertSame(2, (int) DB::table('lessons')->where('id', $l['id'])->value('term_id'));
        $audit = DB::table('audit_events')->where('action', 'timetable.lesson.updated')->where('entity_id', $l['id'])->first();
        $this->assertNotNull($audit);
        $this->assertTrue(json_decode($audit->detail, true)['closed_term_override']);
        $this->assertSame(0, DB::table('lessons')->where('term_id', 1)->count());
        // principal cannot touch a closed term even with confirm
        $this->as('principal');
        $this->putJson(self::API.'/lessons/'.$l['id'], $this->body(['term_id' => 2, 'version' => $r['version'], 'confirm' => true]))->assertForbidden();
        $this->deleteJson(self::API.'/lessons/'.$l['id'], ['term_id' => 2, 'version' => $r['version'], 'confirm' => true])->assertForbidden();
    }

    public function test_every_write_needs_an_explicit_term(): void
    {
        $b = $this->body();
        unset($b['term_id']);
        $this->postJson(self::API.'/lessons', $b)->assertUnprocessable();
        $this->getJson(self::API.'/lessons?view=classroom&id=1')->assertUnprocessable();
    }

    // ------------------------------------------------------------------ OPS-005
    public function test_ops005_delete_needs_current_version_and_is_soft_with_undo(): void
    {
        $l = $this->make();
        $this->deleteJson(self::API.'/lessons/'.$l['id'])->assertUnprocessable();
        $upd = $this->putJson(self::API.'/lessons/'.$l['id'], $this->body(['subject' => 'แก้แล้ว', 'version' => $l['version']]))->assertOk()->json();
        // stale version (the one the first screen still holds) must not delete the newer lesson
        $this->deleteJson(self::API.'/lessons/'.$l['id'], ['term_id' => 1, 'version' => $l['version']])->assertStatus(409)->assertJsonPath('current.subject', 'แก้แล้ว');
        $this->assertNull(DB::table('lessons')->where('id', $l['id'])->value('deleted_at'));

        $del = $this->deleteJson(self::API.'/lessons/'.$l['id'], ['term_id' => 1, 'version' => $upd['version']])->assertOk()->json();
        $this->assertTrue($del['undo']);
        $this->assertNotNull(DB::table('lessons')->where('id', $l['id'])->value('deleted_at')); // row kept
        $this->getJson(self::API.'/lessons?term_id=1&view=classroom&id=1')->assertOk()->assertJsonCount(0, 'lessons');
        $this->getJson(self::API.'/trash?term_id=1')->assertOk()->assertJsonCount(1, 'data');
        $audit = DB::table('audit_events')->where('action', 'timetable.lesson.deleted')->first();
        $this->assertSame('แก้แล้ว', json_decode($audit->detail, true)['snapshot']['subject']);

        $this->deleteJson(self::API.'/lessons/'.$l['id'], ['term_id' => 1, 'version' => $del['version']])->assertStatus(409); // already deleted
        $this->postJson(self::API.'/lessons/'.$l['id'].'/restore', ['term_id' => 1, 'version' => $upd['version']])->assertStatus(409); // stale
        $back = $this->postJson(self::API.'/lessons/'.$l['id'].'/restore', ['term_id' => 1, 'version' => $del['version']])->assertOk()->json();
        $this->assertSame('แก้แล้ว', $back['subject']);
        $this->getJson(self::API.'/lessons?term_id=1&view=classroom&id=1')->assertOk()->assertJsonCount(1, 'lessons');
    }

    public function test_ops005_undo_is_refused_when_slot_was_taken_and_slot_can_be_reused(): void
    {
        $l = $this->make();
        $del = $this->deleteJson(self::API.'/lessons/'.$l['id'], ['term_id' => 1, 'version' => $l['version']])->assertOk()->json();
        // the deleted slot (and its room) are free again although the legacy unique indexes still exist
        $other = $this->make(['teacher_id' => 2, 'subject' => 'ภาษาไทย']);
        $this->assertSame(0, DB::table('lessons')->where('id', $l['id'])->count()); // tombstone purged
        $this->assertNotNull(DB::table('audit_events')->where('action', 'timetable.lesson.purged')->first());
        $this->postJson(self::API.'/lessons/'.$l['id'].'/restore', ['term_id' => 1, 'version' => $del['version']])->assertNotFound();

        $x = $this->make(['period_id' => 3, 'room' => 'B1']);
        $d2 = $this->deleteJson(self::API.'/lessons/'.$x['id'], ['term_id' => 1, 'version' => $x['version']])->assertOk()->json();
        $this->make(['period_id' => 3, 'room' => 'ห้องอื่น', 'classroom_id' => 2, 'teacher_id' => 1]); // takes the teacher slot -> purges tombstone
        $this->postJson(self::API.'/lessons/'.$x['id'].'/restore', ['term_id' => 1, 'version' => $d2['version']])->assertNotFound();
        $this->assertNotNull($other['id']);
    }

    // OPS-007
    public function test_ops007_teacher_view_ignores_classroom_filter_and_matches_load(): void
    {
        foreach ([1, 2, 3] as $c) $this->make(['teacher_id' => 4, 'classroom_id' => $c, 'weekday' => 2, 'period_id' => $c, 'room' => 'ห้อง'.$c]);
        $this->make(['teacher_id' => 4, 'classroom_id' => 1, 'weekday' => 3, 'period_id' => 1, 'room' => 'ห้อง1']);
        $this->make(['teacher_id' => 5, 'classroom_id' => 1, 'weekday' => 4, 'period_id' => 1, 'room' => 'ห้อง9']);
        $view = $this->getJson(self::API.'/lessons?term_id=1&view=teacher&id=4&classroom_id=1')->assertOk()->json();
        $this->assertSame(4, $view['periods_per_week']);
        $this->assertSame([1, 2, 3], collect($view['lessons'])->pluck('classroom_id')->unique()->sort()->values()->all());
        $load = collect($this->getJson(self::API.'/load?term_id=1')->assertOk()->json('data'));
        $this->assertSame(4, $load->firstWhere('teacher_id', 4)['periods']);
        $this->assertSame(1, $load->firstWhere('teacher_id', 5)['periods']);
        $this->assertSame(0, $load->firstWhere('teacher_id', 6)['periods']);
        foreach ($load as $row) {
            $this->assertSame($row['periods'], $this->getJson(self::API.'/lessons?term_id=1&view=teacher&id='.$row['teacher_id'])->json('periods_per_week'));
        }
        // soft-deleted lessons do not count in either place
        $one = $view['lessons'][0];
        $this->deleteJson(self::API.'/lessons/'.$one['id'], ['term_id' => 1, 'version' => $one['version']])->assertOk();
        $this->assertSame(3, $this->getJson(self::API.'/lessons?term_id=1&view=teacher&id=4')->json('periods_per_week'));
        $this->assertSame(3, collect($this->getJson(self::API.'/load?term_id=1')->json('data'))->firstWhere('teacher_id', 4)['periods']);
    }

    public function test_room_view(): void
    {
        $this->make(['room' => 'Lab A']);
        $this->make(['room' => 'lab  a', 'period_id' => 2, 'classroom_id' => 2]); // same room, different period
        $this->make(['room' => 'อาคาร 2', 'period_id' => 2, 'classroom_id' => 3, 'teacher_id' => 2]);
        $r = $this->getJson(self::API.'/lessons?term_id=1&view=room&room='.rawurlencode('LAB A'))->assertOk()->json();
        $this->assertCount(2, $r['lessons']);
        $this->assertSame(['Lab A', 'lab a'], array_column($r['lessons'], 'room'));
        $this->assertCount(2, $this->getJson(self::API.'/meta?term_id=1')->json('rooms'));
    }

    // ------------------------------------------------------------------ OPS-011
    public function test_ops011_room_clash_is_case_and_space_insensitive_and_returns_409(): void
    {
        $a = $this->make(['room' => 'Lab A', 'teacher_id' => 1, 'classroom_id' => 1]);
        $this->assertSame('laba', DB::table('lessons')->where('id', $a['id'])->value('room_key'));
        $this->postJson(self::API.'/lessons', $this->body(['room' => 'LabA', 'teacher_id' => 3, 'classroom_id' => 3]))->assertStatus(409);
        $r = $this->postJson(self::API.'/lessons', $this->body(['room' => '  lab   A ', 'teacher_id' => 2, 'classroom_id' => 2]))->assertStatus(409);
        $r->assertJsonPath('code', 'collision')->assertJsonPath('conflicts.0.type', 'room');
        $this->assertStringContainsString('Lab A', $r->json('message'));
        $this->assertSame(1, DB::table('lessons')->count());
        // different period is fine
        $this->make(['room' => 'LAB A', 'teacher_id' => 2, 'classroom_id' => 2, 'period_id' => 2]);
        // editing into a clash is refused as well
        $c = $this->make(['room' => 'Lab B', 'teacher_id' => 3, 'classroom_id' => 3, 'period_id' => 3]);
        $this->putJson(self::API.'/lessons/'.$c['id'], $this->body(['room' => 'LAB A', 'teacher_id' => 3, 'classroom_id' => 3, 'period_id' => 2, 'version' => $c['version']]))->assertStatus(409);
    }

    public function test_collisions_name_the_clashing_lesson_for_teacher_and_classroom(): void
    {
        $this->make(['room' => 'A1']);
        $t = $this->postJson(self::API.'/lessons', $this->body(['room' => 'A2', 'classroom_id' => 2]))->assertStatus(409);
        $t->assertJsonPath('conflicts.0.type', 'teacher');
        $this->assertStringContainsString('ครูอรุณ', $t->json('message'));
        $this->assertStringContainsString('ป.4/1', $t->json('message'));
        $c = $this->postJson(self::API.'/lessons', $this->body(['room' => 'A3', 'teacher_id' => 2]))->assertStatus(409);
        $c->assertJsonPath('conflicts.0.type', 'classroom');
        $this->assertStringContainsString('ห้อง ป.4/1', $c->json('message'));
        $this->assertSame(1, DB::table('lessons')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'timetable.lesson.created')->count()); // no audit for refused writes
    }

    public function test_lessons_without_room_do_not_clash_with_each_other(): void
    {
        $a = $this->make(['room' => '']);
        $b = $this->make(['room' => null, 'teacher_id' => 2, 'classroom_id' => 2]);
        $this->assertSame('', $a['room']);
        $this->assertSame('', $b['room']);
        $this->assertNull(DB::table('lessons')->where('id', $a['id'])->value('room_key'));
        $this->assertSame(2, DB::table('lessons')->count());
    }

    public function test_unique_violation_from_a_concurrent_writer_becomes_409_not_500(): void
    {
        $this->raw(['teacher_id' => 1, 'classroom_id' => 1, 'room' => 'Lab', 'room_key' => 'lab']);
        $t = app(Timetable::class);
        $m = new \ReflectionMethod($t, 'guarded');
        $m->setAccessible(true);
        try {
            $m->invoke($t, fn () => $this->raw(['teacher_id' => 2, 'classroom_id' => 2, 'room' => 'LAB', 'room_key' => 'lab']), [1, 1, 1, 2, 2, 'lab', null]);
            $this->fail('expected a 409 response');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame('room', json_decode($e->getResponse()->getContent(), true)['conflicts'][0]['type']);
        }
        // a different failure is not swallowed as a collision
        $this->expectException(\Illuminate\Database\QueryException::class);
        $m->invoke($t, fn () => DB::table('lessons')->insert(['school_id' => 1]), [1, 1, 1, 2, 2, null, null]);
    }

    public function test_validation_and_references(): void
    {
        $this->postJson(self::API.'/lessons', $this->body(['weekday' => 9]))->assertUnprocessable();
        $this->postJson(self::API.'/lessons', $this->body(['subject' => '']))->assertUnprocessable();
        $this->postJson(self::API.'/lessons', $this->body(['period_id' => 999]))->assertUnprocessable();
        $this->postJson(self::API.'/lessons', $this->body(['term_id' => 999]))->assertNotFound();
        $this->putJson(self::API.'/lessons/999', $this->body(['version' => 1]))->assertNotFound();
        $this->putJson(self::API.'/lessons/1', $this->body())->assertUnprocessable(); // version required
    }

    public function test_stale_update_is_409_with_current_row(): void
    {
        $l = $this->make();
        $this->putJson(self::API.'/lessons/'.$l['id'], $this->body(['subject' => 'ใหม่', 'version' => $l['version']]))->assertOk();
        $this->putJson(self::API.'/lessons/'.$l['id'], $this->body(['subject' => 'เก่า', 'version' => $l['version']]))->assertStatus(409)->assertJsonPath('current.subject', 'ใหม่');
    }

    public function test_idempotency_key_replays_create(): void
    {
        $h = ['Idempotency-Key' => 'tt-create-1'];
        $a = $this->postJson(self::API.'/lessons', $this->body(), $h)->assertCreated()->json();
        $b = $this->postJson(self::API.'/lessons', $this->body(), $h)->assertCreated()->json();
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(1, DB::table('lessons')->count());
    }

    // ------------------------------------------------------------------ copy
    public function test_copy_term_preview_collision_report_then_commit(): void
    {
        $this->make(['term_id' => 2, 'confirm' => true, 'room' => 'R1']);
        $this->make(['term_id' => 2, 'confirm' => true, 'classroom_id' => 2, 'teacher_id' => 2, 'room' => 'R2']);
        $this->make(['term_id' => 2, 'confirm' => true, 'classroom_id' => 3, 'teacher_id' => 3, 'period_id' => 2, 'room' => '']);
        // target term already has a lesson that clashes with the first source lesson (same teacher, same slot)
        $this->make(['term_id' => 3, 'classroom_id' => 3, 'room' => 'ใหม่']);
        $before = DB::table('lessons')->where('term_id', 2)->count();
        $pre = $this->postJson(self::API.'/copy/preview', ['from_term_id' => 2, 'to_term_id' => 3])->assertOk()->json();
        $this->assertSame(3, $pre['total']);
        $this->assertSame(2, $pre['copyable']);
        $this->assertSame(1, $pre['collision_count']);
        $this->assertSame('teacher', $pre['collisions'][0]['conflicts'][0]['type']);
        $this->assertSame(1, DB::table('lessons')->where('term_id', 3)->count()); // preview writes nothing
        $this->postJson(self::API.'/copy', ['from_term_id' => 2, 'to_term_id' => 3])->assertStatus(409)->assertJsonPath('code', 'collisions');
        $this->assertSame(1, DB::table('lessons')->where('term_id', 3)->count());
        $done = $this->postJson(self::API.'/copy', ['from_term_id' => 2, 'to_term_id' => 3, 'skip_collisions' => true])->assertCreated()->json();
        $this->assertSame(2, $done['copied']);
        $this->assertSame(3, DB::table('lessons')->where('term_id', 3)->count());
        $this->assertSame($before, DB::table('lessons')->where('term_id', 2)->count()); // source untouched
        $copied = DB::table('lessons')->where('term_id', 3)->where('classroom_id', 3)->where('period_id', 2)->first();
        $this->assertNull($copied->room_key); // no-room lesson got its own placeholder, still no room
        $this->assertSame(1, (int) $copied->version);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'timetable.copied')->count());
        // running it again copies nothing new: everything present is a duplicate or a collision
        $again = $this->postJson(self::API.'/copy/preview', ['from_term_id' => 2, 'to_term_id' => 3])->assertOk()->json();
        $this->assertSame(0, $again['copyable']);
        $this->assertSame(2, $again['duplicates']);
        $this->postJson(self::API.'/copy', ['from_term_id' => 3, 'to_term_id' => 3])->assertUnprocessable();
    }

    public function test_copy_into_closed_term_needs_confirm_and_teacher_cannot_copy(): void
    {
        $this->make(['term_id' => 3]);
        $this->postJson(self::API.'/copy', ['from_term_id' => 3, 'to_term_id' => 2])->assertStatus(409)->assertJsonPath('code', 'term_closed');
        $this->postJson(self::API.'/copy', ['from_term_id' => 3, 'to_term_id' => 2, 'confirm' => true])->assertCreated()->assertJsonPath('copied', 1);
        $this->as('teacher');
        $this->postJson(self::API.'/copy/preview', ['from_term_id' => 3, 'to_term_id' => 1])->assertForbidden();
    }

    // ------------------------------------------------------------------ print
    public function test_print_view_for_classroom_and_teacher(): void
    {
        DB::table('classrooms')->where('id', 1)->update(['name' => 'ป.4/1 <b>x</b>']);
        $this->make(['room' => 'ห้องวิทย์ <i>1</i>', 'subject' => 'วิทย์ <script>alert(1)</script>']);
        $r = $this->get('/timetable/print/classroom/1?term=1')->assertOk();
        $r->assertSee('ตารางสอนห้อง', false)->assertSee('ปีการศึกษา 2569 ภาคเรียนที่ 1', false)->assertSee('timetable-print.css', false)->assertSee('รวม 1 คาบต่อสัปดาห์', false);
        $r->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<b>x</b>', false)->assertSee('&lt;script&gt;', false);
        $this->assertStringContainsString('@page{size:A4 landscape', file_get_contents(public_path('assets/modules/timetable/timetable-print.css')));
        $this->assertStringContainsString('@media print', file_get_contents(public_path('assets/modules/timetable/timetable-print.css')));
        $this->get('/timetable/print/teacher/1?term=1')->assertOk()->assertSee('ตารางสอนครู', false)->assertSee('ครูอรุณ', false);
        $this->get('/timetable/print/room/1')->assertNotFound();
        $this->get('/timetable/print/classroom/999?term=1')->assertForbidden();
        // default term is the current one
        $this->get('/timetable/print/classroom/1')->assertOk()->assertSee('ภาคเรียนที่ 1', false);
    }

    // ------------------------------------------------------------------ roles
    public function test_teacher_is_read_only_and_limited_to_scope(): void
    {
        $mine = $this->make(['teacher_id' => 1, 'classroom_id' => 1]);
        $other = $this->make(['teacher_id' => 2, 'classroom_id' => 2, 'period_id' => 2, 'room' => 'Z']);
        $this->as('teacher'); // teacher_id 1, homeroom classroom 1
        $this->postJson(self::API.'/lessons', $this->body(['period_id' => 6, 'room' => 'Q']))->assertForbidden();
        $this->putJson(self::API.'/lessons/'.$mine['id'], $this->body(['version' => $mine['version']]))->assertForbidden();
        $this->deleteJson(self::API.'/lessons/'.$mine['id'], ['term_id' => 1, 'version' => $mine['version']])->assertForbidden();
        $this->postJson(self::API.'/lessons/'.$mine['id'].'/restore', ['term_id' => 1, 'version' => 1])->assertForbidden();
        $this->getJson(self::API.'/trash?term_id=1')->assertForbidden();
        $this->getJson(self::API.'/lessons?term_id=1&view=classroom&id=1')->assertOk()->assertJsonCount(1, 'lessons');
        $this->getJson(self::API.'/lessons?term_id=1&view=classroom&id=2')->assertForbidden();
        $this->getJson(self::API.'/lessons?term_id=1&view=teacher&id=1')->assertOk()->assertJsonCount(1, 'lessons');
        $this->getJson(self::API.'/lessons?term_id=1&view=teacher&id=2')->assertForbidden();
        $this->getJson(self::API.'/lessons?term_id=1&view=room&room=Z')->assertForbidden();
        $load = $this->getJson(self::API.'/load?term_id=1')->assertOk()->json('data');
        $this->assertSame([1], array_column($load, 'teacher_id'));
        $meta = $this->getJson(self::API.'/meta?term_id=1')->assertOk()->json();
        $this->assertFalse($meta['can_write']);
        $this->assertSame([1], array_column($meta['classrooms'], 'id'));
        $this->assertSame([], $meta['rooms']);
        $this->get('/timetable/print/classroom/1?term=1')->assertOk();
        $this->get('/timetable/print/classroom/2?term=1')->assertForbidden();
        $this->get('/timetable/print/teacher/2?term=1')->assertForbidden();
        $this->assertNotNull($other['id']);
    }

    public function test_teacher_who_teaches_in_another_classroom_may_view_it(): void
    {
        $this->make(['teacher_id' => 1, 'classroom_id' => 2, 'period_id' => 4, 'room' => 'K']);
        $this->as('teacher');
        $this->getJson(self::API.'/lessons?term_id=1&view=classroom&id=2')->assertOk()->assertJsonCount(1, 'lessons');
        $this->getJson(self::API.'/lessons?term_id=1&view=classroom&id=3')->assertForbidden();
    }

    public function test_principal_writes_and_guest_is_refused(): void
    {
        $this->as('principal');
        $this->make();
        $this->app['auth']->forgetGuards();
        $this->getJson(self::API.'/meta')->assertUnauthorized();
        $this->postJson(self::API.'/lessons', $this->body())->assertUnauthorized();
        $this->get('/timetable/print/classroom/1')->assertRedirect();
    }

    public function test_other_roles_are_refused(): void
    {
        $u = User::forceCreate(['name' => 'Fictional editor', 'email' => 'editor@school.test', 'password' => 'Original-Password-2026', 'role' => 'editor', 'school_id' => 1, 'is_active' => true, 'is_demo' => true, 'auth_version' => 1]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($u)->getJson(self::API.'/meta')->assertForbidden();
    }

    public function test_school_scoping(): void
    {
        $termB = DB::table('academic_terms')->insertGetId(['school_id' => 2, 'year' => 2569, 'term' => 1, 'starts_on' => '2026-05-01', 'ends_on' => '2027-03-31']);
        $teacherB = DB::table('teachers')->insertGetId(['school_id' => 2, 'name' => 'Other school teacher', 'subject' => 'X']);
        $roomB = DB::table('classrooms')->insertGetId(['school_id' => 2, 'name' => 'Other 1', 'homeroom_teacher_id' => $teacherB]);
        $lessonB = $this->raw(['school_id' => 2, 'term_id' => $termB, 'teacher_id' => $teacherB, 'classroom_id' => $roomB, 'room' => 'OB']);
        $this->putJson(self::API.'/lessons/'.$lessonB, $this->body(['term_id' => $termB, 'version' => 1]))->assertNotFound();
        $this->deleteJson(self::API.'/lessons/'.$lessonB, ['term_id' => $termB, 'version' => 1])->assertNotFound();
        $this->postJson(self::API.'/lessons/'.$lessonB.'/restore', ['term_id' => $termB, 'version' => 1])->assertNotFound();
        $this->postJson(self::API.'/lessons', $this->body(['term_id' => $termB]))->assertNotFound();
        $this->postJson(self::API.'/lessons', $this->body(['teacher_id' => $teacherB]))->assertUnprocessable();
        $this->postJson(self::API.'/lessons', $this->body(['classroom_id' => $roomB]))->assertUnprocessable();
        $this->getJson(self::API.'/lessons?term_id='.$termB.'&view=classroom&id=1')->assertNotFound();
        $this->getJson(self::API.'/lessons?term_id=1&view=teacher&id='.$teacherB)->assertNotFound();
        $this->getJson(self::API.'/load?term_id='.$termB)->assertNotFound();
        $this->postJson(self::API.'/copy/preview', ['from_term_id' => $termB, 'to_term_id' => 1])->assertNotFound();
        $meta = $this->getJson(self::API.'/meta')->assertOk()->json();
        $this->assertNotContains($termB, array_column($meta['terms'], 'id'));
        $this->assertNotContains($teacherB, array_column($meta['teachers'], 'id'));
        $this->assertSame(1, DB::table('lessons')->count());
    }

    public function test_break_periods_are_not_schedulable_and_module_is_wired(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('bell_periods', 'kind')) {
            DB::table('bell_periods')->where('id', 3)->update(['kind' => 'break']);
            $this->postJson(self::API.'/lessons', $this->body(['period_id' => 3]))->assertUnprocessable();
        }
        $this->assertContains('timetable', array_column(app(\App\Support\Modules\ModuleRegistry::class)->summary(), 'id'));
        $this->get('/work')->assertOk()->assertSee('assets/modules/timetable/timetable.js', false)->assertSee('ตารางสอน');
        $this->as('teacher')->get('/work')->assertOk()->assertSee('ตารางสอน');
    }

    public function test_migration_added_the_columns_and_indexes(): void
    {
        foreach (['room_key', 'note', 'updated_by', 'deleted_at'] as $c) $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('lessons', $c), $c);
        $names = array_column(\Illuminate\Support\Facades\Schema::getIndexes('lessons'), 'name');
        foreach (['lesson_teacher_slot', 'lesson_class_slot', 'lesson_room_slot', 'lesson_room_key_slot'] as $i) $this->assertContains($i, $names, $i);
    }

    // ------------------------------------------------------------------ review round 2
    public function test_ops002_open_noncurrent_term_edit_stays_without_confirm(): void
    {
        $l = $this->make(['term_id' => 3]);
        $r = $this->putJson(self::API.'/lessons/'.$l['id'], $this->body(['term_id' => 3, 'subject' => 'ภาษาไทย', 'version' => $l['version']]))->assertOk()->json();
        $this->assertSame(3, $r['term_id']);
        $this->assertSame(3, (int) DB::table('lessons')->where('id', $l['id'])->value('term_id'));
        $this->assertSame(0, DB::table('lessons')->where('term_id', 1)->count());
    }

    public function test_delete_and_restore_need_a_matching_term(): void
    {
        $l = $this->make(['term_id' => 3]);
        $this->deleteJson(self::API.'/lessons/'.$l['id'], ['version' => $l['version']])->assertUnprocessable()->assertJsonValidationErrors('term_id');
        $this->deleteJson(self::API.'/lessons/'.$l['id'], ['term_id' => 1, 'version' => $l['version']])->assertUnprocessable()->assertJsonValidationErrors('term_id');
        $this->assertNull(DB::table('lessons')->where('id', $l['id'])->value('deleted_at'));
        $del = $this->deleteJson(self::API.'/lessons/'.$l['id'], ['term_id' => 3, 'version' => $l['version']])->assertOk()->json();
        $this->postJson(self::API.'/lessons/'.$l['id'].'/restore', ['version' => $del['version']])->assertUnprocessable();
        $this->postJson(self::API.'/lessons/'.$l['id'].'/restore', ['term_id' => 1, 'version' => $del['version']])->assertUnprocessable()->assertJsonValidationErrors('term_id');
        $this->assertNotNull(DB::table('lessons')->where('id', $l['id'])->value('deleted_at'));
        $this->postJson(self::API.'/lessons/'.$l['id'].'/restore', ['term_id' => 3, 'version' => $del['version']])->assertOk();
    }

    public function test_copy_commit_purges_deleted_rows_that_block_the_target(): void
    {
        $this->make(['term_id' => 2, 'confirm' => true]);
        $b = $this->make(['term_id' => 3]); // same teacher, weekday, period as the source lesson
        $this->deleteJson(self::API.'/lessons/'.$b['id'], ['term_id' => 3, 'version' => $b['version']])->assertOk();
        $pre = $this->postJson(self::API.'/copy/preview', ['from_term_id' => 2, 'to_term_id' => 3])->assertOk()->json();
        $this->assertSame(1, $pre['copyable']);
        $this->assertSame(0, $pre['collision_count']);
        $this->assertSame(1, $pre['tombstones_purged']);
        $done = $this->postJson(self::API.'/copy', ['from_term_id' => 2, 'to_term_id' => 3])->assertCreated()->json();
        $this->assertSame(1, $done['copied']);
        $this->assertSame(1, $done['tombstones_purged']);
        $this->assertSame(0, DB::table('lessons')->where('id', $b['id'])->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'timetable.lesson.purged')->where('entity_id', $b['id'])->count());
        $audit = json_decode(DB::table('audit_events')->where('action', 'timetable.copied')->value('detail'), true);
        $this->assertSame($done['lesson_ids'], $audit['lesson_ids']);
        $this->assertSame($done['lesson_ids'], DB::table('lessons')->where('term_id', 3)->pluck('id')->map(fn ($x) => (int) $x)->all());
    }

    public function test_edit_in_closed_term_keeps_a_teacher_who_has_left(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('teachers', 'is_active')) $this->markTestSkipped('no is_active column');
        $l = $this->make(['term_id' => 2, 'confirm' => true, 'teacher_id' => 2]);
        DB::table('teachers')->where('id', 2)->update(['is_active' => false]);
        $this->putJson(self::API.'/lessons/'.$l['id'], $this->body(['term_id' => 2, 'teacher_id' => 2, 'note' => 'แก้หมายเหตุ', 'version' => $l['version'], 'confirm' => true]))->assertOk()->assertJsonPath('note', 'แก้หมายเหตุ');
        // moving the lesson to another inactive teacher is still refused
        DB::table('teachers')->where('id', 3)->update(['is_active' => false]);
        $v = DB::table('lessons')->where('id', $l['id'])->value('version');
        $this->putJson(self::API.'/lessons/'.$l['id'], $this->body(['term_id' => 2, 'teacher_id' => 3, 'version' => $v, 'confirm' => true]))->assertUnprocessable();
    }

    public function test_lesson_overlapping_an_assigned_duty_is_refused(): void
    {
        DB::table('duties')->delete();
        $p = DB::table('bell_periods')->where('id', 1)->first();
        DB::table('duties')->insert(['school_id' => 1, 'teacher_id' => 1, 'date' => '2026-11-09', 'starts_at' => $p->starts_at, 'ends_at' => $p->ends_at, 'location' => 'ประตูหน้า', 'status' => 'assigned', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $r = $this->postJson(self::API.'/lessons', $this->body(['term_id' => 3]))->assertStatus(409)->assertJsonPath('code', 'duty_collision');
        $this->assertStringContainsString('2026-11-09', $r->json('message'));
        $this->assertStringContainsString('ประตูหน้า', $r->json('message'));
        $this->postJson(self::API.'/lessons', $this->body(['term_id' => 3, 'weekday' => 2]))->assertCreated(); // other weekday
        $this->postJson(self::API.'/lessons', $this->body(['term_id' => 3, 'teacher_id' => 2, 'classroom_id' => 2]))->assertCreated(); // other teacher
        $this->assertSame(2, DB::table('lessons')->count());
    }

    public function test_meta_refuses_a_term_of_another_school(): void
    {
        $termB = DB::table('academic_terms')->insertGetId(['school_id' => 2, 'year' => 2569, 'term' => 1, 'starts_on' => '2026-05-01', 'ends_on' => '2027-03-31']);
        $this->getJson(self::API.'/meta?term_id='.$termB)->assertNotFound();
        $this->getJson(self::API.'/meta?term_id=3')->assertOk()->assertJsonPath('term_id', 3);
        // the declared icon must survive ModuleRegistry's icon allowlist (otherwise it silently becomes 'web')
        $menu = json_decode(file_get_contents(base_path('modules/timetable/module.json')), true)['work_menu'][0];
        $item = collect(app(\App\Support\Modules\ModuleRegistry::class)->workMenu('admin'))->firstWhere('path', $menu['path']);
        $this->assertSame($menu['icon'], $item['icon']);
    }

    public function test_is_unique_recognises_driver_codes(): void
    {
        $mk = fn (array $info) => new \Illuminate\Database\QueryException('mysql', 'insert', [], new \PDOException('x', 0));
        $with = function (array $info): \Illuminate\Database\QueryException {
            $p = new \PDOException('driver error');
            $p->errorInfo = $info;
            return new \Illuminate\Database\QueryException('mysql', 'insert', [], $p);
        };
        $this->assertTrue(Timetable::isUnique($with(['23000', 1062, 'Duplicate entry'])));
        $this->assertTrue(Timetable::isUnique($with(['23505', 7, 'duplicate key value violates unique constraint'])));
        $this->assertFalse(Timetable::isUnique($with(['23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails'])));
        $this->assertNotNull($mk([]));
    }

    public function test_database_collation_clash_is_named_not_generic(): void
    {
        // legacy row: raw room only, no room_key; PHP key differs from the key the writer holds, so only the raw room index can see the clash
        $this->raw(['teacher_id' => 1, 'classroom_id' => 1, 'room' => 'Lab', 'room_key' => null, 'subject' => 'เก่า']);
        $t = app(Timetable::class);
        $m = new \ReflectionMethod($t, 'guarded');
        $m->setAccessible(true);
        try {
            $m->invoke($t, function () { throw new \Illuminate\Database\QueryException('mariadb', 'insert', [], tap(new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'zzz' for key 'lessons_room_unique'"), fn ($e) => $e->errorInfo = ['23000', 1062, "Duplicate entry 'zzz' for key 'lessons_room_unique'"])); }, [1, 1, 1, 2, 2, 'zzz', null, 'Lab']);
            $this->fail('expected 409');
        } catch (HttpResponseException $e) {
            $body = json_decode($e->getResponse()->getContent(), true);
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame('room', $body['conflicts'][0]['type']);
            $this->assertStringContainsString('Lab', $body['message']);
        }
    }

    public function test_public_helpers_for_other_modules(): void
    {
        $a = $this->make(['room' => '']);
        $row = DB::table('lessons')->where('id', $a['id'])->first();
        $this->assertSame('', Timetable::roomLabel($row));
        $this->assertStringStartsWith('~none~', $row->room);
        $this->deleteJson(self::API.'/lessons/'.$a['id'], ['term_id' => 1, 'version' => $a['version']])->assertOk();
        $this->assertSame(0, Timetable::liveLessons(DB::table('lessons'))->count());
        $this->assertSame(0, Timetable::liveLessons(DB::table('lessons as l'), 'l')->count());
    
    }
}
