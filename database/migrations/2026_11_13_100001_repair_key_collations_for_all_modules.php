<?php

use App\Support\Saan\KeyCollation;
use Illuminate\Database\Migrations\Migration;

// Key columns of modules installed after the 2026_11_10 core repair never got utf8mb4_bin; repair every known one now (Updater also repeats this after each migrate). Expand-only and idempotent (no-op when the column is already utf8mb4_bin).
return new class extends Migration {
    public function up(): void
    {
        KeyCollation::repair();
    }

    public function down(): void
    {
        // keep-data policy: collations are never reverted
    }
};