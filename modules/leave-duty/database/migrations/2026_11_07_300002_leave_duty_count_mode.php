<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// leave-duty: expand-only. count_mode 'working' (weekdays minus school holidays) or 'calendar' (every day of the range, e.g. maternity, ordination/hajj).
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('ld_leave_types', 'count_mode')) {
            Schema::table('ld_leave_types', fn (Blueprint $t) => $t->string('count_mode', 10)->default('working'));
            DB::table('ld_leave_types')->whereIn('code', ['maternity', 'ordination'])->update(['count_mode' => 'calendar']);
        }
    }

    public function down(): void
    {
        // expand-only: the column stays (disable_policy keep-data)
    }
};
