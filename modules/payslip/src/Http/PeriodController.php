<?php

namespace Saan\Modules\Payslip\Http;

use App\Support\Saan\Idempotency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Saan\Modules\Payslip\Notify;
use Saan\Modules\Payslip\Periods;
use Saan\Modules\Payslip\Slips;
use Saan\Modules\Payslip\Support;
use Saan\Modules\Payslip\Vault;

/** Periods and the slips inside them. List + totals: officer, admin, principal. Everything that changes data: payroll officer only. */
class PeriodController
{
    private const NO_STORE = ['Cache-Control' => 'no-store, private'];

    public function index()
    {
        $rows = DB::table('psl_periods')->where('school_id', Support::school())->orderByDesc('year')->orderByDesc('month')->orderByDesc('id')->limit(300)->get();
        return response()->json(['data' => $rows->map(fn ($p) => Periods::view($p))->all()], 200, self::NO_STORE);
    }

    public function store(Request $r)
    {
        $d = $r->validate(['year' => 'required|integer|min:2000|max:2100', 'month' => 'required|integer|min:1|max:12', 'label' => 'nullable|string|max:120',
            'pay_date' => 'required|date_format:Y-m-d']);
        return Idempotency::run($r, fn () => response()->json(Periods::view(Periods::create((int) $d['year'], (int) $d['month'], trim((string) ($d['label'] ?? '')), $d['pay_date'])), 201));
    }

    /** Slips of one period for the officer: who, kind, status and net (the officer needs it to check and void). */
    public function show(int $id)
    {
        $p = Slips::period($id);
        $rows = DB::table('psl_slips as s')->join('psl_payees as e', 'e.id', '=', 's.payee_id')->where('s.school_id', Support::school())->where('s.period_id', $id)->orderBy('e.payee_code')->orderBy('s.id')->limit(2000)
            ->get(['s.id', 's.payee_id', 's.kind', 's.status', 's.data_enc', 's.version', 's.void_reason', 's.replaces_id', 'e.name', 'e.payee_code']);
        $slips = $rows->map(fn ($s) => ['id' => (int) $s->id, 'payee_id' => (int) $s->payee_id, 'name' => (string) $s->name, 'payee_code' => (string) $s->payee_code, 'kind' => $s->kind, 'status' => $s->status,
            'net' => $s->kind === 'data' ? (Vault::openJson($s->data_enc)['net'] ?? null) : null, 'version' => (int) $s->version, 'void_reason' => $s->void_reason, 'replaces_id' => $s->replaces_id === null ? null : (int) $s->replaces_id])->all();
        return response()->json(['period' => Periods::view($p), 'slips' => $slips], 200, self::NO_STORE);
    }

    public function publish(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'notify' => 'sometimes|boolean']);
        return Idempotency::run($r, function () use ($id, $d) {
            $p = Periods::publish($id, (int) $d['version']);
            $sent = !empty($d['notify']) ? Notify::published($p) : 0;
            return response()->json(['period' => Periods::view($p), 'mails' => $sent]);
        }, fn () => response()->json(['period' => Periods::view(Slips::period($id)), 'mails' => 0]));
    }

    public function cancel(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:300']);
        return Idempotency::run($r, fn () => response()->json(['period' => Periods::view(Periods::cancel($id, (int) $d['version'], trim($d['reason'])), false)]));
    }

    public function voidSlip(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:300']);
        $slip = Slips::find($id) ?? abort(404, 'ไม่พบสลิป');
        $this->notOwn($r, $slip);
        return Idempotency::run($r, function () use ($slip, $d) {
            $row = Slips::void($slip, (int) $d['version'], trim($d['reason']));
            return $this->voided($row);
        }, fn () => $this->voided(Slips::find((int) $slip->id) ?? abort(404, 'ไม่พบสลิป'))); // the body carries period totals: never cached in plaintext
    }

    /** The same rule as PayeeController: an officer never changes their own pay (the amounts print on their own 50 ทวิ); another officer does it. */
    private function notOwn(Request $r, object $slip): void
    {
        $owner = DB::table('psl_payees')->where('school_id', Support::school())->where('id', (int) $slip->payee_id)->value('user_id');
        abort_if($owner !== null && (int) $owner === (int) $r->user()->id, 403, 'แก้หรือยกเลิกสลิปของบัญชีตัวเองไม่ได้ ให้เจ้าหน้าที่อีกคนทำให้');
    }

    private function voided(object $row)
    {
        return response()->json(['id' => (int) $row->id, 'status' => $row->status, 'version' => (int) $row->version, 'period' => Periods::view(Slips::period((int) $row->period_id))]);
    }

    /** Replace a slip: send the corrected amounts (JSON, baht as text) or a replacement PDF (multipart). The old slip is voided with the reason. */
    public function replaceSlip(Request $r, int $id)
    {
        $slip = Slips::find($id) ?? abort(404, 'ไม่พบสลิป');
        $this->notOwn($r, $slip);
        $base = ['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:300'];
        if ($slip->kind === 'pdf') {
            $r->validate($base + ['file' => 'required|file']);
            return Idempotency::run($r, fn () => $this->replaced(Slips::replace($slip, (int) $r->input('version'), trim((string) $r->input('reason')), null, $r->file('file'))), fn () => $this->replacedBy((int) $id));
        }
        $d = $r->validate($base + ['earnings' => 'required|array|min:1|max:'.Slips::MAX_ITEMS, 'earnings.*.name' => 'required|string|max:80', 'earnings.*.amount' => 'required',
            'deductions' => 'sometimes|array|max:'.Slips::MAX_ITEMS, 'deductions.*.name' => 'required|string|max:80', 'deductions.*.amount' => 'required',
            'tax' => 'nullable', 'sso' => 'nullable', 'pf' => 'nullable', 'net' => 'required']);
        $b = Slips::build($d['earnings'], $d['deductions'] ?? [], $d['tax'] ?? 0, $d['sso'] ?? 0, $d['pf'] ?? 0, $d['net']);
        if (!$b['ok']) abort(422, $b['error'] === 'mismatch' ? 'ยอดสุทธิไม่เท่ากับรายรับลบรายการหัก' : 'จำนวนเงินหรือชื่อรายการไม่ถูกต้อง');
        return Idempotency::run($r, fn () => $this->replaced(Slips::replace($slip, (int) $d['version'], trim($d['reason']), $b['data'], null)), fn () => $this->replacedBy((int) $id));
    }

    /** Replay of a replace: the replacement is read again from the table, nothing from the first response is cached. */
    private function replacedBy(int $oldId)
    {
        $new = DB::table('psl_slips')->where('school_id', Support::school())->where('replaces_id', $oldId)->orderByDesc('id')->first() ?? abort(404, 'ไม่พบสลิปที่แทนที่');
        return $this->replaced($new);
    }

    private function replaced(object $new)
    {
        return response()->json(['id' => (int) $new->id, 'replaces_id' => (int) $new->replaces_id, 'kind' => $new->kind, 'period' => Periods::view(Slips::period((int) $new->period_id))], 201);
    }
}
