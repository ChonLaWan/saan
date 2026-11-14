<?php

namespace Saan\Modules\Payslip;

use App\Support\Saan\DbError;
use App\Support\Saan\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Small shared helpers: school id, who is a payroll officer, integer-satang money, Thai dates, payer data, unique-violation to 409. */
final class Support
{
    /** Roles that may read the summaries (never individual slips of other people). */
    public const SUMMARY_ROLES = ['admin', 'principal'];
    public const MONTHS = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

    public static function school(): int { return (int) config('school.school_id'); }

    public static function today(): string { return now('Asia/Bangkok')->toDateString(); }

    /** @return list<int> */
    public static function officerIds(): array
    {
        $ids = Settings::get('payslip.officer_user_ids', []);
        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    }

    /** A payroll officer is an ACTIVE account listed by the admin (setting payslip.officer_user_ids); role alone never grants it. */
    public static function isOfficer(?object $user): bool
    {
        return $user !== null && (bool) ($user->is_active ?? true) && in_array((int) $user->id, self::officerIds(), true);
    }

    public static function canSummary(?object $user): bool
    {
        return $user !== null && (self::isOfficer($user) || in_array($user->role, self::SUMMARY_ROLES, true));
    }

    public static function retentionYears(): int
    {
        $v = Settings::get('payslip.retention_years', 10);
        return is_numeric($v) ? max(0, min(30, (int) $v)) : 10;
    }

    // ---------------------------------------------------------------- money (integer satang, never floats)

    /** 123456 -> "1,234.56" */
    public static function baht(int $satang): string
    {
        $abs = abs($satang);
        return ($satang < 0 ? '-' : '').number_format(intdiv($abs, 100), 0, '.', ',').'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "1,234.5" -> 123450; null when the text is not a plain non-negative amount with at most two decimals. */
    public static function satang(mixed $text): ?int
    {
        if (is_int($text)) return $text >= 0 && $text < 10000000000 ? $text * 100 : null; // a JSON number is baht
        if (is_float($text)) $text = is_finite($text) ? sprintf('%.2f', $text) : '';
        $s = trim((string) $text);
        if ($s === '') return null;
        if (!preg_match('/^(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?$/D', $s, $m)) return null;
        $baht = str_replace(',', '', $m[1]);
        if (strlen($baht) > 10) return null;
        return (int) $baht * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    public static function thaiDate(?string $iso): string
    {
        if (!$iso || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) return '';
        return (int) $m[3].' '.self::MONTHS[(int) $m[2]].' '.((int) $m[1] + 543);
    }

    public static function periodTitle(object $p): string
    {
        return self::MONTHS[(int) $p->month].' '.((int) $p->year + 543).((string) $p->label !== '' ? ' ('.$p->label.')' : '');
    }

    /** Payer data for the 50 tawi: settings first, then the published website theme. @return array{name:string,tax_id:string,address:string} */
    public static function payer(): array
    {
        $theme = [];
        try { $theme = app(\App\Domain\CmsPublishing::class)->theme(self::school())['published'] ?? []; } catch (\Throwable) {}
        $name = trim((string) Settings::get('payslip.payer_name', '')) ?: trim((string) Settings::get('school.name', '')) ?: trim((string) ($theme['school_name'] ?? '')) ?: (string) config('saan.display');
        $address = trim((string) Settings::get('payslip.payer_address', '')) ?: trim((string) ($theme['address'] ?? ''));
        return ['name' => $name, 'tax_id' => trim((string) Settings::get('payslip.payer_tax_id', '')), 'address' => $address];
    }

    public static function key(string $v): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $v)));
    }

    /** Run a write: a unique-key violation becomes HTTP 409 with the given message; anything else is rethrown. */
    public static function unique(\Closure $fn, string $message = 'ข้อมูลซ้ำกับรายการที่มีอยู่ กรุณาโหลดใหม่แล้วลองอีกครั้ง'): mixed
    {
        try { return $fn(); }
        catch (QueryException $e) { if (DbError::isUnique($e)) abort(409, $message); throw $e; }
    }

    public static function users(array $ids): \Illuminate\Support\Collection
    {
        return $ids ? DB::table('users')->where('school_id', self::school())->whereIn('id', $ids)->get(['id', 'name', 'email', 'role', 'is_active'])->keyBy('id') : collect();
    }
}
