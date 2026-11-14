<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// surveys: no-login surveys, one response per person, verifiable certificates. Expand-only.
// Personal data policy: e-mail addresses and roster codes are never stored, only a per-survey HMAC (respondent_key / code_hash).
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('srv_surveys')) Schema::create('srv_surveys', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->string('slug', 80); // always lowercase [a-z0-9-] (validated in code), so the unique index needs no collation help
            $t->string('title', 200); $t->text('intro')->nullable(); $t->json('questions');
            $t->string('identity_mode', 16)->default('anonymous');
            // dateTime (not timestamp): MariaDB/MySQL TIMESTAMP stops at 2038-01-19.
            $t->dateTime('opens_at')->nullable(); $t->dateTime('closes_at')->nullable(); $t->unsignedInteger('anon_ip_hour')->default(300);
            $t->unsignedInteger('max_responses')->nullable(); $t->json('certificate')->nullable();
            $t->string('status', 10)->default('draft'); $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'slug']); $t->index(['school_id', 'status']);
        });
        if (!Schema::hasTable('srv_responses')) Schema::create('srv_responses', function (Blueprint $t) {
            $t->id(); $t->foreignId('survey_id')->constrained('srv_surveys')->restrictOnDelete(); // issued certificates must never cascade away
            $t->char('respondent_key', 64); $t->string('display_name', 160)->nullable(); $t->json('answers'); $t->json('certificate_snapshot')->nullable();
            $t->unsignedTinyInteger('score')->nullable(); $t->string('certificate_no', 40)->nullable()->unique();
            $t->timestamp('created_at')->nullable(); $t->char('ip_hash', 64);
            $t->unique(['survey_id', 'respondent_key']); $t->index(['survey_id', 'created_at']); $t->index(['survey_id', 'ip_hash', 'created_at']);
        });
        if (!Schema::hasTable('srv_roster')) Schema::create('srv_roster', function (Blueprint $t) {
            $t->id(); $t->foreignId('survey_id')->constrained('srv_surveys')->cascadeOnDelete();
            $t->char('code_hash', 64); $t->string('display_name', 160); $t->timestamp('used_at')->nullable();
            $t->unique(['survey_id', 'code_hash']);
        });
        if (!Schema::hasTable('srv_pending')) Schema::create('srv_pending', function (Blueprint $t) {
            $t->id(); $t->foreignId('survey_id')->constrained('srv_surveys')->cascadeOnDelete();
            $t->char('respondent_key', 64); $t->string('display_name', 160)->nullable(); $t->json('answers');
            $t->unsignedBigInteger('response_id')->nullable(); // set once the link was used; the link then shows the same result again
            $t->dateTime('expires_at'); $t->timestamp('created_at')->nullable();
            $t->unique(['survey_id', 'respondent_key']); $t->index('expires_at');
        });
        // One counter row per school and Buddhist-era year; incremented inside the response transaction.
        if (!Schema::hasTable('srv_counters')) Schema::create('srv_counters', function (Blueprint $t) {
            $t->unsignedBigInteger('school_id'); $t->unsignedSmallInteger('year'); $t->unsignedInteger('last')->default(0);
            $t->primary(['school_id', 'year']);
        });
    }

    public function down(): void
    {
        // Intentionally empty: issued certificates must stay verifiable after a rollback.
    }
};
