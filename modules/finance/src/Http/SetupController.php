<?php

namespace Saan\Modules\Finance\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Saan\Modules\Finance\Support;

/** Access info, settings (admin), fee items and period closing. */
class SetupController
{
    public static function key(string $s): string { return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s))); }

    public function me(Request $r)
    {
        $u = $r->user();
        return response()->json([
            'can_access' => RequireFinance::allowed($u), 'is_manager' => in_array($u->role, RequireFinance::MANAGERS, true), 'is_admin' => $u->role === 'admin',
            'role' => $u->role, 'today' => Support::today(), 'fiscal_year' => Support::fiscalYear(Support::today()),
            'message' => RequireFinance::allowed($u) ? null : 'บัญชีนี้ไม่ได้รับสิทธิ์งานการเงิน กรุณาติดต่อผู้ดูแลระบบ',
        ])->header('Cache-Control', 'no-store, private');
    }

    public function lookups()
    {
        $school = Support::school();
        $cols = \Illuminate\Support\Facades\Schema::hasColumn('classrooms', 'grade_level') ? ['id', 'name', 'grade_level'] : ['id', 'name'];
        $rooms = DB::table('classrooms')->where('school_id', $school)->when(\Illuminate\Support\Facades\Schema::hasColumn('classrooms', 'is_active'), fn ($q) => $q->where('is_active', true))->orderBy('name')->limit(300)->get($cols);
        $terms = DB::table('academic_terms')->where('school_id', $school)->orderByDesc('year')->orderByDesc('term')->limit(20)->get(['id', 'year', 'term']);
        return response()->json(['classrooms' => $rooms, 'terms' => $terms, 'current_term_id' => \App\Support\Saan\TermScope::currentId(), 'banks' => \Saan\Modules\Finance\Banks::LIST]);
    }

    public function settings()
    {
        $staff = Support::staffIds();
        $candidates = DB::table('users')->where('school_id', Support::school())->where('is_active', true)->where('role', 'teacher')
            ->orderBy('name')->limit(500)->get(['id', 'name', 'role']);
        return response()->json(['invoice_prefix' => Support::prefix('invoice'), 'receipt_prefix' => Support::prefix('receipt'), 'staff_user_ids' => $staff, 'candidates' => $candidates]);
    }

    public function saveSettings(Request $r)
    {
        $d = $r->validate([
            'invoice_prefix' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9]{1,8}$/D'], 'receipt_prefix' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9]{1,8}$/D'],
            'staff_user_ids' => 'sometimes|array|max:50', 'staff_user_ids.*' => 'integer|min:1|distinct',
        ]);
        $inv = $d['invoice_prefix'] ?? Support::prefix('invoice');
        $rec = $d['receipt_prefix'] ?? Support::prefix('receipt');
        if (strcasecmp($inv, $rec) === 0) abort(422, 'คำนำหน้าใบแจ้งหนี้และใบเสร็จต้องไม่ซ้ำกัน');
        if (isset($d['staff_user_ids'])) {
            $ids = array_values(array_unique(array_map('intval', $d['staff_user_ids'])));
            // Only teachers need the list: admin and principal already have access, and other roles are blocked by the route role gate.
            $found = $ids ? DB::table('users')->where('school_id', Support::school())->where('is_active', true)->where('role', 'teacher')->whereIn('id', $ids)->count() : 0;
            if ($found !== count($ids)) abort(422, 'ผู้ใช้บทบาทนี้ทำงานการเงินไม่ได้ หรือไม่พบบัญชี (เลือกได้เฉพาะครู)');
            Settings::put('finance.staff_user_ids', $ids);
        }
        foreach (['invoice_prefix', 'receipt_prefix'] as $k) if (isset($d[$k])) Settings::put('finance.'.$k, $d[$k]);
        Mutation::audit('finance.settings_saved', 'saan_settings', null, array_intersect_key($d, array_flip(['invoice_prefix', 'receipt_prefix', 'staff_user_ids'])));
        return $this->settings();
    }

    private function feeRules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        return [
            'code' => [$req, 'string', 'max:40'], 'label' => [$req, 'string', 'max:200'], 'default_amount_satang' => [$req, 'integer', 'min:0', 'max:100000000000'],
            'term_id' => 'nullable|integer|min:1', 'grade_levels' => 'nullable|array|max:30', 'grade_levels.*' => 'string|max:20', 'is_active' => 'sometimes|boolean',
            'account_ids' => 'nullable|array|max:20', 'account_ids.*' => 'integer|min:1|distinct',
        ];
    }

    public function feeItems(Request $r)
    {
        $q = DB::table('fin_fee_items')->where('school_id', Support::school())->orderBy('code');
        if ($r->boolean('active')) $q->where('is_active', true);
        return response()->json(['data' => $q->limit(500)->get()->map(fn ($f) => $this->fee($f))]);
    }

    private function fee(object $f): array
    {
        return array_merge((array) $f, ['grade_levels' => $f->grade_levels ? json_decode($f->grade_levels, true) : null, 'account_ids' => $f->account_ids ? json_decode($f->account_ids, true) : null, 'is_active' => (bool) $f->is_active]);
    }

    private function checkTerm(array $d): void
    {
        if (!empty($d['term_id']) && !DB::table('academic_terms')->where('school_id', Support::school())->where('id', $d['term_id'])->exists()) abort(422, 'ไม่พบภาคเรียนที่ระบุ');
    }

    private function dupe(string $key, ?int $except = null): void
    {
        $q = DB::table('fin_fee_items')->where('school_id', Support::school())->where('code_key', $key);
        if ($except) $q->where('id', '!=', $except);
        if ($q->exists()) abort(409, 'รหัสค่าธรรมเนียมนี้มีอยู่แล้ว');
    }

    public function storeFee(Request $r)
    {
        $d = $r->validate($this->feeRules(true));
        $this->checkTerm($d);
        $d['account_ids'] = \Saan\Modules\Finance\Accounts::checkIds($d['account_ids'] ?? null);
        return Idempotency::run($r, function () use ($d) {
            $key = self::key($d['code']);
            $this->dupe($key);
            $row = Support::guard(fn () => Mutation::insert('fin_fee_items', [
                'code' => trim($d['code']), 'code_key' => $key, 'label' => trim($d['label']), 'default_amount_satang' => $d['default_amount_satang'],
                'term_id' => $d['term_id'] ?? null, 'grade_levels' => $d['grade_levels'] ?? null, 'account_ids' => $d['account_ids'], 'is_active' => $d['is_active'] ?? true,
            ], 'finance.fee_created', ['code' => $d['code']]));
            return response()->json($this->fee($row), 201);
        });
    }

    public function updateFee(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1'] + $this->feeRules(false));
        $this->checkTerm($d);
        if (array_key_exists('account_ids', $d)) $d['account_ids'] = \Saan\Modules\Finance\Accounts::checkIds($d['account_ids']);
        $version = (int) $d['version'];
        unset($d['version']);
        if (isset($d['code'])) { $d['code'] = trim($d['code']); $d['code_key'] = self::key($d['code']); $this->dupe($d['code_key'], $id); }
        if (isset($d['label'])) $d['label'] = trim($d['label']);
        $row = Support::guard(fn () => Mutation::update('fin_fee_items', $id, $version, $d, 'finance.fee_updated', ['fields' => array_keys($d)]));
        return response()->json($this->fee($row));
    }

    public function periods()
    {
        $rows = DB::table('fin_periods')->where('school_id', Support::school())->orderByDesc('period')->limit(60)->get();
        return response()->json(['data' => $rows]);
    }

    private function periodArg(string $period): string
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period)) abort(422, 'รูปแบบงวดต้องเป็น YYYY-MM');
        return $period;
    }

    public function close(Request $r, string $period)
    {
        $period = $this->periodArg($period);
        Support::ensureCounter('period');
        $row = Support::tx(function () use ($period, $r) {
            Support::lock('period'); // closing and writing into the period happen one at a time (writers take a shared lock on the row)
            $row = DB::table('fin_periods')->where('school_id', Support::school())->where('period', $period)->lockForUpdate()->first();
            if (!$row) return Mutation::insert('fin_periods', ['period' => $period, 'closed_at' => now(), 'closed_by' => $r->user()->id], 'finance.period_closed', ['period' => $period]);
            if ($row->closed_at !== null) return $row;
            return Mutation::update('fin_periods', $row->id, (int) $row->version, ['closed_at' => now(), 'closed_by' => $r->user()->id], 'finance.period_closed', ['period' => $period]);
        });
        return response()->json($row);
    }

    public function reopen(Request $r, string $period)
    {
        $period = $this->periodArg($period);
        $row = DB::transaction(function () use ($period, $r) {
            $row = DB::table('fin_periods')->where('school_id', Support::school())->where('period', $period)->lockForUpdate()->first();
            if (!$row || $row->closed_at === null) abort(422, 'งวดนี้ยังไม่ได้ปิด');
            return Mutation::update('fin_periods', $row->id, (int) $row->version, ['closed_at' => null, 'closed_by' => null], 'finance.period_reopened', ['period' => $period, 'by' => $r->user()->id]);
        });
        return response()->json($row);
    }
}


