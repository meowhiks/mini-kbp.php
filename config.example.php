<?php
declare(strict_types=1);

/**
 * Copy to config.php next to src/ (project root for Docker, docroot for FTP).
 * Values here override environment variables when set.
 */
return [
    // Europe/Minsk by default
    'APP_TZ' => 'Europe/Minsk',

    // Absolute path preferred on shared hosting, e.g. __DIR__ . '/cache'
    'APP_CACHE_DIR' => __DIR__ . '/cache',

    'KBP_BASE_URL' => 'https://kbp.by/rasp/timetable/view_beta_kbp/',

    // Full names for subjects/teachers (JSON API)
    'RASP_KBP_API_URL' => 'https://rasp.kbp.by',

    // Optional: secret for /api/notify-test.php (leave empty to disable key auth path)
    // Prefer env: NOTIFY_TEST_SECRET=...
    'NOTIFY_TEST_SECRET' => '',
];
