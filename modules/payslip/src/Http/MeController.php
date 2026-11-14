<?php

namespace Saan\Modules\Payslip\Http;

use App\Support\Saan\Mutation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Saan\Modules\Payslip\Certificates;
use Saan\Modules\Payslip\Links;
use Saan\Modules\Payslip\Payees;
use Saan\Modules\Payslip\Pdfs;
use Saan\Modules\Payslip\Slips;
use Saan\Modules\Payslip\Support;
use Saan\Modules\Payslip\Vault;

/**
 * Recipient side. EVERY lookup starts from the payee linked to the signed-in account and filters on it in the query (slip.payee_id = my payee,
 * period published, slip live), so an id in the URL can never open another person's slip or certificate: a foreign or hidden id answers 404, same as a missing one.
 * Every open, print and download writes an audit row (who, which slip, how). The row never contains an amount.
 */
class MeController
{
    private const NO_STORE = ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow, noarchive'];

    private function payee(Request $r): object
    {
        return Payees::ofUser($r->user()) ?? abort(404, 'ยังไม่มีข้อมูลสลิปเงินเดือนของบัญชีนี้ กรุณาติดต่อเจ้าหน้าที่การเงิน');
    }

    /** @return array{0:object,1:object} slip, period */
    private function own(Request $r, int $id): array
    {
        $payee = $this->payee($r);
        $row = DB::table('psl_slips as s')->join('psl_periods as p', 'p.id', '=', 's.period_id')->where('s.school_id', Support::school())->where('p.school_id', Support::school())
            ->where('s.id', $id)->where('s.payee_id', (int) $payee->id)->where('s.status', 'active')->where('p.status', 'published')->first(['s.id']);
        abort_unless($row, 404, 'ไม่พบสลิป');
        $slip = Slips::find($id);
        return [$slip, Slips::period((int) $slip->period_id)];
    }

    public function me(Request $r)
    {
        $u = $r->user();
        $p = Payees::ofUser($u);
        return response()->json(['has_payee' => $p !== null, 'name' => $p->name ?? $u->name, 'is_officer' => Support::isOfficer($u), 'can_summary' => Support::canSummary($u), 'links' => Links::all(),
            'years' => $p ? $this->years((int) $p->id) : []], 200, self::NO_STORE);
    }

    private function years(int $payeeId): array
    {
        return $this->currentCerts($payeeId)->pluck('year')->map(fn ($y) => (int) $y)->all();
    }

    /** Issued certificates of the payee that still match their source slips, newest year first. */
    private function currentCerts(int $payeeId): \Illuminate\Support\Collection
    {
        return DB::table('psl_certs')->where('school_id', Support::school())->where('payee_id', $payeeId)->where('status', 'issued')->orderByDesc('year')->get(['id', 'year', 'issued_at', 'status', 'payee_id', 'src_sig'])
            ->filter(fn ($c) => Certificates::isCurrent($c))->values();
    }

    /** The list shows titles and dates only: an amount appears when the person opens a slip (and that opening is audited). */
    public function slips(Request $r)
    {
        $payee = $this->payee($r);
        $rows = DB::table('psl_slips as s')->join('psl_periods as p', 'p.id', '=', 's.period_id')->where('s.school_id', Support::school())->where('p.school_id', Support::school())
            ->where('s.payee_id', (int) $payee->id)->where('s.status', 'active')->where('p.status', 'published')->orderByDesc('p.pay_date')->orderByDesc('s.id')->limit(200)
            ->get(['s.id', 's.kind', 'p.year', 'p.month', 'p.label', 'p.pay_date']);
        return response()->json(['data' => $rows->map(fn ($x) => ['id' => (int) $x->id, 'kind' => $x->kind, 'title' => Support::periodTitle($x), 'year' => (int) $x->year, 'month' => (int) $x->month,
            'pay_date' => (string) $x->pay_date, 'pay_date_th' => Support::thaiDate((string) $x->pay_date)])->all()], 200, self::NO_STORE);
    }

