<?php

namespace Saan\Modules\Finance\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Saan\Modules\Finance\Baht;
use Saan\Modules\Finance\Billing;
use Saan\Modules\Finance\Support;

/** Daily cash summary, cash book and CSV exports. */
class ReportController
{
    private function daily(string $date): array
    {
        $rows = DB::table('fin_payments as p')->join('fin_invoices as i', 'i.id', '=', 'p.invoice_id')->join('students as s', 's.id', '=', 'i.student_id')
            ->where('p.school_id', Support::school())->where('p.paid_on', $date)->orderBy('p.receipt_no')->limit(5000)
            ->get(['p.id', 'p.receipt_no', 'p.status', 'p.method', 'p.amount_satang', 'p.reference', 'p.received_by_name', 'p.void_reason', 'p.version', 'i.invoice_no', 's.code as student_code', 's.name as student_name']);
        $methods = array_fill_keys(Billing::METHODS, ['count' => 0, 'total_satang' => 0]);
        $void = ['count' => 0, 'total_satang' => 0];
        foreach ($rows as $p) {
            if ($p->status === 'valid') { $methods[$p->method]['count']++; $methods[$p->method]['total_satang'] += (int) $p->amount_satang; }
            else { $void['count']++; $void['total_satang'] += (int) $p->amount_satang; }
        }
        return ['date' => $date, 'methods' => $methods, 'voided' => $void, 'total_satang' => array_sum(array_column($methods, 'total_satang')), 'payments' => $rows, 'closed' => Support::isClosed($date)];
    }

    public function dailySummary(Request $r)
    {
        $d = $r->validate(['date' => 'nullable|date_format:Y-m-d']);
        return response()->json($this->daily($d['date'] ?? Support::today()))->header('Cache-Control', 'no-store, private');
    }

    public function dailyCsv(Request $r)
    {
        $d = $r->validate(['date' => 'nullable|date_format:Y-m-d']);
        $s = $this->daily($d['date'] ?? Support::today());
        $rows = $s['payments']->map(fn ($p) => [$p->receipt_no, $p->status === 'valid' ? 'ใช้งาน' : 'ยกเลิก', Billing::METHOD_LABELS[$p->method] ?? $p->method, $p->invoice_no, $p->student_code, $p->student_name, Baht::plain($p->amount_satang), $p->reference, $p->received_by_name]);
        return BillingController::csvResponse('daily-'.$s['date'].'.csv', ['เลขที่ใบเสร็จ', 'สถานะ', 'วิธีชำระ', 'ใบแจ้งหนี้', 'รหัสนักเรียน', 'ชื่อนักเรียน', 'จำนวนเงิน', 'อ้างอิง', 'ผู้รับเงิน'], $rows);
    }

        private const LEDGER_VIEW_LIMIT = 5000;

    private function ledgerQuery(array $f, string $from, string $to)
    {
        return DB::table('fin_ledger')->where('school_id', Support::school())->whereBetween('entry_date', [$from, $to])
            ->when(!empty($f['kind']), fn ($q) => $q->whereIn('kind', $f['kind'] === 'income' ? ['income', 'reversal'] : [$f['kind']]));
    }

