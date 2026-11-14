<?php

namespace Saan\Modules\Finance;

use App\Support\Saan\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Small shared helpers: school id, Bangkok today, fiscal year, period guard, unique-violation conversion, document numbers. */
final class Support
{
    public static function school(): int { return (int) config('school.school_id'); }

    public static function today(): string { return now('Asia/Bangkok')->toDateString(); }

    /** Thai fiscal year (1 Oct - 30 Sep) in the Buddhist era. */
    public static function fiscalYear(string $date): int
    {
        [$y, $m] = array_map('intval', explode('-', substr($date, 0, 7)));
        return $y + 543 + ($m >= 10 ? 1 : 0);
    }

    public static function period(string $date): string { return substr($date, 0, 7); }

    public static function isClosed(string $date, bool $lock = false): bool
    {
        $q = DB::table('fin_periods')->where('school_id', self::school())->where('period', self::period($date));
        // Inside a write transaction take a shared lock so closing a period and writing into it happen one at a time.
        if ($lock && DB::transactionLevel() > 0) $q->sharedLock();
        $row = $q->first(['closed_at']);
        return $row !== null && $row->closed_at !== null;
    }

    public static function assertOpen(string $date): void
    {
        if (self::isClosed($date, true)) abort(422, 'งวด '.self::period($date).' ปิดบัญชีแล้ว ไม่สามารถบันทึกหรือยกเลิกรายการลงวันที่ในงวดนี้');
    }

    public static function staffIds(): array
    {
        $ids = Settings::get('finance.staff_user_ids', []);
        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    }

    public static function prefix(string $scope): string
    {
        $v = Settings::get('finance.'.$scope.'_prefix');
        return is_string($v) && preg_match('/^[A-Za-z0-9]{1,8}$/D', $v) ? $v : ($scope === 'invoice' ? 'INV' : 'RC');
    }

    /** A unique-key violation: MySQL/MariaDB 1062, PostgreSQL 23505, or constraint codes 19/2067/1555 with a UNIQUE or PRIMARY KEY message. */
    public static function isUniqueViolation(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driver = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : null;
        if ($driver === 1062 || $state === '23505') return true;
        return in_array($driver, [19, 2067, 1555], true) && (str_contains($e->getMessage(), 'UNIQUE constraint failed') || str_contains($e->getMessage(), 'PRIMARY KEY'));
    }

    /** Deadlock or serialization failure: MySQL/MariaDB 1213 (and 1205 lock wait timeout), SQLSTATE 40001. */
    public static function isDeadlock(\PDOException $e): bool
    {
        if ($e instanceof \Illuminate\Database\DeadlockException) return true; // Laravel throws this (a plain PDOException) when its own retries ran out
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driver = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : null;
        return $state === '40001' || $driver === 1213 || $driver === 1205;
    }

    /** Run a write: a unique-key violation becomes HTTP 409, a deadlock that survived the retries becomes 409 'try again'; anything else is rethrown. */
    public static function guard(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (QueryException $e) {
            if (self::isUniqueViolation($e)) abort(409, 'ข้อมูลซ้ำกับรายการที่มีอยู่ กรุณาโหลดใหม่แล้วลองอีกครั้ง');
            if (self::isDeadlock($e)) abort(409, 'ระบบกำลังทำรายการอื่นอยู่ กรุณาลองใหม่');
            throw $e;
        } catch (\PDOException $e) {
            if (self::isDeadlock($e)) abort(409, 'ระบบกำลังทำรายการอื่นอยู่ กรุณาลองใหม่');
            throw $e;
        }
    }

    /** guard() around a top-level transaction that Laravel retries (3 attempts) when the database reports a deadlock. */
    public static function tx(\Closure $fn): mixed
    {
        return self::guard(fn () => DB::transaction($fn, 3));
    }

