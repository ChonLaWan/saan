<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CMS-001 / N9: posts.body was TEXT (65,535 bytes on MySQL/MariaDB) while the editor accepts 50,000 characters;
// Thai text is 3 bytes per character, so long Thai posts were cut or rejected. Expand-only: no down migration.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $t) { $t->longText('body')->change(); });
    }

    public function down(): void
    {
        // Intentionally empty: shrinking the column could destroy content.
    }
};
