<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// exam-checker: exams with answer key, and one scored answer sheet per student per exam.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('exm_exams')) {
            if (!Schema::hasTable('exm_exams')) Schema::create('exm_exams', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('term_id')->nullable();
                $t->string('title', 200);
                $t->string('kind', 20)->default('quiz');
                $t->string('subject_label', 120)->nullable();
                $t->string('grade_level', 40)->nullable();
                $t->unsignedSmallInteger('question_count');
                $t->unsignedTinyInteger('choices_per_question')->default(4);
                $t->decimal('points_per_question', 8, 2)->default(1);
                // list: 1-based correct choice | [accepted choices] | "cancelled" (everyone gets the point)
                $t->json('answer_key')->nullable();
                $t->json('sections')->nullable();
                $t->string('status', 10)->default('draft');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->index(['school_id', 'term_id', 'status'], 'exm_exams_school_term');
            });
        }
        if (!Schema::hasTable('exm_sheets')) {
            if (!Schema::hasTable('exm_sheets')) Schema::create('exm_sheets', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('exam_id');
                $t->unsignedBigInteger('student_id')->nullable();
                $t->string('student_code', 60);
                // normalised (mb_strtolower + trim + collapsed spaces); uniqueness is never left to DB collation
                $t->string('student_code_key', 80);
                $t->string('display_name', 200);
                $t->unsignedBigInteger('classroom_id')->nullable();
                $t->string('classroom_label', 60)->nullable();
                // one char per question: '1'..'6', '-' blank, '*' multiple marks
                $t->string('answers', 255);
                $t->decimal('score', 8, 2)->default(0);
                $t->json('section_scores')->nullable();
                $t->string('source', 10)->default('keyed');
                $t->unsignedBigInteger('recorded_by')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->unique(['exam_id', 'student_code_key'], 'exm_sheets_exam_code');
                $t->index(['school_id', 'exam_id', 'classroom_id'], 'exm_sheets_exam_class');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: exam results must not be dropped by a rollback.
    }
};
