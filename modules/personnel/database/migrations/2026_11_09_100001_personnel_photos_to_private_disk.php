<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Photos published before the private copy existed live only on the public disk, which school:archive does not back up.
 * Copy every referenced derivative to the private disk (storage/app/private/personnel/...). Safe when nothing exists:
 * missing tables, empty paths, paths outside personnel/ and files that are gone are skipped; existing private copies are never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('per_profiles') || !Schema::hasColumn('per_profiles', 'photo_path')) return;
        $public = Storage::disk('public'); $private = Storage::disk('local');
        DB::table('per_profiles')->whereNotNull('photo_path')->orderBy('id')->select(['id', 'photo_path'])->each(function ($row) use ($public, $private) {
            $path = (string) $row->photo_path;
            if (!preg_match('~^personnel/[0-9]+/[A-Za-z0-9_.-]+$~D', $path) || str_contains($path, '..')) return;
            if ($private->exists($path) || !$public->exists($path)) return;
            // the local disk has throw=false: put() returns false on a full or unwritable disk. Fail the migration so it is retried instead of being recorded as done.
            if ($private->put($path, $public->get($path)) === false) throw new \RuntimeException('คัดลอกรูปบุคลากรไป private disk ไม่สำเร็จ: '.$path);
        });
    }

    public function down(): void
    {
        // the copies are harmless backups of live data; nothing to undo
    }
};