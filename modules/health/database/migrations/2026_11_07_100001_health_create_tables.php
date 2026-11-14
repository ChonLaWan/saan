<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// health module (M06). No foreign keys to students/terms: those tables belong to other modules.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('hlth_measurements')) {
            Schema::create('hlth_measurements', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('term_id');
                $t->unsignedTinyInteger('round');
                $t->unsignedBigInteger('student_id');
                $t->date('measured_on');
                $t->decimal('weight_kg', 5, 2)->nullable();
                $t->decimal('height_cm', 5, 1)->nullable();
                $t->unsignedBigInteger('recorded_by')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->unique(['school_id', 'term_id', 'round', 'student_id'], 'hlth_meas_unique');
                $t->index(['school_id', 'term_id', 'round'], 'hlth_meas_round');
                $t->index(['student_id'], 'hlth_meas_student');
            });
        }
        if (!Schema::hasTable('hlth_profiles')) {
            Schema::create('hlth_profiles', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('student_id');
                $t->string('blood_group', 3)->nullable();
                $t->text('allergies')->nullable();
                $t->text('chronic_conditions')->nullable();
                $t->text('medications')->nullable();
                $t->text('emergency_note')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->unique(['student_id'], 'hlth_prof_student');
                $t->index(['school_id'], 'hlth_prof_school');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: health records must not be dropped by a rollback.
    }
};
