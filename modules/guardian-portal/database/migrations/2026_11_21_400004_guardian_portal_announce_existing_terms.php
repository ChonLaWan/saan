<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// guardian-portal (F8) upgrade backfill: before F8 a guardian saw the results of every closed course. F8 shows a term only after the school announces it
// (setting student.announce.<term id>), so an upgraded school would suddenly show nothing for every past term. One-off: every term that already has a
// closed or published course is announced now, once; terms without such a course and terms announced later by the school are not touched.
// An existing student.announce.<id> setting always wins (a school that already chose is never overridden). Re-running changes nothing.
return new class extends Migration {
    public function up(): void
    {
        foreach (['asm_courses', 'saan_settings'] as $t) if (!Schema::hasTable($t)) return;
        $now = now();
        $at = json_encode(['at' => $now->format('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);
        $terms = DB::table('asm_courses')->whereIn('status', ['closed', 'published'])->select('school_id', 'term_id')->distinct()->get();
        foreach ($terms as $t) {
            $key = 'student.announce.'.$t->term_id;
            if (DB::table('saan_settings')->where('school_id', $t->school_id)->where('key', $key)->exists()) continue;
            DB::table('saan_settings')->insert(['school_id' => $t->school_id, 'key' => $key, 'value' => $at, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        // Intentionally empty: the announcements are the school's settings now.
    }
};