<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// activities: first day a member held a seat (late joiners and people promoted from the waitlist). Expand-only; null = counts every session.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('act_members') && !Schema::hasColumn('act_members', 'enrolled_on')) {
            Schema::table('act_members', function (Blueprint $t) {
                $t->date('enrolled_on')->nullable();
            });
        }
    }

    public function down(): void
    {
        // expand-only: the column stays
    }
};
