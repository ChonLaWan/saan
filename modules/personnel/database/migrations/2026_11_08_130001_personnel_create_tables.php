<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// personnel: staff profiles (directory source), portfolio folders and private portfolio files. Expand-only.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('per_profiles')) Schema::create('per_profiles', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('teacher_id');
            $t->string('position', 120)->nullable(); $t->string('academic_standing', 120)->nullable();
            $t->string('department', 120)->nullable(); $t->string('work_group', 120)->nullable();
            $t->date('start_date')->nullable(); $t->json('education')->nullable();
            $t->string('photo_path', 255)->nullable(); // public-safe derivative only (re-encoded square JPEG, no EXIF)
            $t->boolean('show_in_directory')->default(true); $t->integer('directory_order')->default(0);
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'teacher_id'], 'per_profiles_teacher');
            $t->index(['school_id', 'show_in_directory', 'directory_order'], 'per_profiles_dir');
        });
        if (!Schema::hasTable('per_folders')) Schema::create('per_folders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->string('code', 40); $t->string('label', 160); $t->string('label_key', 190);
            $t->unsignedSmallInteger('retention_years')->nullable();
            $t->unsignedInteger('max_file_mb')->default(20);
            $t->unsignedInteger('quota_mb_per_teacher')->nullable();
            $t->unsignedInteger('sort')->default(0);
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'code'], 'per_folders_code');
            $t->unique(['school_id', 'label_key'], 'per_folders_label');
        });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) // uniqueness must not depend on the server collation
            DB::statement('ALTER TABLE per_folders MODIFY label_key VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        if (!Schema::hasTable('per_files')) Schema::create('per_files', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('teacher_id'); $t->unsignedBigInteger('folder_id');
            $t->unsignedSmallInteger('fiscal_or_school_year'); // Buddhist era
            $t->string('original_name', 200); $t->string('path', 255); $t->string('mime', 120);
            $t->unsignedBigInteger('size'); $t->char('sha256', 64);
            $t->char('dedupe_key', 64)->nullable(); // = sha256 while the file is not deleted; unique per teacher, so a race cannot store a duplicate
            $t->string('status', 10)->default('active'); // active | archived | deleted
            $t->string('review_status', 10)->default('pending'); // pending | approved | revise
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable(); $t->timestamp('reviewed_at')->nullable(); $t->string('review_note', 1000)->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->index(['school_id', 'teacher_id', 'folder_id', 'fiscal_or_school_year'], 'per_files_lookup');
            $t->index(['school_id', 'status', 'created_at'], 'per_files_status');
            $t->unique(['school_id', 'teacher_id', 'dedupe_key'], 'per_files_dedupe');
        });
    }

    public function down(): void
    {
        // Intentionally empty: personnel records and the files on disk must not be orphaned by a rollback.
    }
};
