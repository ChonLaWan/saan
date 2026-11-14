<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Saan\Modules\Health\ProfileCrypt;

// Encrypts the legacy plaintext free-text fields of hlth_profiles in place (rows already carrying the enc1: prefix are skipped, so a re-run is harmless).
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('hlth_profiles')) return;
        DB::table('hlth_profiles')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $r) {
                $set = [];
                foreach (ProfileCrypt::FIELDS as $f) {
                    $v = $r->$f;
                    if ($v !== null && $v !== '' && !str_starts_with((string) $v, 'enc1:')) $set[$f] = ProfileCrypt::encrypt((string) $v);
                }
                if ($set) DB::table('hlth_profiles')->where('id', $r->id)->update($set);
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: decrypting health data on a rollback would put it back in plaintext.
    }
};
