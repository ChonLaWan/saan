<?php

namespace Saan\Modules\Payslip;

use App\Support\Saan\Mutation;
use Illuminate\Support\Facades\DB;

/**
 * 50 tawi (annual withholding tax certificate), income type 40(1): built from the LIVE data slips of PUBLISHED periods PAID in one calendar year (pay_date, not the period's year label) per payee.
 * Totals: paid (sum of earnings), tax withheld, social security, provident fund. Uploaded-PDF slips carry no amounts and cannot be counted: a payee who has one in the year gets NO certificate
 * (generate skips and withdraws it, the summary reports "pdf_partial") because the totals would silently miss those months.
 * A certificate is a snapshot (psl_certs, encrypted) with a fingerprint of its source slips: if a slip is voided or replaced afterwards the officer sees "stale" and regenerates.
 */
final class Certificates
{
    public const INCOME_TYPE = '40(1)';

    /** @return array<int,array{gross:int,tax:int,sso:int,pf:int,slips:int,pdf:int,sig:string}> payee id => totals for the year (pay_date inside the year; pdf = live PDF slips that cannot be counted) */
    public static function compute(int $year, ?int $onlyPayee = null): array
    {
        $q = DB::table('psl_slips as s')->join('psl_periods as p', 'p.id', '=', 's.period_id')->where('s.school_id', Support::school())->where('p.school_id', Support::school())
            ->where('p.status', 'published')->whereBetween('p.pay_date', [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)])->where('s.status', 'active')->orderBy('s.id');
        if ($onlyPayee !== null) $q->where('s.payee_id', $onlyPayee);
        $acc = [];
        foreach ($q->get(['s.id', 's.version', 's.payee_id', 's.kind', 's.data_enc']) as $s) {
            $d = $s->kind === 'data' ? Vault::openJson($s->data_enc) : null;
            $a = &$acc[(int) $s->payee_id];
            $a ??= ['gross' => 0, 'tax' => 0, 'sso' => 0, 'pf' => 0, 'slips' => 0, 'pdf' => 0, 'bad' => 0, 'ids' => []];
            // a data slip that cannot be decrypted (key changed, damaged row) is counted, never skipped silently: the year is then partial and no certificate is issued from it
            if ($s->kind === 'data' && !$d) { $a['bad']++; $a['ids'][] = $s->id.':'.$s->version.':bad'; unset($a); continue; }
            if ($s->kind !== 'data') { $a['pdf']++; $a['ids'][] = $s->id.':'.$s->version.':pdf'; unset($a); continue; }
            $a['gross'] += (int) $d['gross']; $a['tax'] += (int) $d['tax']; $a['sso'] += (int) $d['sso']; $a['pf'] += (int) $d['pf']; $a['slips']++; $a['ids'][] = $s->id.':'.$s->version;
            unset($a);
        }
        foreach ($acc as $k => $a) {
            if ($a['slips'] === 0 && $a['bad'] === 0) { unset($acc[$k]); continue; } // only PDF slips in the year: nothing to certify
            $acc[$k]['sig'] = hash('sha256', implode(',', $a['ids'])); unset($acc[$k]['ids']);
        }
        return $acc;
    }

    /** True when the certificate is issued and still equals what the live slips give now (no slip voided, replaced or published since, no PDF slip added). */
    public static function isCurrent(object $cert): bool
    {
        if ($cert->status !== 'issued') return false;
        $t = self::compute((int) $cert->year, (int) $cert->payee_id)[(int) $cert->payee_id] ?? null;
        return $t !== null && $t['pdf'] === 0 && $t['bad'] === 0 && hash_equals((string) $cert->src_sig, $t['sig']);
    }

    /** Creates or refreshes the certificate of every payee who has slips in the year. @return array{issued:int,updated:int,unchanged:int,voided:int} */
    public static function generate(int $year): array
    {
        $out = ['issued' => 0, 'updated' => 0, 'unchanged' => 0, 'voided' => 0];
        $totals = array_filter(self::compute($year), fn ($t) => $t['pdf'] === 0 && $t['bad'] === 0); // a payee with a PDF slip or an unreadable slip in the year is never certified from partial data
        DB::transaction(function () use ($year, $totals, &$out) {
            $existing = DB::table('psl_certs')->where('school_id', Support::school())->where('year', $year)->get()->keyBy('payee_id');
            foreach ($totals as $payeeId => $t) {
                $data = ['gross' => $t['gross'], 'tax' => $t['tax'], 'sso' => $t['sso'], 'pf' => $t['pf'], 'slips' => $t['slips']];
                $cur = $existing[$payeeId] ?? null;
                if (!$cur) {
                    Mutation::insert('psl_certs', ['year' => $year, 'payee_id' => $payeeId, 'status' => 'issued', 'data_enc' => Vault::sealJson($data), 'src_sig' => $t['sig'], 'issued_by' => auth()->id(), 'issued_at' => now()],
                        'payslip.cert.issued', ['year' => $year, 'payee_id' => $payeeId]);
                    $out['issued']++;
                } elseif ($cur->status !== 'issued' || $cur->src_sig !== $t['sig']) {
                    Mutation::update('psl_certs', (int) $cur->id, (int) $cur->version, ['status' => 'issued', 'data_enc' => Vault::sealJson($data), 'src_sig' => $t['sig'], 'issued_by' => auth()->id(), 'issued_at' => now()],
                        'payslip.cert.updated', ['year' => $year, 'payee_id' => $payeeId]);
                    $out['updated']++;
                } else $out['unchanged']++;
            }
            // a certificate whose slips are all gone is withdrawn, not deleted
            foreach ($existing as $payeeId => $cur) {
                if (!isset($totals[$payeeId]) && $cur->status === 'issued') {
                    Mutation::update('psl_certs', (int) $cur->id, (int) $cur->version, ['status' => 'void'], 'payslip.cert.voided', ['year' => $year, 'payee_id' => (int) $payeeId]);
                    $out['voided']++;
                }
            }
        });
        return $out;
    }

    public static function find(int $id): ?object
    {
        return DB::table('psl_certs')->where('school_id', Support::school())->where('id', $id)->first();
    }

    /** What the certificate shows. $fullId = the printed copy (recipient or officer); on screen the id is masked. */
    public static function view(object $cert, object $payee, bool $fullId): array
    {
        $d = Vault::openJson($cert->data_enc) ?? ['gross' => 0, 'tax' => 0, 'sso' => 0, 'pf' => 0, 'slips' => 0];
        $nid = Payees::nid($payee);
        return ['id' => (int) $cert->id, 'year' => (int) $cert->year, 'year_be' => (int) $cert->year + 543, 'status' => $cert->status, 'income_type' => self::INCOME_TYPE,
            'cert_no' => sprintf('WT%d-%06d', (int) $cert->year + 543, (int) $cert->id), 'issued_at' => $cert->issued_at ? (string) $cert->issued_at : null,
            'payer' => Support::payer(),
            'payee' => ['name' => (string) $payee->name, 'national_id' => $nid === null ? null : ($fullId ? Payees::formatNid($nid) : Payees::maskNid($nid, $payee->nid_last4))],
            'gross' => (int) $d['gross'], 'tax' => (int) $d['tax'], 'sso' => (int) $d['sso'], 'pf' => (int) $d['pf'], 'slips' => (int) $d['slips']];
    }

    /** Summary for a year. Per-payee rows only when $rows (payroll officer). */
    public static function summary(int $year, bool $rows): array
    {
        $now = self::compute($year);
        $certs = DB::table('psl_certs')->where('school_id', Support::school())->where('year', $year)->get()->keyBy('payee_id');
        $payees = DB::table('psl_payees')->where('school_id', Support::school())->whereIn('id', array_keys($now))->get()->keyBy('id');
        $sum = ['payees_with_slips' => count($now), 'issued' => 0, 'missing' => 0, 'stale' => 0, 'pdf_partial' => 0, 'unreadable' => 0, 'gross' => 0, 'tax' => 0, 'sso' => 0, 'pf' => 0, 'id_warnings' => 0];
        $list = [];
        foreach ($now as $payeeId => $t) {
            $cert = $certs[$payeeId] ?? null;
            $issued = $cert && $cert->status === 'issued';
            $stale = $issued && $cert->src_sig !== $t['sig'];
            $sum['gross'] += $t['gross']; $sum['tax'] += $t['tax']; $sum['sso'] += $t['sso']; $sum['pf'] += $t['pf'];
            $pdfPartial = $t['pdf'] > 0;
            $unreadable = $t['bad'] > 0;
            if ($pdfPartial) $sum['pdf_partial']++; elseif ($unreadable) $sum['unreadable']++; elseif (!$issued) $sum['missing']++; else $sum['issued']++;
            if ($stale && !$pdfPartial && !$unreadable) $sum['stale']++;
            $p = $payees[$payeeId] ?? null;
            if ($p) { $n = Payees::nid($p); if ($n === null || !Payees::checksumOk($n)) $sum['id_warnings']++; }
            if ($rows && $p) $list[] = ['cert_id' => $cert ? (int) $cert->id : null, 'payee_id' => (int) $payeeId, 'name' => (string) $p->name, 'payee_code' => (string) $p->payee_code,
                'nid_masked' => $p->nid_enc ? Payees::maskNid(Payees::nid($p), $p->nid_last4) : null, 'status' => $pdfPartial ? 'pdf_partial' : ($unreadable ? 'unreadable' : ($issued ? ($stale ? 'stale' : 'issued') : 'missing')),
                'gross' => $t['gross'], 'tax' => $t['tax'], 'sso' => $t['sso'], 'pf' => $t['pf'], 'slips' => $t['slips'], 'pdf_slips' => $t['pdf'], 'unreadable_slips' => $t['bad']];
        }
        usort($list, fn ($a, $b) => strcmp($a['payee_code'], $b['payee_code']));
        return ['year' => $year, 'year_be' => $year + 543, 'summary' => $sum, 'rows' => $rows ? $list : null, 'years' => self::years()];
    }

    /** Calendar years in which a published period was paid (pay_date). @return list<int> */
    public static function years(): array
    {
        return DB::table('psl_periods')->where('school_id', Support::school())->where('status', 'published')->pluck('pay_date')->map(fn ($d) => (int) substr((string) $d, 0, 4))->unique()->sortDesc()->values()->all();
    }
}
