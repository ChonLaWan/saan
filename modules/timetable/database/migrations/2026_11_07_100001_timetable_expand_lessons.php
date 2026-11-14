<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

// timetable: expand-only on lessons. Adds room_key (normalised room name; NULL when no room), note, updated_by, deleted_at.
// Existing unique indexes stay. The raw `room` column is still NOT NULL and uniquely indexed, so lessons without a room
// store an opaque placeholder there (see Timetable::NO_ROOM) and leave room_key NULL.
return new class extends Migration {
    private function key(string $v): string
    {
        if (class_exists(\Normalizer::class)) $v = \Normalizer::normalize($v, \Normalizer::FORM_C) ?: $v;
        return mb_substr(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $v))), 0, 120);
    }

    public function up(): void
    {
        $add = fn (string $col, Closure $def) => Schema::hasColumn('lessons', $col) ?: Schema::table('lessons', fn (Blueprint $t) => $def($t));
        // binary collation on MySQL/MariaDB: uniqueness of room_key must be what PHP computed, never a *_ci collation
        $add('room_key', function ($t) {
            $col = $t->string('room_key', 120)->nullable();
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) $col->charset('utf8mb4')->collation('utf8mb4_bin');
        });
        $add('note', fn ($t) => $t->string('note', 300)->nullable());
        $add('updated_by', fn ($t) => $t->unsignedBigInteger('updated_by')->nullable());
        $add('deleted_at', fn ($t) => $t->timestamp('deleted_at')->nullable());

        DB::table('lessons')->whereNull('version')->orWhere('version', '<', 1)->update(['version' => 1]);

        // backfill before the unique index exists; a variant spelling that collapses onto an earlier row gets a ~id suffix instead of failing the migration
        $seen = [];
        $doubleBooked = [];
        foreach (DB::table('lessons')->orderBy('id')->get(['id', 'term_id', 'weekday', 'period_id', 'room', 'room_key']) as $r) {
            if ($r->room_key !== null || trim((string) $r->room) === '' || str_starts_with((string) $r->room, '~none~')) continue;
            $k = $this->key((string) $r->room);
            if ($k === '') continue;
            $slot = $r->term_id.'|'.$r->weekday.'|'.$r->period_id.'|'.$k;
            if (isset($seen[$slot])) {
                // an existing double booking of one room: keep the row (suffix keeps the index buildable) but report it for an admin to resolve
                $doubleBooked[] = ['lesson_id' => (int) $r->id, 'first_lesson_id' => $seen[$slot], 'term_id' => (int) $r->term_id, 'weekday' => (int) $r->weekday, 'period_id' => (int) $r->period_id, 'room' => (string) $r->room];
                $k = mb_substr($k, 0, 100).'~'.$r->id;
            } else {
                $seen[$slot] = (int) $r->id;
            }
            DB::table('lessons')->where('id', $r->id)->update(['room_key' => $k]);
        }
        if ($doubleBooked) {
            // audit_events needs an actor and a migration has none, so the report goes to the application log
            Log::warning('[timetable] migration found lessons double-booking the same room; resolve them in the timetable (lesson ids: '.implode(', ', array_column($doubleBooked, 'lesson_id')).')', ['lessons' => $doubleBooked]);
        }

        if (!Schema::hasIndex('lessons', 'lesson_room_key_slot')) Schema::table('lessons', fn (Blueprint $t) => $t->unique(['term_id', 'weekday', 'period_id', 'room_key'], 'lesson_room_key_slot'));
        if (!Schema::hasIndex('lessons', 'lessons_term_live')) Schema::table('lessons', fn (Blueprint $t) => $t->index(['school_id', 'term_id', 'deleted_at'], 'lessons_term_live'));
        if (!Schema::hasIndex('lessons', 'lessons_term_teacher')) Schema::table('lessons', fn (Blueprint $t) => $t->index(['school_id', 'term_id', 'teacher_id'], 'lessons_term_teacher'));
    }

    public function down(): void
    {
        // Intentionally empty: lesson data must not be dropped by a rollback.
    }
};
