<?php
declare(strict_types=1);

/**
 * MMIT QR campaign redirect tracker.
 *
 * Public QR URL example:
 * /qr.php?code=brochure_july_2026
 *
 * Design:
 * - Hardcoded campaign map to prevent open redirects.
 * - Private JSONL log outside web root.
 * - 302 redirect so campaign destinations can be changed later.
 */

$campaigns = [
    'brochure_july_2026' => [
        'name' => 'July 2026 Brochure',
        'destination' => '/it-review.html',
        'utm_source' => 'brochure',
        'utm_medium' => 'print',
        'utm_campaign' => 'july_2026_launch',
        'utm_content' => 'brochure_july_2026',
    ],
    'business_card_2026' => [
        'name' => 'Business Card 2026',
        'destination' => '/it-review.html',
        'utm_source' => 'business_card',
        'utm_medium' => 'print',
        'utm_campaign' => 'local_outreach_2026',
        'utm_content' => 'business_card_2026',
    ],
    'google_profile_2026' => [
        'name' => 'Google Business Profile 2026',
        'destination' => '/it-review.html',
        'utm_source' => 'google_business_profile',
        'utm_medium' => 'local_profile',
        'utm_campaign' => 'google_profile_2026',
        'utm_content' => 'profile_link',
    ],
];

function mmit_qr_clean_code($value): string
{
    $value = strtolower(trim((string)$value));

    if (!preg_match('/^[a-z0-9_-]{1,64}$/', $value)) {
        return 'unknown';
    }

    return $value;
}

function mmit_qr_truncate($value, int $max = 500): string
{
    $value = trim((string)$value);

    if (strlen($value) <= $max) {
        return $value;
    }

    return substr($value, 0, $max);
}

function mmit_qr_log(array $record): void
{
    $home = getenv('HOME') ?: dirname(__DIR__);
    $logDir = getenv('MMIT_QR_LOG_DIR') ?: $home . '/private/mmit-qr-campaigns';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }

    $logFile = $logDir . '/qr-clicks.jsonl';
    $line = json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL;

    $fh = @fopen($logFile, 'ab');

    if (!$fh) {
        return;
    }

    if (@flock($fh, LOCK_EX)) {
        @fwrite($fh, $line);
        @flock($fh, LOCK_UN);
    }

    @fclose($fh);
}

$code = mmit_qr_clean_code($_GET['code'] ?? ($_GET['c'] ?? 'unknown'));
$known = array_key_exists($code, $campaigns);

$campaign = $known ? $campaigns[$code] : [
    'name' => 'Unknown QR Campaign',
    'destination' => '/it-review.html',
    'utm_source' => 'qr',
    'utm_medium' => 'print',
    'utm_campaign' => 'unknown_qr',
    'utm_content' => $code,
];

$query = http_build_query([
    'utm_source' => $campaign['utm_source'],
    'utm_medium' => $campaign['utm_medium'],
    'utm_campaign' => $campaign['utm_campaign'],
    'utm_content' => $campaign['utm_content'],
    'qr_code' => $code,
]);

$destination = $campaign['destination'] . '?' . $query;

$userAgent = mmit_qr_truncate($_SERVER['HTTP_USER_AGENT'] ?? '');
$remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

mmit_qr_log([
    'ts_utc' => gmdate('c'),
    'code' => $code,
    'known' => $known,
    'campaign_name' => $campaign['name'],
    'destination' => $destination,
    'host' => mmit_qr_truncate($_SERVER['HTTP_HOST'] ?? ''),
    'request_uri' => mmit_qr_truncate($_SERVER['REQUEST_URI'] ?? ''),
    'referrer' => mmit_qr_truncate($_SERVER['HTTP_REFERER'] ?? ''),
    'user_agent_hash' => hash('sha256', $userAgent),
    'visitor_day_hash' => hash('sha256', $remoteAddr . '|' . $userAgent . '|' . gmdate('Y-m-d')),
]);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $destination, true, 302);
exit;
