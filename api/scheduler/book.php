<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/scheduler-lib.php';

scheduler_require_method('POST');


function mmit_mark_estimate_scheduled(array $data): void
{
    $email = trim((string)($data['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $secretsPath = '/home/mjrmstlj/private/mmit-secrets.php';
    if (!is_file($secretsPath)) {
        return;
    }

    try {
        $secrets = require $secretsPath;
    } catch (Throwable $e) {
        error_log('MMIT scheduler Brevo secret load failed: ' . $e->getMessage());
        return;
    }

    $apiKey = (string)($secrets['brevo']['api_key'] ?? '');
    if ($apiKey === '') {
        return;
    }

    $attributes = [
        'NEXT_STEP' => 'SCHEDULE_CHAT',
        'ESTIMATE_STATUS' => 'SCHEDULED',
    ];
    $payload = [
        'email' => $email,
        'attributes' => $attributes,
        'updateEnabled' => true,
    ];

    $ch = curl_init('https://api.brevo.com/v3/contacts');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'api-key: ' . $apiKey,
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || $status >= 400) {
        error_log('MMIT scheduler Brevo status update failed: ' . ($error ?: ('HTTP ' . $status . ' ' . (string)$response)));
    }
}

try {
    $config = scheduler_load_config();
    if (!scheduler_is_configured($config)) {
        scheduler_json_response([
            'ok' => false,
            'configured' => false,
            'message' => 'Live scheduler config is not ready yet.',
        ], 503);
    }

    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) {
        scheduler_json_response(['ok' => false, 'message' => 'Invalid request payload.'], 400);
    }

    foreach (['name', 'company', 'email', 'interest', 'painPoints', 'requestedSlotIso'] as $required) {
        if (empty($data[$required]) || trim((string)$data[$required]) === '') {
            scheduler_json_response(['ok' => false, 'message' => 'Please complete the required fields and choose a time.'], 422);
        }
    }

    if (!filter_var((string)$data['email'], FILTER_VALIDATE_EMAIL)) {
        scheduler_json_response(['ok' => false, 'message' => 'Please enter a valid email address.'], 422);
    }

    $availability = scheduler_build_availability($config, 14);
    $match = scheduler_find_slot($availability, (string)$data['requestedSlotIso']);
    if (!$match) {
        scheduler_json_response(['ok' => false, 'message' => 'That time is no longer open. Please choose another slot and try again.'], 409);
    }

    $result = scheduler_graph_request(
        $config,
        'POST',
        '/users/' . rawurlencode((string)$config['calendar_user']) . '/calendar/events',
        [],
        scheduler_event_payload($config, $data, (string)$data['requestedSlotIso'])
    );

    mmit_mark_estimate_scheduled($data);

    scheduler_json_response([
        'ok' => true,
        'configured' => true,
        'message' => 'Your chat was booked successfully.',
        'slotLabel' => trim((string)($data['requestedSlotLabel'] ?? (($match['day']['longLabel'] ?? '') . ' at ' . ($match['slot']['label'] ?? '')))),
        'eventId' => (string)($result['id'] ?? ''),
        'webLink' => (string)($result['webLink'] ?? ''),
        'joinUrl' => (string)($result['onlineMeeting']['joinUrl'] ?? ''),
    ]);
} catch (Throwable $e) {
    scheduler_json_response(['ok' => false, 'message' => $e->getMessage()], 500);
}
