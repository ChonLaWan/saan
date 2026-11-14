<?php

namespace Saan\Modules\GuardianPortal;

use Illuminate\Support\Facades\DB;
use Saan\Modules\Finance\Accounts;
use Saan\Modules\Finance\Billing;

/**
 * Fee read model for the guardian pages. The CALLER has already checked that the student is one of the signed-in guardian's viewable children.
 * Hand-picked fields only: no student code, no staff names, no other guardian's claims, no slip file names or hashes.
 * Account numbers are included in full on purpose (the guardian must transfer to them) and only for invoices that can still be paid.
 * The number of queries is constant (invoices, lines, payments, claims, accounts) however many invoices the child has.
 */
final class Fees
{
    public const INVOICE_LIMIT = 60;

    public static function available(): bool
    {
        return GuardianData::moduleData('finance', 'fin_invoices', 'fin_payments', 'fin_payment_accounts', 'fin_payment_claims', 'fin_claim_slips');
    }

    public static function overview(int $guardianId, int $studentId): array
    {
        if (!self::available()) return ['available' => false, 'balance_satang' => 0, 'invoices' => [], 'notices' => []];
        $school = Access::school();
        $balance = (int) DB::table('fin_invoices')->where('school_id', $school)->where('student_id', $studentId)->whereIn('status', ['open', 'partial'])->sum(DB::raw('total_satang - paid_satang'));
        $inv = DB::table('fin_invoices')->where('school_id', $school)->where('student_id', $studentId)->whereIn('status', ['open', 'partial', 'paid'])
            ->orderByDesc('issued_on')->orderByDesc('id')->limit(self::INVOICE_LIMIT)->get();
        $ids = $inv->pluck('id')->map(fn ($v) => (int) $v)->all();
        $lines = $ids ? DB::table('fin_invoice_lines')->whereIn('invoice_id', $ids)->orderBy('id')->get(['invoice_id', 'label', 'amount_satang', 'discount_satang'])->groupBy('invoice_id') : collect();
        $pays = $ids ? DB::table('fin_payments')->where('school_id', $school)->where('status', 'valid')->whereIn('invoice_id', $ids)->orderBy('id')->get(['id', 'invoice_id', 'receipt_no', 'paid_on', 'amount_satang'])->groupBy('invoice_id') : collect();
        $claims = $ids ? DB::table('fin_payment_claims')->where('school_id', $school)->where('guardian_id', $guardianId)->whereIn('invoice_id', $ids)->orderByDesc('id')->limit(200)
            ->get(['id', 'invoice_id', 'status', 'amount_satang', 'approved_satang', 'transferred_at', 'created_at', 'reject_reason', 'version'])->groupBy('invoice_id') : collect();
        $claimIds = $claims->flatten(1)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $slips = $claimIds ? DB::table('fin_claim_slips')->where('school_id', $school)->whereIn('claim_id', $claimIds)->orderBy('id')->get(['id', 'claim_id', 'mime'])->groupBy('claim_id') : collect();
        $payable = $inv->filter(fn ($i) => in_array($i->status, ['open', 'partial'], true))->pluck('id')->map(fn ($v) => (int) $v)->all();
        $accounts = Accounts::forInvoices($payable);
        $out = $inv->map(function ($i) use ($lines, $pays, $claims, $slips, $accounts, $studentId) {
            $open = in_array($i->status, ['open', 'partial'], true);
            return [
                'id' => (int) $i->id, 'invoice_no' => $i->invoice_no, 'issued_on' => (string) $i->issued_on, 'due_on' => $i->due_on ? (string) $i->due_on : null,
                'status' => $i->status, 'status_label' => Billing::STATUS_LABELS[$i->status] ?? $i->status,
                'total_satang' => (int) $i->total_satang, 'paid_satang' => (int) $i->paid_satang, 'outstanding_satang' => $open ? (int) $i->total_satang - (int) $i->paid_satang : 0,
                'reference' => $i->invoice_no, // goes in the transfer note
                'lines' => collect($lines[$i->id] ?? [])->map(fn ($l) => ['label' => $l->label, 'amount_satang' => (int) $l->amount_satang, 'discount_satang' => (int) $l->discount_satang])->all(),
                'accounts' => $open ? array_map(fn ($a) => Accounts::forGuardian($a) + ['qr_url' => $a->qr_path !== null ? url('/guardian/api/children/'.$studentId.'/invoices/'.$i->id.'/accounts/'.$a->id.'/qr') : null], $accounts[(int) $i->id] ?? []) : [],
                'receipts' => collect($pays[$i->id] ?? [])->map(fn ($p) => ['id' => (int) $p->id, 'receipt_no' => $p->receipt_no, 'paid_on' => (string) $p->paid_on, 'amount_satang' => (int) $p->amount_satang,
                    'url' => url('/guardian/api/children/'.$studentId.'/receipts/'.$p->id)])->all(),
                'claims' => collect($claims[$i->id] ?? [])->map(fn ($c) => ['id' => (int) $c->id, 'status' => $c->status, 'amount_satang' => (int) $c->amount_satang,
                    'approved_satang' => $c->approved_satang === null ? null : (int) $c->approved_satang, 'transferred_at' => (string) $c->transferred_at, 'created_at' => (string) $c->created_at,
                    'reject_reason' => $c->status === 'rejected' ? $c->reject_reason : null, 'slips' => collect($slips[$c->id] ?? [])->map(fn ($s) => ['id' => (int) $s->id, 'mime' => $s->mime, 'url' => url('/guardian/api/children/'.$studentId.'/claims/'.$c->id.'/slips/'.$s->id)])->all(), 'version' => (int) $c->version, 'can_withdraw' => $c->status === 'pending'])->all(),
            ];
        })->all();
        return ['available' => true, 'balance_satang' => $balance, 'invoices' => $out, 'notices' => Notices::forChild($guardianId, $studentId)];
    }
}
