<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// REL-02: history of every update attempt (completed or rolled back). Expand-only.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('system_updates')) return; // re-run guard (an interrupted earlier run); the index is re-checked by the 2026_11_10 ensure-indexes migration
        Schema::create('system_updates', function (Blueprint $t) {
            $t->id(); $t->string('package_type', 16); $t->string('package_id', 40); $t->string('from_version', 40)->nullable(); $t->string('to_version', 40);
            $t->string('status', 16); $t->string('manifest_sha256', 64); $t->longText('receipt'); $t->timestamp('created_at');
            $t->index(['package_id', 'created_at']);
        });
    }

    public function down(): void {}
};
