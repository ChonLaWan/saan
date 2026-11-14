<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// leave-duty: expand-only index so "was this rejected request already resubmitted?" does not scan leave_requests.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('leave_requests') || !Schema::hasColumn('leave_requests', 'resubmitted_from')) return;
        if (Schema::hasIndex('leave_requests', 'leave_requests_resub')) return;
        Schema::table('leave_requests', fn (Blueprint $t) => $t->index(['school_id', 'resubmitted_from'], 'leave_requests_resub'));
    }

    public function down(): void
    {
        // expand-only: the index stays
    }
};
