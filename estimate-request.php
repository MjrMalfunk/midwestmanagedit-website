<?php
header('Content-Type: application/json; charset=utf-8');

function json_response(int $status, string $message, array $extra = []): void {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $status < 400, 'message' => $message], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function client_ip_address(): string {
    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        $first = trim((string)($parts[0] ?? ''));
        if ($first !== '') return $first;
    }
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function rate_limit_guard(string $scope, int $maxRequests, int $windowSeconds): bool {
    $key = hash('sha256', $scope . '|' . client_ip_address());
    $file = sys_get_temp_dir() . '/mmit-rate-' . $key . '.json';
    $now = time();
    $state = ['window_start' => $now, 'count' => 0];
    if (is_file($file)) {
        $decoded = json_decode((string)file_get_contents($file), true);
        if (is_array($decoded) && isset($decoded['window_start'], $decoded['count'])) $state = $decoded;
    }
    if (($now - (int)$state['window_start']) >= $windowSeconds) $state = ['window_start' => $now, 'count' => 0];
    $state['count'] = (int)$state['count'] + 1;
    @file_put_contents($file, json_encode($state), LOCK_EX);
    return (int)$state['count'] <= $maxRequests;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, 'Method not allowed.');
}

if (!rate_limit_guard('estimate-request', 20, 300)) {
    json_response(429, 'Too many requests. Please wait and try again.');
}

$secretsPath = '/home/mjrmstlj/private/mmit-secrets.php';
if (!is_file($secretsPath)) {
    json_response(500, 'Server configuration is missing.');
}

$secrets = require $secretsPath;
$apiKey = (string)($secrets['brevo']['api_key'] ?? '');
$estimateListId = (int)($secrets['brevo']['estimate_list_id'] ?? 0);
$pendingListId = (int)($secrets['brevo']['pending_list_id'] ?? 0);
$listId = $estimateListId > 0 ? $estimateListId : $pendingListId;
$attributeMap = $secrets['brevo']['estimate_attribute_map'] ?? [];

if ($apiKey === '' || $listId <= 0) {
    json_response(500, 'Service configuration is incomplete.');
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    json_response(400, 'Invalid request body.');
}

$name = trim((string)($data['name'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
$company = trim((string)($data['company'] ?? ''));
$phone = trim((string)($data['phone'] ?? ''));
$lane = trim((string)($data['lane'] ?? ''));
$range = trim((string)($data['estimate_range'] ?? ''));

$aliases = [
    'cloud_users' => ['users'],
    'estimate_summary' => ['summary'],
    'pain_points' => ['note'],
    'first_name' => ['name'],
    'next_step' => [],
];

function format_brevo_phone_attribute(string $value): string {
    $digits = preg_replace('/\D+/', '', $value);
    if ($digits === null || $digits === '') {
        return '';
    }
    if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) === 10) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
    }
    return trim($value);
}

function format_sms_phone(string $value): string {
    $digits = preg_replace('/\D+/', '', $value);
    if ($digits === null || $digits === '') {
        return '';
    }
    if (strlen($digits) === 10) {
        return '+1' . $digits;
    }
    if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
        return '+' . $digits;
    }
    if (str_starts_with(trim($value), '+')) {
        return '+' . $digits;
    }
    return '+' . $digits;
}

function splitContactName(string $fullName): array {
    $fullName = trim(preg_replace('/\s+/', ' ', $fullName));
    if ($fullName === '') {
        return ['', ''];
    }
    $parts = explode(' ', $fullName);
    $first = array_shift($parts) ?: '';
    $last = trim(implode(' ', $parts));
    return [$first, $last];
}

function load_private_estimate_engine(): ?array {
    $home = rtrim((string)(getenv('HOME') ?: '/home/mjrmstlj'), '/');
    $candidateDirs = [
        $home . '/private/shared/pricing',
        $home . '/private/mmit/pricing',
    ];

    foreach ($candidateDirs as $dir) {
        $catalogPath = $dir . '/catalog.php';
        $calculatorPath = $dir . '/QuoteCalculator.php';
        $formatterPath = $dir . '/EstimateFormatter.php';

        if (!is_file($catalogPath) || !is_file($calculatorPath) || !is_file($formatterPath)) {
            continue;
        }

        $catalog = require $catalogPath;
        require_once $calculatorPath;
        require_once $formatterPath;

        if (!is_array($catalog) || !class_exists('MMIT_QuoteCalculator') || !class_exists('MMIT_EstimateFormatter')) {
            continue;
        }

        return [
            'catalog' => $catalog,
            'dir' => $dir,
        ];
    }

    return null;
}

