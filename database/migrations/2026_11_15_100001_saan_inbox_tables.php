<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// S5 + S6: encrypted inbox and pairing. Every install gets all tables (same code, same schema).
//   pub_inbox          public edition: sealed envelopes waiting to be pulled (ciphertext only; kind, time, a random dedupe hash and the key id are the only plain metadata)
//   pub_inbox_nonces   public edition: nonces of pull requests already seen (replay protection)
//   saan_inbox_done    internal edition: envelopes already handled (idempotency across a lost ack)
//   saan_inbox_quarantine  internal edition: envelopes that could not be dispatched (unknown kind, refused by the handler) kept for review
//   saan_pairing_codes internal edition: one-time pairing codes (only the SHA-256 of a code is stored)
// No foreign keys on purpose. Expand-only.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('pub_inbox')) Schema::create('pub_inbox', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 32);
            $t->dateTime('received_at');
            $t->char('dedupe_hash', 64)->unique();
            $t->char('key_id', 16);
            $t->longText('ciphertext');
            $t->longText('attachments')->nullable();
            $t->unsignedInteger('size')->default(0);
            $t->index('received_at');
        });
        if (!Schema::hasTable('pub_inbox_nonces')) Schema::create('pub_inbox_nonces', function (Blueprint $t) {
            $t->char('nonce', 32)->primary();
            $t->dateTime('seen_at')->index();
        });
        if (!Schema::hasTable('saan_inbox_done')) Schema::create('saan_inbox_done', function (Blueprint $t) {
            $t->char('dedupe_hash', 64)->primary();
            $t->string('kind', 32);
            $t->string('outcome', 40);
            $t->dateTime('handled_at');
            $t->string('ref', 80)->nullable();
        });
        if (!Schema::hasTable('saan_inbox_quarantine')) Schema::create('saan_inbox_quarantine', function (Blueprint $t) {
            $t->id();
            $t->char('envelope_hash', 64)->nullable();
            $t->string('kind', 40)->nullable();
            $t->string('reason', 190);
            $t->longText('payload')->nullable();
            $t->dateTime('created_at');
            $t->index('created_at');
        });
        if (!Schema::hasTable('saan_pairing_codes')) Schema::create('saan_pairing_codes', function (Blueprint $t) {
            $t->id();
            $t->char('code_hash', 64)->unique();
            $t->dateTime('expires_at');
            $t->dateTime('used_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->dateTime('created_at');
        });
    }

    public function down(): void
    {
        // Intentionally empty: a rollback must not drop envelopes that were never pulled.
    }
};
