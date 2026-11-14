<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// api-gateway: how many times a student's PIN has been locked since the last successful sign-in or teacher reset.
// Drives the escalating lock (30 min, 1 h, 2 h, 4 h ... capped at 24 h; every lock expires on its own). Expand-only.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('api_student_pins') && !Schema::hasColumn('api_student_pins', 'lock_count')) {
            Schema::table('api_student_pins', function (Blueprint $t) {
                $t->unsignedSmallInteger('lock_count')->default(0);
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: expand-only.
    }
};
