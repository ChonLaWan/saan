<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// web-notices F9: full names that expire (full_name_until, Asia/Bangkok local date-time) and the optional search-own-name mode. Expand-only.
// A list with name_mode 'full' and no full_name_until (rows created before this change) is treated as already expired = masked.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('notice_lists', function (Blueprint $t) {
            if (!Schema::hasColumn('notice_lists', 'full_name_until')) $t->dateTime('full_name_until')->nullable();
            if (!Schema::hasColumn('notice_lists', 'search_only')) $t->boolean('search_only')->default(false);
        });
    }

    public function down(): void
    {
        // Intentionally empty: a rollback must not silently drop notice data.
    }
};
