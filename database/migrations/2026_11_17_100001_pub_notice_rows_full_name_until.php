<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F9: pub_notice_rows carries full_name_until (Asia/Bangkok local date-time) on rows whose cells hold a FULL name. The public side masks such a row
// once the time passes (renderer, receiver and the scheduled public prune), so a public database snapshot after that moment holds no full name.
// Null = the cells are already masked. Expand-only.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('pub_notice_rows') && !Schema::hasColumn('pub_notice_rows', 'full_name_until')) {
            Schema::table('pub_notice_rows', function (Blueprint $t) { $t->dateTime('full_name_until')->nullable()->index(); });
        }
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