function calculate_private_estimate_fields(array $data): array {
    $engine = load_private_estimate_engine();
    if ($engine === null) {
        return [];
    }

    try {
        $calculator = new MMIT_QuoteCalculator($engine['catalog']);
        $quote = $calculator->calculate([
            'plan' => $data['lane_key'] ?? $data['lane'] ?? 'protect',
            'workstations' => $data['workstations'] ?? 0,
            'servers' => $data['servers'] ?? 0,
            'cloud_users' => $data['cloud_users'] ?? $data['users'] ?? 0,
            'addons' => $data['addon_keys'] ?? $data['addons'] ?? [],
            'quoted_items' => $data['quoted_items'] ?? [],
            'note' => $data['note'] ?? '',
        ]);

        $fields = MMIT_EstimateFormatter::brevoFields($quote);
        $summary = (string)($fields['BASE_SERVICE_SUMMARY'] ?? '');
        $range = (string)($fields['BASE_MONTHLY_TEXT'] ?? $fields['ESTIMATE_RANGE'] ?? '');
        $lineItems = (string)($fields['ESTIMATE_LINE_ITEMS'] ?? '');

        $fields['ESTIMATE_SUMMARY'] = trim(implode(' | ', array_filter([
            $summary,
            $lineItems !== '' ? str_replace("\n", ' | ', $lineItems) : '',
            $range !== '' ? 'Estimated monthly planning range: ' . $range : '',
        ])));

        $note = trim((string)($data['note'] ?? ''));
        $fields['PAIN_POINTS'] = $note !== '' ? $note : $fields['ESTIMATE_SUMMARY'];
        $fields['WORKSTATIONS'] = (int)($quote['workstations'] ?? 0);
        $fields['SERVERS'] = (int)($quote['servers'] ?? 0);
        $fields['CLOUD_USERS'] = (int)($quote['cloud_users'] ?? 0);

        return [
            'quote' => $quote,
            'fields' => $fields,
            'engine_dir' => $engine['dir'],
        ];
    } catch (Throwable $e) {
        error_log('MMIT estimate pricing engine failed: ' . $e->getMessage());
        return [];
    }
}


function mmit_base_url(): string {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'midwestmanagedit.com'));
    if ($host === '') {
        $host = 'midwestmanagedit.com';
    }
    $proto = 'https';
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        $proto = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwarded = strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']);
        $proto = $forwarded === 'http' ? 'http' : 'https';
    }
    return $proto . '://' . $host;
}

function mmit_estimate_token_dir(): string {
    $home = rtrim((string)(getenv('HOME') ?: '/home/mjrmstlj'), '/');
    return $home . '/private/mmit/estimate-links';
}

function mmit_base64url(string $bytes): string {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function mmit_create_schedule_link(array $fields): array {
    try {
        $token = mmit_base64url(random_bytes(24));
    } catch (Throwable $e) {
        $token = bin2hex(random_bytes(16));
    }

    $dir = mmit_estimate_token_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    if (!is_dir($dir) || !is_writable($dir)) {
        error_log('MMIT estimate token directory is not writable: ' . $dir);
        return ['', ''];
    }

    $now = time();
    $record = [
        'version' => 1,
        'created_at' => gmdate(DATE_ATOM, $now),
        'expires_at' => gmdate(DATE_ATOM, $now + (30 * 24 * 60 * 60)),
        'fields' => $fields,
    ];

    $file = $dir . '/' . hash('sha256', $token) . '.json';
    $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) {
        error_log('MMIT estimate token write failed: ' . $file);
        return ['', ''];
    }
    @chmod($file, 0600);

    $link = mmit_base_url() . '/schedule.html?estimate=' . rawurlencode($token);
    return [$token, $link];
}

function mmit_bucketize_team_size(int $workstations, int $cloudUsers): string {
    $count = max($workstations, $cloudUsers);
    if ($count <= 5) return '1 to 5 users';
    if ($count <= 15) return '6 to 15 users';
    if ($count <= 30) return '16 to 30 users';
    if ($count <= 75) return '31 to 75 users';
    return '76+ users';
}

