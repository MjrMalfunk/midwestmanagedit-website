<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/scheduler-lib.php';

scheduler_require_method('GET');

try {
    $config = scheduler_load_config();
    if (!scheduler_is_configured($config)) {
        scheduler_json_response([
            'ok' => false,
            'configured' => false,
            'fallback' => true,
            'message' => 'Live scheduler config is not in place yet.',
        ], 200);
    }

    $days = isset($_GET['days']) ? max(1, min(21, (int)$_GET['days'])) : 14;
    scheduler_json_response(scheduler_build_availability($config, $days));
} catch (Throwable $e) {
    scheduler_json_response([
        'ok' => false,
        'configured' => true,
        'fallback' => true,
        'message' => $e->getMessage(),
    ], 500);
}
