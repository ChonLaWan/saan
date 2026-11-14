<?php

namespace Saan\Modules\Payslip\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use Illuminate\Http\Request;
use Saan\Modules\Payslip\Certificates;
use Saan\Modules\Payslip\Payees;
use Saan\Modules\Payslip\Support;

/** 50 tawi for the school: summary (officer, admin, principal; per-person rows for the officer only), generation and the printed copy (officer). */
class CertController
{
    private const NO_STORE = ['Cache-Control' => 'no-store, private'];

    public function summary(Request $r)
    {
        $d = $r->validate(['year' => 'nullable|integer|min:2000|max:2100']);
        $years = Certificates::years();
        $year = (int) ($d['year'] ?? ($years[0] ?? (int) substr(Support::today(), 0, 4)));
        return response()->json(Certificates::summary($year, Support::isOfficer($r->user())), 200, self::NO_STORE);
    }

    public function generate(Request $r)
    {
        $d = $r->validate(['year' => 'required|integer|min:2000|max:2100']);
        return Idempotency::run($r, function () use ($d) {
            $res = Certificates::generate((int) $d['year']);
            return response()->json(['result' => $res] + Certificates::summary((int) $d['year'], true), 201);
        }, fn () => response()->json(Certificates::summary((int) $d['year'], true), 201));
    }

    /** The printed copy for the payroll officer carries the full id. Opening it is audited like the recipient's own opening. */
    public function print(Request $r, int $id)
    {
        $cert = Certificates::find($id) ?? abort(404, 'ไม่พบหนังสือรับรอง');
        abort_unless($cert->status === 'issued', 422, 'หนังสือรับรองฉบับนี้ถูกเพิกถอนแล้ว');
        abort_unless(Certificates::isCurrent($cert), 409, 'ยอดในหนังสือรับรองไม่ตรงกับสลิปปัจจุบัน กรุณากดออกหนังสือรับรองใหม่ก่อนพิมพ์');
        $payee = Payees::find((int) $cert->payee_id) ?? abort(404, 'ไม่พบผู้รับเงิน');
        Mutation::audit('payslip.cert.open', 'psl_certs', (int) $cert->id, ['via' => 'officer_print']);
        return response()->view('payslip::cert', ['c' => Certificates::view($cert, $payee, true), 'viewer' => (string) $r->user()->name,
            'opened' => Support::thaiDate(Support::today()).' '.now('Asia/Bangkok')->format('H:i:s')], 200, self::NO_STORE);
    }
}
