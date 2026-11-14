<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// guardian-portal (F8): guardian accounts without e-mail (username + password issued by the homeroom teacher), per-account lockout,
// forced change of a temporary password, and verified contact e-mails for students and guardians (self-service reset). Expand-only.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('gp_accounts')) {
            $add = fn (string $col, Closure $def) => Schema::hasColumn('gp_accounts', $col) ?: Schema::table('gp_accounts', fn (Blueprint $t) => $def($t));
            $add('username', fn ($t) => $t->string('username', 40)->nullable());
            $add('username_key', fn ($t) => $t->string('username_key', 40)->nullable());
            $add('must_change', fn ($t) => $t->boolean('must_change')->default(false));
            $add('failed_count', fn ($t) => $t->unsignedSmallInteger('failed_count')->default(0));
            $add('locked_until', fn ($t) => $t->timestamp('locked_until')->nullable());
            $add('lock_count', fn ($t) => $t->unsignedSmallInteger('lock_count')->default(0));
            if (!Schema::hasIndex('gp_accounts', 'gp_accounts_username')) Schema::table('gp_accounts', fn (Blueprint $t) => $t->unique(['school_id', 'username_key'], 'gp_accounts_username'));
        }
        if (!Schema::hasTable('gp_contacts')) Schema::create('gp_contacts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->string('kind', 10); // student | guardian
            $t->unsignedBigInteger('subject_id');
            $t->string('email', 190); $t->string('email_key', 190);
            $t->char('verify_hash', 64)->nullable();
            $t->dateTime('verify_expires_at')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'kind', 'subject_id'], 'gp_contacts_subject');
            $t->index('verify_hash', 'gp_contacts_verify');
        });
    }

    public function down(): void
    {
        // Intentionally empty: expand-only.
    }
};
