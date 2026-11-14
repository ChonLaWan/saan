<?php

namespace App\Domain\Demo;

use App\Models\User;
use App\Support\Modules\ModuleActionException;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRegistry;
use App\Support\Saan\Edition;
use App\Support\Saan\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Full-system trial data: one invented school ("โรงเรียนต้นธารวิทยา") for the current academic year, with rows in every module and
 * one sign-in account per kind of user. It exists so that somebody can install a trial and really use every screen.
 *
 * Rules this class keeps:
 * - only on request: installer choice "ทดลองเต็มระบบ" (mode=full) or `php artisan saan:demo install` on a blank trial;
 * - never on a school that has its own students, teachers, classes or published content, and never on the public edition;
 * - every person, number and document is invented (DemoNames); passwords are random per install, written once to
 *   storage/app/private/demo-accounts.txt and returned once to the caller, and are stored nowhere else in clear;
 * - all or nothing: build() runs inside the caller's database transaction and reports the files it wrote, so a failed build leaves
 *   neither rows nor files and can simply be started again;
 * - there is no uninstall: a trial is thrown away (new folder, new database) when the real school starts.
 */
final class DemoSchool
{
    public const MARKER = 'demo.school';
    public const ACCOUNT_FILE = 'demo-accounts.txt';
    public const NAME_SUFFIX = ' (ระบบทดลอง)';
    public const BADGE = 'ระบบทดลอง · ข้อมูลสมมติทั้งหมด ห้ามใช้กับโรงเรียนจริง';

    /** Modules the trial data lives in, dependencies first. A module that is not installed or cannot be switched on is skipped with its reason. */
    public const MODULES = ['academic', 'registry', 'timetable', 'attendance', 'assessment', 'reports-pp', 'behavior', 'health', 'personnel', 'leave-duty',
        'payslip', 'finance', 'documents', 'maintenance', 'projects', 'activities', 'constructionism', 'exam-checker', 'learning-links', 'connectors',
        'api-gateway', 'guardian-portal', 'student-portal', 'complaints', 'web-cms', 'surveys', 'web-notices', 'web-ita', 'web-social', 'operations'];

    public function __construct(private ModuleRegistry $registry, private ModuleManager $modules) {}

    public static function accountFile(): string
    {
        return storage_path('app/private/'.self::ACCOUNT_FILE);
    }

    /** The marker written at the end of a build, or null when this install holds no trial school. */
    public static function marker(): ?array
    {
        try {
            if (!Schema::hasTable('saan_settings')) return null;
            $v = Settings::get(self::MARKER);
        } catch (\Throwable) {
            return null;
        }
        return is_array($v) ? $v : null;
    }

    /**
     * Why the trial school cannot be created here, or null when it can.
     * $fresh = the installer calls right after it created the website content itself, so published sample content is expected.
     */
    public function refusal(bool $fresh = false): ?string
    {
        if (Edition::isPublic()) return 'ติดตั้งข้อมูลทดลองบนเว็บหน้าบ้าน (public edition) ไม่ได้ ให้ทำที่ระบบภายใน';
        if (self::marker()) return 'ระบบนี้มีโรงเรียนสมมติของชุดทดลองอยู่แล้ว ไม่ติดตั้งซ้ำ';
        $school = (int) config('school.school_id');
        $own = [];
        foreach (['students' => 'นักเรียน', 'teachers' => 'ครู', 'classrooms' => 'ห้องเรียน', 'lessons' => 'ตารางสอน', 'bell_periods' => 'คาบเรียน'] as $table => $label) {
            if (Schema::hasTable($table) && DB::table($table)->where('school_id', $school)->exists()) $own[] = $label;
        }
        if (Schema::hasTable('academic_terms') && DB::table('academic_terms')->where('school_id', $school)->count() > 1) $own[] = 'ภาคเรียน';
        // a blank trial has exactly one account (the installer's administrator); a school that already gave its staff accounts is not a trial
        if (DB::table('users')->where('school_id', $school)->count() > 1) $own[] = 'บัญชีผู้ใช้อื่น';
        if (!$fresh && DB::table('posts')->where('school_id', $school)->where('status', 'published')->exists()) $own[] = 'เนื้อหาที่เผยแพร่แล้ว';
        if ($own) return 'โรงเรียนนี้มีข้อมูลของตัวเองแล้ว ('.implode(', ', $own).') ชุดทดลองลงได้เฉพาะระบบที่ติดตั้งแบบเว็บเปล่าและยังไม่ได้กรอกข้อมูล';
        return null;
    }

