<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// documents (M20): registry books, documents, private files, routing. Expand-only.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('doc_books')) Schema::create('doc_books', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->string('kind', 20); // incoming|outgoing|order|announcement|memo
            $t->string('label', 120);
            $t->string('prefix', 40)->nullable();
            $t->unsignedSmallInteger('year_be');
            $t->unsignedInteger('next_no')->default(1);
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'kind', 'year_be'], 'doc_books_kind_year');
        });
        if (!Schema::hasTable('doc_documents')) Schema::create('doc_documents', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('book_id');
            $t->unsignedInteger('reg_no');
            $t->string('doc_no', 80)->nullable();
            $t->date('doc_date'); $t->date('received_on')->nullable(); $t->date('registered_on');
            $t->string('from_org', 200)->nullable(); $t->string('to_org', 200)->nullable();
            $t->string('subject', 300);
            $t->string('urgency', 12)->default('normal');
            $t->string('secrecy', 12)->default('normal');
            $t->text('summary')->nullable();
            $t->string('status', 12)->default('registered');
            $t->string('cancel_reason', 300)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['book_id', 'reg_no'], 'doc_documents_book_regno');
            $t->index(['school_id', 'registered_on'], 'doc_documents_registered');
            $t->index(['book_id', 'registered_on'], 'doc_documents_book_month'); // year/month filter and the monthly print
            $t->index(['school_id', 'doc_no'], 'doc_documents_docno');
            // NOTE: the subject/from_org indexes below only serve prefix lookups. list() searches LIKE '%term%', which cannot use them;
            // at school scale (thousands of rows per school_id) that is accepted. Real full-text would be an expand-only FULLTEXT ngram migration on MySQL.
            $t->index(['school_id', 'subject'], 'doc_documents_subject');
            $t->index(['school_id', 'from_org'], 'doc_documents_from');
            $t->index(['school_id', 'status'], 'doc_documents_status');
        });
        if (!Schema::hasTable('doc_files')) Schema::create('doc_files', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('document_id');
            $t->string('path', 255); $t->string('original_name', 255); $t->string('mime', 100);
            $t->unsignedBigInteger('size'); $t->string('sha256', 64);
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->index(['school_id', 'document_id'], 'doc_files_document');
        });
        if (!Schema::hasTable('doc_routes')) Schema::create('doc_routes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('document_id');
            $t->unsignedBigInteger('to_user_id'); $t->string('note', 300)->nullable();
            $t->unsignedBigInteger('routed_by')->nullable();
            $t->timestamp('read_at')->nullable(); $t->timestamp('acknowledged_at')->nullable();
            $t->string('action_note', 500)->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['document_id', 'to_user_id'], 'doc_routes_doc_user');
            $t->index(['school_id', 'to_user_id', 'read_at'], 'doc_routes_inbox');
        });
    }

    public function down(): void
    {
        // Intentionally empty: the document registry (official records) must not be dropped by a rollback.
    }
};
