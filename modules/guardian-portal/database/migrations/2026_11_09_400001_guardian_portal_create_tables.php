<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// guardian-portal (G4): own tables only, no foreign keys to registry/behavior tables (they belong to other modules).
// Case-insensitive e-mail uniqueness never relies on collation: email_key is normalised in code and unique-indexed.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('gp_accounts')) Schema::create('gp_accounts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('guardian_id');
            $t->string('email', 190)->nullable(); $t->string('email_key', 190)->nullable();
            $t->char('phone_hash', 64)->nullable();
            $t->string('password', 255)->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->unsignedInteger('auth_version')->default(1);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'guardian_id'], 'gp_accounts_guardian');
            $t->unique(['school_id', 'email_key'], 'gp_accounts_emailkey');
        });
        if (!Schema::hasTable('gp_links')) Schema::create('gp_links', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('guardian_id'); $t->unsignedBigInteger('student_id');
            $t->char('token_hash', 64);
            $t->string('purpose', 8); // invite | view
            // DATETIME on purpose: on MariaDB 10.6 the first NOT NULL TIMESTAMP gets ON UPDATE CURRENT_TIMESTAMP and every UPDATE would rewrite it
            $t->dateTime('expires_at'); $t->timestamp('used_at')->nullable(); $t->timestamp('revoked_at')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique('token_hash', 'gp_links_token');
            $t->index(['school_id', 'student_id', 'guardian_id'], 'gp_links_pair');
        });
        if (!Schema::hasTable('gp_acks')) Schema::create('gp_acks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('guardian_id'); $t->unsignedBigInteger('student_id');
            $t->string('kind', 20); // behavior | attendance_month | term_result
            $t->unsignedBigInteger('ref_id')->nullable(); $t->char('ref_month', 7)->nullable();
            // "<ref_id or 0>|<ref_month or ''>": a never-null copy of the reference, so the unique index also holds on MySQL/MariaDB
            $t->string('ref_key', 40);
            $t->text('note')->nullable(); // the guardian's own comment (max 500 characters, cut in code)
            $t->dateTime('acknowledged_at'); $t->char('ip_hash', 64)->nullable();
            $t->unique(['school_id', 'guardian_id', 'student_id', 'kind', 'ref_key'], 'gp_acks_once');
            $t->index(['school_id', 'student_id', 'kind'], 'gp_acks_student');
        });
        // audit of actions taken by guardians (audit_events.actor_id must be a staff user, a guardian has none)
        if (!Schema::hasTable('gp_events')) Schema::create('gp_events', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('guardian_id')->nullable(); $t->unsignedBigInteger('student_id')->nullable();
            $t->string('action', 40); $t->text('detail')->nullable(); $t->char('ip_hash', 64)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['school_id', 'guardian_id', 'created_at'], 'gp_events_guardian');
        });
    }

    public function down(): void
    {
        // Intentionally empty: acknowledgements and audit history must not be dropped by a rollback.
    }
};
