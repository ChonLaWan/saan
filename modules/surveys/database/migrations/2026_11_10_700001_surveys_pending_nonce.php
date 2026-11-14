<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// surveys: random nonce regenerated on every (re)submit of a pending e-mail response, bound into the signed confirm link,
// so an older link stops working as soon as the pending content is replaced. Expand-only (nullable: old rows stay valid).
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('srv_pending') && !Schema::hasColumn('srv_pending', 'nonce')) {
            Schema::table('srv_pending', function (Blueprint $t) {
                $t->char('nonce', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: expand-only.
    }
};
