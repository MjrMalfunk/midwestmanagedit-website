<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Method not allowed.']);
    exit;
}

$secretsPath = '/home/mjrmstlj/private/mmit-secrets.php';
if (!is_file($secretsPath)) {
    http_response_code(500);
    echo json_encode(['message' => 'Server configuration is missing.']);
    exit;
}

$secrets = require $secretsPath;
$apiKey = (string)($secrets['brevo']['api_key'] ?? '');
$pendingListId = (int)($secrets['brevo']['pending_list_id'] ?? 0);

if ($apiKey === '' || $pendingListId <= 0) {
    http_response_code(500);
    echo json_encode(['message' => 'Brevo configuration is incomplete.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['message' => 'Invalid request body.']);
    exit;
}

$name = trim((string)($data['name'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
$company = trim((string)($data['company'] ?? ''));

if ($name === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['message' => 'Name and email are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['message' => 'Please provide a valid email address.']);
    exit;
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
    http_response_code(500);
    echo json_encode(['message' => 'Server could not reach Brevo.']);
    exit;
}

$brevoResponse = json_decode($response, true);

if ($httpCode >= 200 && $httpCode < 300) {
    echo json_encode(['message' => 'Thanks. Please check your inbox to confirm your signup.']);
    exit;
}

if (!empty($brevoResponse['message'])) {
    $message = $brevoResponse['message'];
    if (stripos($message, 'duplicate') !== false || stripos($message, 'already') !== false) {
        http_response_code(200);
        echo json_encode(['message' => 'This email is already on the pending or subscriber list. Please check your inbox.']);
        exit;
    }

    if (stripos($message, 'blacklist') !== false || stripos($message, 'blocked') !== false) {
        http_response_code(400);
        echo json_encode(['message' => 'That email address is blocked in Brevo. Please use another address or unblock it in Brevo first.']);
        exit;
    }

    http_response_code(500);
    echo json_encode(['message' => $message, 'brevo_status' => $httpCode]);
    exit;
}

http_response_code(500);
echo json_encode([
    'message' => 'Brevo rejected the request.',
    'brevo_status' => $httpCode
]);
