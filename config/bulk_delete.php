<?php

return [
    // Number of ids processed per transaction chunk
    'chunk_size' => env('BULK_DELETE_CHUNK_SIZE', 100),

    // Above this number the operation will be queued unless force sync is allowed
    'queue_threshold' => env('BULK_DELETE_QUEUE_THRESHOLD', 500),

    // Maximum number of ids allowed for a forced synchronous delete
    'force_sync_max' => env('BULK_DELETE_FORCE_SYNC_MAX', 2000),
];