function mmit_interest_from_lane(string $lane): string {
    $needle = strtolower($lane);
    if (str_contains($needle, 'manage')) return 'Managed IT Services';
    if (str_contains($needle, 'protect')) return 'Cybersecurity';
    if (str_contains($needle, 'govern')) return 'Microsoft 365 & Cloud';
    return 'Managed IT Services';
}

function mmit_business_profile(int $servers, int $cloudUsers): string {
    if ($servers > 0) return 'Hybrid or multi-location team';
    if ($cloudUsers >= 10) return 'Microsoft 365 cleanup needed';
    return 'Growing small business';
}

[$firstName, $lastName] = splitContactName($name);

if ($name === '' || $email === '' || $company === '') {
    json_response(400, 'Name, email, and company are required.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(400, 'Please provide a valid email address.');
}

$serverEstimate = calculate_private_estimate_fields($data);
$calculatedFields = is_array($serverEstimate['fields'] ?? null) ? $serverEstimate['fields'] : [];

$attributes = [
    'FIRSTNAME' => $firstName !== '' ? $firstName : $name,
    'OPT_IN' => true,
    'DOUBLE_OPT-IN' => false,
];

if ($lastName !== '') {
    $attributes['LASTNAME'] = $lastName;
}

if ($company !== '') {
    $attributes['COMPANY'] = $company;
}

if (is_array($attributeMap)) {
    foreach ($attributeMap as $payloadKey => $brevoAttribute) {
        $brevoAttribute = trim((string)$brevoAttribute);
        if ($brevoAttribute === '') {
            continue;
        }

        $candidateKeys = array_merge([$payloadKey], $aliases[$payloadKey] ?? []);
        $value = null;

        foreach ($candidateKeys as $candidateKey) {
            if (array_key_exists($candidateKey, $data)) {
                $value = $data[$candidateKey];
                break;
            }
        }

        if ($payloadKey === 'next_step' && ($value === null || $value === '')) {
            $value = 'EMAIL_ESTIMATE';
        }

        if ($value === null) {
            continue;
        }

        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }

        if ($payloadKey === 'phone') {
            $value = trim((string)$value);
            if ($value !== '') {
                $brevoPhone = format_brevo_phone_attribute($value);
                if ($brevoPhone !== '') {
                    $attributes[$brevoAttribute] = $brevoPhone;
                }
                $smsPhone = format_sms_phone($value);
                if ($smsPhone !== '' && (!isset($attributes['SMS']) || $attributes['SMS'] === '')) {
                    $attributes['SMS'] = $smsPhone;
                }
            }
            continue;
        }

        if (in_array($payloadKey, ['workstations', 'servers', 'cloud_users'], true)) {
            $number = (int)$value;
            if ($number >= 0) {
                $attributes[$brevoAttribute] = $number;
            }
            continue;
        }

        $value = trim((string)$value);
        if ($value !== '') {
            $attributes[$brevoAttribute] = $value;
        }
    }
}

/*
 * Private pricing engine wins over browser-submitted math.
 * Keep new/richer fields opt-in so Brevo does not reject unknown attributes before they are created.
 */
$mappedBrevoAttributes = is_array($attributeMap) ? array_values(array_filter(array_map('strval', $attributeMap))) : [];
$alwaysCalculatedAttributes = [
    'ESTIMATE_LANE',
    'ESTIMATE_RANGE',
    'ESTIMATE_SUMMARY',
    'PAIN_POINTS',
    'WORKSTATIONS',
    'SERVERS',
    'CLOUD_USERS',
    // Richer estimate fields now created in Brevo for the 24-hour summary email.
    'ESTIMATE_STATUS',
    'BASE_MONTHLY_TEXT',
    'BASE_SERVICE_SUMMARY',
    'ESTIMATE_LINE_ITEMS',
    'ESTIMATE_INCLUDED',
    'ESTIMATE_ASSUMPTIONS',
];

foreach ($calculatedFields as $brevoAttribute => $value) {
    $brevoAttribute = trim((string)$brevoAttribute);
    if ($brevoAttribute === '') {
        continue;
    }

    $shouldSend = in_array($brevoAttribute, $alwaysCalculatedAttributes, true)
        || in_array($brevoAttribute, $mappedBrevoAttributes, true);

    if (!$shouldSend) {
        continue;
    }

    if (is_array($value)) {
        $value = implode("\n", array_map('strval', $value));
    }

    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            continue;
        }
    }

    $attributes[$brevoAttribute] = $value;
}

