<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// constructionism (M17): project-based learning teams. Teacher-only entry. No foreign keys on purpose (module can be switched off with data kept).
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('con_projects')) Schema::create('con_projects', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('term_id');
            $t->unsignedBigInteger('classroom_id');
            $t->string('title', 160);
            $t->string('title_key', 200); // mb_strtolower + trim + collapsed spaces; uniqueness checked in code first, index is the backstop
            $t->text('driving_question')->nullable();
            $t->unsignedBigInteger('advisor_teacher_id')->nullable();
            $t->string('status', 12)->default('planning');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['school_id', 'term_id', 'classroom_id', 'title_key'], 'con_projects_title');
            $t->index(['school_id', 'term_id', 'classroom_id'], 'con_projects_class');
            $t->index(['school_id', 'advisor_teacher_id'], 'con_projects_advisor');
        });

        if (!Schema::hasTable('con_members')) Schema::create('con_members', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('student_id');
            $t->string('role', 60)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['project_id', 'student_id'], 'con_members_project_student');
            $t->index('student_id', 'con_members_student');
        });

        if (!Schema::hasTable('con_logs')) Schema::create('con_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('project_id');
            $t->date('date');
            $t->text('entry');
            $t->boolean('by_teacher')->default(true); // true = teacher observation, false = recorded from the students' own words
            $t->unsignedBigInteger('by_user_id')->nullable();
            $t->string('by_name', 120)->default('');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['project_id', 'date'], 'con_logs_project_date');
        });

        if (!Schema::hasTable('con_artifacts')) Schema::create('con_artifacts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('project_id');
            $t->string('title', 160);
            $t->string('path', 255)->nullable(); // private disk, never sent to the browser
            $t->string('url', 500)->nullable();
            $t->string('original_name', 160)->nullable();
            $t->string('kind', 10)->default('other');
            $t->unsignedBigInteger('size')->default(0);
            $t->char('sha256', 64)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index('project_id', 'con_artifacts_project');
        });

        if (!Schema::hasTable('con_reflections')) Schema::create('con_reflections', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('student_id');
            $t->text('text')->nullable();
            $t->json('rubric')->nullable(); // {criterion_key: level 1-4}
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['project_id', 'student_id'], 'con_reflections_project_student');
        });
    }

    public function down(): void
    {
        // keep-data policy: journals, reflections, rubric scores and artifact rows (and their private files) are never dropped by a rollback or module removal
    }
};
