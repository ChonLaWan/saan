<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// web-cms F4: group hubs (work group / subject group pages), their document categories, documents and per-day download counts. Expand-only.
// cms_hubs / cms_hub_categories / cms_hub_documents are projected to the public edition (module.json); cms_hub_downloads never is (deny by default):
// the public side counts into it and sends cumulative per-day totals back through the sealed inbox (kind hub_downloads).
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cms_hubs')) Schema::create('cms_hubs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->string('slug', 80); $t->string('title', 160);
            $t->string('kind', 10)->default('work'); // work (กลุ่มงาน) | subject (กลุ่มสาระ)
            $t->string('group_name', 120)->nullable(); // matches per_profiles.work_group or department (personnel has no group entity)
            $t->text('description')->nullable();
            $t->string('news_category', 30)->nullable(); // cms_categories.slug whose news belong to the hub
            $t->string('news_tag', 40)->nullable(); // posts.metadata.tags value that marks news of the hub
            $t->unsignedSmallInteger('news_limit')->default(6);
            $t->unsignedSmallInteger('box_limit')->default(5);
            $t->integer('sort')->default(0);
            $t->string('status', 10)->default('draft'); // draft | published
            $t->text('internal_note')->nullable(); // never projected
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'slug'], 'cms_hubs_slug');
            $t->index(['school_id', 'status', 'sort'], 'cms_hubs_list');
        });
        if (!Schema::hasTable('cms_hub_categories')) Schema::create('cms_hub_categories', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->foreignId('hub_id')->constrained('cms_hubs')->cascadeOnDelete();
            $t->unsignedBigInteger('parent_id')->nullable(); // one level of sub-categories; no foreign key (a self reference would make batch order matter on MariaDB)
            $t->string('label', 120); $t->integer('sort')->default(0); $t->boolean('is_hidden')->default(false);
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->index(['hub_id', 'sort'], 'cms_hub_categories_list');
        });
        if (!Schema::hasTable('cms_hub_documents')) Schema::create('cms_hub_documents', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->foreignId('hub_id')->constrained('cms_hubs')->cascadeOnDelete();
            $t->foreignId('category_id')->constrained('cms_hub_categories')->cascadeOnDelete();
            $t->string('title', 200); $t->date('doc_date');
            $t->foreignId('media_id')->nullable()->constrained('cms_media'); // the file lives in the media library (finfo checked); guests reach it only through /portal/d/{id}
            $t->string('file_name', 200)->nullable(); // public download name (cms_media.original_name never leaves)
            $t->string('external_url', 500)->nullable(); // https link, used when there is no file
            $t->boolean('is_published')->default(false);
            $t->integer('sort')->default(0); // higher first (pinned); then newest date
            $t->text('note')->nullable(); // never projected
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->index(['hub_id', 'category_id', 'is_published', 'doc_date'], 'cms_hub_documents_list');
        });
        if (!Schema::hasTable('cms_hub_downloads')) Schema::create('cms_hub_downloads', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            // No foreign key on purpose: the public edition deletes a withdrawn document's row (Receiver::deleteKeys) and the day's unsent count must survive that,
            // so a republished document continues the same cumulative total. The internal edition deletes the counts itself (HubAdmin::deleteDocument/deleteHub).
            $t->unsignedBigInteger('document_id');
            $t->date('day'); // Asia/Bangkok calendar day: one aggregate row per document per day, no visitor data of any kind
            $t->unsignedInteger('n')->default(0);
            $t->unsignedInteger('sent_n')->default(0); // public edition: the part of n already sent to the internal edition
            $t->timestamps();
            $t->unique(['document_id', 'day'], 'cms_hub_downloads_day');
        });
    }

    public function down(): void
    {
        // Intentionally empty: documents and counts must not be dropped by a rollback.
    }
};
