<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// api-gateway (G6): clients, bearer tokens, request log, SSO codes and per-student PINs.
// No foreign keys on purpose: log/token rows are pruned independently and the module can be switched off with data kept.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('api_clients')) Schema::create('api_clients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->string('name', 120);
            $t->string('name_key', 150); // mb_strtolower + trim + collapsed spaces, uniqueness is checked in code first
            $t->string('client_id', 40)->unique();
            $t->string('secret_hash', 255);
            $t->json('scopes');
            $t->json('allowed_ips')->nullable();
            $t->json('redirect_uris')->nullable();
            $t->unsignedInteger('rate_per_minute')->default(120);
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_used_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['school_id', 'name_key'], 'api_clients_school_name_key');
        });

        if (!Schema::hasTable('api_tokens')) Schema::create('api_tokens', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->char('token_hash', 64)->unique(); // sha256 of the bearer; the bearer itself is never stored
            $t->json('scopes');
            $t->string('user_kind', 10)->nullable(); // set for SSO tokens: student|staff
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->unsignedBigInteger('sso_code_id')->nullable(); // lets a code replay revoke the token it produced
            $t->dateTime('expires_at'); // DATETIME: MariaDB 10.6 would add ON UPDATE CURRENT_TIMESTAMP to a first TIMESTAMP NOT NULL column
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index('client_id', 'api_tokens_client');
            $t->index('sso_code_id', 'api_tokens_sso_code');
        });

        if (!Schema::hasTable('api_request_log')) Schema::create('api_request_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id')->nullable(); // null = call that never identified a client
            $t->string('route', 120);
            $t->unsignedSmallInteger('status');
            $t->unsignedInteger('ms')->default(0);
            $t->dateTime('at');
            $t->index(['client_id', 'at'], 'api_request_log_client_at');
            $t->index('at', 'api_request_log_at');
        });

        if (!Schema::hasTable('api_sso_codes')) Schema::create('api_sso_codes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->char('code_hash', 64)->unique();
            $t->string('user_kind', 10);
            $t->unsignedBigInteger('subject_id');
            $t->string('redirect_uri', 500);
            $t->string('code_challenge', 128);
            $t->dateTime('expires_at'); // DATETIME: MariaDB 10.6 would add ON UPDATE CURRENT_TIMESTAMP to a first TIMESTAMP NOT NULL column
            $t->timestamp('used_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index('expires_at', 'api_sso_codes_expires');
        });

        if (!Schema::hasTable('api_student_pins')) Schema::create('api_student_pins', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('student_id')->unique();
            $t->string('pin_hash', 255);
            $t->unsignedSmallInteger('failed_count')->default(0);
            $t->timestamp('locked_until')->nullable();
            $t->unsignedBigInteger('issued_by')->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index('school_id', 'api_student_pins_school');
        });
    }

    public function down(): void
    {
        // Intentionally empty: client registrations and the request log are audit data and must not be dropped by a rollback.
    }
};
