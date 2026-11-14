<?php

namespace Saan\Modules\Connectors;

use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use App\Support\Saan\TeacherScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only archives of old school years. Rows live only in con_rows; no live table is read for writing or written to.
 * Teachers may look up only students whose code matches a student of their homeroom today, and only when Settings 'connectors.teacher_lookup' is true.
 */
class ArchiveService
{
    public const KINDS = ['student', 'grade', 'attendance_summary', 'other'];
    public const KIND_LABEL = ['student' => 'ทะเบียนนักเรียน', 'grade' => 'ผลการเรียน', 'attendance_summary' => 'สรุปเวลาเรียน', 'other' => 'อื่น ๆ'];
    private const MAX_RESULTS = 50;

    public static function school(): int { return (int) config('school.school_id'); }

    public static function uniqueViolation(\Throwable $e): bool
    {
        if (!$e instanceof QueryException) return false;
        return \App\Support\Saan\DbError::isUnique($e);

    }

    // ---------- archives ----------

    public function archives(): array
    {
        $files = DB::table('con_archive_files')->where('school_id', self::school())->orderBy('id')->get()->groupBy('archive_id');
        return DB::table('con_archives')->where('school_id', self::school())->orderByDesc('year_be')->orderByDesc('id')->get()
            ->map(fn ($a) => $this->present($a, ($files[$a->id] ?? collect())->all()))->all();
    }

