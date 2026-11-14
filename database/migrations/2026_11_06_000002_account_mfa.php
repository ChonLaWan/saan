<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// REL-05: optional TOTP second factor. Secret and recovery hashes are stored encrypted with APP_KEY. Expand-only.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->text('mfa_secret')->nullable(); $t->text('mfa_recovery')->nullable();
            $t->timestamp('mfa_enabled_at')->nullable(); $t->unsignedBigInteger('mfa_last_step')->nullable();
        });
    }

    public function down(): void {}
};
