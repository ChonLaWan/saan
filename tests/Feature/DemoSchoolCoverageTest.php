<?php
namespace Tests\Feature;

use App\Domain\Demo\DemoSchool;
use App\Domain\InstallationContent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The trial school must give every screen something to show: second and third rows in every list, every workflow status the screens filter on, and the tables
 * that only fill through a correction, a history or an import. A module that gets a new feature table has to fill it in the trial (App\Domain\Demo) or put it on
 * the allow-list below with its reason, otherwise the last test of this file fails.
 */
class DemoSchoolCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** Tables that stay empty after a trial build on purpose, with the reason. Prefixes end with "*". */
    private const EMPTY_ON_PURPOSE = [
        'pub_*' => 'the public side of a two-app install: filled by the publisher, never by the internal side',
        'jobs' => 'queue', 'job_batches' => 'queue', 'failed_jobs' => 'queue', 'cache' => 'cache', 'cache_locks' => 'cache', 'sessions' => 'browser sessions of real sign-ins',
        'password_reset_tokens' => 'tokens of real requests', 'password_invite_tokens' => 'tokens of real requests', 'api_tokens' => 'tokens issued to a real client', 'api_sso_codes' => 'codes of a real sign-in',
        'saan_inbox_done' => 'two-app message inbox', 'saan_inbox_quarantine' => 'two-app message inbox', 'saan_pairing_codes' => 'pairing of the two apps', 'school_mail_settings' => 'real mail server settings of the school',
        'system_updates' => 'updates of the installed package', 'stu_events' => 'sign-in log of the student portal: written only by a real sign-in, no screen reads it',
        'cms_import_items' => 'no code reads or writes it: left over from a planned legacy-site import (migration 2026_11_05_000005 only)',
    ];

    private array $temporary = [];
    private ?string $storage = null;
    private ?string $disk = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => false, 'school.local_only' => false]);
        app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $root = storage_path('framework/testing/demo-cov-'.bin2hex(random_bytes(6)));
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

    private function build(string $mode = 'full'): array
    {
        $this->travelTo(Carbon::parse('2026-11-13 10:00', 'Asia/Bangkok'));
        $password = 'Synthetic-Install-2026';
        return app(InstallationContent::class)->install(['mode' => $mode, 'school_name' => 'โรงเรียนต้นธารวิทยา', 'admin_name' => 'ผู้ดูแลทดสอบ', 'admin_email' => 'owner@school.example', 'password' => $password,
            'password_confirmation' => $password, 'app_url' => 'https://trial.school.example', 'public_email' => '', 'phone' => '', 'address' => ''], 'coverage-test');
    }

    /** The rows of a list endpoint, whether it answers a plain list or {data: [...]}. */
    private function rows(string $url): array
    {
        $json = $this->getJson($url)->assertOk()->json();
        return is_array($json) && array_key_exists('data', $json) ? $json['data'] : (array) $json;
    }

    private function statuses(string $table, string $column = 'status'): array
    {
        return DB::table($table)->distinct()->pluck($column)->map(fn ($v) => (string) $v)->sort()->values()->all();
    }

    private function assertHas(array $expect, string $table, string $column = 'status'): void
    {
        $this->assertSame([], array_values(array_diff($expect, $this->statuses($table, $column))), $table.'.'.$column.' lacks a state the screen filters on');
    }

    public function test_every_filled_feature_has_rows_and_every_workflow_status_is_shown(): void
    {
        $r = $this->build();
        $this->assertSame([], $r['demo']['skipped']);

        // tables that were empty or thin before this change (row counts the screens now show)
        $min = ['asm_score_revisions' => 8, 'att_corrections' => 6, 'cmp_accused' => 2, 'cmp_files' => 2, 'cmp_notes' => 3, 'cms_post_revisions' => 4, 'cms_redirects' => 4, 'con_mapping_runs' => 2,
            'fin_periods' => 4, 'gp_acks' => 4, 'gp_contacts' => 4, 'grade_entries' => 100, 'psl_imports' => 2, 'reg_documents' => 8, 'reg_import_batches' => 4, 'reg_import_rows' => 12, 'rpt_templates' => 4,
            'srv_roster' => 20, 'srv_pending' => 3, 'student_classroom_moves' => 4, 'exm_exams' => 5, 'cmp_cases' => 8, 'cmp_reports' => 3, 'act_elections' => 4, 'con_projects' => 5, 'con_archives' => 2,
            'doc_files' => 10, 'prj_plans' => 3, 'psl_periods' => 5, 'srv_surveys' => 5, 'notice_lists' => 4, 'gp_notices' => 6, 'gp_links' => 8, 'gp_accounts' => 5, 'fin_fee_items' => 7, 'rpt_special_results' => 4,
            'ld_duty_swaps' => 6, 'lrn_access' => 6, 'api_clients' => 4, 'ita_years' => 3, 'social_feeds' => 1, 'act_clubs' => 7, 'api_student_pins' => 7, 'cms_messages' => 8];
        foreach ($min as $table => $n) $this->assertGreaterThanOrEqual($n, DB::table($table)->count(), $table);

        // every state of every workflow the lists filter on
        $this->assertHas(['draft', 'submitted', 'approved', 'rejected', 'withdrawn', 'cancel_requested', 'cancelled'], 'leave_requests');
        $this->assertHas(['requested', 'accepted', 'declined', 'approved', 'rejected', 'cancelled'], 'ld_duty_swaps');
        $this->assertHas(['assigned', 'cancelled'], 'duties');
        $this->assertHas(['new', 'accepted', 'in_progress', 'waiting_parts', 'done', 'rejected', 'cancelled'], 'mnt_requests');
        $this->assertHas(['open', 'partial', 'paid', 'void'], 'fin_invoices');
        $this->assertHas(['valid', 'void'], 'fin_payments');
        $this->assertHas(['pending', 'approved', 'rejected', 'withdrawn'], 'fin_payment_claims');
        $this->assertSame(1, DB::table('fin_periods')->whereNull('closed_at')->count(), 'one closed month was opened again');
        $this->assertGreaterThanOrEqual(3, DB::table('fin_periods')->whereNotNull('closed_at')->count());
        $this->assertHas(['draft', 'published', 'cancelled'], 'psl_periods');
        $this->assertSame(1, DB::table('psl_periods')->where('status', 'published')->count());
        $this->assertHas(['active', 'voided'], 'psl_slips');
        $this->assertHas(['staged', 'confirmed'], 'psl_imports');
        $this->assertHas(['registered', 'routed', 'done', 'cancelled'], 'doc_documents');
        $this->assertSame(['incoming', 'memo', 'order', 'outgoing'], array_values(array_intersect(['incoming', 'memo', 'order', 'outgoing'], DB::table('doc_books')->distinct()->pluck('kind')->sort()->values()->all())));
        $this->assertHas(['draft', 'approved', 'closed'], 'prj_plans');
        $this->assertHas(['proposed', 'approved', 'in_progress', 'done', 'cancelled'], 'prj_projects');
        $this->assertHas(['draft', 'open', 'closed'], 'srv_surveys');
        $this->assertHas(['anonymous', 'name_email', 'roster_code'], 'srv_surveys', 'identity_mode');
        $this->assertSame(11, DB::table('srv_roster')->whereNotNull('used_at')->count(), 'roster codes already used');
        $this->assertHas(['draft', 'published', 'archived'], 'notice_lists');
        $this->assertHas(['received', 'in_progress', 'replied', 'closed'], 'cmp_cases');
        $this->assertHas(['general', 'corruption', 'suggestion', 'info_request'], 'cmp_cases', 'category');
        $this->assertHas(['draft', 'published'], 'cmp_reports');
        $this->assertTrue(DB::table('cmp_cases')->where('status', '!=', 'closed')->whereNull('public_reply_at')->where('due_on', '<', '2026-11-13')->exists(), 'an overdue case');
        $this->assertHas(['draft', 'open', 'closed'], 'act_elections');
        $this->assertHas(['enrolled', 'waitlist'], 'act_members');
        $this->assertTrue(DB::table('act_clubs')->where('is_open', false)->exists());
        $this->assertTrue(DB::table('act_members')->where('result_manual', true)->exists(), 'a club result set by hand');
        $this->assertHas(['planning', 'active', 'presenting', 'done'], 'con_projects');
        $this->assertHas(['draft', 'open', 'closed'], 'exm_exams');
        $this->assertHas(['midterm', 'final', 'onet_practice', 'quiz', 'other'], 'exm_exams', 'kind');
        $this->assertHas(['published'], 'ita_years');
        $this->assertHas(['unread', 'read', 'archived'], 'cms_messages');
        $this->assertHas(['draft', 'published', 'scheduled'], 'posts');
        $this->assertTrue(DB::table('posts')->whereNotNull('deleted_at')->exists(), 'an article in the recycle bin');
        $this->assertTrue(DB::table('cms_redirects')->where('is_active', false)->exists() && DB::table('cms_redirects')->where('is_active', true)->exists());
        $this->assertHas(['pending', 'accepted', 'rejected'], 'reg_documents', 'review_state');
        $this->assertHas(['preview', 'committed', 'rolled_back'], 'reg_import_batches');
        $this->assertHas(['previewed', 'committed'], 'con_mapping_runs');
        $this->assertHas(['studying', 'transferred_out', 'left'], 'students');
        $this->assertHas(['active', 'voided'], 'bhv_records');
        $this->assertHas(['pending', 'approved', 'revise'], 'per_files', 'review_status');
        $this->assertHas(['ร', 'มผ', 'มส'], 'rpt_special_results', 'code');
        $this->assertHas(['pp5', 'pp6', 'summary'], 'rpt_templates', 'kind');
        $this->assertHas(['invite', 'view'], 'gp_links', 'purpose');
        $this->assertSame(['behavior', 'attendance_month', 'term_result'], array_values(array_intersect(['behavior', 'attendance_month', 'term_result'], DB::table('gp_acks')->pluck('kind')->unique()->all())));
        $this->assertTrue(DB::table('gp_links')->whereNotNull('revoked_at')->exists() && DB::table('gp_links')->whereNotNull('used_at')->exists() && DB::table('gp_links')->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '<', now())->exists());
        $this->assertTrue(DB::table('gp_accounts')->where('is_active', false)->exists() && DB::table('gp_contacts')->whereNull('verified_at')->exists() && DB::table('gp_contacts')->whereNotNull('verified_at')->exists());
        $this->assertTrue(DB::table('api_clients')->where('is_active', false)->exists() && DB::table('api_clients')->whereNotNull('allowed_ips')->exists() && DB::table('api_clients')->whereNotNull('redirect_uris')->exists());
        $this->assertTrue(DB::table('api_student_pins')->whereNotNull('locked_until')->exists() && DB::table('api_student_pins')->where('must_change', true)->exists());
        $this->assertTrue(DB::table('lrn_access')->where('expires_at', '<', now())->exists());
        $this->assertTrue(DB::table('fin_fee_items')->where('is_active', false)->exists() && DB::table('fin_fee_items')->whereNotNull('grade_levels')->exists());
        $this->assertTrue(DB::table('fin_ledger')->where('kind', 'income')->whereNull('payment_id')->exists() && DB::table('fin_ledger')->where('kind', 'reversal')->exists());

        // reasons are stored with the corrections and revisions they belong to
        $this->assertSame(0, DB::table('att_corrections')->whereNull('reason')->count());
        $this->assertSame(0, DB::table('asm_score_revisions')->whereNull('reason')->count());
        $this->assertGreaterThan(DB::table('asm_score_revisions')->distinct()->count('score_id'), DB::table('asm_score_revisions')->count(), 'a score that was changed twice');
        $this->assertSame(2, DB::table('student_classroom_moves')->where('effective_on', '<=', now()->toDateString())->distinct()->count('student_id'));
        $this->assertSame(0, DB::table('students')->where('status', 'studying')->whereIn('id', DB::table('student_classroom_moves')->select('student_id'))->whereNotIn('classroom_id', DB::table('classrooms')->select('id'))->count());
        $this->assertSame(120, DB::table('students')->count());

        // files that rows point to exist on the disk of the build
        foreach (['doc_files', 'reg_documents', 'cmp_files', 'per_files', 'fin_claim_slips'] as $table) {
            foreach (DB::table($table)->pluck('path') as $path) $this->assertTrue(is_file(storage_path('app/private/'.$path)), $table.' '.$path);
        }

        // the screens read it: signed in as the administrator
        $this->actingAs(User::findOrFail($r['admin_id']));
        $current = \App\Support\Saan\TermScope::current();
        $this->assertSame(DB::table('cmp_cases')->where('category', '!=', 'corruption')->count(), $this->getJson('/api/complaints/cases')->assertOk()->json('total'), 'the administrator sees every case but the corruption ones');
        $worked = (int) DB::table('cmp_notes')->value('case_id');
        $detail = $this->getJson('/api/complaints/cases/'.$worked)->assertOk()->json();
        $this->assertCount(2, $detail['notes']);
        $this->assertCount(1, $detail['files']);
        $this->assertCount(1, $detail['accused']);
        $this->assertTrue($detail['case']['overdue']);
        $this->assertGreaterThanOrEqual(3, count($this->rows('/api/complaints/reports')));
        $this->assertCount(6, array_unique(array_column($this->rows('/api/duty/swaps?scope=all'), 'status')));
        $course = (int) DB::table('asm_score_revisions')->value('course_id');
        $this->assertNotEmpty($this->getJson('/api/assessment/courses/'.$course.'/revisions')->assertOk()->json());
        $this->assertCount(4, $this->rows('/api/finance/periods'));
        $this->assertGreaterThanOrEqual(7, count($this->rows('/api/finance/fee-items')));
        $this->assertNotEmpty($this->rows('/api/timetable/trash?term_id='.$current->id));
        $this->assertNotEmpty($this->rows('/api/registry/guardians/unlinked'));
        $this->assertGreaterThanOrEqual(5, count($this->rows('/api/registry/import/batches')));
        $this->assertGreaterThanOrEqual(2, count($this->rows('/api/connectors/mapping/runs')));
        $roster = $this->getJson('/api/surveys/'.DB::table('srv_roster')->value('survey_id').'/roster')->assertOk()->json();
        $this->assertSame([20, 11], [$roster['total'], $roster['used']]);
        $this->assertGreaterThanOrEqual(3, count($this->rows('/api/ita/years')));
        $this->assertGreaterThanOrEqual(4, count($this->rows('/api/notices/lists')));
        $this->assertGreaterThanOrEqual(7, count($this->rows('/api/activities/clubs')));
        $this->assertGreaterThanOrEqual(4, count($this->rows('/api/activities/elections')));
        $this->assertGreaterThanOrEqual(5, count($this->rows('/api/exam-checker/exams?all_terms=1')));
        $this->assertNotEmpty($this->getJson('/api/state')->assertOk()->json('grades'), 'the school overview lists the old grade rows');
        $this->assertNotEmpty($this->rows('/api/site/redirects'));
        $post = (int) DB::table('cms_post_revisions')->select('post_id')->groupBy('post_id')->orderByRaw('count(*) desc')->value('post_id');
        $this->assertGreaterThanOrEqual(2, count($this->getJson('/api/site/content/'.$post)->assertOk()->json('revisions')));
        // the lessons moved to the recycle bin are not in the room's week any more
        $this->assertSame(2, DB::table('lessons')->whereNotNull('deleted_at')->count());
    }

    public function test_the_same_trial_builds_on_a_blank_install_and_without_some_modules(): void
    {
        // the command path (website content of a blank trial: three news only) and a build with five modules switched off by the host: nothing may fail, nothing of those modules may be written
        $off = ['surveys', 'payslip', 'complaints', 'exam-checker', 'activities'];
        config(['saan.modules.disable' => implode(',', $off)]);
        app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $r = $this->build('blank');
        $result = app(DemoSchool::class)->install((int) $r['admin_id'], 'https://trial.school.example');
        foreach ($off as $module) $this->assertArrayHasKey($module, $result['skipped'], $module);
        foreach (['srv_surveys', 'srv_roster', 'psl_periods', 'psl_imports', 'cmp_cases', 'cmp_files', 'exm_exams', 'act_clubs', 'act_elections'] as $table) $this->assertSame(0, DB::table($table)->count(), $table);
        $this->assertSame(120, DB::table('students')->count());
        $this->assertGreaterThan(0, DB::table('att_corrections')->count());
        $this->assertGreaterThan(0, DB::table('fin_periods')->count());
        $this->assertGreaterThan(0, DB::table('gp_acks')->count());
    }

    public function test_every_table_that_is_empty_after_the_build_is_on_the_allow_list(): void
    {
        $this->build();
        $empty = [];
        // only this database: the account may see other databases on the same server, with tables this one does not have
        foreach (Schema::getTableListing(DB::connection()->getDatabaseName(), false) as $table) {
            if ($table === 'migrations' || DB::table($table)->exists()) continue;
            $allowed = isset(self::EMPTY_ON_PURPOSE[$table]);
            foreach (self::EMPTY_ON_PURPOSE as $pattern => $why) if (str_ends_with($pattern, '*') && str_starts_with($table, substr($pattern, 0, -1))) $allowed = true;
            if (!$allowed) $empty[] = $table;
        }
        $this->assertSame([], $empty, 'these tables are empty in the trial school: fill them in App\Domain\Demo (so the manual can show the screen) or add them to EMPTY_ON_PURPOSE with the reason');
        foreach (array_keys(self::EMPTY_ON_PURPOSE) as $table) if (!str_ends_with($table, '*')) $this->assertTrue(Schema::hasTable($table), $table.' is on the allow-list but does not exist');
    }
}

