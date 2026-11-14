<?php

namespace Saan\Modules\Finance\Http;

use App\Support\Saan\Idempotency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Saan\Modules\Finance\Accounts;
use Saan\Modules\Finance\Baht;
use Saan\Modules\Finance\Claims;
use Saan\Modules\Finance\Slips;
use Saan\Modules\Finance\Support;

/** Finance verification queue: list, open slips, approve, reject, and attaching a slip for a guardian who paid at the counter. */
class ClaimController
{
    /** "2026-11-08T14:30" or "2026-11-08 14:30" (browser datetime-local) -> "2026-11-08 14:30:00"; anything else -> null. */
    public static function when(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})$/D', trim($v), $m)) return null;
        $t = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $m[1].' '.$m[2]);
        return $t && $t->format('Y-m-d H:i') === $m[1].' '.$m[2] ? $t->format('Y-m-d H:i:00') : null;
    }

    private function row(object $c, array $dups = []): array
    {
        return ['id' => (int) $c->id, 'status' => $c->status, 'source' => $c->source, 'amount_satang' => (int) $c->amount_satang, 'approved_satang' => $c->approved_satang === null ? null : (int) $c->approved_satang,
            'transferred_at' => (string) $c->transferred_at, 'created_at' => (string) $c->created_at, 'decided_at' => $c->decided_at ? (string) $c->decided_at : null, 'reject_reason' => $c->reject_reason,
            'payment_id' => $c->payment_id === null ? null : (int) $c->payment_id, 'version' => (int) $c->version, 'invoice_id' => (int) $c->invoice_id, 'invoice_no' => $c->invoice_no ?? null,
            'invoice_total_satang' => isset($c->invoice_total) ? (int) $c->invoice_total : null, 'invoice_paid_satang' => isset($c->invoice_paid) ? (int) $c->invoice_paid : null,
            'student_id' => (int) $c->student_id, 'student_code' => $c->student_code ?? null, 'student_name' => $c->student_name ?? null, 'classroom' => $c->classroom ?? null,
            'guardian_name' => $c->guardian_name ?? null, 'account_label' => $c->account_label ?? null, 'slip_count' => (int) ($c->slip_count ?? 0), 'possible_duplicates' => $dups];
    }

    public function index(Request $r)
    {
        $f = $r->validate(['status' => ['nullable', Rule::in(array_merge(Claims::STATUSES, ['all']))], 'classroom_id' => 'nullable|integer|min:1', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'page' => 'nullable|integer|min:1|max:10000', 'per_page' => 'nullable|integer|min:1|max:100']);
        $status = $f['status'] ?? 'pending';
        $school = Support::school();
        $q = DB::table('fin_payment_claims as c')->join('fin_invoices as i', 'i.id', '=', 'c.invoice_id')->join('students as s', 's.id', '=', 'c.student_id')
            ->leftJoin('classrooms as r', 'r.id', '=', 's.classroom_id')->leftJoin('fin_payment_accounts as a', 'a.id', '=', 'c.account_id')
            ->where('c.school_id', $school);
        if (Schema::hasTable('reg_guardians')) $q->leftJoin('reg_guardians as g', 'g.id', '=', 'c.guardian_id');
        if ($status !== 'all') $q->where('c.status', $status);
        if (!empty($f['classroom_id'])) $q->where('s.classroom_id', (int) $f['classroom_id']);
        if (!empty($f['from'])) $q->where('c.created_at', '>=', $f['from'].' 00:00:00');
        if (!empty($f['to'])) $q->where('c.created_at', '<', date('Y-m-d', strtotime($f['to'].' +1 day')).' 00:00:00');
        $per = (int) ($f['per_page'] ?? 50);
        $page = (int) ($f['page'] ?? 1);
        $total = (clone $q)->count();
        $cols = ['c.*', 'i.invoice_no', 'i.total_satang as invoice_total', 'i.paid_satang as invoice_paid', 's.code as student_code', 's.name as student_name', 'r.name as classroom',
            'a.bank_name as account_bank', 'a.account_name as account_name'];
        if (Schema::hasTable('reg_guardians')) $cols[] = 'g.display_name as guardian_name';
        $rows = $q->select($cols)->selectSub(DB::table('fin_claim_slips')->selectRaw('count(*)')->whereColumn('fin_claim_slips.claim_id', 'c.id'), 'slip_count')
            ->orderBy('c.id', $status === 'pending' ? 'asc' : 'desc')->offset(($page - 1) * $per)->limit($per)->get();
        $dups = Claims::duplicatesFor($rows);
        $data = $rows->map(function ($c) use ($dups) {
            $c->account_label = $c->account_bank ? trim($c->account_bank.' '.$c->account_name) : null;
            return $this->row($c, $dups[(int) $c->id] ?? []);
        })->all();
        return response()->json(['data' => $data, 'total' => $total, 'page' => $page, 'per_page' => $per])->header('Cache-Control', 'no-store, private');
    }

    public function show(int $id)
    {
        $c = DB::table('fin_payment_claims')->where('school_id', Support::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบรายการแจ้งชำระ');
        $inv = DB::table('fin_invoices as i')->join('students as s', 's.id', '=', 'i.student_id')->where('i.school_id', Support::school())->where('i.id', $c->invoice_id)
            ->first(['i.id', 'i.invoice_no', 'i.status', 'i.total_satang', 'i.paid_satang', 'i.version', 's.code as student_code', 's.name as student_name']);
        $slips = DB::table('fin_claim_slips')->where('school_id', Support::school())->where('claim_id', $id)->orderBy('id')->get(['id', 'mime', 'size'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'mime' => $s->mime, 'size' => (int) $s->size, 'url' => url('/api/finance/claims/'.$id.'/slips/'.$s->id)])->all();
        $acc = $c->account_id ? DB::table('fin_payment_accounts')->where('school_id', Support::school())->where('id', $c->account_id)->first(['bank_name', 'account_name']) : null;
        $c->account_label = $acc ? trim($acc->bank_name.' '.$acc->account_name) : null;
        $c->slip_count = count($slips);
        return response()->json(['claim' => $this->row($c, Claims::duplicatesFor([$c])[(int) $c->id] ?? []), 'invoice' => $inv, 'slips' => $slips, 'outstanding_satang' => max(0, (int) $inv->total_satang - (int) $inv->paid_satang)])->header('Cache-Control', 'no-store, private');
    }

    public function slip(int $id, int $slip)
    {
        $s = DB::table('fin_claim_slips')->where('school_id', Support::school())->where('claim_id', $id)->where('id', $slip)->first() ?? abort(404);
        return Slips::respond($s->path, $s->mime, 'slip-'.$s->id.'.'.(Slips::IMAGE_TYPES[$s->mime] ?? 'pdf'));
    }

    public function approve(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'amount_satang' => 'nullable|integer|min:1|max:100000000000', 'paid_on' => 'nullable|date_format:Y-m-d|before_or_equal:'.Support::today(),
            'invoice_version' => 'nullable|integer|min:1', 'confirm_duplicate' => 'nullable|boolean']);
        // the body names the student and the receipt, so the cache keeps only status and fingerprint and a replay re-reads it (Idempotency $rebuild)
        return Idempotency::run($r, function () use ($r, $id, $d) {
            Claims::approve($id, (int) $d['version'], isset($d['amount_satang']) ? (int) $d['amount_satang'] : null, $d['paid_on'] ?? null,
                isset($d['invoice_version']) ? (int) $d['invoice_version'] : null, $r->user(), (bool) ($d['confirm_duplicate'] ?? false));
            return $this->approvedView($id);
        }, fn () => $this->approvedView($id));
    }

    /** What the screen needs after an approval: no snapshot of the student, address or staff name. */
    private function approvedView(int $id)
    {
        $c = DB::table('fin_payment_claims')->where('school_id', Support::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบรายการแจ้งชำระ');
        $pay = $c->payment_id ? DB::table('fin_payments')->where('school_id', Support::school())->where('id', $c->payment_id)->first(['id', 'receipt_no', 'method', 'amount_satang', 'paid_on', 'status', 'version']) : null;
        abort_unless($pay, 404, 'ไม่พบใบเสร็จ');
        $inv = DB::table('fin_invoices')->where('school_id', Support::school())->where('id', $c->invoice_id)->first(['id', 'invoice_no', 'status', 'total_satang', 'paid_satang', 'version']);
        return response()->json(['claim' => $this->row($c), 'payment' => $pay, 'invoice' => $inv, 'receipt_url' => url('/api/finance/payments/'.$pay->id.'/receipt'), 'baht_text' => Baht::text((int) $pay->amount_satang)], 200);
    }

    public function reject(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:300']);
        return Idempotency::run($r, fn () => response()->json(['claim' => $this->row(Claims::reject($id, (int) $d['version'], trim($d['reason']), $r->user()))]));
    }

    /** Counter payment: finance attaches the slip on behalf of the guardian. Same checks as the guardian upload; the claim is marked as entered by staff and still goes through approval. */
    public function store(Request $r, int $id)
    {
        $d = $r->validate(['amount_satang' => 'required|integer|min:1|max:100000000000', 'transferred_at' => 'required|string|max:20', 'account_id' => 'nullable|integer|min:1',
            'slips' => 'required|array|min:1|max:'.Slips::MAX_FILES, 'slips.*' => 'file']);
        $when = self::when($d['transferred_at']) ?? abort(422, 'วันเวลาที่โอนไม่ถูกต้อง');
        $inv = DB::table('fin_invoices')->where('school_id', Support::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบใบแจ้งหนี้');
        return Idempotency::run($r, function () use ($r, $inv, $d, $when) {
            $claim = Claims::submit($inv, null, $r->user(), (int) $d['amount_satang'], $when, isset($d['account_id']) ? (int) $d['account_id'] : null, $d['slips']);
            $claim->slip_count = count($d['slips']);
            return response()->json($this->row($claim), 201);
        });
    }
}
