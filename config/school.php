<?php
return [
    'demo' => env('SCHOOL_DEMO', false),
    'local_only' => env('SCHOOL_LOCAL_ONLY', true),
    'term_id' => (int) env('SCHOOL_TERM_ID', 1),
    'starter_data' => env('SCHOOL_STARTER_DATA', is_file(storage_path('installed.json')) ? (json_decode(file_get_contents(storage_path('installed.json')),true)['mode'] ?? 'blank') : 'blank'),
    'school_id' => (int) env('SCHOOL_ID', 1),
];