    private function ledger(array $f, bool $withRows = true): array
    {
        $school = Support::school();
        $from = $f['from'] ?? substr(Support::today(), 0, 8).'01';
        $to = $f['to'] ?? Support::today();
        $sum = fn ($q) => (int) $q->selectRaw("COALESCE(SUM(CASE WHEN kind = 'income' THEN amount_satang ELSE -amount_satang END), 0) as n")->value('n');
        $opening = $sum(DB::table('fin_ledger')->where('school_id', $school)->where('entry_date', '<', $from));
        // totals are computed in SQL over the whole range, never from the (capped) row list
        $by = $this->ledgerQuery($f, $from, $to)->selectRaw('kind, COALESCE(SUM(amount_satang), 0) as n, COUNT(*) as c')->groupBy('kind')->get()->keyBy('kind');
        $reversal = (int) ($by['reversal']->n ?? 0);
        // a voided receipt is posted as kind 'reversal' (negative income), so income_satang is net income and expense_satang holds real expenses only
        $income = (int) ($by['income']->n ?? 0) - $reversal;
        $expense = (int) ($by['expense']->n ?? 0);
        $count = (int) $by->sum('c');
        $out = ['from' => $from, 'to' => $to, 'opening_satang' => $opening, 'income_satang' => $income, 'reversal_satang' => $reversal, 'expense_satang' => $expense, 'closing_satang' => empty($f['kind']) ? $opening + $income - $expense : null,
            'total_rows' => $count, 'truncated' => $count > self::LEDGER_VIEW_LIMIT];
        if ($withRows) $out['data'] = $this->ledgerQuery($f, $from, $to)->orderBy('entry_date')->orderBy('id')->limit(self::LEDGER_VIEW_LIMIT)->get();
        return $out;
    }

private const RANGE = ['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from'];

    public function ledgerList(Request $r)
    {
        return response()->json($this->ledger($r->validate(self::RANGE + ['kind' => ['nullable', Rule::in(['income', 'expense'])]])))->header('Cache-Control', 'no-store, private');
    }

        public function ledgerCsv(Request $r)
    {
        $f = $r->validate(self::RANGE);
        $l = $this->ledger($f, false);
        $q = $this->ledgerQuery($f, $l['from'], $l['to']);
        $map = fn ($e) => [$e->entry_date, ['income' => 'รายรับ', 'reversal' => 'ยกเลิกรายรับ'][$e->kind] ?? 'รายจ่าย', $e->category, $e->description, $e->document_no,
            Baht::plain($e->kind === 'income' ? (int) $e->amount_satang : ($e->kind === 'reversal' ? -(int) $e->amount_satang : 0)), Baht::plain($e->kind === 'expense' ? (int) $e->amount_satang : 0)];
        return BillingController::csvStream('cashbook-'.$l['from'].'_'.$l['to'].'.csv', ['วันที่', 'ประเภท', 'หมวด', 'รายการ', 'เลขที่เอกสาร', 'รับ', 'จ่าย'], function () use ($q, $map) {
            foreach ($q->orderBy('id')->lazyById(1000, 'id') as $e) yield $map($e);
        });
    }
public function ledgerStore(Request $r)
    {
        $d = $r->validate(['entry_date' => 'required|date_format:Y-m-d|before_or_equal:'.Support::today(), 'kind' => ['required', Rule::in(['income', 'expense'])], 'category' => 'required|string|max:120',
            'amount_satang' => 'required|integer|min:1|max:100000000000', 'description' => 'required|string|max:300', 'document_no' => 'nullable|string|max:60']);
        return Idempotency::run($r, function () use ($d, $r) {
            $row = Support::tx(function () use ($d, $r) {
                Support::assertOpen($d['entry_date']);
                return Mutation::insert('fin_ledger', ['entry_date' => $d['entry_date'], 'kind' => $d['kind'], 'category' => trim($d['category']), 'amount_satang' => $d['amount_satang'],
                    'description' => trim($d['description']), 'document_no' => $d['document_no'] ?? null, 'payment_id' => null, 'created_by' => $r->user()->id], 'finance.ledger_created', ['kind' => $d['kind'], 'amount_satang' => $d['amount_satang']]);
            });
            return response()->json($row, 201);
        });
    }

    public function ledgerDelete(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        DB::transaction(function () use ($id, $d) {
            $e = DB::table('fin_ledger')->where('school_id', Support::school())->where('id', $id)->lockForUpdate()->first();
            abort_unless($e, 404, 'ไม่พบรายการ');
            if ($e->payment_id !== null) abort(422, 'รายการที่มาจากใบเสร็จลบไม่ได้ ให้ยกเลิกใบเสร็จแทน');
            Support::assertOpen($e->entry_date);
            Mutation::delete('fin_ledger', $id, (int) $d['version'], 'finance.ledger_deleted');
        });
        return response()->json(['ok' => true]);
    }
}

