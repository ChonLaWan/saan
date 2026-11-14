<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// maintenance: remember when mnt_assets.status "repair" was set by a repair request (auto) instead of by a person. Expand-only, re-runnable.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mnt_assets') && !Schema::hasColumn('mnt_assets', 'status_auto')) {
            Schema::table('mnt_assets', function (Blueprint $t) { $t->boolean('status_auto')->default(false); });
        }
    }

    public function down(): void
    {
        // keep-data policy: nothing is dropped
    }
};
