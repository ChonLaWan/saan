<?php

namespace Saan\Modules\Payslip\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Saan\Modules\Payslip\Payees;
use Saan\Modules\Payslip\Support;

/** Payroll officer: link staff accounts to payee code, national id and bank account. Identifiers are shown masked; the full values are never returned by any list or form. */
class PayeeController
{
    private const RULES = ['payee_code' => ['string', 'min:1', 'max:40', 'regex:/^[^\x00-\x1F\x7F]+$/u'], 'national_id' => 'nullable|string|max:20', 'bank_name' => 'nullable|string|max:80', 'bank_account' => 'nullable|string|max:40'];

    private function invalid(string $field, string $msg): never { throw ValidationException::withMessages([$field => [$msg]]); }

    private function checkNid(array $d, ?int $exceptId = null): void
    {
        if (!isset($d['national_id']) || trim((string) $d['national_id']) === '') return;
        $nid = Payees::normaliseNid($d['national_id']);
        if (!Payees::nidFormatOk($nid)) $this->invalid('national_id', 'เลขประจำตัวประชาชนต้องมี 13 หลัก');
        $dup = Payees::byNid($nid);
        if ($dup && (int) $dup->id !== $exceptId) $this->invalid('national_id', 'เลขประจำตัวนี้ผูกกับผู้รับเงินรายอื่นแล้ว');
    }

    public function index()
    {
        $rows = DB::table('psl_payees')->where('school_id', Support::school())->orderBy('payee_code')->limit(1000)->get();
        $users = Support::users($rows->pluck('user_id')->filter()->map(fn ($v) => (int) $v)->all());
        return response()->json(['data' => $rows->map(fn ($p) => Payees::view($p, $p->user_id ? $users->get((int) $p->user_id) : null))->all()], 200, ['Cache-Control' => 'no-store, private']);
    }

    /** Active accounts that have no payee yet. */
    public function candidates()
    {
        $taken = DB::table('psl_payees')->where('school_id', Support::school())->whereNotNull('user_id')->pluck('user_id')->all();
        $rows = DB::table('users')->where('school_id', Support::school())->where('is_active', true)->whereNotIn('id', $taken ?: [0])->orderBy('name')->limit(500)->get(['id', 'name', 'role']);
        return response()->json(['data' => $rows->map(fn ($u) => ['id' => (int) $u->id, 'name' => (string) $u->name, 'role' => (string) $u->role])->all()], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function store(Request $r)
    {
        $d = $r->validate(['user_id' => 'required|integer|min:1', 'payee_code' => array_merge(['required'], self::RULES['payee_code'])] + array_diff_key(self::RULES, ['payee_code' => 1]));
        $user = DB::table('users')->where('school_id', Support::school())->where('is_active', true)->where('id', (int) $d['user_id'])->first() ?? $this->invalid('user_id', 'ไม่พบบัญชีเจ้าหน้าที่ที่เปิดใช้งาน');
        // the same rule as update(): an officer never creates the payee row of their own account (bank account and national id print on their own certificate)
        abort_if((int) $user->id === (int) $r->user()->id, 403, 'ตั้งข้อมูลผู้รับเงินของบัญชีตัวเองไม่ได้ ให้เจ้าหน้าที่อีกคนทำให้');
        $this->checkNid($d);
        return Idempotency::run($r, function () use ($d, $user) {
            $p = Support::unique(fn () => Mutation::insert('psl_payees', ['user_id' => (int) $user->id, 'name' => (string) $user->name] + Payees::columns($d, true), 'payslip.payee.created', ['user_id' => (int) $user->id]),
                'รหัสผู้รับเงินหรือบัญชีนี้มีอยู่แล้ว');
            return response()->json(Payees::view($p, $user), 201);
        }, fn () => response()->json(Payees::view(Payees::byCode($d['payee_code']) ?? abort(404), $user), 201));
    }

    public function update(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'user_id' => 'sometimes|integer|min:1', 'payee_code' => array_merge(['sometimes'], self::RULES['payee_code'])] + array_diff_key(self::RULES, ['payee_code' => 1]));
        $p = Payees::find($id) ?? abort(404, 'ไม่พบผู้รับเงิน');
        // an officer never edits the payee row of their own account (bank account and national id print on their own certificate); another officer does it
        abort_if($p->user_id !== null && (int) $p->user_id === (int) $r->user()->id, 403, 'แก้ข้อมูลผู้รับเงินของบัญชีตัวเองไม่ได้ ให้เจ้าหน้าที่อีกคนแก้ให้');
        $this->checkNid($d, $id);
        $changes = Payees::columns($d, false);
        $user = $p->user_id ? DB::table('users')->where('school_id', Support::school())->where('id', (int) $p->user_id)->first() : null;
        $detail = ['user_id' => $p->user_id === null ? null : (int) $p->user_id,
            'fields' => array_keys(array_intersect_key($d, array_flip(['payee_code', 'national_id', 'bank_name', 'bank_account'])))]; // names of the fields only, never values
        if (isset($d['user_id']) && (int) $d['user_id'] !== (int) $p->user_id) {
            // re-link to a new account: only when the old account is gone or switched off, and the new one is active and has no payee yet
            if ($user && $user->is_active) $this->invalid('user_id', 'บัญชีเดิมยังใช้งานอยู่ ผูกกับบัญชีใหม่ไม่ได้');
            $new = DB::table('users')->where('school_id', Support::school())->where('is_active', true)->where('id', (int) $d['user_id'])->first() ?? $this->invalid('user_id', 'ไม่พบบัญชีเจ้าหน้าที่ที่เปิดใช้งาน');
            abort_if((int) $new->id === (int) $r->user()->id, 403, 'ผูกผู้รับเงินเข้ากับบัญชีตัวเองไม่ได้ ให้เจ้าหน้าที่อีกคนทำให้');
            if (DB::table('psl_payees')->where('school_id', Support::school())->where('user_id', (int) $new->id)->exists()) $this->invalid('user_id', 'บัญชีนี้มีผู้รับเงินอยู่แล้ว');
            $changes['user_id'] = (int) $new->id;
            $detail = ['user_id' => (int) $new->id, 'from_user_id' => $p->user_id === null ? null : (int) $p->user_id, 'to_user_id' => (int) $new->id] + $detail;
            $user = $new;
        }
        if ($user) $changes['name'] = (string) $user->name; // the name on slips follows the account
        $row = Support::unique(fn () => Mutation::update('psl_payees', $id, (int) $d['version'], $changes, 'payslip.payee.updated', $detail), 'รหัสผู้รับเงินหรือบัญชีนี้มีอยู่แล้ว');
        return response()->json(Payees::view($row, $user));
    }
}
