<?php

namespace Saan\Modules\Finance\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\TermScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Saan\Modules\Finance\Baht;
use Saan\Modules\Finance\Billing;
use Saan\Modules\Finance\Support;

/** Student lookup, invoices, bulk billing, payments and receipts. */
class BillingController
{
    private const LINES = [
        'lines' => 'required|array|min:1|max:30', 'lines.*.fee_item_id' => 'nullable|integer|min:1', 'lines.*.label' => 'nullable|string|max:200',
        'lines.*.amount_satang' => 'required|integer|min:0|max:100000000000', 'lines.*.discount_satang' => 'nullable|integer|min:0|max:100000000000',
        'lines.*.account_ids' => 'nullable|array|max:20', 'lines.*.account_ids.*' => 'integer|min:1',
    ];

    private function like(string $q): string { return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q); }

    /**     /**
     * Student lookup for the counter. Three shapes, each on an index: a code with a digit (prefix on students(school_id, code)), a classroom
     * (classroom name prefix resolved on the small classrooms table, then students(school_id, classroom_id)), or a name fragment (contains).
     * Outstanding totals are two correlated sub-selects on fin_invoices(school_id, student_id, status) inside the same single students query (no per-student queries).
     */
    public function search(Request $r)
    {
        $f = $r->validate(['q' => 'nullable|string|max:80', 'classroom_id' => 'nullable|integer|min:1', 'only_owing' => 'nullable|boolean']);
        $q = trim((string) ($f['q'] ?? ''));
        if ($q === '' && empty($f['classroom_id'])) abort(422, 'กรุณาระบุรหัส ชื่อ หรือห้องเรียน');
        $school = Support::school();
        $rows = DB::table('students')->leftJoin('classrooms', 'classrooms.id', '=', 'students.classroom_id')->where('students.school_id', $school)
            ->select('students.id', 'students.code', 'students.name', 'students.classroom_id', 'classrooms.name as classroom');
        $byRoom = !empty($f['classroom_id']);
        if ($byRoom) $rows->where('students.classroom_id', (int) $f['classroom_id']);
        if ($q !== '') {
            // a room name has a digit, '/' or '.' ('1/2', 'M.1/2', 'ป.4/1'): try the small classrooms table first, then fall back to a student code (ASCII with a digit) or a name
            $codeLike = preg_match('/\d/', $q) && !preg_match('/[^\x20-\x7E]/', $q);
            $rooms = !$byRoom && preg_match('/[\d\/.]/', $q) && (!$codeLike || preg_match('/[\/.]/', $q))
                ? DB::table('classrooms')->where('school_id', $school)->whereRaw("name like ? escape '!'", [$this->like($q).'%'])->limit(20)->pluck('id')->all() : [];
            if ($rooms) { $rows->whereIn('students.classroom_id', $rooms); $byRoom = true; }
            elseif ($codeLike) $rows->whereRaw("students.code like ? escape '!'", [$this->like($q).'%']);
            else $rows->whereRaw("students.name like ? escape '!'", ['%'.$this->like($q).'%']);
        }
        $open = fn () => DB::table('fin_invoices')->whereColumn('fin_invoices.student_id', 'students.id')->where('fin_invoices.school_id', $school)->whereIn('fin_invoices.status', ['open', 'partial']);
        if (!empty($f['only_owing'])) $rows->whereExists($open());
        $students = $rows->selectSub($open()->selectRaw('COALESCE(SUM(total_satang - paid_satang), 0)'), 'balance_satang')->selectSub($open()->selectRaw('COUNT(*)'), 'open_invoices')
            ->orderBy('students.code')->limit($byRoom ? 60 : 20)->get();
        return response()->json(['data' => $students->map(fn ($s) => array_merge((array) $s, ['balance_satang' => (int) $s->balance_satang, 'open_invoices' => (int) $s->open_invoices]))->all()])->header('Cache-Control', 'no-store, private');
    }

    /** Outstanding per classroom (open and partial invoices of every term) in one grouped query. */
    public function classroomSummary()
    {
        $school = Support::school();
        $rows = DB::table('classrooms as c')->leftJoin('students as s', function ($j) use ($school) { $j->on('s.classroom_id', '=', 'c.id')->where('s.school_id', $school); })
            ->leftJoin('fin_invoices as i', function ($j) use ($school) { $j->on('i.student_id', '=', 's.id')->where('i.school_id', $school)->whereIn('i.status', ['open', 'partial']); })
            ->where('c.school_id', $school)->groupBy('c.id', 'c.name')->orderBy('c.name')->limit(300)
            ->selectRaw('c.id, c.name, COUNT(DISTINCT s.id) as students, COUNT(DISTINCT i.student_id) as owing_students, COUNT(i.id) as open_invoices, COALESCE(SUM(i.total_satang - i.paid_satang), 0) as owed_satang')->get();
        $data = $rows->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name, 'students' => (int) $c->students, 'owing_students' => (int) $c->owing_students,
            'open_invoices' => (int) $c->open_invoices, 'owed_satang' => (int) $c->owed_satang])->all();
        // students without a classroom (moved out, not yet placed) still owe: one extra row so the school total is not understated
        $none = DB::table('students as s')->leftJoin('classrooms as c', 'c.id', '=', 's.classroom_id')->join('fin_invoices as i', function ($j) use ($school) { $j->on('i.student_id', '=', 's.id')->where('i.school_id', $school)->whereIn('i.status', ['open', 'partial']); })
            ->where('s.school_id', $school)->whereNull('c.id')->selectRaw('COUNT(DISTINCT s.id) as students, COUNT(i.id) as open_invoices, COALESCE(SUM(i.total_satang - i.paid_satang), 0) as owed_satang')->first();
        if ($none && (int) $none->open_invoices > 0) $data[] = ['id' => null, 'name' => 'ไม่ระบุห้อง', 'students' => (int) $none->students, 'owing_students' => (int) $none->students,
            'open_invoices' => (int) $none->open_invoices, 'owed_satang' => (int) $none->owed_satang];
        return response()->json(['data' => $data, 'total_owed_satang' => array_sum(array_column($data, 'owed_satang'))])->header('Cache-Control', 'no-store, private');
    }
