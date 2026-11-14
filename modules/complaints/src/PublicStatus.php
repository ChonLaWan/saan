<?php

namespace Saan\Modules\Complaints;

use App\Support\Saan\Edition;
use App\Support\Saan\NetKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The status page: receipt number + tracking code -> status, dates and the public reply. Nothing else is ever read here.
 * The public edition reads the projected table pub_complaint_status; combined/internal read the case table (same answer).
 */
final class PublicStatus
{
    public const GENERIC = 'ไม่พบเรื่องที่ตรงกับเลขรับเรื่องและรหัสติดตามนี้ กรุณาตรวจสอบตัวอักษรอีกครั้ง';
    public const THROTTLED = 'ตรวจสอบสถานะถี่เกินไป กรุณารอสักครู่แล้วลองใหม่';
    /** Every lookup counts, right or wrong: per /64 (IPv4 as is) in 10 minutes and per /48 in an hour. */
    public const PER_NET = 20;
    public const PER_WIDE = 120;

    /** True when this network has used up its lookups (and counts this one). */
    public static function throttled(?string $ip): bool
    {
        $k64 = 'complaint-status:'.NetKey::rate($ip);
        $k48 = 'complaint-status-wide:'.NetKey::wide($ip);
        if (RateLimiter::tooManyAttempts($k64, self::PER_NET) || RateLimiter::tooManyAttempts($k48, self::PER_WIDE)) return true;
        RateLimiter::hit($k64, 600);
        RateLimiter::hit($k48, 3600);
        return false;
    }

    /** @return ?array{receipt_no:string,category_label:string,status:string,status_label:string,received_at:string,updated_at:string,public_reply:?string} */
    public static function lookup(mixed $receiptRaw, mixed $codeRaw): ?array
    {
        $receipt = Complaints::normalizeReceipt($receiptRaw);
        $code = Complaints::normalizeCode($codeRaw);
        $formatOk = (bool) preg_match(Complaints::RECEIPT, $receipt) && (bool) preg_match(Complaints::CODE, $code);
        $row = $formatOk ? self::row($receipt) : null;
        // always the same work: one HMAC and one constant-time comparison per unlock key (current and retired), against a dummy hash
        // when the receipt does not exist or is malformed
        $stored = $row ? (string) $row->track_hash : str_repeat('0', 64);
        $same = Complaints::trackMatches($stored, $formatOk ? $receipt : 'C00-00000000', $formatOk ? $code : 'AAAAAAAAAA');
        if (!$same || $row === null) return null;
        return ['receipt_no' => (string) $row->receipt_no, 'category_label' => (string) $row->category_label, 'status' => (string) $row->status,
            'status_label' => Complaints::STATUSES[$row->status] ?? (string) $row->status, 'received_at' => (string) $row->received_at, 'updated_at' => (string) $row->updated_at,
            'public_reply' => $row->public_reply !== null && $row->public_reply !== '' ? (string) $row->public_reply : null];
    }

    private static function row(string $receipt): ?object
    {
        if (Edition::isPublic()) return DB::table('pub_complaint_status')->where('receipt_no', $receipt)->first();
        $c = DB::table('cmp_cases')->where('school_id', Complaints::school())->where('receipt_no', $receipt)->first();
        if (!$c) return null;
        return (object) ['receipt_no' => $c->receipt_no, 'track_hash' => $c->track_hash, 'category_label' => Complaints::CATEGORIES[$c->category] ?? '', 'status' => $c->status,
            'received_at' => $c->received_at, 'updated_at' => $c->status_changed_at, 'public_reply' => $c->public_reply];
    }

    /** Rows for the publisher (pub_complaint_status). The only columns that leave the internal side. */
    public static function projectedRows(): array
    {
        $out = [];
        foreach (DB::table('cmp_cases')->where('school_id', Complaints::school())->orderBy('id')->get(['receipt_no', 'track_hash', 'category', 'status', 'received_at', 'status_changed_at', 'public_reply']) as $c) {
            $out[] = ['receipt_no' => $c->receipt_no, 'track_hash' => $c->track_hash, 'category_label' => Complaints::CATEGORIES[$c->category] ?? '', 'status' => $c->status,
                'received_at' => $c->received_at, 'updated_at' => $c->status_changed_at, 'public_reply' => $c->public_reply];
        }
        return $out;
    }
}
