<?php

use App\Support\Saan\KeyCollation;
use Illuminate\Database\Migrations\Migration;

// prj_projects.code_key: codes that differ only in accents or tone marks must not collide under utf8mb4_unicode_ci. Expand-only and idempotent (no-op when the column is already utf8mb4_bin).
return new class extends Migration {
    public function up(): void
    {
        KeyCollation::repair(['prj_projects' => ['code_key']]);
    }

    public function down(): void
    {
        // keep-data policy: collations are never reverted
    }
};