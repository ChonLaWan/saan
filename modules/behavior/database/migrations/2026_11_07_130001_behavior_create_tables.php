<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// behavior (M05): rules and point records. Expand-only; records are voided, never deleted.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('bhv_rules')) {
            Schema::create('bhv_rules', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('school_id');
                $t->string('code', 40); $t->string('code_key', 80);
                $t->string('label', 200); $t->string('category', 10);
                $t->integer('points'); $t->boolean('is_active')->default(true);
                $t->unsignedInteger('version')->default(1); $t->timestamps();
                $t->unique(['school_id', 'code'], 'bhv_rules_school_code');
                // case-insensitive uniqueness is enforced in code, not by DB collation
                $t->unique(['school_id', 'code_key'], 'bhv_rules_school_codekey');
                $t->index(['school_id', 'is_active', 'category'], 'bhv_rules_active');
            });
        }
        if (!Schema::hasTable('bhv_records')) {
            Schema::create('bhv_records', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('term_id');
                $t->unsignedBigInteger('student_id'); $t->unsignedBigInteger('rule_id')->nullable();
                $t->date('occurred_on'); $t->integer('points'); $t->text('note')->nullable();
                $t->unsignedBigInteger('recorded_by');
                $t->string('status', 10)->default('active');
                $t->string('voided_reason', 300)->nullable(); $t->unsignedBigInteger('voided_by')->nullable(); $t->timestamp('voided_at')->nullable();
                $t->boolean('guardian_visible')->default(true);
                $t->timestamp('guardian_ack_at')->nullable(); $t->string('guardian_ack_by', 120)->nullable();
                $t->text('guardian_ack_note')->nullable();
                // classroom and rule label as they were when recorded: history must survive promotion and renames
                $t->unsignedBigInteger('classroom_id')->nullable(); $t->string('rule_label', 200)->nullable();
                $t->unsignedInteger('version')->default(1); $t->timestamps();
                $t->index(['school_id', 'term_id', 'classroom_id'], 'bhv_records_term_room');
                $t->index(['school_id', 'student_id', 'term_id'], 'bhv_records_student_term');
                $t->index(['school_id', 'term_id', 'status'], 'bhv_records_term_status');
                $t->index(['school_id', 'rule_id'], 'bhv_records_rule');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: behaviour history must not be dropped by a rollback.
    }
};
