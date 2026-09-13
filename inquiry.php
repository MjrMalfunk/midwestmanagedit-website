<?php
declare(strict_types=1);
require __DIR__ . '/includes/private-config.php';
require __DIR__ . '/includes/inquiry-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function inquiry_response(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    inquiry_response(405, ['ok'=>false, 'message'=>'Method not allowed.']);
}
$host = mmit_detect_host();
if (!in_array($host, ['test.midwestmanagedit.com', 'midwestmanagedit.com', 'www.midwestmanagedit.com'], true)) {
    inquiry_response(503, ['ok'=>false, 'message'=>'Please email info@midwestmanagedit.com to reach us.']);
}
try {
    $config = mmit_load_secrets_config();
    if (($config['inquiry']['enabled'] ?? false) !== true || empty($config['brevo']['api_key'])) throw new RuntimeException('Inquiry not configured.');
    $environment = mmit_is_staging_host($host) ? 'staging' : 'production';
    $dir = '/home/mjrmstlj/private/mmit/inquiries-' . $environment;
    umask(0077);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Storage unavailable.');
    if (!is_writable($dir) || !function_exists('curl_init')) throw new RuntimeException('Storage or cURL unavailable.');
} catch (Throwable $e) {
    error_log('MMIT inquiry unavailable; check private configuration, directory permissions and PHP cURL.');
    inquiry_response(503, ['ok'=>false, 'message'=>'The form is temporarily unavailable. Please email info@midwestmanagedit.com.']);
}
session_name('mmit_inquiry');
session_set_cookie_params(['lifetime'=>0, 'path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Lax']);
ini_set('session.use_strict_mode', '1');
if (!session_start()) inquiry_response(503, ['ok'=>false, 'message'=>'The form could not start. Please email us directly.']);
if ($method === 'GET') {
    $tokens = $_SESSION['inquiry_tokens'] ?? [];
    $tokens = array_filter($tokens, static fn($entry) => time() - $entry['created'] <= 7200);
    if (count($tokens) >= 8) array_shift($tokens);
    $id = bin2hex(random_bytes(16));
    $token = bin2hex(random_bytes(32));
    $tokens[$id] = ['token'=>$token, 'created'=>time()];
    $_SESSION['inquiry_tokens'] = $tokens;
    session_write_close();
    inquiry_response(200, ['ok'=>true, 'token'=>$token, 'request_id'=>$id]);
}
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && $origin !== 'https://' . $host) inquiry_response(403, ['ok'=>false, 'message'=>'Please reload the form and try again.']);
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) inquiry_response(415, ['ok'=>false, 'message'=>'Please use the inquiry form.']);
$raw = file_get_contents('php://input', false, null, 0, 32769);
if (strlen((string)$raw) > 32768) inquiry_response(413, ['ok'=>false, 'message'=>'Your message is too long.']);
$input = json_decode((string)$raw, true);
if (!is_array($input)) inquiry_response(400, ['ok'=>false, 'message'=>'Please check your submission.']);
$id = $input['request_id'] ?? '';
$token = $input['token'] ?? '';
$entry = is_string($id) ? ($_SESSION['inquiry_tokens'][$id] ?? null) : null;
if (!$entry || !is_string($token) || !hash_equals($entry['token'], $token) || time() - $entry['created'] > 7200) {
    inquiry_response(403, ['ok'=>false, 'message'=>'Your form session expired. Please reload and try again.']);
}
session_write_close();
$lock = null;
try {
    $fields = mmit_inquiry_validate($input);
    if (!mmit_inquiry_rate_limit($dir, (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), time())) {
        inquiry_response(429, ['ok'=>false, 'message'=>'Too many attempts. Please wait or email us directly.']);
    }
    $lock = fopen($dir . '/' . $id . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Lock unavailable.');
    $ok = mmit_inquiry_deliver($dir . '/' . $id . '.json', $fields, $id, $environment,
        static fn($endpoint, $payload) => mmit_inquiry_brevo($config['brevo']['api_key'], $endpoint, $payload));
    flock($lock, LOCK_UN); fclose($lock); $lock = null;
    if (!$ok) {
        error_log('MMIT inquiry needs attention: ' . $environment . ' ' . $id);
        inquiry_response(503, ['ok'=>false, 'message'=>'We saved your inquiry, but couldn’t confirm delivery to our follow-up system. Please email info@midwestmanagedit.com with reference ' . $id . '.']);
    }
    inquiry_response(200, ['ok'=>true, 'reference'=>$id]);
} catch (InvalidArgumentException $e) {
    inquiry_response(422, ['ok'=>false, 'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('MMIT inquiry processing failed; reference ' . $id);
    inquiry_response(503, ['ok'=>false, 'message'=>'We couldn’t confirm receipt. Please email info@midwestmanagedit.com with reference ' . $id . '.']);
}
