<?php

namespace Saan\Modules\Finance;

use App\Support\Saan\Mutation;
use App\Support\Saan\VersionConflict;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Payment claims: a guardian (or finance staff at the counter) says "I paid this much into this account" and attaches slips.
 * Finance approves (records a normal fin_payments row with the normal receipt number, partial payments allowed, overpayment refused) or rejects with a reason.
 * Audit: staff actions go through Mutation (audit_events); guardian actions are written by the caller to gp_events. Neither ever holds slip files, hashes or account numbers.
 */
final class Claims
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'withdrawn', 'voided'];
    public const MAX_PENDING_PER_INVOICE = 5;

    private static function school(): int { return Support::school(); }

    /**
     * @param UploadedFile[] $files
     * @return object the new claim row (status pending)
     */
    public static function submit(object $invoice, ?int $guardianId, ?object $staff, int $amount, string $transferredAt, ?int $accountId, array $files, ?string $note = null): object
    {
        if (count($files) < 1 || count($files) > Slips::MAX_FILES) abort(422, 'แนบสลิปได้ 1 ถึง '.Slips::MAX_FILES.' ไฟล์');
        if ($amount < 1) abort(422, 'จำนวนเงินไม่ถูกต้อง');
        $when = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $transferredAt, 'Asia/Bangkok');
        $infos = [];
        $seen = [];
        foreach ($files as $f) {
            $i = Slips::inspect($f, true);
            if (isset($seen[$i['sha256']])) abort(422, Slips::NEUTRAL); // the same file twice in one claim
            $seen[$i['sha256']] = true;
            $infos[] = $i;
        }
        $stored = [];
        try {
            return DB::transaction(function () use ($invoice, $guardianId, $staff, $amount, $when, $accountId, $infos, $note, &$stored) {
                foreach ($stored as $old) Slips::forget($old); // a retried transaction starts clean
                $stored = [];
                $inv = DB::table('fin_invoices')->where('school_id', self::school())->where('id', $invoice->id)->lockForUpdate()->first();
                abort_unless($inv, 404, 'ไม่พบใบแจ้งหนี้');
                if (!in_array($inv->status, ['open', 'partial'], true)) abort(422, 'ใบแจ้งหนี้นี้ไม่รับแจ้งชำระแล้ว');
                if ($amount > (int) $inv->total_satang - (int) $inv->paid_satang) abort(422, 'จำนวนเงินเกินยอดค้างชำระ');
                $pending = DB::table('fin_payment_claims')->where('school_id', self::school())->where('invoice_id', $inv->id)->where('status', 'pending')->count();
                if ($pending >= self::MAX_PENDING_PER_INVOICE) abort(422, 'มีรายการแจ้งชำระที่รอตรวจสอบหลายรายการแล้ว กรุณารอโรงเรียนตรวจสอบ');
                if ($when->greaterThan(CarbonImmutable::now('Asia/Bangkok')->addMinutes(10))) abort(422, 'วันเวลาที่โอนต้องไม่เป็นอนาคต');
                if ($when->toDateString() < substr((string) $inv->issued_on, 0, 10)) abort(422, 'วันเวลาที่โอนต้องไม่ก่อนวันที่ออกใบแจ้งหนี้');
                $allowed = Accounts::forInvoice((int) $inv->id);
                if ($allowed) {
                    if (!$accountId || !in_array($accountId, array_map(fn ($a) => (int) $a->id, $allowed), true)) abort(422, 'กรุณาเลือกบัญชีที่โอนเข้า');
                } else {
                    $accountId = null;
                }
                if (DB::table('fin_claim_slips')->where('school_id', self::school())->whereIn('dup_key', array_column($infos, 'sha256'))->exists()) abort(422, Slips::NEUTRAL);
                $values = ['invoice_id' => $inv->id, 'student_id' => $inv->student_id, 'guardian_id' => $guardianId, 'entered_by' => $staff?->id, 'source' => $staff ? 'staff' : 'guardian',
                    'amount_satang' => $amount, 'transferred_at' => $when->format('Y-m-d H:i:s'), 'account_id' => $accountId, 'status' => 'pending'];
                if ($staff) {
                    $claim = Mutation::insert('fin_payment_claims', $values, 'finance.claim_created', ['invoice_no' => $inv->invoice_no, 'amount_satang' => $amount, 'source' => 'staff']);
                } else {
                    $id = (int) DB::table('fin_payment_claims')->insertGetId($values + ['school_id' => self::school(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                    $claim = DB::table('fin_payment_claims')->where('id', $id)->first();
                }
                foreach ($infos as $i) {
                    $path = Slips::put($i, 'slips');
                    $stored[] = $path;
                    DB::table('fin_claim_slips')->insert(['school_id' => self::school(), 'claim_id' => $claim->id, 'path' => $path, 'mime' => $i['mime'], 'size' => $i['size'],
                        'sha256' => $i['sha256'], 'dup_key' => $i['sha256'], 'created_at' => now(), 'updated_at' => now()]);
                }
                return $claim;
            }, 3);
        } catch (QueryException $e) {
            foreach ($stored as $p) Slips::forget($p);
            if (Support::isUniqueViolation($e)) abort(422, Slips::NEUTRAL); // a parallel upload of the same file won the race
            if (Support::isDeadlock($e)) abort(409, 'ระบบกำลังทำรายการอื่นอยู่ กรุณาลองใหม่');
            throw $e;
        } catch (\Throwable $e) {
            foreach ($stored as $p) Slips::forget($p);
            throw $e;
        }
    }

    /** The guardian takes a pending claim back. Slip files are deleted (the hash is freed). */
    public static function withdraw(int $claimId, int $guardianId, int $version): object
    {
        $paths = [];
        $out = DB::transaction(function () use ($claimId, $guardianId, $version, &$paths) {
            $c = DB::table('fin_payment_claims')->where('school_id', self::school())->where('id', $claimId)->where('guardian_id', $guardianId)->lockForUpdate()->first();
            abort_unless($c, 404);
            if ($c->status !== 'pending') abort(422, 'รายการนี้โรงเรียนดำเนินการไปแล้ว ถอนคืนไม่ได้');
            if ((int) $c->version !== $version) throw new VersionConflict((array) $c);
            $paths = DB::table('fin_claim_slips')->where('school_id', self::school())->where('claim_id', $c->id)->pluck('path')->all();
            DB::table('fin_claim_slips')->where('school_id', self::school())->where('claim_id', $c->id)->delete();
            DB::table('fin_payment_claims')->where('id', $c->id)->update(['status' => 'withdrawn', 'decided_at' => now(), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            return DB::table('fin_payment_claims')->where('id', $c->id)->first();
        });
        foreach ($paths as $p) Slips::forget($p);
        return $out;
    }

    /** Default receipt date: the transfer date, kept between the invoice date and today. */
    private static function paidOn(object $claim, ?string $given): string
    {
        if ($given !== null) return $given;
        $inv = DB::table('fin_invoices')->where('school_id', self::school())->where('id', $claim->invoice_id)->first(['issued_on']);
        $day = substr((string) $claim->transferred_at, 0, 10);
        $on = max(substr((string) ($inv->issued_on ?? $day), 0, 10), min($day, Support::today()));
        // money that arrives after its transfer month was closed is received today, not backdated into the closed period
        return Support::isClosed($on) ? Support::today() : $on;
    }

    /** Window within which two claims for the same amount and account are suspected to be one transfer. */
    public const DUPLICATE_WINDOW_SECONDS = 120;

    /**
     * Other pending or approved claims (any student) with the same amount, the same receiving account and a transfer time within two minutes: the same transfer sent twice
     * as two different files (re-compressed, re-screenshotted) is not caught by the slip hash. A heuristic hint, never a block by itself.
     * @return list<int> claim ids
     */
    public static function possibleDuplicates(object $c): array
    {
        if (!in_array($c->status, ['pending', 'approved'], true)) return [];
        $t = strtotime((string) $c->transferred_at);
        if ($t === false) return [];
        $q = DB::table('fin_payment_claims')->where('school_id', self::school())->where('id', '!=', $c->id)->whereIn('status', ['pending', 'approved'])->where('amount_satang', (int) $c->amount_satang)
            ->whereBetween('transferred_at', [date('Y-m-d H:i:s', $t - self::DUPLICATE_WINDOW_SECONDS), date('Y-m-d H:i:s', $t + self::DUPLICATE_WINDOW_SECONDS)]);
        $c->account_id === null ? $q->whereNull('account_id') : $q->where('account_id', (int) $c->account_id);
        return $q->orderBy('id')->pluck('id')->map('intval')->all();
    }

    /**
     * possibleDuplicates() for a whole page of claims with ONE query (the staff queue must not grow its query count with the number of rows).
     * @param iterable<object> $claims @return array<int,list<int>> claim id => ids of the suspected twins
     */
    public static function duplicatesFor(iterable $claims): array
    {
        $mine = [];
        foreach ($claims as $c) if (in_array($c->status, ['pending', 'approved'], true) && strtotime((string) $c->transferred_at) !== false) $mine[(int) $c->id] = $c;
        if (!$mine) return [];
        $others = DB::table('fin_payment_claims')->where('school_id', self::school())->whereIn('status', ['pending', 'approved'])->whereIn('amount_satang', array_unique(array_map(fn ($c) => (int) $c->amount_satang, $mine)))
            ->where('transferred_at', '>=', date('Y-m-d H:i:s', min(array_map(fn ($c) => strtotime((string) $c->transferred_at), $mine)) - self::DUPLICATE_WINDOW_SECONDS))
            ->where('transferred_at', '<=', date('Y-m-d H:i:s', max(array_map(fn ($c) => strtotime((string) $c->transferred_at), $mine)) + self::DUPLICATE_WINDOW_SECONDS))
            ->orderBy('id')->get(['id', 'amount_satang', 'account_id', 'transferred_at']);
        $out = [];
        foreach ($mine as $id => $c) {
            foreach ($others as $o) {
                if ((int) $o->id === $id || (int) $o->amount_satang !== (int) $c->amount_satang || ($o->account_id === null ? null : (int) $o->account_id) !== ($c->account_id === null ? null : (int) $c->account_id)) continue;
                if (abs(strtotime((string) $o->transferred_at) - strtotime((string) $c->transferred_at)) <= self::DUPLICATE_WINDOW_SECONDS) $out[$id][] = (int) $o->id;
            }
        }
        return $out;
    }

    /** @return array{0:object,1:object,2:object} claim, payment, invoice */
    public static function approve(int $claimId, int $version, ?int $amount, ?string $paidOn, ?int $invoiceVersion, object $user, bool $confirmDuplicate = false): array
    {
        $peek = DB::table('fin_payment_claims')->where('school_id', self::school())->where('id', $claimId)->first();
        abort_unless($peek, 404, 'ไม่พบรายการแจ้งชำระ');
        $on = self::paidOn($peek, $paidOn);
        Support::ensureCounter('receipt', $on);
        [$claim, $pay, $inv] = Support::tx(function () use ($claimId, $version, $amount, $on, $invoiceVersion, $user, $confirmDuplicate) {
            $c = DB::table('fin_payment_claims')->where('school_id', self::school())->where('id', $claimId)->lockForUpdate()->first();
            abort_unless($c, 404, 'ไม่พบรายการแจ้งชำระ');
            if ($c->status !== 'pending') abort(422, 'รายการนี้ถูกดำเนินการไปแล้ว');
            if ((int) $c->version !== $version) throw new VersionConflict((array) $c);
            if ($amount !== null && $amount > (int) $c->amount_satang) abort(422, 'จำนวนที่รับต้องไม่เกินยอดที่แจ้งโอนตามสลิป');
            $dups = self::possibleDuplicates($c);
            if ($dups && !$confirmDuplicate) abort(422, 'พบรายการแจ้งชำระอื่นที่ยอด บัญชี และเวลาโอนใกล้เคียงกัน (รายการที่ '.implode(', ', $dups).') อาจเป็นการโอนครั้งเดียวกัน ตรวจกับรายการเดินบัญชีแล้วยืนยันอีกครั้งด้วย confirm_duplicate');
            $d = ['amount_satang' => $amount ?? (int) $c->amount_satang, 'method' => 'transfer', 'paid_on' => $on, 'reference' => 'สลิปโอน #'.$c->id];
            if ($invoiceVersion !== null) $d['invoice_version'] = $invoiceVersion;
            [$payment, $invoice] = Billing::payInTx((int) $c->invoice_id, $d, $user); // counter row already ensured above, outside the transaction; overpayment, closed period, void/paid invoice are refused here
            $claim = Mutation::update('fin_payment_claims', (int) $c->id, (int) $c->version, ['status' => 'approved', 'payment_id' => $payment->id, 'approved_satang' => $d['amount_satang'],
                'decided_by' => $user->id, 'decided_at' => now()], 'finance.claim_approved', ['claim_id' => (int) $c->id, 'receipt_no' => $payment->receipt_no, 'amount_satang' => $d['amount_satang']] + ($dups ? ['duplicate_confirmed_against' => $dups] : []));
            return [$claim, $payment, $invoice];
        });
        self::notify($claim, 'อนุมัติการแจ้งชำระแล้ว', 'ใบเสร็จเลขที่ '.$pay->receipt_no.' จำนวน '.Baht::format((int) $pay->amount_satang).' บาท ดูและพิมพ์ใบเสร็จได้ในหน้าค่าธรรมเนียม');
        return [$claim, $pay, $inv];
    }

    public static function reject(int $claimId, int $version, string $reason, object $user): object
    {
        $claim = Support::tx(function () use ($claimId, $version, $reason, $user) {
            $c = DB::table('fin_payment_claims')->where('school_id', self::school())->where('id', $claimId)->lockForUpdate()->first();
            abort_unless($c, 404, 'ไม่พบรายการแจ้งชำระ');
            if ($c->status !== 'pending') abort(422, 'รายการนี้ถูกดำเนินการไปแล้ว');
            if ((int) $c->version !== $version) throw new VersionConflict((array) $c);
            // a rejected claim keeps its files for the retention period but frees the hash so the guardian can send a corrected claim
            DB::table('fin_claim_slips')->where('school_id', self::school())->where('claim_id', $c->id)->update(['dup_key' => null, 'updated_at' => now()]);
            return Mutation::update('fin_payment_claims', (int) $c->id, (int) $c->version, ['status' => 'rejected', 'reject_reason' => $reason, 'decided_by' => $user->id, 'decided_at' => now()],
                'finance.claim_rejected', ['claim_id' => (int) $c->id, 'reason' => $reason]);
        });
        self::notify($claim, 'ไม่รับการแจ้งชำระ', 'เหตุผล: '.$reason.' กรุณาตรวจสอบแล้วแจ้งชำระใหม่ หรือติดต่อโรงเรียน');
        return $claim;
    }

    /** Inside the transaction that voids a receipt: the claim that produced it becomes 'voided' and its slip hashes are freed (a corrected claim may reuse the file). */
    public static function onPaymentVoided(int $paymentId): ?object
    {
        $c = DB::table('fin_payment_claims')->where('school_id', self::school())->where('payment_id', $paymentId)->where('status', 'approved')->lockForUpdate()->first();
        if (!$c) return null;
        DB::table('fin_claim_slips')->where('school_id', self::school())->where('claim_id', $c->id)->update(['dup_key' => null, 'updated_at' => now()]);
        return Mutation::update('fin_payment_claims', (int) $c->id, (int) $c->version, ['status' => 'voided', 'decided_by' => $c->decided_by, 'decided_at' => now()], 'finance.claim_voided', ['claim_id' => (int) $c->id, 'payment_id' => $paymentId]);
    }

    public static function notifyVoided(object $claim, string $receiptNo): void
    {
        self::notify($claim, 'โรงเรียนยกเลิกใบเสร็จของรายการแจ้งชำระ', 'ใบเสร็จเลขที่ '.$receiptNo.' ถูกยกเลิก ยอดค้างชำระกลับมาตามใบแจ้งหนี้ กรุณาติดต่อโรงเรียน หรือแจ้งชำระใหม่');
    }

    /** The claim's guardian, or for a counter claim (no guardian) every guardian who may view this student through a used, unrevoked invite. */
    private static function recipients(object $claim): array
    {
        if ($claim->guardian_id !== null) return [(int) $claim->guardian_id];
        if (!class_exists(\Saan\Modules\GuardianPortal\Access::class)) return [];
        $ids = DB::table('gp_links')->where('school_id', self::school())->where('student_id', $claim->student_id)->where('purpose', 'invite')->whereNotNull('used_at')->whereNull('revoked_at')
            ->distinct()->pluck('guardian_id')->map(fn ($v) => (int) $v)->all();
        return array_values(array_filter($ids, fn ($g) => \Saan\Modules\GuardianPortal\Access::mayView($g, (int) $claim->student_id)));
    }

    public static function notify(object $claim, string $title, string $body): void
    {
        if (!class_exists(\Saan\Modules\GuardianPortal\Notices::class)) return;
        $inv = DB::table('fin_invoices')->where('school_id', self::school())->where('id', $claim->invoice_id)->first(['invoice_no']);
        foreach (self::recipients($claim) as $g) {
            \Saan\Modules\GuardianPortal\Notices::push($g, (int) $claim->student_id, 'fee_claim', (int) $claim->id, $title.' (ใบแจ้งหนี้ '.($inv->invoice_no ?? '').')', $body);
        }
    }
}
