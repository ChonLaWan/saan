<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Admin invitations (72 hours) get their own token table: sharing password_reset_tokens let a 60-minute reset token pass the invite broker's longer expiry.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('password_invite_tokens')) Schema::create('password_invite_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_invite_tokens');
    }
};
