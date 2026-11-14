<?php

// Public projection of the CORE website tables (S1 declaration; the publisher in S3 reads this, nothing is exported yet).
// Core is not a module, so its declaration lives here; modules declare theirs in module.json ("public_projection").
// Format and rules: App\Support\Saan\Projection. Deny by default: a table or column that is not listed here is never exported,
// and App\Support\Saan\Projection::forbiddenTables() can never be listed at all (users, students, finance, health, ...).
//
// Rules per column: keep | null | const:<value> | mask_name. People and authorship are never kept: author and uploader point at the
// placeholder account (id 1, no password) that the public database is seeded with, drafts and schedulers are cut.
//
// Menus, hero slides, presentation (colours, fonts, header, footer) and the observance/mourning settings (popup, ribbon) are stored
// inside the published JSON of cms_site_settings / cms_themes, so one row copy covers them; the draft copy is replaced by an empty object.
// Hero banners are posts with content_type banner and travel with posts.

return [
    [
        'table' => 'posts',
        'where' => ['status' => 'published', 'deleted_at' => null],
        'columns' => [
            'id' => 'keep', 'school_id' => 'keep', 'title' => 'keep', 'body' => 'keep', 'status' => 'keep', 'published_at' => 'keep',
            'created_at' => 'keep', 'updated_at' => 'keep', 'slug' => 'keep', 'excerpt' => 'keep', 'category' => 'keep',
            'cover_media_id' => 'keep', 'is_pinned' => 'keep', 'version' => 'keep', 'content_type' => 'keep', 'metadata' => 'keep',
            'author_id' => 'const:1', 'draft_payload' => 'null', 'scheduled_at' => 'null', 'scheduled_by' => 'null',
        ],
    ],
    [
        'table' => 'cms_categories',
        'filter' => 'public_categories',
        'columns' => ['id' => 'keep', 'school_id' => 'keep', 'slug' => 'keep', 'label' => 'keep', 'version' => 'keep', 'created_at' => 'keep', 'updated_at' => 'keep'],
    ],
    [
        'table' => 'cms_media',
        'filter' => 'published_media',
        'columns' => [
            'id' => 'keep', 'school_id' => 'keep', 'uploaded_by' => 'const:1', 'path' => 'keep', 'mime' => 'keep', 'bytes' => 'keep',
            'width' => 'keep', 'height' => 'keep', 'alt' => 'keep', 'original_name' => 'const:', 'folder' => 'const:ทั่วไป',
            'version' => 'keep', 'source_media_id' => 'null', 'created_at' => 'keep', 'updated_at' => 'keep',
        ],
    ],
    [
        'table' => 'cms_site_settings',
        'columns' => [
            'id' => 'keep', 'school_id' => 'keep', 'draft' => 'const:{}', 'published' => 'keep', 'version' => 'keep',
            'published_version' => 'keep', 'created_at' => 'keep', 'updated_at' => 'keep',
        ],
    ],
    [
        // Module switches only (deny by default: Projection::SETTINGS_KEY_WHITELIST). The public edition must not serve a module the school switched off.
        'table' => 'saan_settings',
        'where' => ['key' => ['op' => 'in', 'value' => ['modules.enabled', 'modules.disabled']]],
        'columns' => ['id' => 'keep', 'school_id' => 'keep', 'key' => 'keep', 'value' => 'keep', 'version' => 'keep', 'created_at' => 'keep', 'updated_at' => 'keep'],
    ],
    [
        'table' => 'cms_themes',
        'columns' => [
            'id' => 'keep', 'school_id' => 'keep', 'draft' => 'const:{}', 'published' => 'keep', 'version' => 'keep',
            'published_version' => 'keep', 'created_at' => 'keep', 'updated_at' => 'keep',
        ],
    ],
];
