<?php

namespace Saan\Modules\Payslip;

use App\Support\Saan\Mutation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Slips: building and validating the encrypted amount record, create / void / replace, and period totals.
 *
 * Record (encrypted JSON): e = earning items [[name, satang]], d = deduction items, tax, sso, pf, gross, ded, net (all integer satang), bank (masked), bank_name.
 * Rule: gross = sum(e); ded = sum(d) + tax + sso + pf; net must equal gross - ded exactly, otherwise the row is refused.
 * Audit details carry ids and the kind only, never an amount, a name of an item or an identifier.
 */
final class Slips
{
    public const MAX_ITEMS = 30;
    public const MAX_PDF_MB = 5;

    /**
     * @param array<int,array{name:string,amount:mixed}> $earnings
     * @param array<int,array{name:string,amount:mixed}> $deductions
     * @return array{ok:bool,error?:string,data?:array}
     */
    public static function build(array $earnings, array $deductions, mixed $tax, mixed $sso, mixed $pf, mixed $net): array
    {
        $items = function (array $list) {
            $out = [];
            foreach ($list as $it) {
                $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($it['name'] ?? '')));
                $raw = $it['amount'] ?? 0;
                $a = is_string($raw) && trim($raw) === '' ? 0 : Support::satang($raw); // an empty cell of an item column is zero; the net column is never empty
                if ($name === '' || mb_strlen($name) > 80 || $a === null) return null;
                $out[] = [$name, $a];
            }
            return count($out) > self::MAX_ITEMS ? null : $out;
        };
        $e = $items($earnings); $d = $items($deductions);
        if ($e === null || $d === null) return ['ok' => false, 'error' => 'invalid'];
        $t = ($tax === null || $tax === '') ? 0 : Support::satang($tax);
        $s = ($sso === null || $sso === '') ? 0 : Support::satang($sso);
        $p = ($pf === null || $pf === '') ? 0 : Support::satang($pf);
        $n = Support::satang($net);
        if ($t === null || $s === null || $p === null || $n === null) return ['ok' => false, 'error' => 'invalid'];
        $gross = array_sum(array_column($e, 1));
        $ded = array_sum(array_column($d, 1)) + $t + $s + $p;
        if ($gross <= 0) return ['ok' => false, 'error' => 'invalid'];
        if ($n !== $gross - $ded) return ['ok' => false, 'error' => 'mismatch'];
        return ['ok' => true, 'data' => ['e' => $e, 'd' => $d, 'tax' => $t, 'sso' => $s, 'pf' => $p, 'gross' => $gross, 'ded' => $ded, 'net' => $n]];
    }

    /** Adds the masked bank account of the payee to a record at the moment the slip is made. */
    public static function withBank(array $data, object $payee): array
    {
        $acc = Vault::open($payee->bank_enc);
        $data['bank'] = Payees::maskBank($acc);
        $data['bank_name'] = $payee->bank_name;
        return $data;
    }

    /** The payee is the signed-in account itself: an officer never makes their own slip (same rule as PayeeController / PeriodController::notOwn). */
    public static function isOwn(int $payeeId): bool
    {
        $owner = DB::table('psl_payees')->where('school_id', Support::school())->where('id', $payeeId)->value('user_id');
        return $owner !== null && auth()->id() !== null && (int) $owner === (int) auth()->id();
    }

    public static function find(int $id): ?object
    {
        return DB::table('psl_slips')->where('school_id', Support::school())->where('id', $id)->first();
    }

    public static function data(object $slip): ?array { return Vault::openJson($slip->data_enc); }

    public static function period(int $id): object
    {
        return DB::table('psl_periods')->where('school_id', Support::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบงวด');
    }

    /** @param array|null $data kind data; $path kind pdf. Unique key (period, active_key) refuses a second live slip for the payee. */
    public static function create(object $period, int $payeeId, ?array $data, ?string $path, int $size = 0, ?int $replaces = null): object
    {
        abort_unless($period->status !== 'cancelled', 422, 'งวดนี้ถูกยกเลิกแล้ว');
        abort_if(self::isOwn($payeeId), 403, 'สร้างสลิปของบัญชีตัวเองไม่ได้ ให้เจ้าหน้าที่อีกคนทำให้');
        $row = Support::unique(fn () => Mutation::insert('psl_slips', [
            'period_id' => (int) $period->id, 'payee_id' => $payeeId, 'kind' => $data !== null ? 'data' : 'pdf', 'status' => 'active', 'active_key' => $payeeId,
            'data_enc' => $data !== null ? Vault::sealJson($data) : null, 'file_path' => $path, 'file_size' => $size, 'replaces_id' => $replaces,
            'created_by' => auth()->id(),
        ], 'payslip.slip.created', ['period_id' => (int) $period->id, 'payee_id' => $payeeId, 'kind' => $data !== null ? 'data' : 'pdf']),
            'พนักงานคนนี้มีสลิปที่ใช้งานอยู่ในงวดนี้แล้ว ให้ยกเลิกสลิปเดิมก่อน');
        return $row;
    }

    /** Voids one live slip (reason required, audited). The stored PDF is deleted; the row stays as the record that it existed. */
    public static function void(object $slip, int $version, string $reason): object
    {
        abort_unless($slip->status === 'active', 422, 'สลิปนี้ถูกยกเลิกแล้ว');
        $period = self::period((int) $slip->period_id);
        abort_unless($period->status !== 'cancelled', 422, 'งวดนี้ถูกยกเลิกแล้ว');
        $row = Mutation::update('psl_slips', (int) $slip->id, $version, ['status' => 'voided', 'active_key' => null, 'file_size' => 0, 'void_reason' => $reason, 'voided_by' => auth()->id(), 'voided_at' => now()],
            'payslip.slip.voided', ['period_id' => (int) $slip->period_id, 'payee_id' => (int) $slip->payee_id, 'reason' => $reason]);
        Vault::forget($slip->file_path);
        self::recompute((int) $slip->period_id);
        return $row;
    }

    /** Void the old slip and create the corrected one in one transaction. */
    public static function replace(object $slip, int $version, string $reason, ?array $data, ?UploadedFile $file): object
    {
        $period = self::period((int) $slip->period_id);
        $payee = Payees::find((int) $slip->payee_id) ?? abort(404, 'ไม่พบผู้รับเงิน');
        $path = null; $size = 0;
        if ($file !== null) { $pdf = Pdfs::inspect($file); $path = Vault::putFile($pdf); $size = strlen($pdf); }
        try {
            $new = DB::transaction(function () use ($slip, $version, $reason, $period, $payee, $data, $path, $size) {
                abort_unless($slip->status === 'active', 422, 'สลิปนี้ถูกยกเลิกแล้ว');
                $old = Mutation::update('psl_slips', (int) $slip->id, $version, ['status' => 'voided', 'active_key' => null, 'file_size' => 0, 'void_reason' => $reason, 'voided_by' => auth()->id(), 'voided_at' => now()],
                    'payslip.slip.voided', ['period_id' => (int) $slip->period_id, 'payee_id' => (int) $slip->payee_id, 'reason' => $reason, 'replaced' => true]);
                $new = self::create($period, (int) $payee->id, $data !== null ? self::withBank($data, $payee) : null, $path, $size, (int) $old->id);
                return $new;
            });
        } catch (\Throwable $e) {
            Vault::forget($path);
            throw $e;
        }
        Vault::forget($slip->file_path);
        self::recompute((int) $slip->period_id);
        return $new;
    }

    /** Encrypted totals of a period (gross, deductions, net over its live data slips; counts). */
    public static function recompute(int $periodId): array
    {
        $gross = $ded = $net = 0; $data = 0; $pdf = 0; $bad = 0;
        foreach (DB::table('psl_slips')->where('school_id', Support::school())->where('period_id', $periodId)->where('status', 'active')->get(['kind', 'data_enc']) as $s) {
            if ($s->kind === 'pdf') { $pdf++; continue; }
            $d = Vault::openJson($s->data_enc);
            if (!$d) { $bad++; continue; } // counted, so that a total that misses slips is flagged instead of looking complete
            $data++; $gross += (int) $d['gross']; $ded += (int) $d['ded']; $net += (int) $d['net'];
        }
        $totals = ['gross' => $gross, 'ded' => $ded, 'net' => $net, 'data_slips' => $data, 'pdf_slips' => $pdf, 'unreadable_slips' => $bad];
        DB::table('psl_periods')->where('school_id', Support::school())->where('id', $periodId)->update(['totals_enc' => Vault::sealJson($totals)]);
        return $totals;
    }

    /** Public view of one slip for its recipient (amounts included). */
    public static function recipientView(object $slip, object $period): array
    {
        $d = self::data($slip);
        return ['id' => (int) $slip->id, 'kind' => $slip->kind, 'period' => ['id' => (int) $period->id, 'title' => Support::periodTitle($period), 'year' => (int) $period->year, 'month' => (int) $period->month,
            'pay_date' => (string) $period->pay_date, 'pay_date_th' => Support::thaiDate((string) $period->pay_date)], 'data' => $d];
    }
}
