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
            'message' => 'Live scheduler config is not in place yet.',
            'next_step' => 'Add your local scheduler config and grant Graph permissions before testing live booking.',
        ]);
    }

    scheduler_json_response(scheduler_status_snapshot($config));
} catch (Throwable $e) {
    scheduler_json_response([
        'ok' => false,
        'configured' => true,
        'live_booking_ready' => false,
        'message' => $e->getMessage(),
    ], 500);
}
