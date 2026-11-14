<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// projects (M16): annual action plan, projects, activities and progress reports. Money is integer satang.
// code_key is the normalised project code (lower-case, trimmed, single spaces): uniqueness never depends on collation.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('prj_plans')) Schema::create('prj_plans', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedSmallInteger('fiscal_year_be');
            $t->string('title', 200); $t->string('status', 8)->default('draft');
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'fiscal_year_be'], 'prj_plans_year');
        });
        if (!Schema::hasTable('prj_projects')) Schema::create('prj_projects', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('plan_id');
            $t->string('code', 40); $t->string('code_key', 80); $t->string('title', 200);
            $t->unsignedBigInteger('owner_teacher_id')->nullable();
            $t->string('department', 120)->nullable(); $t->string('strategy', 200)->nullable();
            $t->text('objective'); $t->text('target')->nullable();
            $t->date('starts_on'); $t->date('ends_on');
            $t->unsignedBigInteger('budget_satang')->default(0); $t->string('budget_source', 40);
            $t->string('status', 12)->default('proposed');
            $t->unsignedBigInteger('approved_by')->nullable(); $t->dateTime('approved_at')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'plan_id', 'code_key'], 'prj_projects_code');
            $t->index(['school_id', 'plan_id', 'status'], 'prj_projects_plan');
            $t->index(['school_id', 'owner_teacher_id'], 'prj_projects_owner');
        });
        if (!Schema::hasTable('prj_activities')) Schema::create('prj_activities', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('project_id');
            $t->string('title', 200); $t->date('planned_on')->nullable(); $t->date('done_on')->nullable();
            $t->unsignedBigInteger('budget_satang')->default(0); $t->unsignedBigInteger('spent_satang')->default(0);
            $t->string('note', 500)->nullable(); $t->string('over_budget_reason', 500)->nullable();
            $t->unsignedBigInteger('fin_ledger_id')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->index(['school_id', 'project_id'], 'prj_activities_project');
        });
        if (!Schema::hasTable('prj_reports')) Schema::create('prj_reports', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('project_id');
            $t->text('summary'); $t->unsignedTinyInteger('outcome_pct')->nullable();
            $t->string('evidence_path', 255)->nullable(); $t->string('evidence_name', 200)->nullable();
            $t->string('evidence_mime', 80)->nullable(); $t->unsignedBigInteger('evidence_size')->nullable(); $t->string('evidence_sha256', 64)->nullable();
            $t->unsignedBigInteger('submitted_by'); $t->dateTime('submitted_at');
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->index(['school_id', 'project_id'], 'prj_reports_project');
        });
    }

    public function down(): void
    {
        // Intentionally empty: budget records and evidence references must not be dropped by a rollback.
    }
};
