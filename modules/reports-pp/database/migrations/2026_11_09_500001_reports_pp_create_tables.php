<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// reports-pp: new tables only. asm_scores is numeric, so the non-numeric results (ร, มผ, มส) live in rpt_special_results.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('rpt_remarks')) {
            Schema::create('rpt_remarks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('term_id');
                $t->unsignedBigInteger('student_id');
                $t->string('remark', 500)->default('');
                $t->unsignedInteger('version')->default(1);
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->unique(['term_id', 'student_id'], 'rpt_remarks_term_student');
                $t->index(['school_id', 'term_id'], 'rpt_remarks_school_term');
            });
        }
        if (!Schema::hasTable('rpt_special_results')) {
            Schema::create('rpt_special_results', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('course_id');
                $t->unsignedBigInteger('student_id');
                $t->string('code', 8)->nullable(); // ร | มผ | มส; null = cleared (rows are never deleted so versions only grow: no ABA)
                $t->string('note', 200)->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->unique(['course_id', 'student_id'], 'rpt_special_course_student');
                $t->index('student_id', 'rpt_special_student');
            });
        }
        if (!Schema::hasTable('rpt_templates')) {
            Schema::create('rpt_templates', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->string('kind', 12); // pp5 | pp6 | summary
                $t->unsignedSmallInteger('year_be');
                $t->json('options');
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->unique(['school_id', 'kind', 'year_be'], 'rpt_templates_kind_year');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: report data must not be dropped by a rollback.
    }
};
