<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->boolean('is_demo')->default(false);
            $table->unsignedInteger('auth_version')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('last_login_at')->nullable();
            $table->index(['school_id', 'role', 'is_active']);
        });

        // These exact identities belong to the existing fictional local seed.
        DB::table('users')->whereIn('email', [
            'admin@demo.school.test', 'teacher@demo.school.test', 'principal@demo.school.test',
        ])->update(['is_demo' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'role', 'is_active']);
            $table->dropColumn(['is_active', 'is_demo', 'auth_version', 'version', 'last_login_at']);
        });
    }
};
