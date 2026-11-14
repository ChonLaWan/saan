<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// web-notices: optional applicant / seat number column (the only thing a visitor may search by number in search-only lists). Expand-only.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('notice_rows', 'applicant_no')) Schema::table('notice_rows', function (Blueprint $t) {
            $t->string('applicant_no', 20)->nullable();
        });
    }

    public function down(): void
    {
        // Intentionally empty: a rollback must not silently drop notice data.
    }
};
