<?php

namespace Saan\Modules\Finance;

use Illuminate\Support\Facades\DB;

/**
 * School receiving accounts and which of them an invoice shows.
 * A fee item or invoice line carries account_ids (JSON list). Empty or NULL means "every active account".
 * An invoice shows the union of its lines' lists; one line without a list makes it "every active account".
 * Inactive accounts are never shown, even when a line names them.
 */
final class Accounts
{
    /** @return array<int,object> active accounts of the school, in display order, keyed by id */
    public static function active(): array
    {
        return DB::table('fin_payment_accounts')->where('school_id', Support::school())->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()->keyBy('id')->all();
    }

    /** @param int[] $invoiceIds @return array<int,array<int,object>> invoice id => accounts that invoice may show (same constant 2 queries for any number of invoices) */
    public static function forInvoices(array $invoiceIds): array
    {
        if (!$invoiceIds) return [];
        $active = self::active();
        $sets = array_fill_keys($invoiceIds, []);
        $all = array_fill_keys($invoiceIds, false);
        foreach (DB::table('fin_invoice_lines')->whereIn('invoice_id', $invoiceIds)->get(['invoice_id', 'account_ids']) as $l) {
            $ids = $l->account_ids ? json_decode((string) $l->account_ids, true) : null;
            if (!is_array($ids) || !$ids) $all[(int) $l->invoice_id] = true; else foreach ($ids as $i) $sets[(int) $l->invoice_id][(int) $i] = true;
        }
        $out = [];
        foreach ($invoiceIds as $id) {
            $id = (int) $id;
            $hasLines = $all[$id] || $sets[$id];
            $out[$id] = array_values(array_filter($active, fn ($a) => !$hasLines || $all[$id] || isset($sets[$id][(int) $a->id])));
        }
        return $out;
    }

    public static function forInvoice(int $invoiceId): array
    {
        return self::forInvoices([$invoiceId])[$invoiceId] ?? [];
    }

    /** Validated list of account ids of this school (any status); aborts 422 on an unknown id. Returns null for "all active". */
    public static function checkIds(mixed $ids): ?array
    {
        if ($ids === null || $ids === [] || $ids === '') return null;
        $ids = array_values(array_unique(array_map('intval', (array) $ids)));
        $found = DB::table('fin_payment_accounts')->where('school_id', Support::school())->whereIn('id', $ids)->count();
        if ($found !== count($ids)) abort(422, 'ไม่พบบัญชีรับเงินที่เลือก');
        sort($ids);
        return $ids;
    }

    /** What guardians see: the whole account number is shown on purpose (they must transfer to it). */
    public static function forGuardian(object $a): array
    {
        return ['id' => (int) $a->id, 'bank_name' => $a->bank_name, 'account_name' => $a->account_name, 'account_number' => $a->account_number, 'branch' => $a->branch, 'has_qr' => $a->qr_path !== null];
    }

    public static function present(object $a): array
    {
        return ['id' => (int) $a->id, 'bank_code' => $a->bank_code, 'bank_name' => $a->bank_name, 'account_name' => $a->account_name, 'account_number' => $a->account_number, 'branch' => $a->branch,
            'has_qr' => $a->qr_path !== null, 'is_active' => (bool) $a->is_active, 'sort_order' => (int) $a->sort_order, 'version' => (int) $a->version];
    }
}
