<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// guardian-portal: in-portal notices for a guardian (result of a fee payment claim). Own table, no foreign keys. Expand-only.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('gp_notices')) Schema::create('gp_notices', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('guardian_id'); $t->unsignedBigInteger('student_id')->nullable();
            $t->string('kind', 20); $t->unsignedBigInteger('ref_id')->nullable();
            $t->string('title', 160); $t->string('body', 400)->nullable();
            $t->timestamp('read_at')->nullable(); $t->timestamp('created_at')->nullable();
            $t->index(['school_id', 'guardian_id', 'read_at'], 'gp_notices_guardian');
        });
    }

    public function down(): void
    {
        // Intentionally empty: notices must not be dropped by a rollback.
    }
};
