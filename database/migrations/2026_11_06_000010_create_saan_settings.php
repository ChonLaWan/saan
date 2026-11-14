<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('saan_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->string('key', 120);
            $t->json('value')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['school_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saan_settings');
    }
};