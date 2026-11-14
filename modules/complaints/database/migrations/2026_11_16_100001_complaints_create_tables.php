<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// complaints (F1): cases, action log, internal notes, attachments, accused staff, annual report snapshots (all internal, prefix cmp_, never exported)
// and the two derived public tables (pub_complaint_status, pub_complaint_reports) that the public edition reads.
// No foreign keys on purpose: the module can be switched off with data kept (keep-data policy) and users/files are owned elsewhere. Expand-only.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cmp_cases')) Schema::create('cmp_cases', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->string('receipt_no', 24); // made by the public form (C<BE yy>-<8 chars>); unique per school
            $t->char('track_hash', 64); // HMAC(receipt|tracking code) keyed with HashKey::publicUnlock; the tracking code itself is never stored
            $t->char('envelope_hash', 64)->nullable()->unique(); // the sealed envelope that created the case: a pulled envelope creates one case at most
            $t->string('category', 20);
            $t->boolean('anonymous')->default(false);
            $t->text('contact')->nullable(); // encrypted JSON {name,email,phone}; NULL when anonymous
            $t->string('subject', 200);
            $t->text('detail');
            $t->string('status', 20)->default('received');
            $t->unsignedBigInteger('assignee_id')->nullable();
            $t->date('due_on');
            $t->dateTime('received_at');
            $t->dateTime('status_changed_at'); // what the status page shows as "updated": internal work (assignment, notes) does not move it
            $t->dateTime('closed_at')->nullable();
            $t->text('public_reply')->nullable();
            $t->dateTime('public_reply_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['school_id', 'receipt_no'], 'cmp_cases_receipt');
            $t->index(['school_id', 'status'], 'cmp_cases_status');
            $t->index(['school_id', 'category'], 'cmp_cases_category');
            $t->index(['school_id', 'due_on'], 'cmp_cases_due');
            $t->index(['school_id', 'received_at'], 'cmp_cases_received');
        });

        if (!Schema::hasTable('cmp_actions')) Schema::create('cmp_actions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('case_id');
            $t->unsignedBigInteger('actor_id')->nullable(); // NULL = the system (intake)
            $t->string('action', 40);
            $t->text('detail')->nullable(); // codes and ids only, never text of the complaint
            $t->dateTime('created_at');
            $t->index(['case_id', 'id'], 'cmp_actions_case');
        });

        if (!Schema::hasTable('cmp_notes')) Schema::create('cmp_notes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('case_id');
            $t->unsignedBigInteger('user_id');
            $t->text('body');
            $t->dateTime('created_at');
            $t->index(['case_id', 'id'], 'cmp_notes_case');
        });

        if (!Schema::hasTable('cmp_files')) Schema::create('cmp_files', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('case_id');
            $t->string('name', 160);
            $t->string('path', 190); // private disk, complaints/<school>/<random>.<ext>; only an authorised controller serves it
            $t->string('mime', 80);
            $t->unsignedBigInteger('size');
            $t->char('sha256', 64);
            $t->dateTime('created_at');
            $t->index(['case_id', 'id'], 'cmp_files_case');
        });

        if (!Schema::hasTable('cmp_accused')) Schema::create('cmp_accused', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('case_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedInteger('version')->default(1);
            $t->dateTime('created_at');
            $t->unique(['case_id', 'user_id'], 'cmp_accused_pair');
            $t->index('user_id', 'cmp_accused_user');
        });

        if (!Schema::hasTable('cmp_reports')) Schema::create('cmp_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedSmallInteger('fiscal_year'); // Buddhist era
            $t->longText('snapshot'); // counts only: no names, contact, subjects or details
            $t->string('status', 12)->default('draft'); // draft | published
            $t->dateTime('published_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['school_id', 'fiscal_year'], 'cmp_reports_year');
        });

        // public edition: status of a case, found by receipt number and verified with the HMAC of the tracking code. No names, contact, detail, assignee or notes.
        if (!Schema::hasTable('pub_complaint_status')) Schema::create('pub_complaint_status', function (Blueprint $t) {
            $t->string('receipt_no', 24)->primary();
            $t->char('track_hash', 64);
            $t->string('category_label', 80);
            $t->string('status', 20);
            $t->dateTime('received_at');
            $t->dateTime('updated_at');
            $t->text('public_reply')->nullable();
        });

        if (!Schema::hasTable('pub_complaint_reports')) Schema::create('pub_complaint_reports', function (Blueprint $t) {
            $t->string('fiscal_year', 8)->primary();
            $t->longText('snapshot');
            $t->dateTime('published_at')->nullable();
        });
    }

    public function down(): void
    {
        // keep-data policy: complaints are never dropped by a rollback or module removal
    }
};
