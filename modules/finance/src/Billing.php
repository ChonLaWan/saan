<?php

namespace Saan\Modules\Finance;

use App\Support\Saan\Mutation;
use App\Support\Saan\TermScope;
use App\Support\Saan\VersionConflict;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Invoice, payment and void rules. All amounts are integer satang; every number is issued inside the transaction. */
final class Billing
{
    public const METHODS = ['cash', 'transfer', 'promptpay', 'other'];
    public const BULK_LIMIT = 1000;
    public const METHOD_LABELS = ['cash' => 'เงินสด', 'transfer' => 'โอนเงิน', 'promptpay' => 'พร้อมเพย์', 'other' => 'อื่น ๆ'];
    public const STATUS_LABELS = ['open' => 'ค้างชำระ', 'partial' => 'ชำระบางส่วน', 'paid' => 'ชำระครบ', 'void' => 'ยกเลิก'];

    public static function termId(?int $given): int
    {
        if ($given !== null) {
            if (!DB::table('academic_terms')->where('school_id', Support::school())->where('id', $given)->exists()) abort(422, 'ไม่พบภาคเรียนที่ระบุ');
            return $given;
        }
        try {
            $id = TermScope::currentId();
        } catch (DomainException) {
            $id = null;
        }
        return $id ?? abort(422, 'ยังไม่ได้ตั้งภาคเรียน');
    }

    /** @return array<int,array{fee_item_id:?int,label:string,amount_satang:int,discount_satang:int,account_ids:?string}> */
    public static function lines(array $raw): array
    {
        $ids = array_values(array_filter(array_map(fn ($l) => $l['fee_item_id'] ?? null, $raw)));
        $feeRows = $ids ? DB::table('fin_fee_items')->where('school_id', Support::school())->whereIn('id', $ids)->get(['id', 'label', 'account_ids'])->keyBy('id') : collect();
        $items = $feeRows->pluck('label', 'id')->all();
        $out = [];
        foreach ($raw as $l) {
            $fid = isset($l['fee_item_id']) ? (int) $l['fee_item_id'] : null;
            if ($fid !== null && !isset($items[$fid])) abort(422, 'ไม่พบรายการค่าธรรมเนียมที่ระบุ');
            $label = trim((string) ($l['label'] ?? '')) ?: ($items[$fid] ?? '');
            if ($label === '') abort(422, 'รายการต้องมีชื่อ');
            $amount = (int) $l['amount_satang'];
            $discount = (int) ($l['discount_satang'] ?? 0);
            if ($amount < 0 || $discount < 0 || $discount > $amount) abort(422, 'จำนวนเงินหรือส่วนลดไม่ถูกต้อง');
            // account choice: the line's own list, else the fee item's list, else NULL = every active account
            $accounts = array_key_exists('account_ids', $l) ? Accounts::checkIds($l['account_ids']) : ($fid !== null && $feeRows[$fid]->account_ids ? (json_decode((string) $feeRows[$fid]->account_ids, true) ?: null) : null);
            $out[] = ['fee_item_id' => $fid, 'label' => mb_substr($label, 0, 200), 'amount_satang' => $amount, 'discount_satang' => $discount, 'account_ids' => $accounts ? json_encode(array_values($accounts)) : null];
        }
        return $out;
    }

    public static function total(array $lines): int
    {
        return array_sum(array_map(fn ($l) => $l['amount_satang'] - $l['discount_satang'], $lines));
    }

    public static function createInvoice(array $d): object
    {
        $student = DB::table('students')->where('school_id', Support::school())->where('id', (int) $d['student_id'])->exists();
        abort_unless($student, 404, 'ไม่พบนักเรียน');
        $term = self::termId(isset($d['term_id']) ? (int) $d['term_id'] : null);
        $lines = self::lines($d['lines']);
        if (self::total($lines) <= 0) abort(422, 'ยอดรวมต้องมากกว่าศูนย์');
        Support::ensureCounter('invoice', $d['issued_on']);
        return Support::tx(function () use ($d, $term, $lines) {
            Support::assertOpen($d['issued_on']);
            return self::insertInvoice((int) $d['student_id'], $term, $d['issued_on'], $d['due_on'] ?? null, $lines);
        });
    }

    private static function insertInvoice(int $student, int $term, string $issued, ?string $due, array $lines): object
    {
        $total = self::total($lines);
        $no = Support::nextNumber('invoice', $issued);
        $inv = Mutation::insert('fin_invoices', [
            'term_id' => $term, 'student_id' => $student, 'invoice_no' => $no, 'issued_on' => $issued, 'due_on' => $due,
            'status' => 'open', 'total_satang' => $total, 'paid_satang' => 0,
        ], 'finance.invoice_created', ['invoice_no' => $no, 'student_id' => $student, 'total_satang' => $total]);
        DB::table('fin_invoice_lines')->insert(array_map(fn ($l) => $l + ['invoice_id' => $inv->id], $lines));
        return $inv;
    }

