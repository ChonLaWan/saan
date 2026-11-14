<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// activities: secret ballot. key_secret is no longer written; wipe any value that still sits in the DB (and in future copies of it),
// and replace the old derivable voter_key of existing ballots with random values so nothing can be recomputed from APP_KEY.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('act_elections') && Schema::hasColumn('act_elections', 'key_secret')) {
            DB::table('act_elections')->whereNotNull('key_secret')->update(['key_secret' => null]);
        }
        if (Schema::hasTable('act_ballots') && Schema::hasColumn('act_ballots', 'voter_key')) {
            DB::table('act_ballots')->select('id')->orderBy('id')->chunk(500, function ($rows) {
                foreach ($rows as $r) DB::table('act_ballots')->where('id', $r->id)->update(['voter_key' => bin2hex(random_bytes(32))]);
            });
        }
    }

    public function down(): void
    {
        // expand-only / keep-data policy: nothing to undo
    }
};