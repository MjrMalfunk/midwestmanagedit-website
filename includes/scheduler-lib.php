<?php
declare(strict_types=1);

function scheduler_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function scheduler_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($method)) {
        scheduler_json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }
}

function scheduler_client_ip(): string
{
    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        $first = trim((string)($parts[0] ?? ''));
        if ($first !== '') {
            return $first;
        }
    }
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function scheduler_rate_limit_guard(string $scope, int $maxRequests, int $windowSeconds): bool
{
    $key = hash('sha256', $scope . '|' . scheduler_client_ip());
    $file = sys_get_temp_dir() . '/mmit-rate-' . $key . '.json';
    $now = time();
    $state = ['window_start' => $now, 'count' => 0];

    if (is_file($file)) {
        $decoded = json_decode((string)file_get_contents($file), true);
        if (is_array($decoded) && isset($decoded['window_start'], $decoded['count'])) {
            $state = $decoded;
        }
    }

    if (($now - (int)$state['window_start']) >= $windowSeconds) {
        $state = ['window_start' => $now, 'count' => 0];
    }

    $state['count'] = (int)$state['count'] + 1;
    @file_put_contents($file, json_encode($state), LOCK_EX);

    return (int)$state['count'] <= $maxRequests;
}

function scheduler_load_config(): ?array
{
    $candidates = [];
    $envPath = getenv('MMIT_SCHEDULER_CONFIG');
    if (is_string($envPath) && trim($envPath) !== '') {
        $candidates[] = trim($envPath);
    }

    $candidates[] = dirname(__DIR__) . '/api/scheduler/config.local.php';
    $candidates[] = dirname(__DIR__) . '/private/mmit-scheduler.php';
    $candidates[] = dirname(__DIR__, 2) . '/private/mmit-scheduler.php';

    foreach ($candidates as $path) {
        if (is_file($path)) {
            $config = require $path;
            return is_array($config) ? $config : null;
        }
    }

    return null;
}

function scheduler_is_configured(?array $config): bool
{
    if (!$config) return false;
    foreach (['provider', 'calendar_user', 'tenant_id', 'client_id', 'client_secret', 'timezone', 'graph_timezone'] as $required) {
        if (!isset($config[$required]) || trim((string)$config[$required]) === '' || str_contains((string)$config[$required], 'YOUR_')) {
            return false;
        }
    }
    return ($config['provider'] ?? '') === 'm365';
}

function scheduler_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
}

