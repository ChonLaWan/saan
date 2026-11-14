<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// attendance (M04, OPS-006): expand-only. attendances keeps working for the old prototype (new columns are nullable or defaulted).
return new class extends Migration {
    public function up(): void
    {
        $add = fn (string $col, Closure $def) => Schema::hasColumn('attendances', $col) ?: Schema::table('attendances', fn (Blueprint $t) => $def($t));
        $add('version', fn ($t) => $t->unsignedInteger('version')->default(1));
        $add('term_id', fn ($t) => $t->unsignedBigInteger('term_id')->nullable());
        $add('note', fn ($t) => $t->string('note', 200)->nullable());
        $add('updated_by', fn ($t) => $t->unsignedBigInteger('updated_by')->nullable());
        if (!Schema::hasIndex('attendances', 'att_school_date')) {
            Schema::table('attendances', fn (Blueprint $t) => $t->index(['school_id', 'date'], 'att_school_date'));
        }

        if (!Schema::hasTable('att_corrections')) {
            Schema::create('att_corrections', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('attendance_id');
                $t->unsignedBigInteger('student_id');
                $t->date('date');
                $t->string('old_status', 12);
                $t->string('new_status', 12);
                $t->string('reason', 300)->nullable();
                $t->unsignedBigInteger('changed_by');
                $t->timestamp('created_at')->nullable();
                $t->index(['school_id', 'attendance_id'], 'att_corr_attendance');
                $t->index(['school_id', 'student_id', 'date'], 'att_corr_student');
            });
        }

        if (!Schema::hasTable('att_class_days')) {
            Schema::create('att_class_days', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('classroom_id');
                $t->date('date');
                $t->unsignedBigInteger('confirmed_by')->nullable();
                $t->timestamp('confirmed_at')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->unique(['school_id', 'classroom_id', 'date'], 'att_class_day_unique');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: attendance records must not be dropped by a rollback.
    }
};