    public static function pay(int $invoiceId, array $d, object $user): array
    {
        Support::ensureCounter('receipt', $d['paid_on']);
        return Support::tx(fn () => self::payInTx($invoiceId, $d, $user));
    }

    /**
     * The payment itself, for a caller that already holds a transaction (Claims::approve). It never creates the counter row: the caller ran
     * Support::ensureCounter before opening its transaction, because INSERT IGNORE inside one takes a shared lock and two approvals can deadlock.
     */
    public static function payInTx(int $invoiceId, array $d, object $user): array
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('payInTx ต้องเรียกภายใน transaction');
        $inv = DB::table('fin_invoices')->where('school_id', Support::school())->where('id', $invoiceId)->lockForUpdate()->first();
        abort_unless($inv, 404, 'ไม่พบใบแจ้งหนี้');
        if (isset($d['invoice_version']) && (int) $d['invoice_version'] !== (int) $inv->version) throw new VersionConflict((array) $inv);
        if ($inv->status === 'void') abort(422, 'ใบแจ้งหนี้นี้ถูกยกเลิกแล้ว');
        if ($inv->status === 'paid') abort(422, 'ใบแจ้งหนี้นี้ชำระครบแล้ว');
        if ($d['paid_on'] < (string) $inv->issued_on) abort(422, 'วันที่รับเงินต้องไม่ก่อนวันที่ออกใบแจ้งหนี้');
        Support::assertOpen($d['paid_on']);
        $amount =(int) $d['amount_satang'];
        if ($amount > (int) $inv->total_satang - (int) $inv->paid_satang) abort(422, 'จำนวนเงินเกินยอดค้างชำระ');
        $no = Support::nextNumber('receipt', $d['paid_on']);
        $pay = Mutation::insert('fin_payments', [
            'invoice_id' => $inv->id, 'receipt_no' => $no, 'paid_on' => $d['paid_on'], 'method' => $d['method'], 'amount_satang' => $amount,
            'reference' => $d['reference'] ?? null, 'received_by' => $user->id, 'received_by_name' => mb_substr((string) $user->name, 0, 160), 'status' => 'valid',
        ] + Support::receiptSnapshot((int) $inv->student_id), 'finance.payment_received', ['receipt_no' => $no, 'invoice_no' => $inv->invoice_no, 'amount_satang' => $amount, 'method' => $d['method']]);
        $paid = (int) $inv->paid_satang + $amount;
        $invoice = Mutation::update('fin_invoices', $inv->id, (int) $inv->version, ['paid_satang' => $paid, 'status' => self::status((int) $inv->total_satang, $paid)], 'finance.invoice_paid', ['receipt_no' => $no]);
        Mutation::insert('fin_ledger', [
            'entry_date' => $d['paid_on'], 'kind' => 'income', 'category' => 'ค่าธรรมเนียมนักเรียน', 'amount_satang' => $amount,
            'description' => mb_substr('รับชำระ '.$inv->invoice_no, 0, 300), 'document_no' => $no, 'payment_id' => $pay->id, 'created_by' => $user->id,
        ], 'finance.ledger_auto', ['receipt_no' => $no]);
        return [$pay, $invoice];
    }

    public static function voidPayment(int $id, int $version, string $reason, object $user): array
    {
        $voided = null;
        $out = Support::tx(function () use ($id, $version, $reason, $user, &$voided) {
            $voided = null; // a retried transaction starts clean
            $p = DB::table('fin_payments')->where('school_id', Support::school())->where('id', $id)->lockForUpdate()->first();
            abort_unless($p, 404, 'ไม่พบใบเสร็จ');
            if ($p->status === 'void') abort(422, 'ใบเสร็จนี้ถูกยกเลิกแล้ว');
            Support::assertOpen($p->paid_on);
            Support::assertOpen(Support::today());
            $pay = Mutation::update('fin_payments', $id, $version, ['status' => 'void', 'void_reason' => $reason, 'voided_by' => $user->id, 'voided_at' => now()], 'finance.payment_voided', ['receipt_no' => $p->receipt_no, 'reason' => $reason]);
            $inv = DB::table('fin_invoices')->where('school_id', Support::school())->where('id', $p->invoice_id)->lockForUpdate()->first();
            $paid = max(0, (int) $inv->paid_satang - (int) $p->amount_satang);
            $invoice = Mutation::update('fin_invoices', $inv->id, (int) $inv->version, ['paid_satang' => $paid, 'status' => self::status((int) $inv->total_satang, $paid)], 'finance.invoice_unpaid', ['receipt_no' => $p->receipt_no]);
            Mutation::insert('fin_ledger', [
                'entry_date' => Support::today(), 'kind' => 'reversal', 'category' => 'ยกเลิกใบเสร็จ', /* reversal nets the income; it is not an expense */ 'amount_satang' => (int) $p->amount_satang,
                'description' => mb_substr('ยกเลิกใบเสร็จ '.$p->receipt_no.' : '.$reason, 0, 300), 'document_no' => $p->receipt_no, 'payment_id' => $p->id, 'created_by' => $user->id,
            ], 'finance.ledger_auto', ['receipt_no' => $p->receipt_no, 'reversal' => true]);
            $voided = Claims::onPaymentVoided($id); // the approved claim behind this receipt must not keep saying 'approved'
            return [$pay, $invoice, $p->receipt_no];
        });
        if ($voided) Claims::notifyVoided($voided, $out[2]);
        return [$out[0], $out[1]];
    }

    public static function voidInvoice(int $id, int $version, string $reason, object $user): object
    {
        $rejected = [];
        $out = Support::tx(function () use ($id, $version, $reason, $user, &$rejected) {
            $rejected = [];
            $inv = DB::table('fin_invoices')->where('school_id', Support::school())->where('id', $id)->lockForUpdate()->first();
            abort_unless($inv, 404, 'ไม่พบใบแจ้งหนี้');
            if ($inv->status === 'void') abort(422, 'ใบแจ้งหนี้นี้ถูกยกเลิกแล้ว');
            if ((int) $inv->paid_satang > 0) abort(422, 'ต้องยกเลิกใบเสร็จของใบแจ้งหนี้นี้ก่อน');
            Support::assertOpen($inv->issued_on);
            $void = Mutation::update('fin_invoices', $id, $version, ['status' => 'void', 'void_reason' => $reason, 'voided_by' => $user->id], 'finance.invoice_voided', ['invoice_no' => $inv->invoice_no, 'reason' => $reason]);
            // a claim still waiting on this invoice would keep its slip hash locked and vanish from the guardian page: reject it with the reason, free the hash
            foreach (DB::table('fin_payment_claims')->where('school_id', Support::school())->where('invoice_id', $id)->where('status', 'pending')->lockForUpdate()->get() as $c) {
                DB::table('fin_claim_slips')->where('school_id', Support::school())->where('claim_id', $c->id)->update(['dup_key' => null, 'updated_at' => now()]);
                $rejected[] = Mutation::update('fin_payment_claims', (int) $c->id, (int) $c->version, ['status' => 'rejected', 'reject_reason' => 'ใบแจ้งหนี้ถูกยกเลิก: '.$reason, 'decided_by' => $user->id, 'decided_at' => now()],
                    'finance.claim_rejected', ['claim_id' => (int) $c->id, 'reason' => 'invoice_voided']);
            }
            return $void;
        });
        foreach ($rejected as $c) Claims::notify($c, 'ไม่รับการแจ้งชำระ', 'ใบแจ้งหนี้ถูกยกเลิก: '.$reason.' หากโอนเงินแล้วกรุณาติดต่อโรงเรียน หรือแจ้งชำระใหม่กับใบแจ้งหนี้ใบใหม่');
        return $out;
    }

    public static function status(int $total, int $paid): string
    {
        return $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'open');
    }

    /**
     * Bulk invoice plan for a classroom or a grade level. Students that already hold a non-void invoice of this term
     * with any of the chosen fee items are skipped, so running it twice never double-bills.
     */
    public static function plan(array $d): array
    {
        $school = Support::school();
        $term = self::termId(isset($d['term_id']) ? (int) $d['term_id'] : null);
        $rooms = DB::table('classrooms')->where('school_id', $school)->when(Schema::hasColumn('classrooms', 'is_active'), fn ($q) => $q->where('is_active', true));
        if (!empty($d['classroom_id'])) $rooms->where('id', (int) $d['classroom_id']);
        elseif (!empty($d['grade_level']) && Schema::hasColumn('classrooms', 'grade_level')) $rooms->where('grade_level', (string) $d['grade_level']);
        else abort(422, 'ต้องระบุห้องเรียนหรือระดับชั้น');
        $rooms = $rooms->get(['id', 'name'] + (Schema::hasColumn('classrooms', 'grade_level') ? [2 => 'grade_level'] : []))->keyBy('id');
        if ($rooms->isEmpty()) abort(422, 'ไม่พบห้องเรียนที่ระบุ');

        $chosen = collect($d['items'])->keyBy(fn ($i) => (int) $i['fee_item_id']);
        $fees = DB::table('fin_fee_items')->where('school_id', $school)->where('is_active', true)->whereIn('id', $chosen->keys()->all())
            ->where(fn ($q) => $q->whereNull('term_id')->orWhere('term_id', $term))->get()->keyBy('id');
        if ($fees->count() !== $chosen->count()) abort(422, 'รายการค่าธรรมเนียมบางรายการไม่พร้อมใช้งานในภาคเรียนนี้');

        $students = DB::table('students')->where('school_id', $school)->whereIn('classroom_id', $rooms->keys()->all())
            ->when(Schema::hasColumn('students', 'status'), fn ($q) => $q->where('status', 'studying'))
            ->orderBy('code')->limit(self::BULK_LIMIT + 1)->get(['id', 'code', 'name', 'classroom_id']);
        if ($students->count() > self::BULK_LIMIT) abort(422, 'นักเรียนเกิน '.self::BULK_LIMIT.' คนต่อครั้ง กรุณาเลือกเป็นห้อง');

        // student_id => [fee_item_id => true] already billed in this term on a non-void invoice
        $billed = [];
        if (!$students->isEmpty()) {
            $pairs = DB::table('fin_invoices as i')->join('fin_invoice_lines as l', 'l.invoice_id', '=', 'i.id')
                ->where('i.school_id', $school)->where('i.term_id', $term)->where('i.status', '!=', 'void')
                ->whereIn('i.student_id', $students->pluck('id')->all())->whereIn('l.fee_item_id', $fees->keys()->all())
                ->distinct()->get(['i.student_id', 'l.fee_item_id']);
            foreach ($pairs as $p) $billed[(int) $p->student_id][(int) $p->fee_item_id] = true;
        }

        $rows = [];
        foreach ($students as $s) {
            $grade = $rooms[$s->classroom_id]->grade_level ?? null;
            $lines = [];
            $dups = 0;
            foreach ($fees as $fee) {
                $levels = $fee->grade_levels ? (json_decode($fee->grade_levels, true) ?: []) : [];
                if ($levels && !in_array($grade, $levels, true)) continue;
                if (isset($billed[$s->id][$fee->id])) { $dups++; continue; }
                $amount = (int) ($chosen[$fee->id]['amount_satang'] ?? $fee->default_amount_satang);
                $lines[] = ['fee_item_id' => (int) $fee->id, 'label' => $fee->label, 'amount_satang' => $amount, 'discount_satang' => 0, 'account_ids' => $fee->account_ids ?: null];
            }
            $total = self::total($lines);
            $skip = $lines === [] ? ($dups > 0 ? 'duplicate' : 'no_fee') : ($total <= 0 ? 'no_fee' : null);
            $rows[] = ['student_id' => (int) $s->id, 'code' => $s->code, 'name' => $s->name, 'classroom' => $rooms[$s->classroom_id]->name ?? '', 'lines' => $lines, 'total_satang' => $total, 'skip' => $skip];
        }
        $create = array_filter($rows, fn ($r) => $r['skip'] === null);
        return ['term_id' => $term, 'rows' => $rows, 'create_count' => count($create), 'skip_count' => count($rows) - count($create), 'total_satang' => array_sum(array_column($create, 'total_satang'))];
    }

    public static function bulk(array $d): array
    {
        Support::ensureCounter('bulk');
        Support::ensureCounter('invoice', $d['issued_on']);
        return Support::tx(function () use ($d) {
            Support::lock('bulk');
            Support::assertOpen($d['issued_on']);
            $plan = self::plan($d);
            if ($plan['create_count'] !== (int) $d['expected_count']) throw new VersionConflict(['create_count' => $plan['create_count'], 'total_satang' => $plan['total_satang']], 'ข้อมูลเปลี่ยนไปหลังดูตัวอย่าง กรุณาดูตัวอย่างใหม่');
            $ids = [];
            foreach ($plan['rows'] as $r) {
                if ($r['skip'] !== null) continue;
                $no = Support::nextNumber('invoice', $d['issued_on']);
                $id = (int) DB::table('fin_invoices')->insertGetId([
                    'school_id' => Support::school(), 'term_id' => $plan['term_id'], 'student_id' => $r['student_id'], 'invoice_no' => $no, 'issued_on' => $d['issued_on'],
                    'due_on' => $d['due_on'] ?? null, 'status' => 'open', 'total_satang' => $r['total_satang'], 'paid_satang' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('fin_invoice_lines')->insert(array_map(fn ($l) => $l + ['invoice_id' => $id], $r['lines']));
                $ids[] = $id;
            }
            Mutation::audit('finance.invoices_generated', 'fin_invoices', null, ['count' => count($ids), 'first_id' => $ids[0] ?? null, 'last_id' => $ids ? end($ids) : null, 'term_id' => $plan['term_id'], 'total_satang' => $plan['total_satang']]);
            return ['created' => count($ids), 'skipped' => $plan['skip_count'], 'total_satang' => $plan['total_satang'], 'invoice_ids' => $ids];
        });
    }
}