function scheduler_get_token(array $config): string
{
    $url = 'https://login.microsoftonline.com/' . rawurlencode((string)$config['tenant_id']) . '/oauth2/v2.0/token';
    $payload = http_build_query([
        'client_id' => (string)$config['client_id'],
        'client_secret' => (string)$config['client_secret'],
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($errno !== 0) {
        throw new RuntimeException('Unable to reach Microsoft identity platform: ' . $error);
    }
    $data = json_decode((string)$raw, true);
    if ($status >= 400 || !is_array($data) || empty($data['access_token'])) {
        $message = is_array($data) ? ($data['error_description'] ?? $data['error'] ?? 'Token request failed.') : 'Token request failed.';
        throw new RuntimeException((string)$message);
    }
    return (string)$data['access_token'];
}

function scheduler_graph_request(array $config, string $method, string $path, array $query = [], ?array $body = null, array $extraHeaders = []): array
{
    $token = scheduler_get_token($config);
    $url = 'https://graph.microsoft.com/v1.0' . $path . ($query ? ('?' . http_build_query($query)) : '');
    $headers = array_merge([
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ], $extraHeaders);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 45,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($errno !== 0) {
        throw new RuntimeException('Microsoft Graph request failed: ' . $error);
    }
    $data = json_decode((string)$raw, true);
    if ($status >= 400) {
        $message = 'Microsoft Graph request failed.';
        if (is_array($data)) {
            $message = (string)($data['error']['message'] ?? $data['error_description'] ?? $message);
        }
        throw new RuntimeException($message);
    }
    return is_array($data) ? $data : [];
}

function scheduler_overlaps(DateTimeImmutable $start, DateTimeImmutable $end, array $intervals, int $bufferMinutes = 0): bool
{
    foreach ($intervals as $interval) {
        $busyStart = $interval['start'];
        $busyEnd = $interval['end'];
        if ($bufferMinutes > 0) {
            $busyStart = $busyStart->modify(sprintf('-%d minutes', $bufferMinutes));
            $busyEnd = $busyEnd->modify(sprintf('+%d minutes', $bufferMinutes));
        }
        if ($start < $busyEnd && $end > $busyStart) {
            return true;
        }
    }
    return false;
}

function scheduler_build_availability(array $config, int $requestedDays = 14): array
{
    $tz = new DateTimeZone((string)$config['timezone']);
    $meeting = $config['meeting'] ?? [];
    $slotMinutes = (int)($meeting['duration_minutes'] ?? 30);
    $bufferMinutes = (int)($meeting['buffer_minutes'] ?? 15);
    $minNoticeMinutes = (int)($meeting['min_notice_minutes'] ?? 120);
    $maxDaysAhead = max(1, min(31, (int)($meeting['max_days_ahead'] ?? 21)));
    $maxVisibleDays = max(1, min(14, (int)($meeting['max_visible_days'] ?? 10)));
    $daysToCheck = max(1, min($requestedDays, $maxDaysAhead));
    $now = new DateTimeImmutable('now', $tz);
    $windowStart = $now;
    $windowEnd = $now->setTime(23, 59, 59)->modify(sprintf('+%d days', $daysToCheck));

    $eventsData = scheduler_graph_request(
        $config,
        'GET',
        '/users/' . rawurlencode((string)$config['calendar_user']) . '/calendarView',
        [
            'startDateTime' => $windowStart->format(DATE_ATOM),
            'endDateTime' => $windowEnd->format(DATE_ATOM),
            '$select' => 'showAs,start,end',
            '$top' => '500',
        ],
        null,
        ['Prefer: outlook.timezone="' . (string)$config['graph_timezone'] . '"']
    );

    $busyIntervals = [];
    foreach (($eventsData['value'] ?? []) as $event) {
        $showAs = strtolower((string)($event['showAs'] ?? 'busy'));
        if (in_array($showAs, ['free', 'workingelsewhere'], true)) {
            continue;
        }
        $startRaw = $event['start']['dateTime'] ?? null;
        $endRaw = $event['end']['dateTime'] ?? null;
        if (!$startRaw || !$endRaw) {
            continue;
        }
        try {
            $busyIntervals[] = [
                'start' => (new DateTimeImmutable((string)$startRaw, $tz))->setTimezone($tz),
                'end' => (new DateTimeImmutable((string)$endRaw, $tz))->setTimezone($tz),
            ];
        } catch (Throwable $e) {
        }
    }

    foreach (($config['blackout_ranges'] ?? []) as $range) {
        if (empty($range['start']) || empty($range['end'])) {
            continue;
        }
        try {
            $busyIntervals[] = [
                'start' => new DateTimeImmutable((string)$range['start'], $tz),
                'end' => new DateTimeImmutable((string)$range['end'], $tz),
            ];
        } catch (Throwable $e) {
        }
    }

    $blackoutDates = array_flip(array_map('strval', $config['blackout_dates'] ?? []));
    $workingHours = $config['working_hours'] ?? [];
    $days = [];

    for ($offset = 0; $offset < $daysToCheck; $offset += 1) {
        $day = $now->setTime(0, 0, 0)->modify(sprintf('+%d days', $offset));
        $isoDate = $day->format('Y-m-d');
        $weekday = (int)$day->format('N');
        $ranges = $workingHours[$weekday] ?? [];
        if (!$ranges || isset($blackoutDates[$isoDate])) {
            continue;
        }

        $slots = [];
        foreach ($ranges as $range) {
            if (empty($range['start']) || empty($range['end'])) {
                continue;
            }
            [$sh, $sm] = array_map('intval', explode(':', (string)$range['start']));
            [$eh, $em] = array_map('intval', explode(':', (string)$range['end']));
            $rangeStart = $day->setTime($sh, $sm, 0);
            $rangeEnd = $day->setTime($eh, $em, 0);
            for ($cursor = $rangeStart; $cursor < $rangeEnd; $cursor = $cursor->modify(sprintf('+%d minutes', $slotMinutes))) {
                $slotEnd = $cursor->modify(sprintf('+%d minutes', $slotMinutes));
                if ($slotEnd > $rangeEnd) {
                    break;
                }
                if ($cursor < $now->modify(sprintf('+%d minutes', $minNoticeMinutes))) {
                    continue;
                }
                if (scheduler_overlaps($cursor, $slotEnd, $busyIntervals, $bufferMinutes)) {
                    continue;
                }
                $slots[] = [
                    'time' => $cursor->format('H:i'),
                    'label' => $cursor->format('g:i A'),
                    'iso' => $cursor->format(DATE_ATOM),
                ];
            }
        }
        if ($slots) {
            $days[] = [
                'isoDate' => $isoDate,
                'label' => $day->format('D, M j'),
                'longLabel' => $day->format('l, F j'),
                'slots' => $slots,
            ];
        }
        if (count($days) >= $maxVisibleDays) {
            break;
        }
    }

    return [
        'ok' => true,
        'configured' => true,
        'timezone' => (string)$config['timezone'],
        'timezone_label' => (string)($config['timezone_label'] ?? $config['timezone']),
        'days' => $days,
    ];
}

function scheduler_find_slot(array $availability, string $slotIso): ?array
{
    foreach (($availability['days'] ?? []) as $day) {
        foreach (($day['slots'] ?? []) as $slot) {
            if (($slot['iso'] ?? '') === $slotIso) {
                return ['day' => $day, 'slot' => $slot];
            }
        }
    }
    return null;
}

function scheduler_event_payload(array $config, array $request, string $slotIso): array
{
    $tz = new DateTimeZone((string)$config['timezone']);
    $meeting = $config['meeting'] ?? [];
    $durationMinutes = (int)($meeting['duration_minutes'] ?? 30);
    $startLocal = (new DateTimeImmutable($slotIso))->setTimezone($tz);
    $endLocal = $startLocal->modify(sprintf('+%d minutes', $durationMinutes));

    $body = '<p><strong>New website scheduler booking</strong></p>'
        . '<p><strong>Name:</strong> ' . htmlspecialchars(trim((string)($request['name'] ?? '')), ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Company:</strong> ' . htmlspecialchars(trim((string)($request['company'] ?? '')), ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Email:</strong> ' . htmlspecialchars(trim((string)($request['email'] ?? '')), ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Phone:</strong> ' . htmlspecialchars(trim((string)($request['phone'] ?? 'Not provided')), ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Team size:</strong> ' . htmlspecialchars(trim((string)($request['teamSize'] ?? 'Not provided')), ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Interest:</strong> ' . htmlspecialchars(trim((string)($request['interest'] ?? 'Not provided')), ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Relationship lane:</strong> ' . htmlspecialchars(trim((string)($request['planFit'] ?? 'Not provided')), ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Support model:</strong> ' . htmlspecialchars(trim((string)($request['supportModel'] ?? 'Not provided')), ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Business profile:</strong> ' . htmlspecialchars(trim((string)($request['businessProfile'] ?? 'Not provided')), ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Preferred contact path:</strong> ' . htmlspecialchars(trim((string)($request['contactMethod'] ?? 'Not provided')), ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><strong>Pain points</strong><br>' . nl2br(htmlspecialchars(trim((string)($request['painPoints'] ?? '')), ENT_QUOTES, 'UTF-8')) . '</p>'
        . '<p><strong>Additional notes</strong><br>' . nl2br(htmlspecialchars(trim((string)($request['availability'] ?? 'Not provided')), ENT_QUOTES, 'UTF-8')) . '</p>';

    $payload = [
        'subject' => (string)($meeting['title_prefix'] ?? 'Schedule a Chat - ') . trim((string)($request['company'] ?? 'Prospect')),
        'body' => ['contentType' => 'HTML', 'content' => $body],
        'start' => ['dateTime' => $startLocal->format('Y-m-d\TH:i:s'), 'timeZone' => (string)$config['graph_timezone']],
        'end' => ['dateTime' => $endLocal->format('Y-m-d\TH:i:s'), 'timeZone' => (string)$config['graph_timezone']],
        'location' => ['displayName' => (string)($meeting['location'] ?? 'Online / Phone')],
        'attendees' => [[
            'emailAddress' => [
                'address' => trim((string)($request['email'] ?? '')),
                'name' => trim((string)($request['name'] ?? '')),
            ],
            'type' => 'required',
        ]],
        'allowNewTimeProposals' => false,
        'transactionId' => scheduler_uuid(),
    ];

    if (!empty($meeting['create_online_meeting'])) {
        $payload['isOnlineMeeting'] = true;
        $payload['onlineMeetingProvider'] = (string)($meeting['online_meeting_provider'] ?? 'teamsForBusiness');
    }

    return $payload;
}

function scheduler_status_snapshot(array $config): array
{
    $availability = scheduler_build_availability($config, 7);
    $days = $availability['days'] ?? [];
    $firstOpen = null;
    if (!empty($days) && !empty($days[0]['slots'][0]['label'])) {
        $firstOpen = ($days[0]['longLabel'] ?? $days[0]['isoDate']) . ' at ' . $days[0]['slots'][0]['label'];
    }

    return [
        'ok' => true,
        'configured' => true,
        'provider' => (string)($config['provider'] ?? 'm365'),
        'calendar_user' => (string)($config['calendar_user'] ?? ''),
        'timezone' => (string)($config['timezone_label'] ?? $config['timezone'] ?? ''),
        'slot_duration_minutes' => (int)(($config['meeting']['duration_minutes'] ?? 30)),
        'buffer_minutes' => (int)(($config['meeting']['buffer_minutes'] ?? 15)),
        'visible_days' => count($days),
        'first_opening' => $firstOpen,
        'live_booking_ready' => true,
    ];
}
