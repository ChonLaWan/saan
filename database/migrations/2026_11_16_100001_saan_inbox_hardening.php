<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// S5/S6 review fixes. Expand-only.
//   saan_pairing_codes.push_url   the public push URL a code is bound to when it is issued (a code cannot move the push/pull target)
//   saan_inbox_done.handled_at    index for the daily prune of old idempotency rows
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('saan_pairing_codes') && !Schema::hasColumn('saan_pairing_codes', 'push_url')) {
            Schema::table('saan_pairing_codes', function (Blueprint $t) { $t->string('push_url', 255)->nullable(); });
        }
        if (Schema::hasTable('saan_inbox_done')) {
            try { Schema::table('saan_inbox_done', function (Blueprint $t) { $t->index('handled_at', 'saan_inbox_done_handled_at_index'); }); } catch (\Throwable) { /* already there */ }
        }
    }

    public function down(): void
    {
        // Intentionally empty (expand-only).
    }
};
