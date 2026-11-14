<?php

namespace Saan\Modules\Finance\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Saan\Modules\Finance\Accounts;
use Saan\Modules\Finance\Banks;
use Saan\Modules\Finance\Slips;
use Saan\Modules\Finance\Support;

/**
 * School receiving accounts (finance staff). Guardians see the whole account number on purpose, so every change is audited:
 * the audit row names the fields that changed and keeps only the last four digits of an old and a new number.
 */
class AccountController
{
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        return [
            'bank_code' => [$req, 'string', Rule::in(Banks::codes())], 'account_name' => [$req, 'string', 'min:2', 'max:160'], 'account_number' => [$req, 'string', 'max:30'],
            'branch' => 'nullable|string|max:120', 'is_active' => 'sometimes|boolean', 'sort_order' => 'sometimes|integer|min:0|max:9999',
        ];
    }

    private function find(int $id): object
    {
        return DB::table('fin_payment_accounts')->where('school_id', Support::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบบัญชีรับเงิน');
    }

    public function index()
    {
        $rows = DB::table('fin_payment_accounts')->where('school_id', Support::school())->orderByDesc('is_active')->orderBy('sort_order')->orderBy('id')->limit(100)->get();
        return response()->json(['data' => $rows->map(fn ($a) => Accounts::present($a))->all(), 'banks' => Banks::LIST])->header('Cache-Control', 'no-store, private');
    }

    private function number(array &$d): void
    {
        if (!isset($d['account_number'])) return;
        $n = Banks::normalizeNumber($d['account_number']);
        if ($n === null) abort(422, 'เลขบัญชีต้องเป็นตัวเลข 10 ถึง 15 หลัก');
        $d['account_number'] = $n;
    }

    public function store(Request $r)
    {
        $d = $r->validate($this->rules(true));
        $this->number($d);
        return Idempotency::run($r, function () use ($d) {
            $row = Support::guard(fn () => Mutation::insert('fin_payment_accounts', [
                'bank_code' => $d['bank_code'], 'bank_name' => Banks::name($d['bank_code']), 'account_name' => trim($d['account_name']), 'account_number' => $d['account_number'],
                'branch' => isset($d['branch']) && trim($d['branch']) !== '' ? trim($d['branch']) : null, 'is_active' => $d['is_active'] ?? true, 'sort_order' => $d['sort_order'] ?? 0,
            ], 'finance.account_created', ['bank_code' => $d['bank_code'], 'number_tail' => Banks::tail($d['account_number'])]));
            return response()->json(Accounts::present($row), 201);
        });
    }

    public function update(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1'] + $this->rules(false));
        $version = (int) $d['version'];
        unset($d['version']);
        $this->number($d);
        $old = $this->find($id);
        if (isset($d['bank_code'])) $d['bank_name'] = Banks::name($d['bank_code']);
        if (isset($d['account_name'])) $d['account_name'] = trim($d['account_name']);
        if (array_key_exists('branch', $d)) $d['branch'] = isset($d['branch']) && trim((string) $d['branch']) !== '' ? trim($d['branch']) : null;
        $detail = ['fields' => array_keys(array_diff_key($d, ['bank_name' => 1]))];
        if (isset($d['account_number']) && $d['account_number'] !== $old->account_number) $detail += ['number_from' => Banks::tail($old->account_number), 'number_to' => Banks::tail($d['account_number'])];
        $row = Support::guard(fn () => Mutation::update('fin_payment_accounts', $id, $version, $d, 'finance.account_updated', $detail));
        return response()->json(Accounts::present($row));
    }

    public function qrStore(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'qr' => 'required|file']);
        $this->find($id);
        $info = Slips::inspect($d['qr'], false); // PNG/JPEG/WebP only, no PDF, no SVG
        $path = Slips::put($info, 'qr');
        try {
            $old = $this->find($id)->qr_path;
            $row = Mutation::update('fin_payment_accounts', $id, (int) $d['version'], ['qr_path' => $path, 'qr_mime' => $info['mime'], 'qr_size' => $info['size']], 'finance.account_qr_set', ['account_id' => $id]);
        } catch (\Throwable $e) {
            Slips::forget($path);
            throw $e;
        }
        Slips::forget($old);
        return response()->json(Accounts::present($row));
    }

    public function qrDelete(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        $old = $this->find($id)->qr_path;
        $row = Mutation::update('fin_payment_accounts', $id, (int) $d['version'], ['qr_path' => null, 'qr_mime' => null, 'qr_size' => null], 'finance.account_qr_removed', ['account_id' => $id]);
        Slips::forget($old);
        return response()->json(Accounts::present($row));
    }

    public function qrShow(int $id)
    {
        $a = $this->find($id);
        return Slips::respond($a->qr_path, (string) $a->qr_mime, 'qr-'.$a->id);
    }
}
