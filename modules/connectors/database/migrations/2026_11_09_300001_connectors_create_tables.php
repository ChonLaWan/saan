<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// connectors: read-only archives of old school years. Nothing here is ever joined into or copied to live tables.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('con_archives')) {
            Schema::create('con_archives', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->string('label', 120);
                $t->string('label_key', 120); // mb_strtolower + trim + collapsed spaces; uniqueness is checked in code, not by collation
                $t->unsignedSmallInteger('year_be');
                $t->string('source', 200)->nullable();
                $t->string('status', 16)->default('ready');
                $t->boolean('locked')->default(false);
                $t->unsignedInteger('row_count')->default(0);
                $t->timestamp('imported_at')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->unique(['school_id', 'label_key'], 'con_archives_school_label');
                $t->index(['school_id', 'year_be'], 'con_archives_school_year');
            });
        }
        if (!Schema::hasTable('con_archive_files')) {
            Schema::create('con_archive_files', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('archive_id');
                $t->string('kind', 24);
                $t->string('filename', 200);
                $t->string('sha256', 64);
                $t->string('preset', 24);
                $t->unsignedInteger('row_count')->default(0);
                $t->unsignedInteger('skipped')->default(0);
                $t->unsignedBigInteger('imported_by')->nullable();
                $t->timestamp('imported_at')->nullable();
                $t->timestamps();
                $t->unique(['archive_id', 'kind', 'sha256'], 'con_files_archive_kind_sha');
                $t->index(['school_id', 'archive_id'], 'con_files_school_archive');
            });
        }
        if (!Schema::hasTable('con_rows')) {
            Schema::create('con_rows', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('archive_id');
                $t->unsignedBigInteger('file_id')->nullable();
                $t->string('kind', 24);
                $t->string('key1', 80);
                $t->string('key2', 80)->nullable();
                $t->string('name_key', 200)->nullable();
                $t->longText('data');
                $t->index(['archive_id', 'kind', 'key1'], 'con_rows_archive_kind_key');
                $t->index(['school_id', 'key1'], 'con_rows_school_key');
                $t->index(['school_id', 'name_key'], 'con_rows_school_name');
                $t->index('file_id', 'con_rows_file');
            });
        }
        if (!Schema::hasTable('con_mapping_runs')) {
            Schema::create('con_mapping_runs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('registry_batch_id');
                $t->string('filename', 200);
                $t->string('preset', 24);
                $t->unsignedInteger('row_count')->default(0);
                $t->string('status', 16)->default('previewed');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->index(['school_id', 'registry_batch_id'], 'con_runs_school_batch');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: archived school records must not be dropped by a rollback.
    }
};
