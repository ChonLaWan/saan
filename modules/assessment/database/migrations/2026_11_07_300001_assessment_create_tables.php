<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// assessment: new tables only. grade_entries (old prototype) is left untouched.
// Case-insensitive uniqueness never relies on collation: code_key is normalised in code, unique index backs it.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('asm_subjects')) {
            Schema::create('asm_subjects', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->string('code', 20);
                $t->string('code_key', 40);
                $t->string('name', 150);
                $t->string('group_name', 100)->nullable();
                $t->decimal('credit', 3, 1)->nullable();
                $t->unsignedSmallInteger('hours_per_year')->nullable();
                $t->json('grade_levels')->nullable();
                $t->boolean('is_active')->default(true);
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->unique(['school_id', 'code_key'], 'asm_subjects_school_codekey');
            });
        }
        if (!Schema::hasTable('asm_courses')) {
            Schema::create('asm_courses', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('term_id');
                $t->unsignedBigInteger('subject_id');
                $t->unsignedBigInteger('classroom_id');
                $t->unsignedBigInteger('teacher_id')->nullable();
                $t->json('policy');
                $t->string('status', 10)->default('open');
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->unique(['term_id', 'subject_id', 'classroom_id'], 'asm_courses_term_subject_class');
                $t->index(['school_id', 'term_id', 'teacher_id'], 'asm_courses_teacher');
                $t->index(['school_id', 'term_id', 'classroom_id'], 'asm_courses_class');
                $t->index('subject_id', 'asm_courses_subject');
            });
        }
        if (!Schema::hasTable('asm_scores')) {
            Schema::create('asm_scores', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('course_id');
                $t->unsignedBigInteger('student_id');
                $t->string('component_key', 40);
                // null = cleared / not entered. Rows are never deleted so a cell version only ever grows (no ABA on compare-and-swap).
                $t->unsignedInteger('score_centi')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->unique(['course_id', 'student_id', 'component_key'], 'asm_scores_cell');
                $t->index(['course_id', 'student_id'], 'asm_scores_course_student');
                $t->index('student_id', 'asm_scores_student');
            });
        }
        if (!Schema::hasTable('asm_score_revisions')) {
            Schema::create('asm_score_revisions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('score_id');
                $t->unsignedBigInteger('course_id');
                $t->unsignedBigInteger('student_id');
                $t->string('component_key', 40);
                $t->unsignedInteger('old_centi')->nullable();
                $t->unsignedInteger('new_centi')->nullable();
                $t->string('reason', 200)->nullable();
                $t->unsignedBigInteger('changed_by')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index('score_id', 'asm_revisions_score');
                $t->index(['course_id', 'student_id'], 'asm_revisions_course_student');
            });
        }
        if (!Schema::hasTable('asm_attributes')) {
            Schema::create('asm_attributes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('term_id');
                $t->unsignedBigInteger('course_id')->default(0); // 0 = term level (not tied to one course)
                $t->unsignedBigInteger('student_id');
                $t->string('kind', 16); // characteristic | competency | reading
                $t->string('item_code', 20);
                $t->unsignedTinyInteger('level'); // 0..3
                $t->unsignedInteger('version')->default(1);
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->unique(['term_id', 'course_id', 'student_id', 'kind', 'item_code'], 'asm_attributes_cell');
                $t->index(['course_id', 'student_id'], 'asm_attributes_course_student');
                $t->index(['school_id', 'term_id', 'student_id'], 'asm_attributes_term_student');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: score data must not be dropped by a rollback.
    }
};
