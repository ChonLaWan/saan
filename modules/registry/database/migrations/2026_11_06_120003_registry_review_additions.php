<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// registry: expand-only additions found in review. Passport issuer and expiry, and the post-commit snapshot used for rollback checks.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('reg_identifiers', 'issuer')) Schema::table('reg_identifiers', fn (Blueprint $t) => $t->string('issuer', 10)->nullable());
        if (!Schema::hasColumn('reg_identifiers', 'expires_on')) Schema::table('reg_identifiers', fn (Blueprint $t) => $t->date('expires_on')->nullable());
        if (!Schema::hasColumn('reg_import_rows', 'after')) Schema::table('reg_import_rows', fn (Blueprint $t) => $t->json('after')->nullable());
    }

    public function down(): void
    {
        // Intentionally empty: registry data must not be dropped by a rollback.
    }
};
