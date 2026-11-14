<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// web-cms F5a: news view counter. cms_post_views holds ONE aggregate row per post per Bangkok day (no visitor data of any kind) and is never projected
// (Projection FORBIDDEN): the public edition counts into it and sends cumulative per-day totals back through the sealed inbox (kind post_views).
// pub_popular_posts is the derived aggregate that the internal edition publishes (post id and three totals, never a per-visitor row). Expand-only.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cms_post_views')) Schema::create('cms_post_views', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            // No foreign key on purpose (same reasoning as cms_hub_downloads): the public edition deletes a withdrawn post's row and the unsent count must survive it.
            $t->unsignedBigInteger('post_id');
            $t->date('day'); // Asia/Bangkok calendar day; 1970-01-01 is the folded total of days older than the retention window
            $t->unsignedInteger('n')->default(0);
            $t->unsignedInteger('sent_n')->default(0); // public edition: the part of n already sent to the internal edition
            $t->timestamps();
            $t->unique(['post_id', 'day'], 'cms_post_views_day');
            $t->index(['school_id', 'day'], 'cms_post_views_window');
        });
        if (!Schema::hasTable('pub_popular_posts')) Schema::create('pub_popular_posts', function (Blueprint $t) {
            $t->unsignedBigInteger('post_id')->primary();
            $t->unsignedInteger('views_7')->default(0);
            $t->unsignedInteger('views_30')->default(0);
            $t->unsignedInteger('views_total')->default(0);
        });
    }

    public function down(): void
    {
        // Intentionally empty: counts must not be dropped by a rollback.
    }
};
