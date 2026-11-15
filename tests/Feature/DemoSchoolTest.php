<?php
namespace Tests\Feature;

use App\Domain\Demo\DemoAcademic;
use App\Domain\Demo\DemoNames;
use App\Domain\Demo\DemoSchool;
use App\Domain\InstallationContent;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

require_once __DIR__.'/../../installer/Setup.php';
use SchoolAtelier\Installer\Setup;

/** Full-system trial data (installer mode "full" and `php artisan saan:demo`): a fictional school in every module plus trial accounts with random passwords. */
class DemoSchoolTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Synthetic-Install-2026';
    private array $temporary = [];
    private ?string $storage = null;
    private ?string $disk = null;

    protected function setUp(): void
    {
        parent::setUp();
        // like a real install: nothing is "demo mode", and files go to a throw-away storage folder
        config(['school.demo' => false, 'school.local_only' => false]);
        app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $root = storage_path('framework/testing/demo-'.bin2hex(random_bytes(6)));
        mkdir($root.'/storage', 0700, true);
        $this->temporary[] = $root;
        $this->storage = $this->app->storagePath();
        $this->disk = config('filesystems.disks.local.root');
        $this->app->useStoragePath($root.'/storage');
        config(['filesystems.disks.local.root' => $root.'/storage/app/private']);
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        if ($this->storage !== null) {
            $this->app->useStoragePath($this->storage);
            config(['filesystems.disks.local.root' => $this->disk]);
            Storage::forgetDisk('local');
        }
        foreach ($this->temporary as $root) (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($root);
        parent::tearDown();
    }

    private function input(string $mode): array
    {
        return ['mode' => $mode, 'school_name' => 'โรงเรียนต้นธารวิทยา', 'admin_name' => 'ผู้ดูแลทดสอบ', 'admin_email' => 'owner@school.example', 'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD, 'app_url' => 'https://trial.school.example', 'public_email' => '', 'phone' => '', 'address' => ''];
    }

    private function signOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    private function account(array $accounts, string $login): array
    {
        foreach ($accounts as $a) if ($a['login'] === $login) return $a;
        $this->fail('no trial account '.$login);
    }

    public function test_full_install_fills_every_module_and_every_trial_account_can_sign_in(): void
    {
        $this->travelTo(Carbon::parse('2026-11-13 10:00', 'Asia/Bangkok'));
        $r = app(InstallationContent::class)->install($this->input('full'), 'full-test');
        $demo = $r['demo'];
        $this->assertSame('full', $r['mode']);
        $this->assertSame([], $demo['skipped'], 'no module may be skipped in a complete install');
        $this->assertLessThan(60, $demo['seconds']);
        $this->assertNull(auth()->user(), 'the builder must not leave its acting administrator signed in');

        // rows per table, not just "some rows somewhere"
        $expect = ['academic_terms' => 2, 'acad_calendar_days' => 10, 'bell_periods' => 8, 'teachers' => 13, 'classrooms' => 6, 'students' => 120, 'users' => 15,
            'reg_guardians' => 140, 'reg_student_guardians' => 150, 'reg_identifiers' => 130, 'lessons' => 420, 'att_class_days' => 90, 'attendances' => 60,
            'asm_subjects' => 96, 'asm_courses' => 96, 'asm_scores' => 2880, 'asm_attributes' => 1680, 'rpt_remarks' => 120, 'rpt_special_results' => 1,
            'bhv_rules' => 5, 'bhv_records' => 60, 'hlth_measurements' => 360, 'hlth_profiles' => 14, 'per_profiles' => 13, 'per_folders' => 5, 'per_files' => 5,
            'ld_leave_types' => 3, 'leave_requests' => 10, 'duties' => 40, 'ld_duty_swaps' => 1, 'psl_payees' => 14, 'psl_periods' => 2, 'psl_slips' => 28, 'psl_certs' => 14,
            'fin_fee_items' => 3, 'fin_payment_accounts' => 2, 'fin_invoices' => 120, 'fin_invoice_lines' => 360, 'fin_payments' => 80, 'fin_payment_claims' => 6, 'fin_claim_slips' => 6, 'fin_ledger' => 83,
            'doc_books' => 5, 'doc_documents' => 12, 'doc_routes' => 10, 'doc_files' => 1, 'mnt_assets' => 15, 'mnt_requests' => 9, 'mnt_request_logs' => 15, 'mnt_stock_items' => 6, 'mnt_stock_moves' => 8,
            'prj_plans' => 2, 'prj_projects' => 8, 'prj_activities' => 10, 'prj_reports' => 5, 'act_clubs' => 4, 'act_members' => 60, 'act_sessions' => 16, 'act_attendance' => 240,
            'act_certificates' => 5, 'act_elections' => 1, 'act_candidates' => 3, 'act_voters' => 90, 'act_ballots' => 90, 'con_projects' => 2, 'con_members' => 8, 'con_logs' => 6,
            'con_artifacts' => 4, 'con_reflections' => 4, 'exm_exams' => 1, 'exm_sheets' => 20, 'lrn_links' => 7, 'lrn_access' => 2, 'con_archives' => 1, 'con_archive_files' => 3, 'con_rows' => 24,
            'api_clients' => 1, 'api_request_log' => 14, 'api_student_pins' => 2, 'gp_accounts' => 2, 'gp_links' => 3, 'gp_notices' => 2, 'cmp_cases' => 3, 'cmp_actions' => 4,
            'srv_surveys' => 2, 'srv_responses' => 20, 'notice_lists' => 1, 'notice_rows' => 20, 'ita_years' => 1, 'ita_topics' => 18, 'ita_items' => 18,
            'cms_hubs' => 3, 'cms_hub_documents' => 7, 'cms_hub_downloads' => 7, 'cms_post_views' => 42, 'social_feeds' => 1, 'cms_messages' => 5, 'posts' => 62, 'cms_media' => 53];
        foreach ($expect as $table => $min) $this->assertGreaterThanOrEqual($min, DB::table($table)->count(), $table);
        $this->assertSame(DB::table('act_voters')->count(), DB::table('act_ballots')->count(), 'one ballot per voter');

        // coherent structure: a current open term and a closed, announced one; a clash-free full week for every room
        $current = \App\Support\Saan\TermScope::current();
        $this->assertSame([2569, 2], [(int) $current->year, (int) $current->term]);
        $this->assertSame(1, (int) $current->id, 'the current term must be the one SCHOOL_TERM_ID points at');
        $previous = DB::table('academic_terms')->where('id', '!=', $current->id)->first();
        $this->assertSame(96 / 2, DB::table('asm_courses')->where('term_id', $previous->id)->where('status', 'closed')->count());
        $this->assertSame(96 / 2, DB::table('asm_courses')->where('term_id', $current->id)->where('status', 'open')->count());
        foreach (DB::table('classrooms')->pluck('id') as $room) $this->assertSame(35, DB::table('lessons')->where('term_id', $current->id)->where('classroom_id', $room)->count(), 'full week of room '.$room);
        foreach (['teacher_id', 'classroom_id', 'room_key'] as $what) {
            $this->assertSame(0, DB::table('lessons')->select('term_id', 'weekday', 'period_id', $what)->groupBy('term_id', 'weekday', 'period_id', $what)->havingRaw('count(*) > 1')->get()->count(), 'timetable clash on '.$what);
        }
        $this->assertSame(0, DB::table('lessons')->whereNull('room_key')->count());
        $this->assertSame(15, DB::table('att_class_days')->distinct()->count('date'), 'attendance of the last 15 school days');
        $this->assertSame(0, DB::table('leave_requests')->whereNull('fiscal_year')->count());
        foreach (['draft', 'submitted', 'approved', 'rejected', 'withdrawn', 'cancel_requested', 'cancelled'] as $state) $this->assertTrue(DB::table('leave_requests')->where('status', $state)->exists(), 'leave '.$state);
        foreach (['pending', 'approved', 'rejected'] as $state) $this->assertTrue(DB::table('fin_payment_claims')->where('status', $state)->exists(), 'slip '.$state);
        foreach (['open', 'partial', 'paid'] as $state) $this->assertTrue(DB::table('fin_invoices')->where('status', $state)->exists(), 'invoice '.$state);
        $this->assertSame(1, DB::table('psl_periods')->where('status', 'published')->count());
        // the two surveys of the original trial (the coverage test checks the others): one still open, one closed
        $this->assertSame(['closed', 'open'], DB::table('srv_surveys')->where(fn ($q) => $q->where('slug', 'like', 'satisfaction-%')->orWhere('slug', 'like', 'green-school-quiz-%'))->orderBy('status')->pluck('status')->all());
        $this->assertGreaterThanOrEqual(5, DB::table('srv_responses')->whereNotNull('certificate_no')->count());
        // the first three cases are the ones of the original trial (the coverage test checks the stages added after them)
        $this->assertSame(['received', 'received', 'replied'], DB::table('cmp_cases')->orderBy('id')->limit(3)->pluck('status')->sort()->values()->all());
        $this->assertSame('published', DB::table('ita_years')->value('status'));
        foreach (['foreign', 'stateless', 'undetermined'] as $status) $this->assertTrue(DB::table('students')->where('nationality_status', $status)->exists(), $status);
        foreach (['thai_id', 'passport', 'g_code', 'other', 'pending'] as $kind) $this->assertTrue(DB::table('reg_identifiers')->where('kind', $kind)->exists(), $kind);

        // fictional identity numbers that the module's own validation accepts; nothing readable is stored for them
        $this->assertTrue(\Saan\Modules\Registry\Identity::thaiIdValid(DemoNames::thaiId(100)));
        $this->assertStringStartsWith('09999', DemoNames::thaiId(100));
        $identifier = DB::table('reg_identifiers')->where('kind', 'thai_id')->where('person_type', 'student')->orderBy('id')->first();
        $this->assertSame(DemoNames::thaiId(100), \Saan\Modules\Registry\Identity::decrypt($identifier->value_enc));
        $this->assertSame(0, DB::table('users')->where('email', 'not like', '%@'.DemoNames::MAIL_DOMAIN)->where('id', '!=', $r['admin_id'])->count());
        $this->assertSame(0, DB::table('users')->where('is_demo', true)->count(), 'trial accounts are ordinary accounts: no demo-login button can use them');

        // marks of a trial: marker, school name, site badge, staff directory from the personnel register
        $this->assertNotNull(DemoSchool::marker());
        $theme = json_decode(DB::table('cms_themes')->value('published'), true);
        $this->assertStringContainsString('ระบบทดลอง', $theme['school_name']);
        $site = json_decode(DB::table('cms_site_settings')->value('published'), true);
        $this->assertSame(DemoSchool::BADGE, $site['badge']);
        $this->assertSame('personnel', $site['directory_source']);
        // the figures under the home page banner are this trial school's own counts, not the numbers of the sample website set (24 / 6 / 3)
        $figures = array_column($site['statistics'], 'value', 'label');
        $this->assertSame([
            'นักเรียนสมมติ' => (string) DB::table('students')->where('status', 'studying')->count(),
            'ครูและบุคลากรสมมติ' => (string) DB::table('teachers')->where('is_active', true)->count(),
            'ห้องเรียน' => (string) DB::table('classrooms')->count(),
        ], $figures);
        $this->assertGreaterThan(24, (int) $figures['นักเรียนสมมติ']);
        $draft = json_decode(DB::table('cms_site_settings')->value('draft'), true);
        $this->assertSame($site['statistics'], $draft['statistics'], 'the draft shows the same figures as the published site');
        $home = $this->get('/portal')->assertOk();
        foreach ($figures as $label => $value) $home->assertSee('<strong>'.$value.'</strong><span>'.$label.'</span>', false);
        $home->assertDontSee('<strong>24</strong>', false);

        // the account list: shown once (returned), written once, never a fixed password
        $file = DemoSchool::accountFile();
        $this->assertFileExists($file);
        $text = file_get_contents($file);
        $staff = ['principal', 'homeroom', 'subject', 'finance', 'payroll', 'clerk', 'editor'];
        foreach ($demo['accounts'] as $a) {
            $this->assertStringContainsString($a['login']."\t".$a['password']."\t".$a['url'], $text);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{8,}$/', $a['password']);
            $this->assertStringNotContainsString($a['password'], json_encode(DB::table('saan_settings')->get()), 'no password in the settings table');
        }
        $this->assertSame(count($demo['accounts']), count(array_unique(array_column($demo['accounts'], 'password'))), 'every account has its own password');

        // admin: the installer's own account
        $this->post('/login', ['email' => 'owner@school.example', 'password' => self::PASSWORD])->assertRedirect('/');
        $this->get('/cms')->assertOk();
        $this->get('/work')->assertOk()->assertSee('ระบบทดลอง');
        $this->signOut();

        // staff accounts through the real sign-in form
        foreach ($staff as $key) {
            $a = $this->account($demo['accounts'], $key.'@'.DemoNames::MAIL_DOMAIN);
            $this->post('/login', ['email' => $a['login'], 'password' => $a['password']])->assertRedirect('/');
            $this->get($key === 'editor' ? '/cms' : '/work')->assertOk();
            if ($key === 'homeroom') {
                $room = (int) DB::table('classrooms')->orderBy('id')->value('id');
                $this->assertNotEmpty($this->getJson('/api/attendance/sheet?classroom_id='.$room.'&date=2026-11-12')->assertOk()->json());
                $this->assertNotEmpty($this->getJson('/api/assessment/courses')->assertOk()->json());
                $this->assertNotEmpty($this->getJson('/api/payslip/my/slips')->assertOk()->json());
                $this->assertNotEmpty($this->getJson('/api/leave/requests?scope=mine')->assertOk()->json());
                $this->assertNotEmpty($this->getJson('/api/documents/inbox')->assertOk()->json());
            }
            if ($key === 'finance') $this->assertNotEmpty($this->getJson('/api/finance/claims?status=pending')->assertOk()->json());
            if ($key === 'payroll') $this->assertNotEmpty($this->getJson('/api/payslip/payees')->assertOk()->json());
            if ($key === 'clerk') {
                $this->assertNotEmpty($this->getJson('/api/documents?kind=incoming')->assertOk()->json());
                $this->assertNotEmpty($this->getJson('/api/maintenance/requests?scope=all')->assertOk()->json());
            }
            if ($key === 'principal') $this->assertNotEmpty($this->getJson('/api/leave/requests?scope=all')->assertOk()->json());
            $this->signOut();
        }
        $this->post('/login', ['email' => 'teacher02@'.DemoNames::MAIL_DOMAIN, 'password' => 'whatever-123456'])->assertSessionHasErrors('email');
        $this->signOut();

        // students: student code and password on the student portal
        foreach (DemoAcademic::STUDENT_ACCOUNTS as $k) {
            $code = DB::table('students')->orderBy('id')->skip($k)->value('code');
            $a = $this->account($demo['accounts'], (string) $code);
            $this->get('/student')->assertOk();
            $this->post('/student/login', ['code' => $a['login'], 'secret' => $a['password']])->assertRedirect('/student');
            $this->get('/student')->assertOk();
            $this->getJson('/student/api/me')->assertOk()->assertJsonPath('must_change', false);
            $report = json_encode($this->getJson('/student/api/report')->assertOk()->json(), JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString('gpa', $report);
            $this->assertStringContainsString('ภาษาไทย', $report, 'grades of the announced term');
            $this->assertNotEmpty($this->getJson('/student/api/timetable')->assertOk()->json());
            $this->getJson('/student/api/attendance')->assertOk();
            $this->getJson('/student/api/behavior')->assertOk();
            $this->signOut();
        }

        // guardians: e-mail and password on the guardian portal
        foreach (DemoAcademic::GUARDIAN_ACCOUNTS as $i => $k) {
            $a = $this->account($demo['accounts'], 'guardian'.($i + 1).'@'.DemoNames::MAIL_DOMAIN);
            $this->post('/guardian/login', ['email' => $a['login'], 'password' => $a['password']])->assertRedirect('/guardian');
            $this->get('/guardian')->assertOk();
            $this->getJson('/guardian/api/me')->assertOk();
            $children = $this->getJson('/guardian/api/children')->assertOk()->json();
            $this->assertStringContainsString((string) DB::table('students')->orderBy('id')->skip($k)->value('first_name'), json_encode($children, JSON_UNESCAPED_UNICODE));
            $child = (int) DB::table('students')->orderBy('id')->skip($k)->value('id');
            $this->assertStringContainsString('gpa', json_encode($this->getJson('/guardian/api/children/'.$child.'/report')->assertOk()->json()));
            $this->assertStringContainsString('INV-', $this->getJson('/guardian/api/children/'.$child.'/fees')->assertOk()->getContent());
            foreach (['attendance', 'behavior', 'results', 'timetable', 'health'] as $page) $this->getJson('/guardian/api/children/'.$child.'/'.$page)->assertOk();
            $this->signOut();
        }

        // every module screen has something to show (signed in as the administrator)
        $admin = User::findOrFail($r['admin_id']);
        $this->actingAs($admin);
        $room = (int) DB::table('classrooms')->orderBy('id')->value('id');
        $student = (int) DB::table('students')->orderBy('id')->value('id');
        $election = (int) DB::table('act_elections')->value('id');
        $lists = ['/api/academic/terms', '/api/academic/classrooms', '/api/academic/teachers', '/api/academic/calendar?from=2026-10-16&to=2027-04-30',
            '/api/timetable/lessons?term_id='.$current->id.'&view=classroom&id='.$room, '/api/attendance/classes', '/api/attendance/reports/month?classroom_id='.$room.'&month=2026-11',
            '/api/assessment/subjects', '/api/assessment/courses', '/api/assessment/history/courses?term_id='.$previous->id, '/api/reports-pp/overview?term_id='.$previous->id,
            '/api/registry/students', '/api/registry/students/'.$student, '/api/behavior/classrooms/'.$room.'/summary', '/api/health/classrooms/'.$room.'/grid?round=1',
            '/api/personnel/staff', '/api/personnel/directory', '/api/leave/requests?scope=all', '/api/leave/types', '/api/duty/duties', '/api/duty/swaps?scope=all',
            '/api/payslip/periods', '/api/finance/invoices', '/api/finance/claims?status=all', '/api/finance/accounts', '/api/finance/fee-items', '/api/finance/ledger',
            '/api/documents?kind=incoming', '/api/documents/books', '/api/maintenance/requests?scope=all', '/api/maintenance/assets', '/api/maintenance/stock',
            '/api/projects/plans', '/api/projects/projects', '/api/activities/clubs', '/api/activities/elections', '/api/activities/elections/'.$election.'/results',
            '/api/pbl/projects', '/api/exam-checker/exams', '/api/learning/links', '/api/connectors/archives', '/api/api-gateway/clients', '/api/complaints/cases',
            '/api/surveys', '/api/notices/lists', '/api/ita/years', '/api/cms/hubs', '/api/social', '/api/site/messages', '/api/guardian-portal/terms', '/api/state'];
        foreach ($lists as $url) $this->assertNotEmpty($this->getJson($url)->assertOk()->json(), $url);
        foreach (['/operations', '/admin/modules', '/print/pp/student/'.$student.'?term_id='.$previous->id, '/timetable/print/classroom/'.$room.'?term='.$current->id] as $url) $this->get($url)->assertOk();
        $this->signOut();

        // public pages of the same data; the name list shows masked family names only
        $this->get('/portal')->assertOk()->assertSee('ระบบทดลอง');
        $this->get('/portal/people')->assertOk()->assertSee('ต้นธาร เรียนดี');
        $this->get('/portal/ita')->assertOk();
        $this->get('/portal/groups')->assertOk()->assertSee('กลุ่มบริหารวิชาการ');
        $this->get('/portal/surveys/'.DB::table('srv_surveys')->where('status', 'open')->value('slug'))->assertOk();
        $row = DB::table('notice_rows')->orderBy('id')->first();
        $family = explode(' ', $row->display_name)[2];
        $this->get('/portal/notices/'.DB::table('notice_lists')->value('slug'))->assertOk()->assertSee(explode(' ', $row->display_name)[1])->assertDontSee($family);
        $complaint = $this->account($demo['accounts'], (string) DB::table('cmp_cases')->where('status', 'replied')->value('receipt_no'));
        $this->post('/portal/complaints/status', ['receipt_no' => $complaint['login'], 'tracking_code' => $complaint['password']])->assertOk()->assertSee('ข้อความตอบตัวอย่าง');
    }

    public function test_the_school_is_built_around_today_also_in_the_first_term(): void
    {
        $this->travelTo(Carbon::parse('2027-06-10 09:00', 'Asia/Bangkok'));
        $r = app(InstallationContent::class)->install($this->input('full'), 'june-test');
        $this->assertSame([], $r['demo']['skipped']);
        $current = \App\Support\Saan\TermScope::current();
        $this->assertSame([2570, 1, '2027-05-01'], [(int) $current->year, (int) $current->term, (string) $current->starts_on]);
        $this->assertSame([2569, 2], array_map('intval', array_values((array) DB::table('academic_terms')->where('id', '!=', $current->id)->first(['year', 'term']))));
        $this->assertSame(15, DB::table('att_class_days')->distinct()->count('date'));
        $this->assertSame(0, DB::table('att_class_days')->where('date', '>', '2027-06-10')->count());
        $this->assertSame(0, DB::table('lessons')->select('term_id', 'weekday', 'period_id', 'teacher_id')->groupBy('term_id', 'weekday', 'period_id', 'teacher_id')->havingRaw('count(*) > 1')->get()->count());
        $this->assertSame(120, DB::table('fin_invoices')->count());
        $this->assertGreaterThan(0, DB::table('act_sessions')->count());
    }

    public function test_terms_always_contain_today_and_follow_each_other(): void
    {
        foreach (['2026-05-01', '2026-10-15', '2026-10-16', '2026-12-31', '2027-01-01', '2027-04-30', '2028-02-29'] as $day) {
            [$current, $previous] = DemoAcademic::terms(CarbonImmutable::parse($day, 'Asia/Bangkok'));
            $this->assertTrue($day >= $current['starts_on'] && $day <= $current['ends_on'], $day);
            $this->assertSame($current['starts_on'], CarbonImmutable::parse($previous['ends_on'])->addDay()->toDateString(), $day);
            $this->assertContains((int) substr($current['starts_on'], 0, 4), [$current['year'] - 543, $current['year'] - 542], $day);
        }
    }

    public function test_sample_and_blank_modes_create_no_trial_accounts_and_no_account_file(): void
    {
        foreach (['demo', 'blank'] as $mode) {
            DB::beginTransaction();
            $r = app(InstallationContent::class)->install($this->input($mode), $mode.'-test');
            $this->assertArrayNotHasKey('demo', $r);
            $this->assertSame(1, DB::table('users')->count(), $mode);
            $this->assertSame(0, DB::table('gp_accounts')->count() + DB::table('api_student_pins')->count(), $mode);
            $this->assertNull(DemoSchool::marker(), $mode);
            $this->assertFileDoesNotExist(DemoSchool::accountFile());
            DB::rollBack();
        }
        $this->assertSame('full', Setup::validateSite($this->input('full'))['mode']);
    }

    public function test_the_mock_library_of_the_website_sample_stays_available_on_a_full_trial(): void
    {
        // storage/installed.json carries the mode; config school.starter_data reads it (no SCHOOL_STARTER_DATA line is written into .env for it)
        $catalog = storage_path('app/private/'.\App\Domain\Cms\DemoLibrary::prefixFor(1).'/catalog.json');
        mkdir(dirname($catalog), 0700, true);
        file_put_contents($catalog, '{}');
        foreach (['full' => true, 'demo' => true, 'blank' => false] as $mode => $offered) {
            config(['school.starter_data' => $mode]);
            $this->assertSame($offered, \App\Domain\Cms\DemoLibrary::available(), $mode);
        }
        $this->assertFalse(method_exists(Setup::class, 'modeEnv'));
    }

    public function test_the_command_installs_on_a_blank_trial_once_and_passwords_differ_between_runs(): void
    {
        $this->travelTo(Carbon::parse('2026-11-13 10:00', 'Asia/Bangkok'));
        app(InstallationContent::class)->install($this->input('blank'), 'blank-trial');
        $this->assertNull(app(DemoSchool::class)->refusal());
        $this->artisan('saan:demo', ['action' => 'status'])->expectsOutputToContain('ไม่มีโรงเรียนสมมติ')->assertExitCode(0);

        // two builds on the same blank school (the first one is rolled back): no password repeats
        $files = [];
        DB::beginTransaction();
        $first = app(DemoSchool::class)->build((int) DB::table('users')->value('id'), 'https://trial.school.example', 'test', $files);
        $firstText = file_get_contents(DemoSchool::accountFile());
        DB::rollBack();
        foreach ($files as $f) @unlink($f);
        $this->assertSame(0, DB::table('students')->count());

        $this->artisan('saan:demo', ['action' => 'install', '--url' => 'https://trial.school.example'])->expectsOutputToContain('principal@'.DemoNames::MAIL_DOMAIN)->assertExitCode(0);
        $this->assertSame(120, DB::table('students')->count());
        $this->assertSame(13, DB::table('teachers')->count());
        $this->assertSame(1, (int) \App\Support\Saan\TermScope::currentId(), 'the term row of the blank install became the current term');
        $this->assertGreaterThanOrEqual(3, DB::table('posts')->where('status', 'published')->where('content_type', 'article')->count(), 'a blank trial gets a few invented news items');
        $secondText = file_get_contents(DemoSchool::accountFile());
        $this->assertNotSame($firstText, $secondText);
        $second = [];
        foreach (explode("\n", trim($secondText)) as $line) if (substr_count($line, "\t") >= 3) $second[] = explode("\t", $line)[2];
        $this->assertNotEmpty($second);
        $this->assertSame([], array_intersect(array_column($first['accounts'], 'password'), $second), 'a password of one install must never come back in another');
        $account = explode("\t", array_values(array_filter(explode("\n", $secondText), fn ($l) => str_starts_with(explode("\t", $l)[1] ?? '', 'homeroom@')))[0]);
        $this->post('/login', ['email' => $account[1], 'password' => $account[2]])->assertRedirect('/');
        $this->get('/work')->assertOk();
        $this->signOut();

        // a second run is refused and changes nothing
        $users = DB::table('users')->count();
        $this->artisan('saan:demo', ['action' => 'install'])->expectsOutputToContain('อยู่แล้ว')->assertExitCode(1);
        $this->assertSame($users, DB::table('users')->count());
        $this->assertSame($secondText, file_get_contents(DemoSchool::accountFile()));
        $this->artisan('saan:demo', ['action' => 'status'])->expectsOutputToContain('ระบบทดลอง')->assertExitCode(0);
    }

    public function test_the_command_refuses_a_school_with_its_own_data_and_the_public_edition(): void
    {
        app(InstallationContent::class)->install($this->input('blank'), 'own-data');
        DB::table('teachers')->insert(['school_id' => 1, 'name' => 'ครูของโรงเรียนจริง', 'subject' => 'ภาษาไทย']);
        $this->artisan('saan:demo', ['action' => 'install'])->expectsOutputToContain('มีข้อมูลของตัวเองแล้ว')->assertExitCode(1);
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(0, DB::table('students')->count());
        $this->assertFileDoesNotExist(DemoSchool::accountFile());
        DB::table('teachers')->delete();

        DB::table('posts')->where('slug', 'about')->update(['status' => 'published', 'published_at' => now()]);
        $this->assertStringContainsString('เนื้อหาที่เผยแพร่แล้ว', (string) app(DemoSchool::class)->refusal());
        DB::table('posts')->where('slug', 'about')->update(['status' => 'draft', 'published_at' => null]);

        // a school that already created accounts for its staff (and nothing else yet) is not a blank trial either
        $staff = User::forceCreate(['name' => 'ครูของโรงเรียนจริง', 'email' => 'real-teacher@school.example', 'password' => 'test-only-password', 'school_id' => 1, 'role' => 'teacher', 'is_demo' => false, 'is_active' => true]);
        $this->assertStringContainsString('บัญชีผู้ใช้อื่น', (string) app(DemoSchool::class)->refusal());
        $this->artisan('saan:demo', ['action' => 'install'])->expectsOutputToContain('มีข้อมูลของตัวเองแล้ว')->assertExitCode(1);
        $this->assertSame(0, DB::table('students')->count());
        DB::table('users')->where('id', $staff->id)->delete();
        $this->assertNull(app(DemoSchool::class)->refusal());

        config(['saan.edition' => 'public']);
        $this->artisan('saan:demo', ['action' => 'install'])->expectsOutputToContain('public edition')->assertExitCode(1);
        config(['saan.edition' => 'combined']);
        $this->assertSame(0, DB::table('students')->count());
    }

    public function test_a_failing_build_leaves_no_rows_and_no_files(): void
    {
        app(InstallationContent::class)->install($this->input('blank'), 'failing');
        // the page address of the trial's own group hub is taken: the build fails late, after portraits, slips and documents were already written
        DB::table('cms_hubs')->insert(['school_id' => 1, 'slug' => 'academic-affairs', 'title' => 'หน้าของโรงเรียนเอง', 'created_at' => now(), 'updated_at' => now()]);
        try {
            app(DemoSchool::class)->install((int) DB::table('users')->orderBy('id')->value('id'), 'https://trial.school.example');
            $this->fail('the build should have failed');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(0, DB::table('teachers')->count());
        $this->assertSame(0, DB::table('students')->count());
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('academic_terms')->count());
        $this->assertSame(1, DB::table('cms_hubs')->count());
        $this->assertNull(DemoSchool::marker());
        $this->assertFileDoesNotExist(DemoSchool::accountFile());
        $left = is_dir(storage_path('app/private')) ? iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(storage_path('app/private'), \FilesystemIterator::SKIP_DOTS))) : [];
        $this->assertSame([], array_values(array_filter(array_map('strval', $left), 'is_file')), 'no file of the failed build may stay');
        $this->assertNull(auth()->user());
    }
}
