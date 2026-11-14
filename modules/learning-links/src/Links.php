<?php

namespace Saan\Modules\LearningLinks;

use App\Support\Modules\ModuleRegistry;
use App\Support\Saan\DbError;
use App\Support\Saan\Mutation;
use App\Support\Saan\TeacherScope;
use App\Support\Saan\TermScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Domain logic of learning-links: scoping, validation, link writes. Controllers stay thin. */
class Links
{
    public const KINDS = ['lms' => 'ระบบจัดการเรียนรู้ (LMS)', 'video' => 'วิดีโอ', 'doc' => 'เอกสาร', 'app' => 'แอปหรือเว็บ'];
    public const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function school(): int
    {
        return (int) config('school.school_id');
    }

    public static function q(string $table)
    {
        return DB::table($table)->where('school_id', self::school());
    }

    public static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    public static function isAll(?object $user): bool
    {
        return $user && in_array($user->role, TeacherScope::ALL_ROLES, true);
    }

    public static function hasCourses(): bool
    {
        return Schema::hasTable('asm_courses');
    }

    /** @return int[] ids of asm_courses taught by this teacher account (any term) */
    public static function teacherCourseIds(?object $user): array
    {
        if (!$user || !$user->teacher_id || !self::hasCourses()) return [];
        return self::q('asm_courses')->where('teacher_id', $user->teacher_id)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** Active API clients that may sign students in (scope sso:student): id => name. Empty unless api-gateway is active. */
    public static function ssoClients(): array
    {
        if (!app(ModuleRegistry::class)->active('api-gateway') || !Schema::hasTable('api_clients')) return [];
        $out = [];
        foreach (self::q('api_clients')->where('is_active', true)->get(['id', 'name', 'scopes']) as $c) {
            $scopes = json_decode((string) $c->scopes, true);
            if (is_array($scopes) && in_array('sso:student', $scopes, true)) $out[(int) $c->id] = $c->name;
        }
        return $out;
    }

    /**
     * The badge "sign in with the school account" is promised only when the link really leads to the registered app: the host of the link must be
     * the host of one of that client's redirect URIs (otherwise any https URL tied to an SSO client would carry the badge).
     */
    public static function ssoBadge(object $r, array $sso): bool
    {
        if (!$r->api_client_id || !isset($sso[(int) $r->api_client_id])) return false;
        static $hosts = [];
        $id = (int) $r->api_client_id;
        if (!isset($hosts[$id]) || app()->runningUnitTests()) {
            $uris = json_decode((string) self::q('api_clients')->where('id', $id)->value('redirect_uris'), true);
            $hosts[$id] = array_values(array_filter(array_map(fn ($u) => is_string($u) ? strtolower((string) parse_url($u, PHP_URL_HOST)) : '', is_array($uris) ? $uris : [])));
        }
        $host = strtolower((string) parse_url((string) $r->url, PHP_URL_HOST));
        return $host !== '' && in_array($host, $hosts[$id], true);
    }

    public static function courseLabel(object $c): string
    {
        $name = null;
        if (Schema::hasTable('asm_subjects')) {
            $s = self::q('asm_subjects')->where('id', $c->subject_id)->first();
            $name = $s?->name ?? $s?->code ?? null;
        }
        return trim(($name ?: 'รายวิชา #'.$c->id).' ('.(self::q('classrooms')->where('id', $c->classroom_id)->value('name') ?? '-').')');
    }

    // ------------------------------------------------------------------ staff reads

    public static function options(?object $user): array
    {
        $ids = TeacherScope::classroomIds($user);
        $rooms = self::q('classrooms')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))->orderBy('name')->get(['id', 'name']);
        $courses = [];
        $term = TermScope::currentId();
        if (self::hasCourses() && $term) {
            $q = self::q('asm_courses')->where('term_id', $term);
            if (!self::isAll($user)) {
                $mine = self::teacherCourseIds($user);
                $q->where(fn ($w) => $w->whereIn('classroom_id', $ids ?: [0])->orWhereIn('id', $mine ?: [0]));
            }
            foreach ($q->orderBy('id')->get() as $c) $courses[] = ['id' => (int) $c->id, 'classroom_id' => (int) $c->classroom_id, 'label' => self::courseLabel($c)];
        }
        return [
            'classrooms' => $rooms->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'can_manage_code' => self::canManageCode((int) $r->id, $user)])->all(),
            'courses' => $courses,
            'sso_clients' => collect(self::ssoClients())->map(fn ($n, $id) => ['id' => $id, 'name' => $n])->values()->all(),
            'kinds' => self::KINDS,
            'can_school_wide' => self::isAll($user),
            'term_id' => $term,
        ];
    }

    public static function list(?object $user, array $f): array
    {
        $q = self::q('lrn_links');
        if (!self::isAll($user)) {
            $ids = TeacherScope::classroomIds($user);
            $mine = self::teacherCourseIds($user);
            $q->where(fn ($w) => $w->whereIn('classroom_id', $ids ?: [0])->orWhereIn('course_id', $mine ?: [0])->orWhere(fn ($x) => $x->whereNull('classroom_id')->whereNull('course_id')));
        }
        if (!empty($f['classroom_id'])) $q->where('classroom_id', (int) $f['classroom_id']);
        if (!empty($f['course_id'])) $q->where('course_id', (int) $f['course_id']);
        if (empty($f['all_terms'])) {
            $term = TermScope::currentId();
            $q->where(fn ($w) => $w->whereNull('term_id')->when($term, fn ($x) => $x->orWhere('term_id', $term)));
        }
        $rows = $q->orderBy('sort')->orderBy('id')->limit(500)->get();
        $rooms = self::q('classrooms')->pluck('name', 'id');
        $sso = self::ssoClients();
        return $rows->map(fn ($r) => self::shape($r, $rooms, $sso, $user))->all();
    }

    public static function shape(object $r, $rooms, array $sso, ?object $user): array
    {
        return [
            'id' => (int) $r->id, 'term_id' => $r->term_id ? (int) $r->term_id : null, 'classroom_id' => $r->classroom_id ? (int) $r->classroom_id : null,
            'classroom_name' => $r->classroom_id ? ($rooms[$r->classroom_id] ?? null) : null, 'course_id' => $r->course_id ? (int) $r->course_id : null,
            'title' => $r->title, 'url' => $r->url, 'kind' => $r->kind, 'kind_label' => self::KINDS[$r->kind] ?? $r->kind,
            'api_client_id' => $r->api_client_id ? (int) $r->api_client_id : null, 'sso' => self::ssoBadge($r, $sso),
            'sort' => (int) $r->sort, 'version' => (int) $r->version, 'can_edit' => self::canEdit($r, $user),
        ];
    }

    public static function canEdit(object $link, ?object $user): bool
    {
        if (self::isAll($user)) return true;
        if (!$user || $user->role !== 'teacher') return false;
        if ($link->course_id && in_array((int) $link->course_id, self::teacherCourseIds($user), true)) return true;
        return $link->classroom_id !== null && TeacherScope::canClassroom((int) $link->classroom_id, $user);
    }

    // ------------------------------------------------------------------ writes

    /** On update, fields the request leaves out keep the stored value instead of being reset. */
    private static function fields(array $d, ?object $row = null): array
    {
        if ($row) {
            foreach (['kind', 'sort', 'api_client_id'] as $k) {
                if (!array_key_exists($k, $d)) $d[$k] = $row->$k;
            }
        }
        $kind = (string) ($d['kind'] ?? 'app');
        if (!isset(self::KINDS[$kind])) self::invalid('kind', 'ชนิดของลิงก์ไม่ถูกต้อง');
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($d['title'] ?? '')));
        if ($title === '' || mb_strlen($title) > 160) self::invalid('title', 'กรุณากรอกชื่อลิงก์ (ไม่เกิน 160 ตัวอักษร)');
        $url = UrlRule::check((string) ($d['url'] ?? ''));
        $client = $d['api_client_id'] ?? null;
        if ($client !== null && $client !== '') {
            if (!isset(self::ssoClients()[(int) $client])) self::invalid('api_client_id', 'แอปนี้ไม่ได้ลงทะเบียนเป็นระบบที่ใช้บัญชีโรงเรียนเข้าสู่ระบบ');
            $client = (int) $client;
        } else $client = null;
        return ['title' => $title, 'url' => $url, 'url_hash' => UrlRule::hash($url), 'kind' => $kind, 'api_client_id' => $client, 'sort' => max(0, min(9999, (int) ($d['sort'] ?? 0)))];
    }

    private static function target(array $d, ?object $user): array
    {
        $term = null;
        if (!empty($d['term_id'])) {
            if (!self::q('academic_terms')->where('id', (int) $d['term_id'])->exists()) self::invalid('term_id', 'ไม่พบภาคเรียน');
            $term = (int) $d['term_id'];
        }
        if (!empty($d['course_id'])) {
            $course = self::hasCourses() ? self::q('asm_courses')->where('id', (int) $d['course_id'])->first() : null;
            if (!$course) self::invalid('course_id', 'ไม่พบรายวิชา');
            $own = $user && $user->teacher_id && (int) $course->teacher_id === (int) $user->teacher_id;
            if (!self::isAll($user) && !$own && !TeacherScope::canClassroom((int) $course->classroom_id, $user)) abort(403, 'ไม่มีสิทธิ์จัดการรายวิชานี้');
            return ['term_id' => (int) $course->term_id, 'classroom_id' => (int) $course->classroom_id, 'course_id' => (int) $course->id];
        }
        if (!empty($d['classroom_id'])) {
            $cid = (int) $d['classroom_id'];
            if (!self::q('classrooms')->where('id', $cid)->exists()) self::invalid('classroom_id', 'ไม่พบห้องเรียน');
            if (!TeacherScope::canClassroom($cid, $user)) abort(403, 'ไม่มีสิทธิ์จัดการห้องเรียนนี้');
            // a classroom link belongs to the current term unless the creator explicitly asks for "all terms"
            if ($term === null && empty($d['all_terms'])) $term = TermScope::currentId();
            return ['term_id' => $term, 'classroom_id' => $cid, 'course_id' => null];
        }
        if (!self::isAll($user)) abort(403, 'ลิงก์ของทั้งโรงเรียนจัดการได้เฉพาะผู้ดูแลระบบและผู้อำนวยการ');
        return ['term_id' => $term, 'classroom_id' => null, 'course_id' => null];
    }

    private static function scopeKey(array $t): string
    {
        return 'c'.($t['classroom_id'] ?? 0).'|k'.($t['course_id'] ?? 0).'|t'.($t['term_id'] ?? 0);
    }

    private static function dupe(array $t, string $hash, ?int $except = null): void
    {
        $q = self::q('lrn_links')->where('scope_key', self::scopeKey($t))->where('url_hash', $hash);
        if ($except) $q->where('id', '!=', $except);
        if ($q->exists()) abort(409, 'ลิงก์นี้มีอยู่ในห้องหรือรายวิชานี้แล้ว');
    }

    private static function guard(\Closure $fn): object
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            if (DbError::isUnique($e)) abort(409, 'ลิงก์นี้มีอยู่ในห้องหรือรายวิชานี้แล้ว');
            throw $e;
        }
    }

    public static function create(array $d, ?object $user): array
    {
        $t = self::target($d, $user);
        $f = self::fields($d);
        self::dupe($t, $f['url_hash']);
        $row = self::guard(fn () => Mutation::insert('lrn_links', $f + $t + ['scope_key' => self::scopeKey($t), 'created_by' => $user->id], 'learning.link.create', ['title' => $f['title']]));
        return self::shape($row, self::q('classrooms')->pluck('name', 'id'), self::ssoClients(), $user);
    }

    public static function update(int $id, array $d, ?object $user): array
    {
        $row = self::q('lrn_links')->where('id', $id)->first() ?? abort(404);
        if (!self::canEdit($row, $user)) abort(403, 'ไม่มีสิทธิ์แก้ไขลิงก์นี้');
        $f = self::fields($d, $row);
        self::dupe(['classroom_id' => $row->classroom_id, 'course_id' => $row->course_id, 'term_id' => $row->term_id], $f['url_hash'], $id);
        $new = self::guard(fn () => Mutation::update('lrn_links', $id, (int) $d['version'], $f, 'learning.link.update', ['title' => $f['title']]));
        return self::shape($new, self::q('classrooms')->pluck('name', 'id'), self::ssoClients(), $user);
    }

    public static function delete(int $id, int $version, ?object $user): void
    {
        $row = self::q('lrn_links')->where('id', $id)->first() ?? abort(404);
        if (!self::canEdit($row, $user)) abort(403, 'ไม่มีสิทธิ์ลบลิงก์นี้');
        Mutation::delete('lrn_links', $id, $version, 'learning.link.delete');
    }

    // ------------------------------------------------------------------ access codes

    public static function hashCode(int $classroomId, string $code): string
    {
        // keyed with the derived public-unlock key (not the master key): the public edition can verify it without ever holding the master key
        return hash_hmac('sha256', self::school().'|'.$classroomId.'|'.self::normaliseCode($code), \App\Support\Saan\HashKey::publicUnlock());
    }

    /** Verifies against the public-unlock key first, then the dedicated hash key, the app key and previous app keys, so older hashes, a rotation or a restore onto another key do not lock every class out. */
    public static function codeMatches(string $stored, int $classroomId, string $code): bool
    {
        $ok = false;
        foreach (\App\Support\Saan\HashKey::unlockCandidates() as $key) {
            $ok = hash_equals($stored, hash_hmac('sha256', self::school().'|'.$classroomId.'|'.self::normaliseCode($code), $key)) || $ok;
        }
        return $ok;
    }

    public static function normaliseCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public static function newCode(): string
    {
        $s = '';
        for ($i = 0; $i < 8; $i++) $s .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        return $s;
    }

    public static function accessStatus(int $classroomId): array
    {
        $a = self::q('lrn_access')->where('classroom_id', $classroomId)->first();
        return ['active' => (bool) $a && strtotime((string) $a->expires_at) > time(), 'exists' => (bool) $a, 'expires_at' => $a?->expires_at, 'version' => $a ? (int) $a->version : null];
    }

    /** One code per classroom: only the homeroom teacher, admin and principal may issue or revoke it. */
    public static function canManageCode(int $classroomId, ?object $user): bool
    {
        if (!TeacherScope::canClassroom($classroomId, $user)) return false;
        $ids = TeacherScope::homeroomIds($user);
        return $ids === null || in_array($classroomId, $ids, true);
    }

    /** @return array{code:string,expires_at:string} plaintext code is returned this once only */
    public static function issueCode(int $classroomId, int $days, ?object $user): array
    {
        if (!self::canManageCode($classroomId, $user)) abort(403, 'เฉพาะครูประจำชั้น ผู้ดูแลระบบ และผู้อำนวยการจัดการรหัสห้องได้');
        $code = self::newCode();
        $values = ['code_hash' => self::hashCode($classroomId, $code), 'expires_at' => now()->addDays($days), 'created_by' => $user->id];
        $cur = self::q('lrn_access')->where('classroom_id', $classroomId)->first();
        self::guard(fn () => $cur
            ? Mutation::update('lrn_access', (int) $cur->id, (int) $cur->version, $values, 'learning.access.issue', ['classroom_id' => $classroomId, 'days' => $days])
            : Mutation::insert('lrn_access', $values + ['classroom_id' => $classroomId], 'learning.access.issue', ['classroom_id' => $classroomId, 'days' => $days]));
        return ['code' => $code, 'expires_at' => (string) self::q('lrn_access')->where('classroom_id', $classroomId)->value('expires_at')];
    }

    public static function revokeCode(int $classroomId, ?object $user): void
    {
        if (!self::canManageCode($classroomId, $user)) abort(403, 'เฉพาะครูประจำชั้น ผู้ดูแลระบบ และผู้อำนวยการจัดการรหัสห้องได้');
        $cur = self::q('lrn_access')->where('classroom_id', $classroomId)->first() ?? abort(404, 'ห้องนี้ยังไม่มีรหัสเข้าดู');
        Mutation::delete('lrn_access', (int) $cur->id, (int) $cur->version, 'learning.access.revoke');
    }

    // ------------------------------------------------------------------ public page data (titles and links only)

    public static function publicLinks(int $classroomId): array
    {
        $term = TermScope::currentId();
        $rows = self::q('lrn_links')->where(fn ($w) => $w->where('classroom_id', $classroomId)->orWhere(fn ($x) => $x->whereNull('classroom_id')->whereNull('course_id')))
            ->where(fn ($w) => $w->whereNull('term_id')->when($term, fn ($x) => $x->orWhere('term_id', $term)))
            ->orderBy('sort')->orderBy('id')->limit(200)->get(['title', 'url', 'kind', 'api_client_id']);
        $sso = self::ssoClients();
        return $rows->map(fn ($r) => ['title' => $r->title, 'url' => $r->url, 'kind' => $r->kind, 'kind_label' => self::KINDS[$r->kind] ?? '', 'sso' => self::ssoBadge($r, $sso)])->all();
    }
}
