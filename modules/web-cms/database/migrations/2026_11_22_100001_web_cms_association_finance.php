<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// web-cms F5b: association pages and monthly trial-balance reports on top of the group hubs (F4). Expand-only.
//  cms_hubs.contact / committee     typed by the admin, projected (committee = JSON list of {name, role}: a few office holders, never a member register)
//  cms_hub_categories.rule          null | pdf (PDF files only) | monthly (PDF only, one document per month, listed by Thai fiscal year); projected
//  cms_hub_documents.period_*       month (1-12) and Buddhist-era year of a monthly document; projected
//  cms_hub_documents.superseded_at  set when a newer file replaced this one: the replaced file stays internal (the row is unpublished, so it never leaves); NOT projected
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('cms_hubs', 'contact')) Schema::table('cms_hubs', function (Blueprint $t) {
            $t->text('contact')->nullable();
            $t->text('committee')->nullable();
        });
        if (!Schema::hasColumn('cms_hub_categories', 'rule')) Schema::table('cms_hub_categories', function (Blueprint $t) {
            $t->string('rule', 10)->nullable();
        });
        if (!Schema::hasColumn('cms_hub_documents', 'period_month')) Schema::table('cms_hub_documents', function (Blueprint $t) {
            $t->unsignedTinyInteger('period_month')->nullable();
            $t->unsignedSmallInteger('period_year')->nullable();
            $t->timestamp('superseded_at')->nullable();
            $t->index(['category_id', 'period_year', 'period_month'], 'cms_hub_documents_period');
        });
    }

    public function down(): void
    {
        // Intentionally empty: documents must not be dropped by a rollback.
    }
};
