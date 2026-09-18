<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

mkbp_json_response([
    'ok' => true,
    'service' => 'mini-kbp-php',
    'time' => date('c'),
]);
