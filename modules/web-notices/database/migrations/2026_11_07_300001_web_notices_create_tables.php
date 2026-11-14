<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// web-notices: public notice lists that expose minimum data and expire. Expand-only.
// slug is restricted in code to lowercase [a-z0-9-], so the unique index needs no collation tricks.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('notice_lists')) Schema::create('notice_lists', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->string('slug', 80);
            $t->string('title', 200); $t->text('intro')->nullable(); $t->json('columns');
            $t->string('name_mode', 16)->default('first_initial');
            $t->date('publish_from'); $t->date('expires_on');
            $t->string('access_code_hash', 200)->nullable();
            $t->string('status', 16)->default('draft'); $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'slug']); $t->index(['school_id', 'status', 'expires_on']);
        });
        if (!Schema::hasTable('notice_rows')) Schema::create('notice_rows', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->foreignId('list_id')->constrained('notice_lists')->cascadeOnDelete();
            $t->unsignedInteger('order_no'); $t->string('display_name', 160);
            $t->string('classroom', 60)->nullable(); $t->string('group_label', 80)->nullable(); $t->string('note', 255)->nullable();
            $t->index(['list_id', 'order_no']);
        });
    }

    public function down(): void
    {
        // Intentionally empty: a rollback must not silently drop notice data.
    }
};
