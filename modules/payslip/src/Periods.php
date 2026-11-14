<?php

namespace Saan\Modules\Payslip;

use App\Support\Saan\Mutation;
use Illuminate\Support\Facades\DB;

/** Pay periods: draft -> published, or draft -> cancelled (a whole period can only be cancelled before it is published). */
final class Periods
{
    public const STATUSES = ['draft', 'published', 'cancelled'];

    public static function create(int $year, int $month, string $label, string $payDate): object
    {
        return Support::unique(fn () => Mutation::insert('psl_periods', ['year' => $year, 'month' => $month, 'label' => $label, 'pay_date' => $payDate, 'status' => 'draft', 'created_by' => auth()->id()],
            'payslip.period.created', ['year' => $year, 'month' => $month]), 'มีงวดเดือนนี้ที่ใช้ชื่อเดียวกันแล้ว กรุณาใช้ชื่องวดอื่น');
    }

    /** Publishes a draft period that holds at least one live slip. */
    public static function publish(int $id, int $version): object
    {
        $p = Slips::period($id);
        abort_unless($p->status === 'draft', 422, 'เผยแพร่ได้เฉพาะงวดที่ยังเป็นฉบับร่าง');
        abort_unless(DB::table('psl_slips')->where('school_id', Support::school())->where('period_id', $id)->where('status', 'active')->exists(), 422, 'งวดนี้ยังไม่มีสลิป');
        return Mutation::update('psl_periods', $id, $version, ['status' => 'published', 'published_by' => auth()->id(), 'published_at' => now()], 'payslip.period.published', ['year' => (int) $p->year, 'month' => (int) $p->month]);
    }

    /** Cancels a draft period: its slips, stored PDFs and staged imports are deleted (no recipient ever saw them). */
    public static function cancel(int $id, int $version, string $reason): object
    {
        $p = Slips::period($id);
        abort_unless($p->status === 'draft', 422, 'ยกเลิกทั้งงวดได้ก่อนเผยแพร่เท่านั้น งวดที่เผยแพร่แล้วให้ยกเลิกสลิปรายคน');
        $paths = DB::table('psl_slips')->where('school_id', Support::school())->where('period_id', $id)->whereNotNull('file_path')->pluck('file_path')->all();
        $row = DB::transaction(function () use ($id, $version, $reason, $p) {
            $row = Mutation::update('psl_periods', $id, $version, ['status' => 'cancelled', 'cancelled_by' => auth()->id(), 'cancelled_at' => now(), 'cancel_reason' => $reason, 'totals_enc' => null],
                'payslip.period.cancelled', ['year' => (int) $p->year, 'month' => (int) $p->month, 'reason' => $reason]);
            DB::table('psl_slips')->where('school_id', Support::school())->where('period_id', $id)->delete();
            DB::table('psl_imports')->where('school_id', Support::school())->where('period_id', $id)->delete();
            return $row;
        });
        foreach ($paths as $path) Vault::forget($path);
        return $row;
    }

    /** One row for the officer list; amounts appear only when $withTotals (officer and summaries). */
    public static function view(object $p, bool $withTotals = true): array
    {
        $out = ['id' => (int) $p->id, 'year' => (int) $p->year, 'month' => (int) $p->month, 'label' => (string) $p->label, 'title' => Support::periodTitle($p), 'pay_date' => (string) $p->pay_date,
            'pay_date_th' => Support::thaiDate((string) $p->pay_date), 'status' => $p->status, 'version' => (int) $p->version, 'published_at' => $p->published_at ? (string) $p->published_at : null,
            'cancel_reason' => $p->cancel_reason];
        $live = DB::table('psl_slips')->where('school_id', Support::school())->where('period_id', $p->id)->where('status', 'active')->count();
        $voided = DB::table('psl_slips')->where('school_id', Support::school())->where('period_id', $p->id)->where('status', 'voided')->count();
        $out['slips'] = $live; $out['voided'] = $voided;
        if ($withTotals) $out['totals'] = $p->status === 'cancelled' ? null : (Vault::openJson($p->totals_enc) ?? ['gross' => 0, 'ded' => 0, 'net' => 0, 'data_slips' => 0, 'pdf_slips' => 0, 'unreadable_slips' => 0]);
        return $out;
    }
}
