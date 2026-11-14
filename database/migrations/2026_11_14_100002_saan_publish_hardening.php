<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S3 review fixes (expand-only): a sequence number can never be stored twice for one stream, and certificate verification on the
// public edition gets a key check value (sha256 of the recipient's ?k= token) so names are shown only to the holder of the link.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('pub_outbox') && !Schema::hasIndex('pub_outbox', 'pub_outbox_stream_seq_unique')) {
            // an earlier build could have stored a duplicate: keep the oldest, drop the rest (they were never deliverable twice anyway)
            $dupes = DB::table('pub_outbox')->whereNotNull('seq')->select('stream', 'seq')->groupBy('stream', 'seq')->havingRaw('count(*) > 1')->get();
            foreach ($dupes as $d) {
                $keep = DB::table('pub_outbox')->where('stream', $d->stream)->where('seq', $d->seq)->min('id');
                DB::table('pub_outbox')->where('stream', $d->stream)->where('seq', $d->seq)->where('id', '!=', $keep)->delete();
            }
            Schema::table('pub_outbox', function (Blueprint $t) {
                $t->unique(['stream', 'seq'], 'pub_outbox_stream_seq_unique');
            });
        }
        if (Schema::hasTable('pub_certificates') && !Schema::hasColumn('pub_certificates', 'key_hash')) {
            Schema::table('pub_certificates', function (Blueprint $t) {
                $t->char('key_hash', 64)->default('');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: a rollback must not drop the queue or the published copy.
    }
};
