<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// maintenance (M21): repair requests, asset register (ครุภัณฑ์), consumable stock. Expand-only, re-runnable.
return new class extends Migration {
    private function bin(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function keyColumn(Blueprint $t, string $col, int $len)
    {
        $c = $t->string($col, $len);
        if ($this->bin()) $c->collation('utf8mb4_bin'); // never let the DB fold case on its own; the key is normalised in code
        return $c;
    }

    public function up(): void
    {
        if (!Schema::hasTable('mnt_assets')) Schema::create('mnt_assets', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->string('asset_code', 80);
            $this->keyColumn($t, 'asset_code_key', 80);
            $t->string('name', 200);
            $t->string('category', 80)->nullable();
            $t->string('location', 160)->nullable();
            $t->date('acquired_on')->nullable();
            $t->unsignedBigInteger('price_satang')->nullable();
            $t->string('status', 12)->default('in_use'); // in_use|repair|disposed
            $t->unsignedBigInteger('responsible_teacher_id')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'asset_code_key'], 'mnt_assets_code_key');
            $t->index(['school_id', 'status'], 'mnt_assets_status');
            $t->index(['school_id', 'category'], 'mnt_assets_category');
        });
        if (!Schema::hasTable('mnt_requests')) Schema::create('mnt_requests', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('asset_id')->nullable();
            $t->string('location', 160);
            $t->string('title', 200);
            $t->text('detail')->nullable();
            $t->string('urgency', 10)->default('normal'); // normal|urgent
            $t->string('photo_path', 255)->nullable(); // PRIVATE disk, generated ASCII name
            $t->string('photo_name', 200)->nullable();
            $t->string('photo_mime', 60)->nullable();
            $t->unsignedBigInteger('photo_size')->nullable();
            $t->unsignedBigInteger('reported_by');
            $t->string('status', 14)->default('new');
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->text('resolution')->nullable();
            $t->unsignedBigInteger('cost_satang')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->index(['school_id', 'status'], 'mnt_requests_status');
            $t->index(['school_id', 'reported_by'], 'mnt_requests_reporter');
            $t->index(['school_id', 'asset_id'], 'mnt_requests_asset');
            $t->index(['school_id', 'closed_at'], 'mnt_requests_closed');
        });
        if (!Schema::hasTable('mnt_request_logs')) Schema::create('mnt_request_logs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('request_id');
            $t->string('from_status', 14)->nullable(); $t->string('to_status', 14);
            $t->string('note', 1000)->nullable();
            $t->unsignedBigInteger('by')->nullable();
            $t->timestamp('at')->nullable();
            $t->index(['school_id', 'request_id'], 'mnt_logs_request');
        });
        if (!Schema::hasTable('mnt_stock_items')) Schema::create('mnt_stock_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->string('code', 60);
            $this->keyColumn($t, 'code_key', 60);
            $t->string('name', 200);
            $t->string('unit', 30);
            $t->integer('qty_on_hand')->default(0);
            $t->integer('reorder_level')->default(0);
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'code_key'], 'mnt_stock_code_key');
        });
        if (!Schema::hasTable('mnt_stock_moves')) Schema::create('mnt_stock_moves', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('item_id');
            $t->string('kind', 8); // in|out|adjust (adjust stores a signed delta)
            $t->integer('qty');
            $t->string('ref', 120)->nullable();
            $t->unsignedBigInteger('by')->nullable();
            $t->timestamp('at')->nullable();
            $t->string('note', 300)->nullable();
            $t->index(['school_id', 'item_id'], 'mnt_moves_item');
        });
    }

    public function down(): void
    {
        // keep-data policy: nothing is dropped
    }
};
