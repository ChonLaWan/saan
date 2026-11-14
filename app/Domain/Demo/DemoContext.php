<?php

namespace App\Domain\Demo;

use App\Support\Modules\ModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** State of one trial-school build: what was created so far, the dates it is built around, and small helpers the area builders share. */
final class DemoContext
{
    public int $school;
    /** users row of the installer's administrator; services that need a signed-in manager run as this account */
    public object $admin;
    public string $baseUrl;
    public CarbonImmutable $today;
    /** current term and the closed one before it: ['id','year','term','starts_on','ends_on'] */
    public array $term = [];
    public array $prev = [];
    /** class period number => bell_periods.id */
    public array $periods = [];
    /** staff index (DemoNames::STAFF) => ['id' => teachers.id, 'name', 'user_id'] */
    public array $teachers = [];
    /** 0..5 => ['id','name','grade' => 'ม.1','level' => 1,'homeroom' => staff index] */
    public array $classrooms = [];
    /** k => ['id','code','name','prefix','first','last','sex','room' => classroom index,'roll'] */
    public array $students = [];
    /** k => primary reg_guardians.id */
    public array $guardians = [];
    /** account key => users.id (principal, homeroom, subject, finance, payroll, clerk, editor, t<index>) */
    public array $users = [];
    /** rows of the account list: role, login, password, url, note */
    public array $accounts = [];
    /** module id => reason it got no data */
    public array $skipped = [];
    /** module ids that received data */
    public array $filled = [];
    /** absolute paths written by this build; removed again when the build fails */
    public array $files = [];
    /** holidays of the two terms: 'Y-m-d' => title */
    public array $holidays = [];

    private array $columns = [];
    private array $userRows = [];
    private array $temp = [];

    public function __construct(private ModuleRegistry $registry)
    {
        $this->school = (int) config('school.school_id');
        $this->today = CarbonImmutable::now('Asia/Bangkok')->startOfDay();
    }

    // ---------------------------------------------------------------- modules

    /** True when the module ships with this install, is switched on and its tables exist; otherwise the reason is kept for the report. */
    public function has(string $module, string ...$tables): bool
    {
        if (isset($this->skipped[$module])) return false;
        $m = $this->registry->get($module);
        if (!$m) return $this->skip($module, 'ไม่ได้ติดตั้งโมดูลนี้');
        if (!$m['active']) return $this->skip($module, 'โมดูลปิดอยู่: '.$m['reason']);
        foreach ($tables as $t) if (!Schema::hasTable($t)) return $this->skip($module, 'ไม่พบตาราง '.$t);
        $this->filled[$module] = true;
        return true;
    }

    public function skip(string $module, string $why): bool
    {
        $this->skipped[$module] = $why;
        unset($this->filled[$module]);
        return false;
    }

    // ---------------------------------------------------------------- rows

    public function columns(string $table): array
    {
        return $this->columns[$table] ??= array_flip(Schema::getColumnListing($table));
    }

