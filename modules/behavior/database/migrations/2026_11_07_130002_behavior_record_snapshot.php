<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// behavior (M05): expand-only catch-up for databases created before the snapshot / acknowledgement columns existed.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('bhv_records')) return;
        Schema::table('bhv_records', function (Blueprint $t) {
            if (!Schema::hasColumn('bhv_records', 'guardian_ack_note')) $t->text('guardian_ack_note')->nullable();
            if (!Schema::hasColumn('bhv_records', 'classroom_id')) $t->unsignedBigInteger('classroom_id')->nullable();
            if (!Schema::hasColumn('bhv_records', 'rule_label')) $t->string('rule_label', 200)->nullable();
        });
        if (!collect(Schema::getIndexes('bhv_records'))->contains(fn ($i) => $i['name'] === 'bhv_records_term_room')) {
            Schema::table('bhv_records', fn (Blueprint $t) => $t->index(['school_id', 'term_id', 'classroom_id'], 'bhv_records_term_room'));
        }
    }

    public function down(): void
    {
        // Intentionally empty: behaviour history must not be dropped by a rollback.
    }
};
