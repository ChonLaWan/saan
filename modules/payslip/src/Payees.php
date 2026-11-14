<?php

namespace Saan\Modules\Payslip;

use App\Support\Saan\HashKey;
use App\Support\Saan\Mutation;
use Illuminate\Support\Facades\DB;

/**
 * Payees: the link between a staff account and the identifiers payroll files use (payee code, national id), plus the bank account.
 * National id: keyed hash (HMAC with a key derived from HashKey, so a leaked table cannot be brute forced), encrypted full value, last 4 for display.
 * Payroll files are matched by code or by hash; the id itself is never written into a period, import row or audit detail.
 */
final class Payees
{
    public static function normaliseNid(?string $v): string { return preg_replace('/[\s-]+/u', '', trim((string) $v)); }

    public static function nidFormatOk(string $digits): bool { return (bool) preg_match('/^\d{13}$/D', $digits); }

    /** Check digit of a 13-digit Thai id. A mismatch is a warning for the officer, not a refusal (data entry is theirs to confirm). */
    public static function checksumOk(string $digits): bool
    {
        if (!self::nidFormatOk($digits)) return false;
        $sum = 0;
        for ($i = 0; $i < 12; $i++) $sum += (int) $digits[$i] * (13 - $i);
        return (11 - $sum % 11) % 10 === (int) $digits[12];
    }

    public static function hash(string $digits, ?string $key = null): string
    {
        $bytes = HashKey::bytes($key ?? HashKey::secret());
        return hash_hmac('sha256', $digits, hash('sha256', 'saan.payslip.payee|'.$bytes, true));
    }

    /** @return list<string> hashes under the current key and every key a lookup must still honour */
    public static function hashes(string $digits): array
    {
        return array_values(array_unique(array_map(fn ($k) => self::hash($digits, $k), HashKey::candidates())));
    }

    public static function codeKey(string $code): string { return Support::key($code); }

    /** "1-2345-67890-12-3" masked to "x-xxxx-xxxx9-01-2" style: only the last four digits show. */
    public static function maskNid(?string $digits, ?string $last4 = null): string
    {
        $d = (string) $digits;
        if (self::nidFormatOk($d)) $last4 = substr($d, -4);
        $l = str_pad((string) $last4, 4, 'x', STR_PAD_LEFT);
        return 'x-xxxx-xxxx'.$l[0].'-'.$l[1].$l[2].'-'.$l[3];
    }

    public static function formatNid(string $d): string
    {
        return self::nidFormatOk($d) ? substr($d, 0, 1).'-'.substr($d, 1, 4).'-'.substr($d, 5, 5).'-'.substr($d, 10, 2).'-'.substr($d, 12, 1) : $d;
    }

    public static function maskBank(?string $account): ?string
    {
        $a = preg_replace('/\D+/', '', (string) $account);
        if ($a === '') return null;
        return str_repeat('x', max(0, strlen($a) - 4)).substr($a, -4);
    }

    public static function find(int $id): ?object
    {
        return DB::table('psl_payees')->where('school_id', Support::school())->where('id', $id)->first();
    }

    /** The payee of a signed-in account; null when payroll has not linked this account. */
    public static function ofUser(?object $user): ?object
    {
        if (!$user) return null;
        return DB::table('psl_payees')->where('school_id', Support::school())->where('user_id', (int) $user->id)->first();
    }

    public static function byCode(string $code): ?object
    {
        $k = self::codeKey($code);
        return $k === '' ? null : DB::table('psl_payees')->where('school_id', Support::school())->where('code_key', $k)->first();
    }

    public static function byNid(string $digits): ?object
    {
        if (!self::nidFormatOk($digits)) return null;
        return DB::table('psl_payees')->where('school_id', Support::school())->whereIn('nid_hash', self::hashes($digits))->first();
    }

    public static function nid(object $payee): ?string { return Vault::open($payee->nid_enc); }

    /** Row for lists and forms: masked identifiers only. */
    public static function view(object $p, ?object $user = null): array
    {
        $nid = self::nid($p);
        return ['id' => (int) $p->id, 'user_id' => $p->user_id === null ? null : (int) $p->user_id, 'name' => (string) $p->name, 'payee_code' => (string) $p->payee_code,
            'nid_masked' => $p->nid_enc ? self::maskNid($nid, $p->nid_last4) : null, 'nid_warning' => $nid !== null && !self::checksumOk($nid),
            'bank_name' => $p->bank_name, 'bank_masked' => self::maskBank(Vault::open($p->bank_enc)), 'version' => (int) $p->version,
            'account_active' => $user ? (bool) $user->is_active : null, 'account_email' => $user->email ?? null];
    }

    /** Values written to the table. $d keys: payee_code, national_id, bank_name, bank_account (null = leave, '' = clear). @return array<string,mixed> */
    public static function columns(array $d, bool $creating): array
    {
        $out = [];
        if (isset($d['payee_code'])) { $out['payee_code'] = trim($d['payee_code']); $out['code_key'] = self::codeKey($d['payee_code']); }
        if (array_key_exists('national_id', $d) && $d['national_id'] !== null) {
            $nid = self::normaliseNid($d['national_id']);
            if ($nid === '') { $out['nid_enc'] = null; $out['nid_hash'] = null; $out['nid_last4'] = null; }
            else { $out['nid_enc'] = Vault::seal($nid); $out['nid_hash'] = self::hash($nid); $out['nid_last4'] = substr($nid, -4); }
        }
        if (array_key_exists('bank_name', $d) && $d['bank_name'] !== null) $out['bank_name'] = trim($d['bank_name']) ?: null;
        if (array_key_exists('bank_account', $d) && $d['bank_account'] !== null) $out['bank_enc'] = Vault::seal(preg_replace('/[^0-9A-Za-z-]+/', '', $d['bank_account']) ?: null);
        return $out;
    }

    public static function audit(string $action, object $payee): void
    {
        Mutation::audit($action, 'psl_payees', (int) $payee->id, ['user_id' => $payee->user_id === null ? null : (int) $payee->user_id]);
    }
}