    public function show(Request $r, int $id)
    {
        [$slip, $period] = $this->own($r, $id);
        Mutation::audit('payslip.open', 'psl_slips', (int) $slip->id, ['via' => 'view']);
        return response()->json(Slips::recipientView($slip, $period), 200, self::NO_STORE);
    }

    public function print(Request $r, int $id)
    {
        [$slip, $period] = $this->own($r, $id);
        abort_unless($slip->kind === 'data', 404, 'สลิปนี้เป็นไฟล์ PDF ให้ดาวน์โหลด');
        $v = Slips::recipientView($slip, $period);
        abort_unless($v['data'] !== null, 404, 'อ่านข้อมูลสลิปไม่ได้');
        Mutation::audit('payslip.open', 'psl_slips', (int) $slip->id, ['via' => 'print']);
        $payee = $this->payee($r);
        return response()->view('payslip::slip', ['v' => $v, 'school' => Support::payer()['name'], 'name' => (string) $payee->name, 'code' => (string) $payee->payee_code,
            'viewer' => (string) $payee->name, 'opened' => Support::thaiDate(Support::today()).' '.now('Asia/Bangkok')->format('H:i:s')], 200, self::NO_STORE);
    }

    public function download(Request $r, int $id)
    {
        [$slip, $period] = $this->own($r, $id);
        abort_unless($slip->kind === 'pdf', 404, 'สลิปนี้ไม่มีไฟล์ PDF ให้พิมพ์จากหน้าสลิป');
        $bytes = Vault::readFile($slip->file_path) ?? abort(404, 'ไม่พบไฟล์');
        Mutation::audit('payslip.open', 'psl_slips', (int) $slip->id, ['via' => 'download']);
        return Pdfs::respond($bytes, 'payslip-'.$period->year.'-'.str_pad((string) $period->month, 2, '0', STR_PAD_LEFT).'.pdf');
    }

    // ---------------------------------------------------------------- 50 tawi

    private function ownCert(Request $r, int $id): array
    {
        $payee = $this->payee($r);
        $cert = DB::table('psl_certs')->where('school_id', Support::school())->where('id', $id)->where('payee_id', (int) $payee->id)->where('status', 'issued')->first();
        abort_unless($cert, 404, 'ไม่พบหนังสือรับรอง');
        // a certificate whose source slips changed since it was issued (void, replace, later publish) is never shown or printed until the officer issues it again
        abort_unless(Certificates::isCurrent($cert), 409, 'หนังสือรับรองฉบับนี้อยู่ระหว่างออกใหม่ เนื่องจากสลิปเงินเดือนมีการเปลี่ยนแปลง กรุณาติดต่อเจ้าหน้าที่การเงิน');
        return [$cert, $payee];
    }

    public function certs(Request $r)
    {
        $payee = $this->payee($r);
        $rows = $this->currentCerts((int) $payee->id);
        return response()->json(['data' => $rows->map(fn ($c) => ['id' => (int) $c->id, 'year' => (int) $c->year, 'year_be' => (int) $c->year + 543, 'issued_at' => (string) $c->issued_at])->all()], 200, self::NO_STORE);
    }

    public function cert(Request $r, int $id)
    {
        [$cert, $payee] = $this->ownCert($r, $id);
        Mutation::audit('payslip.cert.open', 'psl_certs', (int) $cert->id, ['via' => 'view']);
        return response()->json(Certificates::view($cert, $payee, false), 200, self::NO_STORE);
    }

    public function certPrint(Request $r, int $id)
    {
        [$cert, $payee] = $this->ownCert($r, $id);
        Mutation::audit('payslip.cert.open', 'psl_certs', (int) $cert->id, ['via' => 'print']);
        return response()->view('payslip::cert', ['c' => Certificates::view($cert, $payee, true), 'viewer' => (string) $payee->name,
            'opened' => Support::thaiDate(Support::today()).' '.now('Asia/Bangkok')->format('H:i:s')], 200, self::NO_STORE);
    }
}
