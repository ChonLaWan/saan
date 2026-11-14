<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// web-notices F9: full_name_spent_until keeps the latest end of a full-name window ever set on the list and is never cleared, so a window
// that already ended cannot be reopened by any path (also not by switching to a masked mode and back). Internal only (not projected).
// A legacy full list without full_name_until showed full names before F9: it counts as spent now.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('notice_lists', 'full_name_spent_until')) {
            Schema::table('notice_lists', fn (Blueprint $t) => $t->dateTime('full_name_spent_until')->nullable());
            DB::table('notice_lists')->where('name_mode', 'full')->whereNull('full_name_until')
                ->update(['full_name_spent_until' => now('Asia/Bangkok')->format('Y-m-d H:i:s')]);
            DB::table('notice_lists')->whereNotNull('full_name_until')->update(['full_name_spent_until' => DB::raw('full_name_until')]);
        }
    }

    public function down(): void
    {
        // Intentionally empty: a rollback must not silently drop notice data.
    }
};
