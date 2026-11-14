<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// web-ita F6: an item can take its link from the system ("ลิงก์อัตโนมัติจากระบบ"). source = key of ItaSources::KEYS, source_opts = small JSON of that source
// (for example {"category":"news"}). Nothing here stores a host or a resolved link: the link is computed at render time. Expand-only.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('ita_items')) return;
        Schema::table('ita_items', function (Blueprint $t) {
            if (!Schema::hasColumn('ita_items', 'source')) $t->string('source', 32)->nullable();
            if (!Schema::hasColumn('ita_items', 'source_opts')) $t->string('source_opts', 300)->nullable();
        });
    }

    public function down(): void
    {
        // Intentionally empty: published transparency records must not be altered by a rollback.
    }
};
