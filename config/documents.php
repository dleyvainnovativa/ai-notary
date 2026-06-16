<?php

return [
    'max_size_bytes' => 20 * 1024 * 1024, // 20 MB
    'max_pages' => 50,
    'accepted_mimes' => [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
    ],
    'temp_disk' => 'local',          // storage/app — NOT public
    'temp_dir' => 'temp_uploads',
    'temp_ttl_minutes' => 15,        // fail-safe sweep threshold
];
