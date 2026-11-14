<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand-only, safe to run again: indexes for the queries that grow with the school's history.
 * - audit_events: leave/entity history looks rows up by (school_id, entity, entity_id); the audit viewer pages by (school_id, id).
 *   Retention of audit rows is a school policy decision and is intentionally not automated here.
 * - posts: (school_id, status, deleted_at, version) lets the public media fingerprint (COUNT/SUM/MAX over published rows) run on the index alone;
 *   (school_id, content_type, status, deleted_at, published_at) serves the homepage news, notice, album and event blocks and the section lists.
 */
return new class extends Migration {
    private const INDEXES = [
        'audit_events' => [
            'audit_events_entity_lookup' => ['school_id', 'entity', 'entity_id', 'id'],
            'audit_events_school_id_id' => ['school_id', 'id'],
        ],
        'posts' => [
            'posts_publication_fingerprint' => ['school_id', 'status', 'deleted_at', 'version'],
            'posts_public_listing' => ['school_id', 'content_type', 'status', 'deleted_at', 'published_at'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (!Schema::hasTable($table)) continue;
            foreach ($indexes as $name => $columns) {
                if (array_filter($columns, fn ($c) => !Schema::hasColumn($table, $c))) continue;
                if (Schema::hasIndex($table, $name)) continue;
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (!Schema::hasTable($table)) continue;
            foreach (array_keys($indexes) as $name) if (Schema::hasIndex($table, $name)) Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
        }
    }
};
