<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

// Expand-only data fix. Assessment::key now removes ALL whitespace, so subject code_key values are recomputed from the code.
// A subject whose new key is already used by another subject keeps its old key and is reported in the log (merge them by hand).
return new class extends Migration {
    private function key(string $v): string
    {
        if (class_exists(\Normalizer::class)) $v = \Normalizer::normalize($v, \Normalizer::FORM_C) ?: $v;
        return mb_strtolower((string) preg_replace('/\s+/u', '', $v));
    }

    public function up(): void
    {
        if (!Schema::hasTable('asm_subjects')) return;
        $rows = DB::table('asm_subjects')->orderBy('id')->get(['id', 'school_id', 'code', 'code_key']);
        $taken = [];
        $moves = [];
        foreach ($rows as $r) {
            $new = $this->key((string) $r->code);
            if ($new === '' || $new === $r->code_key) { $taken[$r->school_id.'|'.$r->code_key] = (int) $r->id; continue; }
            $moves[] = [$r, $new];
        }
        $clash = [];
        foreach ($moves as [$r, $new]) {
            $slot = $r->school_id.'|'.$new;
            if (isset($taken[$slot])) { $clash[] = ['subject_id' => (int) $r->id, 'other_subject_id' => $taken[$slot], 'code' => (string) $r->code]; continue; }
            $taken[$slot] = (int) $r->id;
            DB::table('asm_subjects')->where('id', $r->id)->update(['code_key' => $new]);
        }
        if ($clash) Log::warning('[assessment] subject codes differ only by spaces; merge them by hand (subject ids: '.implode(', ', array_column($clash, 'subject_id')).')', ['subjects' => $clash]);
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
