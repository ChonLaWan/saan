<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// S2 + S3: derived public tables (pub_certificates, pub_notice_rows, pub_learning_rooms, pub_active_modules), the receiver state
// (pub_sync_state) and the publisher queue (pub_outbox, pub_sent_rows). Every install gets all of them (same code, same schema);
// the internal edition fills pub_outbox/pub_sent_rows/pub_sync_state (last built seq), the public edition fills the pub_* data tables
// and pub_sync_state (last applied seq). No foreign keys on purpose: rows arrive from the other side in batches. Expand-only.
return new class extends Migration {
    public function up(): void
    {
        // masked certificate verification data (number + masked holder + survey title + issue date); never the full name or answers
        if (!Schema::hasTable('pub_certificates')) Schema::create('pub_certificates', function (Blueprint $t) {
            $t->string('number', 40)->primary();
            $t->string('holder_name', 160);
            $t->string('survey_title', 200);
            $t->dateTime('issued_at')->nullable();
        });
        // notice rows exactly as NoticeRepository::mask returned them (JSON cells), for published, unexpired lists only
        if (!Schema::hasTable('pub_notice_rows')) Schema::create('pub_notice_rows', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('list_id');
            $t->unsignedInteger('order_no');
            $t->text('cells');
            $t->index(['list_id', 'order_no']);
        });
        // classrooms that have an unexpired learning access code: id and name only
        if (!Schema::hasTable('pub_learning_rooms')) Schema::create('pub_learning_rooms', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('school_id');
            $t->string('name', 120);
        });
        // modules switched on in the internal edition; the public edition serves a module only when named here (deny by default)
        if (!Schema::hasTable('pub_active_modules')) Schema::create('pub_active_modules', function (Blueprint $t) {
            $t->string('module_id', 40)->primary();
        });
        // receiver: last applied sequence/hash per stream; publisher: last built sequence per stream
        if (!Schema::hasTable('pub_sync_state')) Schema::create('pub_sync_state', function (Blueprint $t) {
            $t->string('stream', 64)->primary();
            $t->unsignedBigInteger('last_seq')->default(0);
            $t->char('last_hash', 64)->nullable();
            $t->dateTime('applied_at')->nullable();
        });
        // publisher queue. payload NULL + sent_at NULL = "this stream changed, rebuild the diff" marker; payload set = a built batch
        if (!Schema::hasTable('pub_outbox')) Schema::create('pub_outbox', function (Blueprint $t) {
            $t->id();
            $t->string('stream', 64);
            $t->unsignedBigInteger('seq')->nullable();
            $t->string('op', 8); // upsert | delete | full
            $t->longText('payload')->nullable();
            $t->char('payload_hash', 64)->nullable();
            $t->dateTime('created_at');
            $t->dateTime('available_at')->nullable();
            $t->dateTime('sent_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->index(['sent_at', 'id']);
            $t->index(['stream', 'seq']);
        });
        // publisher: what the public side holds per stream and key (hash of the projected row), used to compute upserts and deletes
        if (!Schema::hasTable('pub_sent_rows')) Schema::create('pub_sent_rows', function (Blueprint $t) {
            $t->string('stream', 64);
            $t->string('row_key', 80);
            $t->char('row_hash', 64);
            $t->primary(['stream', 'row_key']);
        });
    }

    public function down(): void
    {
        // Intentionally empty: a rollback must not drop the queue or the published copy.
    }
};
