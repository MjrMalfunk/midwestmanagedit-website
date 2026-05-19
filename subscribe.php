<?php
header('Content-Type: application/json; charset=utf-8');

function json_response(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['ok' => $status < 400, 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function client_ip_address(): string {
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

function rate_limit_guard(string $scope, int $maxRequests, int $windowSeconds): bool {
    $key = hash('sha256', $scope . '|' . client_ip_address());
    $file = sys_get_temp_dir() . '/mmit-rate-' . $key . '.json';
    $now = time();
    $state = ['window_start' => $now, 'count' => 0];
    if (is_file($file)) {
        $raw = file_get_contents($file);
        $decoded = json_decode((string)$raw, true);
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, 'Method not allowed.');
}

if (!rate_limit_guard('subscribe', 20, 300)) {
    json_response(429, 'Too many requests. Please wait and try again.');
}

$secretsPath = '/home/mjrmstlj/private/mmit-secrets.php';
if (!is_file($secretsPath)) {
    json_response(500, 'Server configuration is missing.');
}

$secrets = require $secretsPath;
$apiKey = (string)($secrets['brevo']['api_key'] ?? '');
$pendingListId = (int)($secrets['brevo']['pending_list_id'] ?? 0);

if ($apiKey === '' || $pendingListId <= 0) {
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

if ($name === '' || $email === '') {
    json_response(400, 'Name and email are required.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(400, 'Please provide a valid email address.');
}

$attributes = [
    'FIRSTNAME' => $name,
    'OPT_IN' => true,
    'DOUBLE_OPT-IN' => false
];

if ($company !== '') {
    $attributes['COMPANY'] = $company;
}

$payload = [
    'email' => $email,
    'attributes' => $attributes,
    'listIds' => [$pendingListId],
    'updateEnabled' => true
];

$ch = curl_init('https://api.brevo.com/v3/contacts');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'accept: application/json',
        'api-key: ' . $apiKey,
        'content-type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 20
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
    json_response(200, 'Thanks. Please check your inbox to confirm your signup.');
}

if (!empty($brevoResponse['message'])) {
    $message = $brevoResponse['message'];
    if (stripos($message, 'duplicate') !== false || stripos($message, 'already') !== false) {
        json_response(200, 'This email is already on the pending or subscriber list. Please check your inbox.');
    }

    if (stripos($message, 'blacklist') !== false || stripos($message, 'blocked') !== false) {
        json_response(400, 'That email address is blocked. Please use another address.');
    }

    error_log('MMIT subscribe provider response: ' . $message . ' (HTTP ' . $httpCode . ')');
    json_response(500, 'Subscription request was not accepted. Please try again.');
}

json_response(500, 'Subscription request was not accepted. Please try again.');