    /** What `saan:demo status` prints. */
    public function status(): array
    {
        $marker = self::marker();
        $school = (int) config('school.school_id');
        $count = fn (string $t) => Schema::hasTable($t) ? (int) DB::table($t)->where('school_id', $school)->count() : 0;
        return [
            'installed' => $marker !== null,
            'installed_at' => $marker['installed_at'] ?? null,
            'source' => $marker['source'] ?? null,
            'version' => $marker['version'] ?? null,
            'seconds' => $marker['seconds'] ?? null,
            'skipped' => $marker['skipped'] ?? [],
            'account_file' => is_file(self::accountFile()) ? self::accountFile() : null,
            'students' => $count('students'), 'teachers' => $count('teachers'), 'classrooms' => $count('classrooms'), 'users' => $count('users'),
            'refusal' => $marker ? null : $this->refusal(),
        ];
    }

    /**
     * Stand-alone run (the artisan command): its own transaction, and nothing stays behind when it fails.
     * @return array{accounts:list<array>,file:string,seconds:float,skipped:array,filled:list<string>,files:list<string>}
     */
    public function install(int $adminId, string $baseUrl, string $source = 'command'): array
    {
        $created = [];
        try {
            // a closure with a reference: an arrow function would hand build() a copy and the file list would stay empty here
            return DB::transaction(function () use ($adminId, $baseUrl, $source, &$created) {
                return $this->build($adminId, $baseUrl, $source, $created);
            });
        } catch (\Throwable $e) {
            foreach ($created as $file) if (is_file($file)) @unlink($file);
            throw $e;
        }
    }

    /**
     * Build the trial school. Must run inside a database transaction of the caller; every file written is appended to $created
     * (also when the build fails half-way) so the caller can remove them together with the rolled-back rows.
     * @param list<string> $created
     */
    public function build(int $adminId, string $baseUrl, string $source, array &$created, bool $fresh = false): array
    {
        if ($why = $this->refusal($fresh)) throw new \RuntimeException($why);
        $admin = User::where('school_id', (int) config('school.school_id'))->where('role', 'admin')->where('is_active', true)->find($adminId)
            ?? throw new \RuntimeException('ไม่พบบัญชีผู้ดูแลระบบสำหรับสร้างข้อมูลทดลอง');
        $started = microtime(true);
        $ctx = new DemoContext($this->registry);
        $ctx->admin = $admin;
        $ctx->baseUrl = rtrim($baseUrl, '/');
        $guard = auth()->guard();
        $before = $guard->user();
        $mail = config('mail.default');
        @set_time_limit(600);
        try {
            // module services audit as the signed-in manager and must never send real mail while seeding
            config(['mail.default' => 'array']);
            $guard->setUser($admin);
            $this->switchOn($ctx);

            $academic = new DemoAcademic($ctx);
            $academic->structure();
            $academic->staffAccounts();
            $academic->registry();
            $learning = new DemoLearning($ctx);
            $learning->timetable();
            $learning->attendance();
            $learning->assessment();
            $learning->behavior();
            $learning->health();
            $office = new DemoOffice($ctx);
            $office->personnel();
            $office->leaveDuty();
            $office->payslip();
            $office->finance();
            $office->documents();
            $office->maintenance();
            $office->projects();
            $life = new DemoStudentLife($ctx);
            $life->activities();
            $life->projectLearning();
            $life->exams();
            $life->learningLinks();
            $life->connectors();
            $life->gateway();
            $academic->portalAccounts();
            $web = new DemoWebsite($ctx);
            $web->news();
            $web->hubs();
            $web->surveys();
            $web->notices();
            $web->complaints();
            $web->messages();
            $web->social();
            $web->markSite();
            $web->ita(); // last: its automatic links look at the news, hubs, complaints and the staff directory made above

            $seconds = round(microtime(true) - $started, 1);
            ksort($ctx->skipped);
            Settings::put(self::MARKER, ['installed_at' => now()->toIso8601String(), 'source' => $source, 'version' => (string) config('saan.version'),
                'seconds' => $seconds, 'filled' => array_keys($ctx->filled), 'skipped' => $ctx->skipped, 'accounts' => count($ctx->accounts)]);
            $file = $this->writeAccounts($ctx);
            return ['accounts' => $ctx->accounts, 'file' => $file, 'seconds' => $seconds, 'skipped' => $ctx->skipped, 'filled' => array_keys($ctx->filled), 'files' => $ctx->files];
        } finally {
            foreach ($ctx->files as $f) $created[] = $f;
            $ctx->cleanTemp();
            config(['mail.default' => $mail]);
            if ($before) $guard->setUser($before);
            elseif (method_exists($guard, 'forgetUser')) $guard->forgetUser();
        }
    }