    /** Fill the bookkeeping columns a table has (school, version, timestamps) and drop columns it does not have (a module that is not installed). */
    private function shape(string $table, array $row): array
    {
        $cols = $this->columns($table);
        $now = now();
        foreach (['school_id' => $this->school, 'version' => 1, 'created_at' => $now, 'updated_at' => $now] as $k => $v) {
            if (isset($cols[$k]) && !array_key_exists($k, $row)) $row[$k] = $v;
        }
        foreach ($row as $k => $v) if (is_array($v)) $row[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return array_intersect_key($row, $cols);
    }

    public function insertId(string $table, array $row): int
    {
        return (int) DB::table($table)->insertGetId($this->shape($table, $row));
    }

    /** Bulk insert; every row must carry the same keys. */
    public function insert(string $table, array $rows): void
    {
        foreach (array_chunk(array_map(fn ($r) => $this->shape($table, $r), $rows), 400) as $chunk) DB::table($table)->insert($chunk);
    }

    public function count(string $table): int
    {
        return Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;
    }

    // ---------------------------------------------------------------- people

    /** users row for a service that wants the acting user as an object. */
    public function user(string $key): object
    {
        return $this->userRows[$key] ??= \App\Models\User::findOrFail($this->users[$key]);
    }

    public function actAs(object $user): void
    {
        auth()->setUser($user instanceof \App\Models\User ? $user : \App\Models\User::findOrFail($user->id));
    }

    public function studentsOf(int $room): array
    {
        return array_filter($this->students, fn ($s) => $s['room'] === $room);
    }

    // ---------------------------------------------------------------- dates

    public function isSchoolDay(CarbonImmutable $d): bool
    {
        return $d->isWeekday() && !isset($this->holidays[$d->toDateString()]);
    }

    /** The last $n school days up to today, oldest first. */
    public function lastSchoolDays(int $n, ?CarbonImmutable $notBefore = null): array
    {
        $out = [];
        for ($d = $this->today, $i = 0; count($out) < $n && $i < 400; $d = $d->subDay(), $i++) {
            if ($notBefore && $d->lt($notBefore)) break;
            if ($this->isSchoolDay($d)) $out[] = $d;
        }
        return array_reverse($out);
    }

    /** The next $n school days after today. */
    public function nextSchoolDays(int $n): array
    {
        $out = [];
        for ($d = $this->today->addDay(), $i = 0; count($out) < $n && $i < 400; $d = $d->addDay(), $i++) if ($this->isSchoolDay($d)) $out[] = $d;
        return $out;
    }

    /** Term a date falls in (id), or null between terms. */
    public function termOf(string $date): ?int
    {
        foreach ([$this->term, $this->prev] as $t) if ($t && $date >= $t['starts_on'] && $date <= $t['ends_on']) return (int) $t['id'];
        return null;
    }

    /** A school day about $days before today that is not earlier than $floor (a term start, an issue date). */
    public function daysAgo(int $days, ?string $floor = null): CarbonImmutable
    {
        $d = $this->today->subDays($days);
        if ($floor !== null && $d->toDateString() < $floor) $d = CarbonImmutable::parse($floor, 'Asia/Bangkok');
        for ($i = 0; $i < 10 && !$this->isSchoolDay($d) && $d->lt($this->today); $i++) $d = $d->addDay();
        return $d;
    }

    /** A Thai wall-clock moment as the string a timestamp column stores (the application time zone). */
    public function at(string|CarbonImmutable $date, string $time = '08:00'): string
    {
        $d = $date instanceof CarbonImmutable ? $date->toDateString() : $date;
        return CarbonImmutable::parse($d.' '.$time, 'Asia/Bangkok')->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    // ---------------------------------------------------------------- files

    /** One-page PDF with plain Latin text (enough for a viewer to open; no font is embedded, so the text is not Thai). */
    public static function pdf(string $title, array $lines = []): string
    {
        $esc = fn (string $s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) preg_replace('/[^\x20-\x7E]/', '?', $s));
        $content = 'BT /F1 16 Tf 56 780 Td ('.$esc($title).") Tj ET\n";
        $y = 750;
        foreach (array_merge($lines, ['Fictional sample of the Saan trial school. Not a real document.']) as $line) {
            $content .= 'BT /F1 11 Tf 56 '.$y.' Td ('.$esc($line).") Tj ET\n";
            $y -= 18;
        }
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $o) {
            $offsets[] = strlen($out);
            $out .= ($i + 1)." 0 obj\n".$o."\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) $out .= sprintf("%010d 00000 n \n", $o);
        return $out.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    /** Small PNG that looks like a transfer slip mock-up; the lines make every image different. Null without GD. */
    public static function png(array $lines): ?string
    {
        if (!function_exists('imagecreatetruecolor')) return null;
        $im = imagecreatetruecolor(360, 480);
        imagefill($im, 0, 0, imagecolorallocate($im, 250, 250, 244));
        $ink = imagecolorallocate($im, 25, 62, 49);
        imagerectangle($im, 8, 8, 351, 471, $ink);
        imagestring($im, 5, 24, 24, 'SAMPLE SLIP - NOT A PAYMENT', $ink);
        foreach ($lines as $i => $line) imagestring($im, 4, 24, 64 + $i * 26, (string) preg_replace('/[^\x20-\x7E]/', '?', $line), $ink);
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    /** Write bytes below storage/app/private and remember the file for clean-up. Returns the relative path. */
    public function put(string $relative, string $bytes): string
    {
        $abs = storage_path('app/private/'.$relative);
        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0700, true) && !is_dir(dirname($abs))) throw new \RuntimeException('สร้างโฟลเดอร์ข้อมูลทดลองไม่ได้');
        if (file_put_contents($abs, $bytes) !== strlen($bytes)) throw new \RuntimeException('เขียนไฟล์ข้อมูลทดลองไม่สำเร็จ');
        $this->files[] = $abs;
        return $relative;
    }

    /** Remember a file that a module service stored (relative to the private disk). */
    public function track(?string $relative): void
    {
        if ($relative) $this->files[] = storage_path('app/private/'.$relative);
    }

    /** An upload object for a module service that only accepts uploaded files. The temporary file is removed by cleanTemp(). */
    public function upload(string $bytes, string $name, string $mime): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'saan-demo-');
        file_put_contents($tmp, $bytes);
        $this->temp[] = $tmp;
        return new UploadedFile($tmp, $name, $mime, null, true);
    }

    public function cleanTemp(): void
    {
        foreach ($this->temp as $f) if (is_file($f)) @unlink($f);
        $this->temp = [];
    }
}
