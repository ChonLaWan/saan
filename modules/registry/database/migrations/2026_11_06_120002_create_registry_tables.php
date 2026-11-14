<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// registry: guardians, links, identifiers (encrypted + keyed hash), private documents, import batches. Expand-only.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('reg_guardians')) Schema::create('reg_guardians', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->string('prefix', 30)->nullable(); $t->string('first_name', 120)->nullable(); $t->string('last_name', 120)->nullable();
            $t->string('display_name', 240);
            $t->text('phone_enc')->nullable(); $t->string('email', 190)->nullable(); $t->string('line_id', 80)->nullable();
            $t->string('occupation', 120)->nullable();
            $t->string('ref_code', 60)->nullable(); $t->string('ref_key', 80)->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->index(['school_id', 'display_name']);
            $t->unique(['school_id', 'ref_key'], 'reg_guardians_refkey');
        });
        if (!Schema::hasTable('reg_student_guardians')) Schema::create('reg_student_guardians', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('student_id'); $t->unsignedBigInteger('guardian_id');
            $t->string('relationship', 30)->default('other');
            $t->boolean('is_primary')->default(false); $t->boolean('is_emergency_contact')->default(false);
            $t->boolean('can_pick_up')->default(true); $t->string('pickup_note', 300)->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['student_id', 'guardian_id'], 'reg_sg_unique');
            $t->index(['school_id', 'guardian_id']);
        });
        if (!Schema::hasTable('reg_identifiers')) Schema::create('reg_identifiers', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->string('person_type', 10); $t->unsignedBigInteger('person_id');
            $t->string('kind', 12);
            $t->text('value_enc')->nullable(); $t->char('value_hash', 64)->nullable(); $t->string('last4', 4)->nullable();
            $t->string('verification_state', 12)->default('unverified'); $t->string('note', 300)->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->index(['school_id', 'person_type', 'person_id'], 'reg_ident_person');
            $t->unique(['school_id', 'kind', 'value_hash'], 'reg_ident_hash');
        });
        if (!Schema::hasTable('reg_documents')) Schema::create('reg_documents', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('student_id');
            $t->string('kind', 30); $t->string('label', 160);
            $t->string('path', 400); $t->string('mime', 80); $t->unsignedBigInteger('size'); $t->char('sha256', 64);
            $t->string('review_state', 10)->default('pending'); $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->index(['school_id', 'student_id'], 'reg_docs_student');
            $t->index(['school_id', 'review_state'], 'reg_docs_state');
        });
        if (!Schema::hasTable('reg_import_batches')) Schema::create('reg_import_batches', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->string('kind', 20); $t->string('filename', 200);
            $t->string('status', 12)->default('preview'); $t->json('summary')->nullable();
            $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
            $t->index(['school_id', 'status']);
        });
        if (!Schema::hasTable('reg_import_rows')) Schema::create('reg_import_rows', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('batch_id'); $t->unsignedInteger('row_no');
            $t->json('data')->nullable(); $t->json('errors')->nullable();
            $t->string('action', 8)->default('skip'); $t->unsignedBigInteger('entity_id')->nullable(); $t->json('before')->nullable();
            $t->index(['batch_id', 'row_no']);
        });
    }

    public function down(): void
    {
        // Intentionally empty: identity records and uploaded documents must not be dropped by a rollback.
    }
};
