<?php

use App\Support\Saan\KeyCollation;
use Illuminate\Database\Migrations\Migration;

// con_projects.title_key: Thai titles that differ only in tone marks must not collide under utf8mb4_unicode_ci. Expand-only and idempotent (no-op when the column is already utf8mb4_bin).
return new class extends Migration {
    public function up(): void
    {
        KeyCollation::repair(['con_projects' => ['title_key']]);
    }

    public function down(): void
    {
        // keep-data policy: collations are never reverted
    }
};