    public function find(int $id): object
    {
        return DB::table('con_archives')->where('school_id', self::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบชุดข้อมูลปีเก่า');
    }

    public function present(object $a, ?array $files = null): array
    {
        $files ??= DB::table('con_archive_files')->where('school_id', self::school())->where('archive_id', $a->id)->orderBy('id')->get()->all();
        return ['id' => (int) $a->id, 'label' => $a->label, 'year_be' => (int) $a->year_be, 'source' => $a->source, 'status' => $a->status, 'locked' => (bool) $a->locked,
            'row_count' => (int) $a->row_count, 'imported_at' => $a->imported_at, 'version' => (int) $a->version,
            'files' => array_map(fn ($f) => ['id' => (int) $f->id, 'kind' => $f->kind, 'filename' => $f->filename, 'preset' => $f->preset, 'row_count' => (int) $f->row_count,
                'skipped' => (int) $f->skipped, 'imported_at' => $f->imported_at], $files)];
    }

    public function create(string $label, int $year, ?string $source): array
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label));
        $key = Presets::normalizeKey($label);
        abort_if(DB::table('con_archives')->where('school_id', self::school())->where('label_key', $key)->exists(), 409, 'มีชุดข้อมูลชื่อนี้แล้ว');
        try {
            $row = Mutation::insert('con_archives', ['label' => $label, 'label_key' => $key, 'year_be' => $year, 'source' => $source !== null ? trim($source) ?: null : null,
                'status' => 'ready', 'locked' => false, 'row_count' => 0, 'created_by' => auth()->id()], 'connectors.archive.created', ['label' => $label, 'year_be' => $year]);
        } catch (\Throwable $e) {
            if (self::uniqueViolation($e)) abort(409, 'มีชุดข้อมูลชื่อนี้แล้ว');
            throw $e;
        }
        return $this->present($row);
    }

    public function update(int $id, int $version, array $in, bool $isAdmin): array
    {
        $a = $this->find($id);
        $changes = []; $detail = [];
        if (array_key_exists('locked', $in) && (bool) $in['locked'] !== (bool) $a->locked) {
            abort_if(!$in['locked'] && !$isAdmin, 403, 'เฉพาะผู้ดูแลระบบเท่านั้นที่ปลดล็อกได้');
            $changes['locked'] = (bool) $in['locked']; $detail['locked'] = $changes['locked'];
        }
        $renames = array_intersect_key($in, array_flip(['label', 'year_be', 'source']));
        if ($renames) {
            abort_if($a->locked || ($changes['locked'] ?? false), 409, 'ชุดข้อมูลถูกล็อกไว้ แก้ไขไม่ได้');
            if (isset($in['label'])) {
                $label = trim(preg_replace('/\s+/u', ' ', $in['label'])); $key = Presets::normalizeKey($label);
                abort_if(DB::table('con_archives')->where('school_id', self::school())->where('label_key', $key)->where('id', '!=', $id)->exists(), 409, 'มีชุดข้อมูลชื่อนี้แล้ว');
                $changes['label'] = $label; $changes['label_key'] = $key; $detail['label'] = $label;
            }
            if (isset($in['year_be'])) { $changes['year_be'] = (int) $in['year_be']; $detail['year_be'] = $changes['year_be']; }
            if (array_key_exists('source', $in)) { $changes['source'] = $in['source'] !== null ? trim($in['source']) ?: null : null; $detail['source'] = true; }
        }
        if (!$changes) abort(422, 'ไม่มีข้อมูลที่เปลี่ยนแปลง');
        try {
            $row = Mutation::update('con_archives', $id, $version, $changes, 'connectors.archive.updated', $detail);
        } catch (\Throwable $e) {
            if (self::uniqueViolation($e)) abort(409, 'มีชุดข้อมูลชื่อนี้แล้ว');
            throw $e;
        }
        return $this->present($row);
    }

    /** Typed confirmation: the caller must send exactly confirmText($archive). */
    public static function confirmText(object $a): string { return 'ลบ '.$a->label; }

    public function delete(int $id, int $version, string $confirm): void
    {
        $a = $this->find($id);
        abort_if($a->locked, 409, 'ชุดข้อมูลถูกล็อกไว้ ลบไม่ได้ (ต้องปลดล็อกก่อน)');
        if (trim($confirm) !== self::confirmText($a)) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['message' => 'พิมพ์ข้อความยืนยันให้ตรงก่อนลบ', 'confirm_text' => self::confirmText($a)], 422));
        }
        DB::transaction(function () use ($id, $version, $a) {
            Mutation::delete('con_archives', $id, $version, 'connectors.archive.deleted');
            DB::table('con_rows')->where('school_id', self::school())->where('archive_id', $id)->delete();
            DB::table('con_archive_files')->where('school_id', self::school())->where('archive_id', $id)->delete();
            Mutation::audit('connectors.archive.deleted.detail', 'con_archives', $id, ['label' => $a->label, 'rows' => (int) $a->row_count]);
        });
    }

    // ---------- import of one file into an archive ----------

    public function importFile(int $archiveId, string $kind, string $preset, array $custom, string $filename, string $bytes): array
    {
        abort_unless(in_array($kind, self::KINDS, true), 422, 'ชนิดข้อมูลไม่ถูกต้อง');
        abort_unless(in_array($preset, Presets::keys(), true), 422, 'รูปแบบไฟล์ไม่ถูกต้อง');
        $a = $this->find($archiveId);
        abort_if($a->locked, 409, 'ชุดข้อมูลถูกล็อกไว้ นำเข้าเพิ่มไม่ได้');
        $sha = hash('sha256', $bytes);
        abort_if(DB::table('con_archive_files')->where('archive_id', $archiveId)->where('kind', $kind)->where('sha256', $sha)->exists(), 409, 'ไฟล์นี้ถูกนำเข้าในชุดข้อมูลนี้แล้ว');

        $csv = LegacyCsv::parse($bytes);
        [$map, $unmapped] = Presets::mapHeaders($csv['headers'], $preset, $custom);
        if ($kind !== 'other') abort_unless(in_array('code', $map, true), 422, 'ไม่พบคอลัมน์รหัสนักเรียน (เลขประจำตัวนักเรียน) ตั้งการจับคู่คอลัมน์เอง');
        if ($kind === 'student') abort_unless(array_intersect(['first_name', 'full_name'], $map), 422, 'ไม่พบคอลัมน์ชื่อนักเรียน');

        $rows = []; $skipped = 0; $problems = [];
        foreach ($csv['rows'] as $i => $cells) {
            $d = [];
            foreach ($cells as $c => $v) {
                $v = mb_substr((string) $v, 0, 500);
                // unmapped columns (national ID, phone, address, health...) are dropped: only Presets::FIELDS are kept
                if (isset($map[$c])) $d[$map[$c]] = $v;
            }
            $code = trim((string) ($d['code'] ?? ''));
            if ($kind === 'other' && $code === '') $code = 'row-'.$csv['lines'][$i];
            if ($code === '' || mb_strlen($code) > 40) {
                $skipped++; if (count($problems) < 20) $problems[] = 'แถว '.$csv['lines'][$i].': '.($code === '' ? 'ไม่มีรหัสนักเรียน' : 'รหัสยาวเกิน 40 ตัวอักษร');
                continue;
            }
            $name = trim((string) ($d['full_name'] ?? '')) ?: trim(trim((string) ($d['first_name'] ?? '')).' '.trim((string) ($d['last_name'] ?? '')));
            if ($kind === 'student' && $name === '') {
                $skipped++; if (count($problems) < 20) $problems[] = 'แถว '.$csv['lines'][$i].': ไม่มีชื่อนักเรียน'; continue;
            }
            $d = array_filter($d, fn ($v) => $v !== '');
            $d['code'] = $code;
            if ($name !== '') $d['name'] = $name;
            if (($room = Presets::classroom($d)) !== '') $d['classroom'] = $room;
            if (isset($d['sex']) && ($s = Presets::sex($d['sex']))) $d['sex'] = $s;
            $key2 = match ($kind) {
                'grade' => mb_substr(trim(($d['year'] ?? '').'/'.($d['term'] ?? '').'/'.($d['subject_code'] ?? $d['subject_name'] ?? ''), '/'), 0, 80) ?: null,
                'attendance_summary' => mb_substr(trim(($d['year'] ?? '').'/'.($d['term'] ?? ''), '/'), 0, 80) ?: null,
                default => null,
            };
            $rows[] = ['school_id' => self::school(), 'archive_id' => $archiveId, 'kind' => $kind, 'key1' => Presets::normalizeKey($code), 'key2' => $key2,
                'name_key' => $name !== '' ? mb_substr(Presets::normalizeKey($name), 0, 200) : null, 'data' => json_encode($d, JSON_UNESCAPED_UNICODE)];
        }
        abort_if(!$rows, 422, 'ไม่มีแถวที่นำเข้าได้'.($problems ? ' ('.$problems[0].')' : ''));

        try {
            $fileId = DB::transaction(function () use ($archiveId, $kind, $preset, $filename, $sha, $rows, $skipped, $a) {
                // the lock may have been taken while this file was parsed
                $fresh = DB::table('con_archives')->where('id', $archiveId)->where('school_id', self::school())->lockForUpdate()->first();
                abort_if(!$fresh || $fresh->locked, 409, 'ชุดข้อมูลถูกล็อกไว้ นำเข้าเพิ่มไม่ได้');
                $fid = (int) DB::table('con_archive_files')->insertGetId(['school_id' => self::school(), 'archive_id' => $archiveId, 'kind' => $kind, 'filename' => mb_substr(basename(str_replace('\\', '/', $filename)), 0, 200),
                    'sha256' => $sha, 'preset' => $preset, 'row_count' => count($rows), 'skipped' => $skipped, 'imported_by' => auth()->id(), 'imported_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                foreach (array_chunk($rows, 200) as $chunk) DB::table('con_rows')->insert(array_map(fn ($r) => $r + ['file_id' => $fid], $chunk));
                DB::table('con_archives')->where('id', $archiveId)->update(['row_count' => DB::raw('row_count + '.count($rows)), 'version' => DB::raw('version + 1'), 'imported_at' => now(), 'updated_at' => now()]);
                Mutation::audit('connectors.file.imported', 'con_archives', $archiveId, ['kind' => $kind, 'preset' => $preset, 'rows' => count($rows), 'skipped' => $skipped, 'sha256' => $sha]);
                return $fid;
            });
        } catch (\Throwable $e) {
            if (self::uniqueViolation($e)) abort(409, 'ไฟล์นี้ถูกนำเข้าในชุดข้อมูลนี้แล้ว');
            throw $e;
        }
        return ['archive' => $this->present($this->find($archiveId)), 'file_id' => $fileId, 'imported' => count($rows), 'skipped' => $skipped, 'problems' => $problems, 'unmapped_columns' => array_values($unmapped), 'dropped_columns' => array_values($unmapped)];
    }

    /** Legacy rows imported before unmapped columns were dropped may carry data.extra; only managers may see it. */
    private function decode(string $json, ?array $homerooms): array
    {
        $d = json_decode($json, true) ?: [];
        if ($homerooms !== null) unset($d['extra']);
        return $d;
    }

    // ---------- lookup (read only) ----------

    public static function teacherLookupOn(): bool { return Settings::get('connectors.teacher_lookup', false) === true; }

    public static function isManager(?object $user = null): bool { return in_array(($user ?? auth()->user())?->role, TeacherScope::ALL_ROLES, true); }

    /** Teachers: the setting must be on. Returns null for managers or the allowed homeroom classroom ids for a teacher. */
    public function gate(?object $user = null): ?array
    {
        $user ??= auth()->user();
        if (self::isManager($user)) return null;
        abort_unless($user?->role === 'teacher' && self::teacherLookupOn(), 403, 'ยังไม่เปิดให้ครูค้นข้อมูลปีเก่า');
        return TeacherScope::homeroomIds($user) ?: [0];
    }

    /** restrict a con_rows query to students currently in the teacher's homerooms */
    private function scopeRows($q, ?array $homerooms)
    {
        if ($homerooms === null) return $q;
        $expr = Schema::hasColumn('students', 'code_key') ? 'COALESCE(code_key, LOWER(TRIM(code)))' : 'LOWER(TRIM(code))';
        $sub = DB::table('students')->where('school_id', self::school())->whereIn('classroom_id', $homerooms);
        if (Schema::hasColumn('students', 'status')) $sub->where('status', 'studying');
        return $q->whereIn('key1', $sub->select(DB::raw($expr)));
    }

    public function search(string $q): array
    {
        $homerooms = $this->gate();
        $q = trim($q);
        $norm = Presets::normalizeKey($q); // exact code match keeps _ and % as typed (legacy codes such as STD_001)
        abort_if(mb_strlen(Presets::normalizeKey(preg_replace('/[%_\\\\]+/u', ' ', $q))) < 2, 422, 'พิมพ์อย่างน้อย 2 ตัวอักษร');
        $tokens = array_values(array_filter(explode(' ', $norm), fn ($t) => $t !== ''));
        // exact student code first (uses the con_rows school/key1 index); fall back to the name scan only when nothing matches
        $base = $this->scopeRows(DB::table('con_rows')->where('school_id', self::school())->where('key1', $norm), $homerooms);
        if (!(clone $base)->exists()) {
            $base = $this->scopeRows(DB::table('con_rows')->where('school_id', self::school()), $homerooms)
                ->where(function ($x) use ($tokens) {
                    foreach ($tokens as $t) $x->whereRaw("name_key LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $t).'%']);
                });
        }
        $groups = (clone $base)->select('archive_id', 'key1', DB::raw('COUNT(*) as n'))->groupBy('archive_id', 'key1')->orderByDesc('archive_id')->orderBy('key1')->limit(self::MAX_RESULTS + 1)->get();
        $truncated = $groups->count() > self::MAX_RESULTS;
        $groups = $groups->take(self::MAX_RESULTS);
        $archives = DB::table('con_archives')->where('school_id', self::school())->whereIn('id', $groups->pluck('archive_id')->unique())->get()->keyBy('id');
        $names = [];
        if ($groups->isNotEmpty()) {
            foreach (DB::table('con_rows')->where('school_id', self::school())->whereIn('archive_id', $groups->pluck('archive_id')->unique())->whereIn('key1', $groups->pluck('key1')->unique())
                ->whereNotNull('name_key')->orderByRaw("CASE kind WHEN 'student' THEN 0 ELSE 1 END")->orderBy('id')->get(['archive_id', 'key1', 'data']) as $r) {
                $names[$r->archive_id.'|'.$r->key1] ??= json_decode($r->data, true) ?: [];
            }
        }
        $out = $groups->map(function ($g) use ($archives, $names) {
            $d = $names[$g->archive_id.'|'.$g->key1] ?? [];
            $a = $archives[$g->archive_id] ?? null;
            return ['archive_id' => (int) $g->archive_id, 'archive_label' => $a->label ?? '', 'year_be' => (int) ($a->year_be ?? 0), 'code' => $d['code'] ?? $g->key1,
                'key' => $g->key1, 'name' => $d['name'] ?? '', 'classroom' => $d['classroom'] ?? '', 'rows' => (int) $g->n];
        })->values()->all();
        Mutation::audit('connectors.searched', 'con_rows', null, ['q' => mb_substr($q, 0, 80), 'results' => count($out), 'role' => auth()->user()->role]);
        return ['data' => $out, 'truncated' => $truncated];
    }

    /** All archived rows of one student in one archive, grouped by kind. */
    public function record(int $archiveId, string $code, bool $audit = true, string $action = 'connectors.record.viewed'): array
    {
        $homerooms = $this->gate();
        $a = $this->find($archiveId);
        $key = Presets::normalizeKey($code);
        $rows = $this->scopeRows(DB::table('con_rows')->where('school_id', self::school())->where('archive_id', $archiveId)->where('key1', $key), $homerooms)
            ->orderBy('kind')->orderBy('key2')->orderBy('id')->get(['kind', 'key1', 'key2', 'data']);
        abort_if($rows->isEmpty(), 404, 'ไม่พบข้อมูลนักเรียนในชุดข้อมูลนี้');
        $groups = [];
        foreach (self::KINDS as $k) $groups[$k] = [];
        foreach ($rows as $r) $groups[$r->kind][] = ['key2' => $r->key2, 'data' => $this->decode($r->data, $homerooms)];
        $name = '';
        foreach ($rows as $r) { $d = $this->decode($r->data, $homerooms); if (!empty($d['name'])) { $name = $d['name']; break; } }
        if ($audit) Mutation::audit($action, 'con_archives', $archiveId, ['code' => $code, 'rows' => $rows->count(), 'role' => auth()->user()->role]);
        return ['archive' => ['id' => (int) $a->id, 'label' => $a->label, 'year_be' => (int) $a->year_be], 'code' => $code, 'name' => $name, 'groups' => $groups, 'kinds' => self::KIND_LABEL];
    }

    /** CSV text (UTF-8 with BOM) of one student's archived history. */
    public function exportCsv(?int $archiveId, string $code): array
    {
        // entries: [year_be, label, kind, key2, data]; one archive, or every archive of the school when $archiveId is null
        $entries = [];
        if ($archiveId !== null) {
            $rec = $this->record($archiveId, $code, false);
            foreach ($rec['groups'] as $kind => $list) foreach ($list as $r) $entries[] = [$rec['archive']['year_be'], $rec['archive']['label'], $kind, $r['key2'], $r['data']];
        } else {
            $homerooms = $this->gate();
            $rows = $this->scopeRows(DB::table('con_rows')->where('school_id', self::school())->where('key1', Presets::normalizeKey($code)), $homerooms)->get(['id', 'archive_id', 'kind', 'key2', 'data']);
            abort_if($rows->isEmpty(), 404, 'ไม่พบข้อมูลนักเรียนในชุดข้อมูลปีเก่า');
            $archives = DB::table('con_archives')->where('school_id', self::school())->whereIn('id', $rows->pluck('archive_id')->unique())->get()->keyBy('id');
            foreach ($rows as $r) {
                $a = $archives[$r->archive_id] ?? null;
                if ($a) $entries[] = [(int) $a->year_be, $a->label, $r->kind, $r->key2, $this->decode($r->data, $homerooms), (int) $a->id, (int) $r->id];
            }
            usort($entries, fn ($x, $y) => [$x[0], $x[5], array_search($x[2], self::KINDS), (string) $x[3], $x[6]] <=> [$y[0], $y[5], array_search($y[2], self::KINDS), (string) $y[3], $y[6]]);
            abort_if(!$entries, 404, 'ไม่พบข้อมูลนักเรียนในชุดข้อมูลปีเก่า');
        }
        $fields = [];
        foreach ($entries as $e) foreach ($e[4] as $k => $v) {
            if ($k === 'extra' && is_array($v)) { foreach ($v as $ek => $_) $fields['extra:'.$ek] = true; } else $fields[$k] = true;
        }
        $fields = array_keys($fields);
        $out = "\xEF\xBB\xBF".LegacyCsv::line(array_merge(['ปีการศึกษา (พ.ศ.)', 'ชุดข้อมูล', 'ประเภท', 'รหัสนักเรียน', 'คีย์รอง'], $fields));
        foreach ($entries as $e) {
            $line = [$e[0], $e[1], self::KIND_LABEL[$e[2]] ?? $e[2], $code, $e[3]];
            foreach ($fields as $f) $line[] = str_starts_with($f, 'extra:') ? ($e[4]['extra'][substr($f, 6)] ?? '') : ($e[4][$f] ?? '');
            $out .= LegacyCsv::line($line);
        }
        Mutation::audit('connectors.record.exported', 'con_archives', $archiveId, ['code' => $code, 'scope' => $archiveId === null ? 'all' : 'one', 'rows' => count($entries), 'role' => auth()->user()->role]);
        return ['csv' => $out, 'filename' => 'archive-'.($archiveId ?? 'all').'-'.substr(sha1(Presets::normalizeKey($code)), 0, 8).'.csv'];
    }
}
