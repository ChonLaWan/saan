<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// learning-links (M22): external learning resources per classroom / course and the short classroom access codes.
// No foreign keys on purpose: the module can be switched off with data kept and other modules own the referenced tables.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('lrn_links')) Schema::create('lrn_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('term_id')->nullable();
            $t->unsignedBigInteger('classroom_id')->nullable();
            $t->unsignedBigInteger('course_id')->nullable();
            $t->string('scope_key', 60); // c<classroom>|k<course>|t<term>: NULL columns do not collide in a unique index, this one does
            $t->string('title', 160);
            $t->string('url', 500);
            $t->char('url_hash', 40); // sha1 of the lower-cased url without trailing slash: duplicate check without relying on collation
            $t->string('kind', 10)->default('app');
            $t->unsignedBigInteger('api_client_id')->nullable();
            $t->unsignedInteger('sort')->default(0);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['school_id', 'scope_key', 'url_hash'], 'lrn_links_scope_url');
            $t->index(['school_id', 'classroom_id'], 'lrn_links_class');
            $t->index(['school_id', 'course_id'], 'lrn_links_course');
        });

        if (!Schema::hasTable('lrn_access')) Schema::create('lrn_access', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('classroom_id');
            $t->char('code_hash', 64); // HMAC of the code; the code itself is shown once and never stored
            $t->dateTime('expires_at');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['school_id', 'classroom_id'], 'lrn_access_class');
        });
    }

    public function down(): void
    {
        // keep-data policy: teachers' links and access rows are never dropped by a rollback or module removal
    }
};
