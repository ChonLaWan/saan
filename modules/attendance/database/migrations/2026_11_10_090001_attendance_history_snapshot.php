<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Expand-only: remember which room a mark belongs to and how many students a confirmed day covered, so history
// (e.g. the API attendance summary) does not depend on the student's CURRENT room or today's class size.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('attendances') && !Schema::hasColumn('attendances', 'classroom_id')) {
            Schema::table('attendances', fn (Blueprint $t) => $t->unsignedBigInteger('classroom_id')->nullable());
            DB::statement('UPDATE attendances SET classroom_id = (SELECT s.classroom_id FROM students s WHERE s.id = attendances.student_id) WHERE classroom_id IS NULL');
        }
        if (Schema::hasTable('att_class_days') && !Schema::hasColumn('att_class_days', 'student_count')) {
            Schema::table('att_class_days', fn (Blueprint $t) => $t->unsignedInteger('student_count')->nullable());
        }
    }

    public function down(): void
    {
        // Intentionally empty: expand-only.
    }
};