    /**
     * Create the counter row in its own statement BEFORE the transaction opens, so the locking read inside the transaction always finds
     * an existing row (no gap-lock insert race between two requests on the first number of a fiscal year).
     */
    public static function ensureCounter(string $scope, ?string $date = null): void
    {
        $fy = in_array($scope, ['invoice', 'receipt'], true) ? self::fiscalYear($date ?? self::today()) : 0;
        DB::table('fin_counters')->insertOrIgnore(['school_id' => self::school(), 'scope' => $scope, 'fiscal_year' => $fy, 'last_value' => 0]);
    }

    /**
     * Next document number '<prefix>-<BE fiscal year>-<6 digits>'. Must run inside a transaction: the per-year counter row
     * is locked FOR UPDATE and incremented, so two requests can never receive the same number.
     */
    public static function nextNumber(string $scope, string $date): string
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('nextNumber ต้องเรียกภายใน transaction');
        $fy = self::fiscalYear($date);
        $where = ['school_id' => self::school(), 'scope' => $scope, 'fiscal_year' => $fy];
        $row = DB::table('fin_counters')->where($where)->lockForUpdate()->first();
        if (!$row) {
            DB::table('fin_counters')->insertOrIgnore($where + ['last_value' => 0]);
            $row = DB::table('fin_counters')->where($where)->lockForUpdate()->first();
        }
        $next = (int) $row->last_value + 1;
        DB::table('fin_counters')->where('id', $row->id)->update(['last_value' => $next]);
        return sprintf('%s-%d-%06d', self::prefix($scope), $fy, $next);
    }

    /** Serialise a whole operation (for example bulk billing) behind a locked counter row. Needs a transaction. */
    public static function lock(string $name): void
    {
        $where = ['school_id' => self::school(), 'scope' => $name, 'fiscal_year' => 0];
        $row = DB::table('fin_counters')->where($where)->lockForUpdate()->first();
        if (!$row) {
            // Normally ensureCounter() ran before the transaction; this fallback only covers a missing row.
            DB::table('fin_counters')->insertOrIgnore($where + ['last_value' => 0]);
            DB::table('fin_counters')->where($where)->lockForUpdate()->first();
        }
    }

    /** Facts printed on a receipt, frozen at payment time so a reprint matches the original after promotion or a school rename. */
    public static function receiptSnapshot(int $studentId): array
    {
        $school = self::school();
        $s = DB::table('students')->leftJoin('classrooms', 'classrooms.id', '=', 'students.classroom_id')->where('students.school_id', $school)->where('students.id', $studentId)
            ->first(['students.code', 'students.name', 'classrooms.name as classroom']);
        $theme = app(\App\Domain\CmsPublishing::class)->theme($school)['published'];
        $name = trim((string) ($theme['school_name'] ?? '')) ?: (string) config('saan.display');
        return ['snap_student_code' => $s->code ?? null, 'snap_student_name' => $s->name ?? null, 'snap_classroom' => $s->classroom ?? null,
            'snap_school_name' => mb_substr($name, 0, 200), 'snap_address' => mb_substr(trim((string) ($theme['address'] ?? '')), 0, 500) ?: null];
    }

    /** Writes BOM + header + rows to an open stream; formula-looking cells are neutralised. Does not close the stream. */
    public static function csvWrite($fh, array $header, iterable $rows): void
    {
        fwrite($fh, "\xEF\xBB\xBF");
        $clean = fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !preg_match('/^-?\d+(\.\d+)?$/D', $v) ? "'".$v : $v;
        fputcsv($fh, $header, ',', '"', '');
        foreach ($rows as $row) fputcsv($fh, array_map($clean, $row), ',', '"', '');
    }

    /** CSV text (UTF-8 BOM for Excel). Cells that start with a formula character are neutralised. */
    public static function csv(array $header, iterable $rows): string
    {
        $fh = fopen('php://temp', 'w+');
        self::csvWrite($fh, $header, $rows);
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);
        return $out;
    }

    public static function thaiDate(?string $iso): string
    {
        if (!$iso || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) return '';
        $months = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        return (int) $m[3].' '.$months[(int) $m[2]].' '.((int) $m[1] + 543);
    }
}

