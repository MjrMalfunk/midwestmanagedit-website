<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../includes/private-config.php';

function mmit_contact_json_response(int $status, string $message, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $status < 400, 'message' => $message], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function mmit_contact_rate_limit(string $scope, int $maxRequests, int $windowSeconds): bool
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $key = hash('sha256', $scope . '|' . $ip);
    $path = sys_get_temp_dir() . '/mmit-rate-' . $key . '.json';
    $now = time();
    $state = ['window_start' => $now, 'count' => 0];

    if (is_file($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded) && isset($decoded['window_start'], $decoded['count'])) {
            $state = $decoded;
        }
    }

    if (($now - (int)$state['window_start']) >= $windowSeconds) {
        $state = ['window_start' => $now, 'count' => 0];
    }

    $state['count'] = (int)$state['count'] + 1;
    @file_put_contents($path, json_encode($state), LOCK_EX);
    return (int)$state['count'] <= $maxRequests;
}

function mmit_contact_truncate(string $value, int $maxBytes): string
{
    if (strlen($value) <= $maxBytes) {
        return $value;
    }

    $value = substr($value, 0, $maxBytes);
    while ($value !== '' && preg_match('//u', $value) !== 1) {
        $value = substr($value, 0, -1);
    }
    return $value;
}

function mmit_contact_clean(string $value, int $maxLength): string
{
    $value = trim($value);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return mmit_contact_truncate($value, $maxLength);
}

function mmit_contact_brevo_post(string $path, string $apiKey, array $payload): array
{
    $ch = curl_init('https://api.brevo.com/v3/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'api-key: ' . $apiKey,
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return ['status' => $status, 'error' => $error, 'body' => (string)$body];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mmit_contact_json_response(405, 'Method not allowed.');
}

if (!mmit_contact_rate_limit('contact-request', 12, 600)) {
    mmit_contact_json_response(429, 'Too many requests. Please wait a few minutes and try again.');
}

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) {
    mmit_contact_json_response(400, 'Invalid request. Please refresh the page and try again.');
}

if (trim((string)($data['website'] ?? '')) !== '') {
    mmit_contact_json_response(200, 'Thanks. Your message is with Midwest Managed IT.');
}

$name = mmit_contact_clean((string)($data['name'] ?? ''), 120);
$email = trim((string)($data['email'] ?? ''));
$company = mmit_contact_clean((string)($data['company'] ?? ''), 200);
$phone = mmit_contact_clean((string)($data['phone'] ?? ''), 40);
$topic = mmit_contact_clean((string)($data['topic'] ?? ''), 120);
$message = trim(strip_tags((string)($data['message'] ?? '')));
$message = mmit_contact_truncate(str_replace(["\0", "\r\n", "\r"], ['', "\n", "\n"], $message), 4000);
$source = mmit_contact_clean((string)($data['source'] ?? 'website_contact'), 100);
$campaign = mmit_contact_clean((string)($data['campaign'] ?? 'general_contact'), 100);
$landingPage = mmit_contact_clean((string)($data['landing_page'] ?? 'contact.html'), 160);
$utmSource = mmit_contact_clean((string)($data['utm_source'] ?? ''), 100);
$utmMedium = mmit_contact_clean((string)($data['utm_medium'] ?? ''), 100);
$utmCampaign = mmit_contact_clean((string)($data['utm_campaign'] ?? ''), 100);
$utmContent = mmit_contact_clean((string)($data['utm_content'] ?? ''), 100);
$qrCode = mmit_contact_clean((string)($data['qr_code'] ?? ''), 100);

if ($name === '' || $message === '') {
    mmit_contact_json_response(422, 'Please add your name and a short message.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    mmit_contact_json_response(422, 'Please enter a valid email address.');
}

$allowedTopics = [
    'Managed IT services',
    'Cybersecurity',
    'Microsoft 365 & cloud',
    'Backup & recovery',
    'Help desk & remote support',
    'Technology guidance',
    'Free IT & Backup Readiness Review',
    'Something else',
];
if (!in_array($topic, $allowedTopics, true)) {
    $topic = 'General inquiry';
}

$secrets = [];
try {
    $secrets = mmit_load_secrets_config();
} catch (Throwable $e) {
    error_log('MMIT contact config load failed: ' . $e->getMessage());
    mmit_contact_json_response(503, 'The message service is temporarily unavailable. Please email info@midwestmanagedit.com.');
}

$apiKey = (string)($secrets['brevo']['api_key'] ?? '');
if ($apiKey === '' || !function_exists('curl_init')) {
    mmit_contact_json_response(503, 'The message service is temporarily unavailable. Please email info@midwestmanagedit.com.');
}

$senderEmail = trim((string)($secrets['brevo']['contact_sender_email'] ?? $secrets['brevo']['sender_email'] ?? 'info@midwestmanagedit.com'));
if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
    $senderEmail = 'info@midwestmanagedit.com';
}
$recipientEmail = 'info@midwestmanagedit.com';
$subjectTopic = $topic !== '' ? $topic : 'General inquiry';
$subject = 'Website inquiry: ' . substr(preg_replace('/[\r\n]+/', ' ', $subjectTopic), 0, 90);
$lines = [
    'A new website inquiry was submitted.',
    '',
    'Name: ' . $name,
    'Company: ' . ($company !== '' ? $company : 'Not provided'),
    'Email: ' . $email,
    'Phone: ' . ($phone !== '' ? $phone : 'Not provided'),
    'Topic: ' . $subjectTopic,
    '',
    'Message:',
    $message,
    '',
    'Source: ' . $source,
    'Campaign: ' . $campaign,
    'Landing page: ' . $landingPage,
    'UTM source / medium / campaign / content: ' . implode(' / ', [$utmSource, $utmMedium, $utmCampaign, $utmContent]),
    'QR code: ' . ($qrCode !== '' ? $qrCode : 'Not provided'),
];
$emailResult = mmit_contact_brevo_post('smtp/email', $apiKey, [
    'sender' => ['email' => $senderEmail, 'name' => 'Midwest Managed IT'],
    'to' => [['email' => $recipientEmail, 'name' => 'Midwest Managed IT']],
    'replyTo' => ['email' => $email, 'name' => $name],
    'subject' => $subject,
    'textContent' => implode("\n", $lines),
]);

if ($emailResult['error'] !== '' || $emailResult['status'] < 200 || $emailResult['status'] >= 300) {
    error_log('MMIT contact email delivery failed: ' . ($emailResult['error'] !== '' ? $emailResult['error'] : 'HTTP ' . $emailResult['status']));
    mmit_contact_json_response(502, 'We could not send your note just now. Please email info@midwestmanagedit.com.');
}

// Contact requests are answered directly; they do not enroll visitors in campaigns.
mmit_contact_json_response(200, 'Thanks. Your message is with Midwest Managed IT. We’ll reply by email.');
