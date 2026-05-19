<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

$token = trim((string)($_GET['estimate'] ?? ''));
if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{24,96}$/', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'This estimate link is not valid.']);
    exit;
}

$home = rtrim((string)(getenv('HOME') ?: '/home/mjrmstlj'), '/');
$dir = $home . '/private/mmit/estimate-links';
$file = $dir . '/' . hash('sha256', $token) . '.json';

if (!is_file($file)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'This estimate link could not be found or has expired.']);
    exit;
}

$raw = file_get_contents($file);
$record = json_decode((string)$raw, true);
if (!is_array($record)) {
    http_response_code(410);
    echo json_encode(['ok' => false, 'message' => 'This estimate link is no longer available.']);
    exit;
}

$expiresAt = (string)($record['expires_at'] ?? '');
try {
    $expires = $expiresAt !== '' ? new DateTimeImmutable($expiresAt) : null;
} catch (Throwable $e) {
    $expires = null;
}

if (!$expires || $expires < new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
    @unlink($file);
    http_response_code(410);
    echo json_encode(['ok' => false, 'message' => 'This estimate link has expired.']);
    exit;
}

$fields = $record['fields'] ?? [];
if (!is_array($fields)) {
    http_response_code(410);
    echo json_encode(['ok' => false, 'message' => 'This estimate link is incomplete.']);
    exit;
}

$allowed = [
    'name',
    'company',
    'email',
    'phone',
    'plan_fit',
    'team_size',
    'interest',
    'support_model',
    'business_profile',
    'contact_method',
    'pain_points',
    'availability',
    'estimate_range',
    'requested_slot',
    'requested_slot_iso',
    'source',
];
$out = [];
foreach ($allowed as $key) {
    if (array_key_exists($key, $fields)) {
        $value = trim((string)$fields[$key]);
        if ($value !== '') {
            $out[$key] = $value;
        }
    }
}

if (empty($out['email']) || empty($out['company'])) {
    http_response_code(410);
    echo json_encode(['ok' => false, 'message' => 'This estimate link does not contain enough scheduling context.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'fields' => $out,
    'expires_at' => $expires->format(DATE_ATOM),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