if (!isset($attributes['NEXT_STEP']) || trim((string)$attributes['NEXT_STEP']) === '') {
    $attributes['NEXT_STEP'] = 'EMAIL_ESTIMATE';
}

/*
 * Build an opaque, server-side schedule link for Brevo emails.
 * The email link contains only a random token, while the private token file stores the prefill context.
 */
$finalLaneForSchedule = (string)($attributes['ESTIMATE_LANE'] ?? $lane);
$finalRangeForSchedule = (string)($attributes['BASE_MONTHLY_TEXT'] ?? $attributes['ESTIMATE_RANGE'] ?? $range);
$finalSummaryForSchedule = (string)($attributes['BASE_SERVICE_SUMMARY'] ?? $attributes['ESTIMATE_SUMMARY'] ?? ($data['estimate_summary'] ?? $data['summary'] ?? ''));
$finalWorkstationsForSchedule = (int)($attributes['WORKSTATIONS'] ?? $data['workstations'] ?? 0);
$finalServersForSchedule = (int)($attributes['SERVERS'] ?? $data['servers'] ?? 0);
$finalCloudUsersForSchedule = (int)($attributes['CLOUD_USERS'] ?? $data['cloud_users'] ?? $data['users'] ?? 0);
$finalPhoneForSchedule = (string)($attributes['PHONE'] ?? format_brevo_phone_attribute($phone));
$schedulePrefillFields = [
    'name' => $name,
    'company' => $company,
    'email' => $email,
    'phone' => $finalPhoneForSchedule,
    'plan_fit' => $finalLaneForSchedule,
    'team_size' => mmit_bucketize_team_size($finalWorkstationsForSchedule, $finalCloudUsersForSchedule),
    'interest' => mmit_interest_from_lane($finalLaneForSchedule),
    'business_profile' => mmit_business_profile($finalServersForSchedule, $finalCloudUsersForSchedule),
    'contact_method' => 'Video meeting',
    'estimate_range' => $finalRangeForSchedule,
    'availability' => $finalRangeForSchedule !== '' ? 'Estimator planning range: ' . $finalRangeForSchedule : '',
    'pain_points' => $finalSummaryForSchedule,
    'source' => 'estimate-email-link',
];
$schedulePrefillFields = array_filter($schedulePrefillFields, static fn($value) => trim((string)$value) !== '');
[$estimateToken, $scheduleLink] = mmit_create_schedule_link($schedulePrefillFields);
if ($scheduleLink !== '') {
    $attributes['SCHEDULE_LINK'] = $scheduleLink;
}

$payload = [
    'email' => $email,
    'attributes' => $attributes,
    'listIds' => [$listId],
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
    CURLOPT_TIMEOUT => 20,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    json_response(500, 'Service is temporarily unavailable. Please try again.');
}

$brevoResponse = json_decode($response, true);

if ($httpCode >= 200 && $httpCode < 300) {
    $finalLane = (string)($attributes['ESTIMATE_LANE'] ?? $lane);
    $finalRange = (string)($attributes['ESTIMATE_RANGE'] ?? $range);
    $finalSummary = (string)($attributes['ESTIMATE_SUMMARY'] ?? ($data['estimate_summary'] ?? $data['summary'] ?? ''));

    $message = 'Thanks. We received your estimate request and someone will follow up by email.';
    if ($finalLane !== '' && $finalRange !== '') {
        $message = sprintf('Thanks. We captured your %s planning range of %s and someone will follow up by email.', $finalLane, $finalRange);
    }

    json_response(200, $message, [
        'estimate_lane' => $finalLane,
        'estimate_range' => $finalRange,
        'estimate_summary' => $finalSummary,
        'schedule_link' => $scheduleLink,
        'server_calculated' => !empty($calculatedFields),
    ]);
}

if (!empty($brevoResponse['message'])) {
    $message = $brevoResponse['message'];
    if (stripos($message, 'duplicate') !== false || stripos($message, 'already') !== false) {
        json_response(200, 'This contact is already in the estimate follow-up path. We can still follow up by email from the existing record.');
    }

    if (stripos($message, 'blacklist') !== false || stripos($message, 'blocked') !== false) {
        json_response(400, 'That email address is blocked. Please use another address.');
    }

    error_log('MMIT estimate provider response: ' . $message . ' (HTTP ' . $httpCode . ')');
    json_response(500, 'Estimate request was not accepted. Please try again.');
}

json_response(500, 'Estimate request was not accepted. Please try again.');
