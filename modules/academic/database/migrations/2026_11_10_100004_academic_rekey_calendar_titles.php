<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

// Expand-only data fix. The first backfill cut acad_calendar_days.title_key at 150 characters while the application computes it at 160,
// so long titles never matched a new entry of the same name. Recompute those keys at 160; a key that would collide keeps its old value.
return new class extends Migration {
    private function key(string $v): string
    {
        if (class_exists(\Normalizer::class)) $v = \Normalizer::normalize($v, \Normalizer::FORM_C) ?: $v;
        return mb_strtolower((string) preg_replace('/\s+/u', '', $v));
    }

    public function up(): void
    {
        if (!Schema::hasTable('acad_calendar_days') || !Schema::hasColumn('acad_calendar_days', 'title_key')) return;
        $rows = DB::table('acad_calendar_days')->orderBy('id')->get(['id', 'school_id', 'date', 'title', 'title_key']);
        $taken = [];
        $moves = [];
        foreach ($rows as $r) {
            $new = mb_substr($this->key((string) $r->title), 0, 160);
            if ($r->title_key === null || $new === $r->title_key || preg_match('/~\d+$/', (string) $r->title_key) || mb_strlen($new) <= 150) {
                $taken[$r->school_id.'|'.substr((string) $r->date, 0, 10).'|'.$r->title_key] = (int) $r->id;
                continue;
            }
            $moves[] = [$r, $new];
        }
        $clash = [];
        foreach ($moves as [$r, $new]) {
            $slot = $r->school_id.'|'.substr((string) $r->date, 0, 10).'|'.$new;
            if (isset($taken[$slot])) { $clash[] = (int) $r->id; continue; }
            $taken[$slot] = (int) $r->id;
            DB::table('acad_calendar_days')->where('id', $r->id)->update(['title_key' => $new]);
        }
        if ($clash) Log::warning('[academic] calendar day titles that still collide after re-keying (ids: '.implode(', ', $clash).')');
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
