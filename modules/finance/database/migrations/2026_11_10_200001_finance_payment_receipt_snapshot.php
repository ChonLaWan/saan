<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// finance: receipts keep a snapshot of the student/classroom/school header as printed at payment time. Expand-only; legacy rows stay NULL and fall back to live data.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('fin_payments')) return;
        Schema::table('fin_payments', function (Blueprint $t) {
            foreach (['snap_student_code' => 40, 'snap_student_name' => 200, 'snap_classroom' => 120, 'snap_school_name' => 200, 'snap_address' => 500] as $c => $len) {
                if (!Schema::hasColumn('fin_payments', $c)) $t->string($c, $len)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: financial records must not be changed by a rollback.
    }
};