    /** Same switch as the admin page /admin/modules (ModuleManager), for every module the data needs that is off. */
    private function switchOn(DemoContext $ctx): void
    {
        foreach (self::MODULES as $id) {
            $m = $this->registry->get($id);
            if (!$m || $m['active']) continue;
            try {
                $this->modules->setActive($id, true, $id);
            } catch (ModuleActionException $e) {
                $ctx->skip($id, $e->getMessage());
            }
        }
        $this->registry->flush();
    }

    /** Plain list, one account per line: role, sign-in name, password, where to start. Readable by the web server user only. */
    private function writeAccounts(DemoContext $ctx): string
    {
        $lines = [
            'สาน (Saan) - บัญชีทดลองของโรงเรียนสมมติ',
            'สร้างเมื่อ '.now('Asia/Bangkok')->format('Y-m-d H:i').' (เวลาไทย) สำหรับ '.$ctx->baseUrl,
            'ทุกบัญชีและทุกข้อมูลเป็นข้อมูลสมมติ ใช้ทดลองเท่านั้น ห้ามใช้ระบบนี้กับโรงเรียนจริง',
            'รหัสผ่านสุ่มใหม่ทุกการติดตั้ง ระบบไม่เก็บรหัสผ่านแบบอ่านได้ไว้ที่อื่น ลบไฟล์นี้เมื่อจดแล้ว',
            'บัญชีผู้ดูแลระบบคือบัญชีที่ตั้งไว้ตอนติดตั้ง (ไม่อยู่ในรายการนี้)',
            '',
            implode("\t", ['บทบาท', 'ชื่อเข้าระบบ', 'รหัสผ่าน', 'เริ่มที่', 'หมายเหตุ']),
        ];
        foreach ($ctx->accounts as $a) $lines[] = implode("\t", [$a['role'], $a['login'], $a['password'], $a['url'], $a['note'] ?? '']);
        $file = self::accountFile();
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true) && !is_dir(dirname($file))) throw new \RuntimeException('สร้างโฟลเดอร์เก็บรายการบัญชีทดลองไม่ได้');
        $bytes = implode("\n", $lines)."\n";
        if (file_put_contents($file, $bytes, LOCK_EX) !== strlen($bytes)) throw new \RuntimeException('เขียนรายการบัญชีทดลองไม่สำเร็จ');
        @chmod($file, 0600);
        $ctx->files[] = $file;
        return $file;
    }
}
