<?php

namespace Saan\Modules\LeaveDuty;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared helpers: school scoping, 422 helper, unique-violation to 409, role checks, date helpers. */
trait Common
{
    public function school(): int
    {
        return (int) config('school.school_id');
    }

    protected function q(string $table)
    {
        return DB::table($table)->where('school_id', $this->school());
    }

    /** Business-rule failure -> HTTP 422 with a Thai message on a field. */
    protected function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    public static function isApprover(?object $user): bool
    {
        return $user && in_array($user->role, Notify::APPROVER_ROLES, true);
    }

    protected function teacherOf(?object $user): ?int
    {
        return $user && $user->teacher_id ? (int) $user->teacher_id : null;
    }

    /** Run a write and convert unique-index violations (MySQL/MariaDB 1062, PostgreSQL 23505) into HTTP 409. */
    protected function guard(Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (QueryException $e) {
            $state = (string) ($e->errorInfo[0] ?? $e->getCode());
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            // unique violations only: NOT NULL / foreign-key failures share SQLSTATE 23000 (and driver code 19) but are real bugs and must stay 500s
            $unique = $driverCode === 1062 || $driverCode === 1586 || $state === '23505' || str_contains($e->getMessage(), 'UNIQUE constraint failed');
            if ($unique) {
                abort(409, 'ข้อมูลซ้ำกับรายการที่มีอยู่แล้ว กรุณาโหลดใหม่');
            }
            throw $e;
        }
    }

    /** Serialises concurrent writers of one teacher (overlap / quota / duty clash checks) inside a transaction. */
    protected function lockTeacher(int $teacherId): void
    {
        DB::table('teachers')->where('id', $teacherId)->where('school_id', $this->school())->lockForUpdate()->first();
    }

    public static function today(): string
    {
        return now()->toDateString();
    }

    public static function parseDate(string $d): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $d);
    }

    /** Thai fiscal year (Oct-Sep) in Buddhist Era, from a Y-m-d date. */
    public static function fiscalYear(string $date): int
    {
        $y = (int) substr($date, 0, 4);
        return ((int) substr($date, 5, 2) >= 10 ? $y + 1 : $y) + 543;
    }

    public static function mins(string $hhmm): int
    {
        return (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2);
    }

    /** Trim + collapse spaces + lower-case: case/space-insensitive key without relying on DB collation. */
    public static function key(string $v): string
    {
        if (class_exists(\Normalizer::class)) $v = \Normalizer::normalize($v, \Normalizer::FORM_C) ?: $v;
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $v)));
    }

    protected function teacherNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        return $ids ? $this->q('teachers')->whereIn('id', $ids)->pluck('name', 'id')->all() : [];
    }
}
