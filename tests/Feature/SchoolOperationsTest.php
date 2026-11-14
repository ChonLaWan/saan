<?php
namespace Tests\Feature;
use App\Models\User;
use App\Domain\ScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchoolOperationsTest extends TestCase {
    use RefreshDatabase;
    protected function setUp(): void {parent::setUp();config(['school.demo'=>true]);$this->seed();}
    private function asRole(string $role='admin'): static {$this->actingAs(User::where('role',$role)->first());return $this;}
    public function test_authentication_required(): void {$this->getJson('/api/state')->assertUnauthorized();}
    public function test_demo_refuses_remote_access(): void {$this->withServerVariables(['REMOTE_ADDR'=>'192.0.2.10'])->get('/login')->assertForbidden();}
    public function test_demo_disabled_keeps_password_login_and_refuses_role_login(): void {config(['school.demo'=>false]);$this->get('/login')->assertOk()->assertDontSee('action="http://localhost/demo-login"',false);$this->post('/demo-login',['role'=>'admin'])->assertForbidden();}
    public function test_teacher_receives_only_own_class_and_own_leaves(): void {
        $r=$this->asRole('teacher')->getJson('/api/state')->assertOk();$this->assertCount(8,$r->json('students'));$this->assertCount(1,$r->json('classrooms'));
        foreach($r->json('leaves')as $l)$this->assertSame(1,$l['teacher_id']);
    }
        /** N8 cutover: every prototype write path answers 410 Gone and names the page that replaced it. Behaviour is covered by the module tests. */
    public static function retiredWrites(): array {
        return [
            'lesson create'=>['post','/api/lessons','/work#timetable','TimetableModuleTest'],
            'lesson update'=>['put','/api/lessons/1','/work#timetable','TimetableModuleTest'],
            'lesson delete'=>['delete','/api/lessons/1','/work#timetable','TimetableModuleTest'],
            'duty create'=>['post','/api/duties','/work#duty','LeaveDutyModuleTest'],
            'duty update'=>['put','/api/duties/1','/work#duty','LeaveDutyModuleTest'],
            'leave request'=>['post','/api/leaves','/work#leave','LeaveDutyModuleTest'],
            'leave decision'=>['post','/api/leaves/1/decision','/work#leave-approve','LeaveDutyModuleTest'],
            'attendance'=>['post','/api/attendance','/work#attendance','AttendanceModuleTest'],
            'grades'=>['post','/api/grades','/work#scores','AssessmentModuleTest'],
        ];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('retiredWrites')]
    public function test_retired_write_routes_return_410_for_every_role_and_write_nothing(string $method,string $url,string $moved,string $coveredBy=''): void {
        $tables=['lessons','duties','leave_requests','attendances','grade_entries','audit_events'];$before=array_map(fn($t)=>DB::table($t)->count(),$tables);
        foreach(['admin','principal','teacher']as$role){
            $this->asRole($role)->json($method,$url,['teacher_id'=>6,'classroom_id'=>1,'student_id'=>1,'continuous'=>10,'exam'=>5])->assertStatus(410)->assertJsonPath('message','ย้ายไปที่ '.$moved)->assertJsonPath('moved_to',$moved);
        }
        $this->assertSame($before,array_map(fn($t)=>DB::table($t)->count(),$tables));
        $this->assertSame(1,DB::table('lessons')->where('id',1)->value('version'));
    }
    public function test_retired_write_routes_still_refuse_guests_and_editors(): void {
        $this->postJson('/api/lessons',[])->assertUnauthorized();
        $this->asRole('admin');$editor=User::where('role','teacher')->first()->replicate();$editor->email='editor-n8@demo.school.test';$editor->role='editor';$editor->save();
        $this->actingAs($editor->fresh())->postJson('/api/lessons',[])->assertForbidden();
    }
    public function test_overview_stays_read_only_and_links_to_work_pages(): void {
        $html=$this->asRole()->get('/operations')->assertOk()->assertSee('หน้านี้ดูอย่างเดียว')->getContent();
        foreach(['/work#timetable','/work#duty','/work#leave','/work#attendance','/work#scores']as$link)$this->assertStringContainsString($link,$html);
        $this->getJson('/api/state')->assertOk()->assertJsonStructure(['teachers','classrooms','students','lessons','duties','leaves']);
        $this->getJson('/api/duty-suggestions?date=2026-11-05&starts_at=08:00&ends_at=08:20')->assertOk();
    }
    public function test_soft_deleted_lessons_leave_teacher_scope_and_old_readers(): void {
        // teacher 1 (homeroom class 1) also teaches class 2 or 3 in the seed; soft-deleting those lessons must drop the class
        $teacher=User::where('role','teacher')->first();
        $taught=DB::table('lessons')->where('teacher_id',$teacher->teacher_id)->where('classroom_id','!=',1)->pluck('classroom_id')->unique()->values()->all();
        $this->assertNotEmpty($taught);
        $this->assertEqualsCanonicalizing(array_values(array_unique(array_merge([1],$taught))),\App\Support\Saan\TeacherScope::classroomIds($teacher,1));
        DB::table('lessons')->where('teacher_id',$teacher->teacher_id)->where('classroom_id','!=',1)->update(['deleted_at'=>now()]);
        $this->assertSame([1],\App\Support\Saan\TeacherScope::classroomIds($teacher,1));
        $live=DB::table('lessons')->whereNull('deleted_at')->count();
        $this->assertCount($live,$this->asRole()->getJson('/api/state')->json('lessons'));
        // duty availability ignores a deleted lesson: a duty on the slot of a deleted lesson is no longer blocked
        $l=DB::table('lessons')->whereNull('deleted_at')->where('weekday',4)->where('teacher_id',$teacher->teacher_id)->first();
        $this->assertNotNull($l);$p=DB::table('bell_periods')->find($l->period_id);$ops=app(\App\Domain\SchoolOperations::class);
        $this->assertSame('ช่วงเวลาทับคาบสอน',$ops->dutyAvailable($l->teacher_id,'2026-11-05',$p->starts_at,$p->ends_at));
        DB::table('lessons')->where('id',$l->id)->update(['deleted_at'=>now()]);
        $this->assertNull($ops->dutyAvailable($l->teacher_id,'2026-11-05',$p->starts_at,$p->ends_at));
    }
    public function test_overview_follows_the_current_term_setting_and_survives_a_missing_term(): void {
        $this->asRole();$first=DB::table('academic_terms')->orderBy('id')->first();
        $other=DB::table('academic_terms')->insertGetId(['school_id'=>1,'year'=>$first->year+1,'term'=>1,'starts_on'=>'2027-05-01','ends_on'=>'2027-09-30']);
        \App\Support\Saan\Settings::put('academic.current_term_id',$other);
        $r=$this->getJson('/api/state')->assertOk();$this->assertSame($other,(int)$r->json('term.id'));$this->assertCount(0,$r->json('lessons'));
        $this->assertSame('วันที่อยู่นอกภาคเรียนสาธิต',app(\App\Domain\SchoolOperations::class)->dutyAvailable(1,'2026-11-05','08:00','08:20'));
        \App\Support\Saan\Settings::put('academic.current_term_id',null);DB::table('academic_terms')->update(['school_id'=>99]);
        $this->assertNull($this->getJson('/api/state')->assertOk()->json('term'));
        $this->assertSame('ยังไม่ได้ตั้งภาคเรียน',app(\App\Domain\SchoolOperations::class)->dutyAvailable(1,'2026-11-05','08:00','08:20'));
    }
    public function test_served_overview_script_has_no_write_ui(): void {
        $js=file_get_contents(public_path('assets/atelier.js'));
        foreach(["'/api/grades'","'/api/leaves'","'/api/lessons","'/api/duties","'/api/attendance'","'/api/posts","'POST'","'PUT'","'DELETE'","dutyEditor","leaveEditor"]as$needle)$this->assertStringNotContainsString($needle,$js,$needle);
        foreach(['/work#timetable','/work#duty','/work#leave','/work#leave-approve','/work#scores','/work#attendance']as$link)$this->assertStringContainsString($link,$js);
    }
    public function test_overview_lists_requests_the_leave_module_filed_as_submitted(): void {
        // the module's status is "submitted" ("pending" is the prototype's): the approvals table, the counters and the status tag must know both
        $js=file_get_contents(public_path('assets/atelier.js'));
        $this->assertStringContainsString("function leavePending(l){return l.status==='submitted'||l.status==='pending';}",$js);
        $this->assertStringContainsString('const pending=state.leaves.filter(leavePending);',$js);
        $this->assertSame(1,preg_match_all("/l\\.status==='pending'/",$js),'only leavePending() compares with the legacy status');
        $this->assertStringContainsString("tag(...(labels[l.status]||[l.status,'']))",$js); // an unknown status no longer throws in the people table
        DB::table('leave_requests')->update(['status'=>'submitted']);
        $r=$this->asRole()->getJson('/api/state')->assertOk();
        $this->assertGreaterThan(0,$r->json('counts.leaves_pending'));
        $this->assertContains('submitted',array_column($r->json('leaves'),'status'));
    }
    public function test_teacher_home_goes_to_work_shell_and_admin_to_cms(): void {
        $this->asRole('teacher')->get('/')->assertRedirect('/work');$this->asRole('admin')->get('/')->assertRedirect('/cms');
        $labels=array_column(\App\Support\SchoolNavigation::groups('teacher'),'label');
        $this->assertLessThan(array_search('ภาพรวม (ดูอย่างเดียว)',$labels,true),array_search('งานโรงเรียน',$labels,true));
    }
    public function test_post_prototype_routes_are_throttled(): void {
        $this->asRole();
        foreach(range(1,30)as$i)$this->postJson('/api/posts',[])->assertStatus(422);
        $this->postJson('/api/posts',[])->assertStatus(429);
    }
    public function test_teacher_titles_follow_academic_rules(): void {
        DB::table('teachers')->insert(['school_id'=>1,'name'=>'น.ส. สมศรี ใจดี','subject'=>'x']);DB::table('teachers')->insert(['school_id'=>1,'name'=>'ผศ.ดร. Mr.Test Person','subject'=>'x']);
        \App\Domain\InstallationContent::normaliseOperational(1);
        $t=DB::table('teachers')->where('name','น.ส. สมศรี ใจดี')->first();
        $this->assertSame('สมศรี',$t->first_name);$this->assertSame('ใจดี',$t->last_name);$this->assertSame('น.ส.',$t->prefix);
        if(class_exists(\Saan\Modules\Academic\Academic::class))$this->assertSame(\Saan\Modules\Academic\Academic::teacherKey('สมศรี','ใจดี'),$t->name_key);
        $this->assertSame('ผศ.ดร.',DB::table('teachers')->where('name','ผศ.ดร. Mr.Test Person')->value('prefix'));
    }
    public function test_normalised_demo_rows_carry_module_keys(): void {
        // the test seeder stays legacy-shaped on purpose (module tests rely on it); the demo install path normalises
        \App\Domain\InstallationContent::normaliseOperational(1);
        $this->assertSame(0,DB::table('classrooms')->whereNull('name_key')->count());$this->assertSame(0,DB::table('teachers')->whereNull('name_key')->count());
        $this->assertSame(0,DB::table('students')->whereNull('code_key')->count());$this->assertSame(0,DB::table('students')->whereNull('first_name')->count());
        $this->assertSame(0,DB::table('lessons')->whereNull('room_key')->count());$this->assertSame(0,DB::table('leave_requests')->whereNull('fiscal_year')->count());
        $this->assertSame(0,DB::table('attendances')->whereNull('term_id')->count());
        $this->assertSame('ป.4/1',DB::table('classrooms')->where('id',1)->value('name'));$this->assertSame('ป.4/1',DB::table('classrooms')->where('id',1)->value('name_key'));
        $this->assertSame('อรุณ(สมมติ)',DB::table('teachers')->where('id',1)->value('name_key'));
        \App\Domain\InstallationContent::normaliseOperational(1); // idempotent
        $this->assertSame(0,DB::table('lessons')->whereNull('room_key')->count());
    }
    public function test_new_modules_read_the_seeded_demo_data(): void {
        $this->asRole('admin');
        $this->assertNotEmpty($this->getJson('/api/academic/classrooms')->assertOk()->json());$this->assertNotEmpty($this->getJson('/api/academic/teachers')->assertOk()->json());$this->assertNotEmpty($this->getJson('/api/registry/students')->assertOk()->json());
        $meta=$this->getJson('/api/timetable/meta')->assertOk()->json();$this->assertNotEmpty($meta);
        $this->getJson('/api/timetable/lessons?term_id=1&view=classroom&id=1')->assertOk()->assertJsonPath('term_id',1);$this->assertGreaterThan(0,count($this->getJson('/api/timetable/lessons?term_id=1&view=classroom&id=1')->json('lessons')));
        $this->assertNotEmpty($this->getJson('/api/leave/requests')->assertOk()->json());$this->assertNotEmpty($this->getJson('/api/duty/duties')->assertOk()->json());$this->assertNotEmpty($this->getJson('/api/attendance/classes')->assertOk()->json());
    }
    public function test_draft_is_hidden_and_content_is_escaped_after_publication(): void {
        $r=$this->asRole()->postJson('/api/posts',['title'=>'UNPUBLISHED-TEST-MARKER','body'=>'<script>harmless_marker</script>'])->assertCreated();$this->get('/portal')->assertDontSee('UNPUBLISHED-TEST-MARKER');$this->asRole('teacher')->getJson('/api/state')->assertJsonMissing(['title'=>'UNPUBLISHED-TEST-MARKER']);
        $this->asRole()->postJson('/api/posts/'.$r->json('id').'/publish')->assertOk();$this->get('/portal')->assertSee('UNPUBLISHED-TEST-MARKER')->assertSee('&lt;script&gt;harmless_marker&lt;/script&gt;',false)->assertDontSee('<script>harmless_marker</script>',false);
    }
    public function test_suggestions_rank_available_before_unavailable(): void {
        $r=$this->asRole()->getJson('/api/duty-suggestions?date=2026-11-05&starts_at=08:00&ends_at=08:20')->assertOk();$seenUnavailable=false;foreach($r->json('candidates') as $x){if($x['unavailable'])$seenUnavailable=true;else $this->assertFalse($seenUnavailable);}
    }
}
