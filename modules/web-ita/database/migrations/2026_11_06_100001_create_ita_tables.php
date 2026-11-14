<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// web-ita: one row per fiscal year (Buddhist Era), topics (indicators) and their items. Expand-only.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('ita_years')) Schema::create('ita_years', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedSmallInteger('fiscal_year');
            $t->string('title', 200); $t->string('status', 16)->default('draft'); $t->unsignedInteger('version')->default(1);
            $t->timestamp('published_at')->nullable(); $t->timestamps();
            $t->unique(['school_id', 'fiscal_year']); $t->index(['school_id', 'status']);
        });
        if (!Schema::hasTable('ita_topics')) Schema::create('ita_topics', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->foreignId('year_id')->constrained('ita_years')->cascadeOnDelete();
            $t->string('code', 20)->default(''); $t->string('title', 300); $t->unsignedSmallInteger('sort')->default(0); $t->timestamps();
            $t->index(['year_id', 'sort']);
        });
        if (!Schema::hasTable('ita_items')) Schema::create('ita_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->foreignId('topic_id')->constrained('ita_topics')->cascadeOnDelete();
            $t->string('title', 300); $t->string('url', 1000)->nullable(); $t->unsignedBigInteger('media_id')->nullable();
            $t->string('note', 500)->default(''); $t->unsignedSmallInteger('sort')->default(0); $t->timestamps();
            $t->index(['topic_id', 'sort']); $t->index(['school_id', 'media_id']);
        });
    }

    public function down(): void
    {
        // Intentionally empty: published transparency records must not be dropped by a rollback.
    }
};
