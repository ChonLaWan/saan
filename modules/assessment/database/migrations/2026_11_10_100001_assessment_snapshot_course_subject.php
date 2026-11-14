<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Expand-only. A course keeps its own copy of the subject's code, name and credit from the moment it is created, so editing a
// subject later never rewrites the GPA/credits of terms that were already reported. snap_at null = no snapshot (live subject).
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('asm_courses')) return;
        Schema::table('asm_courses', function (Blueprint $t) {
            if (!Schema::hasColumn('asm_courses', 'snap_at')) $t->timestamp('snap_at')->nullable();
            if (!Schema::hasColumn('asm_courses', 'subject_code_snap')) $t->string('subject_code_snap', 20)->nullable();
            if (!Schema::hasColumn('asm_courses', 'subject_name_snap')) $t->string('subject_name_snap', 150)->nullable();
            if (!Schema::hasColumn('asm_courses', 'credit_snap')) $t->decimal('credit_snap', 3, 1)->nullable();
        });
        $subjects = DB::table('asm_subjects')->get(['id', 'code', 'name', 'credit'])->keyBy('id');
        $now = now();
        DB::table('asm_courses')->whereNull('snap_at')->orderBy('id')->chunkById(500, function ($rows) use ($subjects, $now) {
            foreach ($rows as $r) {
                $s = $subjects[$r->subject_id] ?? null;
                if (!$s) continue;
                DB::table('asm_courses')->where('id', $r->id)->whereNull('snap_at')->update(['snap_at' => $now, 'subject_code_snap' => $s->code, 'subject_name_snap' => $s->name, 'credit_snap' => $s->credit]);
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: snapshots are report evidence and must not be dropped by a rollback.
    }
};
