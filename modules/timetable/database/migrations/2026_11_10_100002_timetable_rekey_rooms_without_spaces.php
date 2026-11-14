<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

// Expand-only data fix. Timetable::key now removes ALL whitespace, so existing room_key values are recomputed from the room text.
// A row whose new key would land on a slot already holding the same room keeps its old key and is reported (never fails the migration).
return new class extends Migration {
    private function key(string $v): string
    {
        if (class_exists(\Normalizer::class)) $v = \Normalizer::normalize($v, \Normalizer::FORM_C) ?: $v;
        return mb_substr(mb_strtolower((string) preg_replace('/\s+/u', '', $v)), 0, 120);
    }

    public function up(): void
    {
        if (!Schema::hasTable('lessons') || !Schema::hasColumn('lessons', 'room_key')) return;
        $rows = DB::table('lessons')->whereNotNull('room_key')->orderBy('id')->get(['id', 'term_id', 'weekday', 'period_id', 'room', 'room_key']);
        $taken = [];
        $moves = [];
        foreach ($rows as $r) {
            $new = preg_match('/~\d+$/', (string) $r->room_key) ? (string) $r->room_key : $this->key((string) $r->room);
            if ($new === '' || $new === $r->room_key) { $taken[$r->term_id.'|'.$r->weekday.'|'.$r->period_id.'|'.$r->room_key] = (int) $r->id; continue; }
            $moves[] = [$r, $new];
        }
        $clash = [];
        foreach ($moves as [$r, $new]) {
            $slot = $r->term_id.'|'.$r->weekday.'|'.$r->period_id.'|'.$new;
            if (isset($taken[$slot])) { $clash[] = ['lesson_id' => (int) $r->id, 'other_lesson_id' => $taken[$slot], 'term_id' => (int) $r->term_id, 'room' => (string) $r->room]; continue; }
            $taken[$slot] = (int) $r->id;
            DB::table('lessons')->where('id', $r->id)->update(['room_key' => $new]);
        }
        if ($clash) Log::warning('[timetable] after whitespace-insensitive room keys these lessons double-book one room; resolve them in the timetable (lesson ids: '.implode(', ', array_column($clash, 'lesson_id')).')', ['lessons' => $clash]);
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
