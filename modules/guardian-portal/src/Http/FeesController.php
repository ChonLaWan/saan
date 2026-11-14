<?php

namespace Saan\Modules\GuardianPortal\Http;

use App\Support\Saan\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Saan\Modules\Finance\Accounts;
use Saan\Modules\Finance\Baht;
use Saan\Modules\Finance\Claims;
use Saan\Modules\Finance\Http\ClaimController;
use Saan\Modules\Finance\Receipts;
use Saan\Modules\Finance\Slips;
use Saan\Modules\GuardianPortal\Access;
use Saan\Modules\GuardianPortal\Fees;
use Saan\Modules\GuardianPortal\Notices;

/**
 * Guardian fees (F3, internal only): invoices, payment accounts with QR, slip claims, receipts.
 * Every route first checks the child against the guardian's viewable students (404 for anything else), then that the invoice / claim / payment
 * belongs to THAT child (and a claim or slip to THIS guardian). Files are streamed only from here, never from a public URL.
 */
class FeesController
{
    public const CLAIMS_PER_HOUR = 10;

    private function child(Request $r, int $id): array
    {
        $ctx = $r->attributes->get('gp');
        if (!in_array($id, $ctx['student_ids'], true) || !Fees::available()) abort(404);
        return $ctx;
    }

    private function invoice(int $studentId, int $invoiceId): object
    {
        return DB::table('fin_invoices')->where('school_id', Access::school())->where('student_id', $studentId)->where('id', $invoiceId)->first() ?? abort(404);
    }

    public function fees(Request $r, int $id): JsonResponse
    {
        $ctx = $this->child($r, $id);
        return response()->json(Fees::overview($ctx['guardian_id'], $id))->header('Cache-Control', 'no-store, private');
    }

    /** QR image of an account: only for an invoice of this child that can still be paid and that uses this account. */
    public function qr(Request $r, int $id, int $invoice, int $account)
    {
        $this->child($r, $id);
        $inv = $this->invoice($id, $invoice);
        if (!in_array($inv->status, ['open', 'partial'], true)) abort(404);
        $acc = collect(Accounts::forInvoice((int) $inv->id))->first(fn ($a) => (int) $a->id === $account && $a->qr_path !== null);
        abort_unless($acc, 404);
        return Slips::respond($acc->qr_path, (string) $acc->qr_mime, 'qr-'.$acc->id);
    }

    public function receipt(Request $r, int $id, int $payment)
    {
        $this->child($r, $id);
        $p = DB::table('fin_payments as p')->join('fin_invoices as i', 'i.id', '=', 'p.invoice_id')->where('p.school_id', Access::school())->where('i.school_id', Access::school())
            ->where('i.student_id', $id)->where('p.id', $payment)->where('p.status', 'valid')->select('p.*')->first();
        abort_unless($p, 404);
        return Receipts::response($p, true);
    }

    public function claim(Request $r, int $id, int $invoice): JsonResponse
    {
        $ctx = $this->child($r, $id);
        $g = $ctx['guardian_id'];
        $key = 'gp-slip:'.$g;
        // every attempt counts (not only successful ones): the upload must not be usable to probe which files the school already holds
        if (RateLimiter::tooManyAttempts($key, self::CLAIMS_PER_HOUR)) abort(429, 'ส่งสลิปหลายครั้งเกินไป กรุณารอ '.ceil(RateLimiter::availableIn($key) / 60).' นาทีแล้วลองใหม่');
        RateLimiter::hit($key, 3600);
        $d = $r->validate(['slips' => 'required|array|min:1|max:'.Slips::MAX_FILES, 'slips.*' => 'file', 'amount' => 'required|string|max:20', 'transferred_at' => 'required|string|max:20', 'account_id' => 'nullable|integer|min:1'],
            [], ['slips' => 'สลิป', 'amount' => 'จำนวนเงิน', 'transferred_at' => 'วันเวลาที่โอน']);
        try {
            $amount = Baht::toSatang($d['amount']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }
        $when = ClaimController::when($d['transferred_at']);
        if ($when === null) throw ValidationException::withMessages(['transferred_at' => 'วันเวลาที่โอนไม่ถูกต้อง']);
        $inv = $this->invoice($id, $invoice);
        return Idempotency::run($r, function () use ($r, $g, $id, $inv, $amount, $when, $d) {
            $claim = Claims::submit($inv, $g, null, $amount, $when, isset($d['account_id']) ? (int) $d['account_id'] : null, $d['slips']);
            Access::event('guardian.fee_claim.submitted', $g, $id, ['claim_id' => (int) $claim->id, 'invoice_id' => (int) $inv->id, 'amount_satang' => $amount, 'files' => count($d['slips'])], $r);
            return response()->json(Fees::overview($g, $id), 201)->header('Cache-Control', 'no-store, private');
        }, fn () => Fees::overview($g, $id));
    }

    public function withdraw(Request $r, int $id, int $claim): JsonResponse
    {
        $ctx = $this->child($r, $id);
        $d = $r->validate(['version' => 'required|integer|min:1']);
        $own = DB::table('fin_payment_claims')->where('school_id', Access::school())->where('id', $claim)->where('student_id', $id)->where('guardian_id', $ctx['guardian_id'])->exists();
        abort_unless($own, 404);
        Claims::withdraw($claim, $ctx['guardian_id'], (int) $d['version']);
        Access::event('guardian.fee_claim.withdrawn', $ctx['guardian_id'], $id, ['claim_id' => $claim], $r);
        return response()->json(Fees::overview($ctx['guardian_id'], $id))->header('Cache-Control', 'no-store, private');
    }

    public function slip(Request $r, int $id, int $claim, int $slip)
    {
        $ctx = $this->child($r, $id);
        $s = DB::table('fin_claim_slips as s')->join('fin_payment_claims as c', 'c.id', '=', 's.claim_id')->where('s.school_id', Access::school())->where('c.school_id', Access::school())
            ->where('c.id', $claim)->where('c.student_id', $id)->where('c.guardian_id', $ctx['guardian_id'])->where('s.id', $slip)->select('s.*')->first();
        abort_unless($s, 404);
        return Slips::respond($s->path, $s->mime, 'slip-'.$s->id.'.'.(Slips::IMAGE_TYPES[$s->mime] ?? 'pdf'));
    }

    public function readNotices(Request $r, int $id): JsonResponse
    {
        $ctx = $this->child($r, $id);
        Notices::markRead($ctx['guardian_id'], $id);
        return response()->json(['ok' => true]);
    }
}
