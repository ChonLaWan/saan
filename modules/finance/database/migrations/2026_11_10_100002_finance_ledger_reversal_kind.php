<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// finance: a voided receipt used to be posted as kind 'expense'. It is now kind 'reversal' (negative income) so monthly income/expense totals stay correct.
// Data-only, idempotent: re-labels the auto-posted rows of earlier voids (they always carry payment_id and the category below). Closing balances do not change.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('fin_ledger')) return;
        DB::table('fin_ledger')->where('kind', 'expense')->where('category', 'ยกเลิกใบเสร็จ')->whereNotNull('payment_id')->update(['kind' => 'reversal']);
    }

    public function down(): void
    {
        // Intentionally empty: financial records must not be changed by a rollback.
    }
};