public function account(int $id)
    {
        $school = Support::school();
        $s = DB::table('students')->leftJoin('classrooms', 'classrooms.id', '=', 'students.classroom_id')->where('students.school_id', $school)->where('students.id', $id)
            ->first(['students.id', 'students.code', 'students.name', 'students.classroom_id', 'classrooms.name as classroom']);
        abort_unless($s, 404, 'ไม่พบนักเรียน');
        $mine = fn () => DB::table('fin_invoices')->where('school_id', $school)->where('student_id', $id);
        // balance = every open/partial invoice (aggregate, same rule as search()); all of them are listed, only paid/void history is capped
        $balance = (int) $mine()->whereIn('status', ['open', 'partial'])->sum(DB::raw('total_satang - paid_satang'));
        $open = $mine()->whereIn('status', ['open', 'partial'])->orderByDesc('issued_on')->orderByDesc('id')->get();
        $history = $mine()->whereNotIn('status', ['open', 'partial'])->orderByDesc('issued_on')->orderByDesc('id')->limit(100)->get();
        $inv = $open->concat($history)->sortBy([['issued_on', 'desc'], ['id', 'desc']])->values();
        $pay = $inv->isEmpty() ? collect() : DB::table('fin_payments')->where('school_id', $school)->whereIn('invoice_id', $inv->pluck('id')->all())->orderByDesc('paid_on')->orderByDesc('id')->get();
        return response()->json(['student' => $s, 'invoices' => $inv, 'payments' => $pay, 'balance_satang' => (int) $balance])->header('Cache-Control', 'no-store, private');
    }

    public function invoices(Request $r)
    {
        $f = $r->validate([
            'student_id' => 'nullable|integer|min:1', 'term_id' => 'nullable|integer|min:1', 'status' => ['nullable', Rule::in(['open', 'partial', 'paid', 'void'])],
            'all_terms' => 'nullable|boolean', 'invoice_no' => 'nullable|string|max:40', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'page' => 'nullable|integer|min:1|max:10000', 'per_page' => 'nullable|integer|min:1|max:200',
        ]);
        $q = $this->invoiceQuery($f);
        $per = (int) ($f['per_page'] ?? 50);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('i.issued_on')->orderByDesc('i.id')->offset((($f['page'] ?? 1) - 1) * $per)->limit($per)->get();
        return response()->json(['data' => $rows, 'total' => $total, 'page' => (int) ($f['page'] ?? 1), 'per_page' => $per]);
    }

    private function invoiceQuery(array $f)
    {
        $q = DB::table('fin_invoices as i')->join('students as s', 's.id', '=', 'i.student_id')->where('i.school_id', Support::school())
            ->select('i.*', 's.code as student_code', 's.name as student_name');
        foreach (['student_id', 'status'] as $k) if (!empty($f[$k])) $q->where('i.'.$k, $f[$k]);
        // Term data goes through TermScope: the current term unless a term_id is given or all_terms=1 is explicit.
        if (empty($f['all_terms'])) {
            try {
                TermScope::apply($q, 'i.term_id', !empty($f['term_id']) ? (int) $f['term_id'] : null);
            } catch (\DomainException) {
                abort(422, 'ยังไม่ได้ตั้งภาคเรียน');
            }
        } elseif (!empty($f['term_id'])) $q->where('i.term_id', (int) $f['term_id']);
        if (!empty($f['invoice_no'])) $q->whereRaw("i.invoice_no like ? escape '!'", [$this->like($f['invoice_no']).'%']);
        if (!empty($f['from'])) $q->where('i.issued_on', '>=', $f['from']);
        if (!empty($f['to'])) $q->where('i.issued_on', '<=', $f['to']);
        return $q;
    }

    public function exportInvoices(Request $r)
    {
        $f = $r->validate(['all_terms' => 'nullable|boolean', 'term_id' => 'nullable|integer|min:1', 'status' => ['nullable', Rule::in(['open', 'partial', 'paid', 'void'])], 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $q = $this->invoiceQuery($f);
        return self::csvStream('invoices-'.Support::today().'.csv', ['เลขที่ใบแจ้งหนี้', 'วันที่ออก', 'ครบกำหนด', 'รหัสนักเรียน', 'ชื่อนักเรียน', 'สถานะ', 'ยอดรวม', 'ชำระแล้ว', 'ค้างชำระ'], function () use ($q) {
            foreach ($q->lazyById(1000, 'i.id', 'id') as $i) {
                yield [$i->invoice_no, $i->issued_on, $i->due_on, $i->student_code, $i->student_name, Billing::STATUS_LABELS[$i->status] ?? $i->status,
                    Baht::plain($i->total_satang), Baht::plain($i->paid_satang), Baht::plain(in_array($i->status, ['open', 'partial'], true) ? (int) $i->total_satang - (int) $i->paid_satang : 0)]; /* void/paid owe nothing */
            }
        });
    }

    /** Streams a CSV row by row (no full result in memory, no row cap). $rows is a closure returning a generator. */
    public static function csvStream(string $name, array $header, \Closure $rows)
    {
        return response()->streamDownload(function () use ($header, $rows) { Support::csvWrite(fopen('php://output', 'w'), $header, $rows()); }, $name,
            ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public static function csvResponse(string $name, array $header, iterable $rows)
    {
        return response(Support::csv($header, $rows), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function csv(string $name, array $header, iterable $rows) { return self::csvResponse($name, $header, $rows); }

    public function showInvoice(int $id)
    {
        return response()->json($this->invoiceDetail($id))->header('Cache-Control', 'no-store, private');
    }

    private function invoiceDetail(int $id): array
    {
        $inv = DB::table('fin_invoices as i')->join('students as s', 's.id', '=', 'i.student_id')->where('i.school_id', Support::school())->where('i.id', $id)
            ->first(['i.*', 's.code as student_code', 's.name as student_name']);
        abort_unless($inv, 404, 'ไม่พบใบแจ้งหนี้');
        return ['invoice' => $inv, 'lines' => DB::table('fin_invoice_lines')->where('invoice_id', $id)->orderBy('id')->get(),
            'payments' => DB::table('fin_payments')->where('school_id', Support::school())->where('invoice_id', $id)->orderBy('id')->get()];
    }

    public function storeInvoice(Request $r)
    {
        $d = $r->validate(['student_id' => 'required|integer|min:1', 'term_id' => 'nullable|integer|min:1', 'issued_on' => 'required|date_format:Y-m-d|before_or_equal:'.Support::today(),
            'due_on' => 'nullable|date_format:Y-m-d|after_or_equal:issued_on'] + self::LINES);
        // the body names the student, so the cache keeps only status and fingerprint plus a reference to the new invoice; a replay re-reads it
        return $this->idem($r, fn () => Billing::createInvoice($d)->id, fn (int $id) => response()->json($this->invoiceDetail($id), 201));
    }

    public function voidInvoice(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:300']);
        Billing::voidInvoice($id, (int) $d['version'], trim($d['reason']), $r->user());
        return response()->json($this->invoiceDetail($id));
    }

    private function bulkRules(): array
    {
        return ['classroom_id' => 'nullable|integer|min:1|required_without:grade_level', 'grade_level' => 'nullable|string|max:20|required_without:classroom_id', 'term_id' => 'nullable|integer|min:1',
            'items' => 'required|array|min:1|max:20', 'items.*.fee_item_id' => 'required|integer|min:1|distinct', 'items.*.amount_satang' => 'nullable|integer|min:0|max:100000000000'];
    }

    public function bulkPreview(Request $r)
    {
        return response()->json(Billing::plan($r->validate($this->bulkRules())));
    }

    public function bulkCommit(Request $r)
    {
        $d = $r->validate($this->bulkRules() + ['issued_on' => 'required|date_format:Y-m-d|before_or_equal:'.Support::today(), 'due_on' => 'nullable|date_format:Y-m-d|after_or_equal:issued_on', 'expected_count' => 'required|integer|min:0']);
        return Idempotency::run($r, fn () => response()->json(Billing::bulk($d), 201));
    }

    public function pay(Request $r, int $id)
    {
        $d = $r->validate(['amount_satang' => 'required|integer|min:1|max:100000000000', 'method' => ['required', Rule::in(Billing::METHODS)], 'paid_on' => 'required|date_format:Y-m-d|before_or_equal:'.Support::today(),
            'reference' => 'nullable|string|max:120', 'invoice_version' => 'nullable|integer|min:1']);
        // the payment row carries the student snapshot of the receipt: the cache keeps only a reference to it
        return $this->idem($r, fn () => Billing::pay($id, $d, $r->user())[0]->id, fn (int $payId) => $this->paymentView($payId, 201));
    }

    public function voidPayment(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:300']);
        return $this->idem($r, fn () => Billing::voidPayment($id, (int) $d['version'], trim($d['reason']), $r->user())[0]->id, fn (int $payId) => $this->paymentView($payId, 200), $id);
    }

    /** A payment with its invoice, read fresh from the database (never from the idempotency cache). */
    private function paymentView(int $payId, int $status)
    {
        $pay = DB::table('fin_payments')->where('school_id', Support::school())->where('id', $payId)->first() ?? abort(404, 'ไม่พบใบเสร็จ');
        $inv = DB::table('fin_invoices')->where('school_id', Support::school())->where('id', $pay->invoice_id)->first();
        $body = ['payment' => $pay, 'invoice' => $inv];
        if ($status === 201) $body += ['receipt_url' => url('/api/finance/payments/'.$pay->id.'/receipt'), 'baht_text' => Baht::text($pay->amount_satang)];
        return response()->json($body, $status);
    }

    /**
     * Idempotent mutation whose answer holds personal data: $make does the work and returns the id of the resource, $view builds the answer from that id.
     * The cache keeps only status, fingerprint and a day-long reference to the id ($known when the route already names it), never the body.
     */
    private function idem(Request $r, \Closure $make, \Closure $view, ?int $known = null)
    {
        $key = trim((string) $r->header('Idempotency-Key', ''));
        $ref = 'fin:idem-ref:'.sha1((int) $r->user()?->id.'|'.$r->method().'|'.$r->path().'|'.(int) config('school.school_id').'|'.$key);
        return Idempotency::run($r, function () use ($make, $view, $ref, $key) {
            $id = (int) $make();
            if ($key !== '') Cache::put($ref, $id, now()->addDay());
            return $view($id);
        }, function () use ($view, $ref, $known) {
            $id = $known !== null ? (int) Cache::get($ref, $known) : Cache::get($ref);
            abort_if($id === null, 409, 'ผลของคำขอเดิมหมดอายุแล้ว กรุณาตรวจรายการในระบบ');
            return $view((int) $id);
        });
    }

    public function receipt(int $id)
    {
        $p = DB::table('fin_payments')->where('school_id', Support::school())->where('id', $id)->first();
        abort_unless($p, 404, 'ไม่พบใบเสร็จ');
        return \Saan\Modules\Finance\Receipts::response($p);
    }
}