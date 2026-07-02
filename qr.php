<?php
declare(strict_types=1);

/**
 * MMIT QR campaign redirect tracker.
 *
 * Public QR URL example:
 * /qr.php?code=brochure_july_2026
 *
 * OPS can publish active campaigns to:
 * /home/<user>/private/mmit-qr-campaigns[-test]/campaigns.json
 */

function mmit_qr_is_staging_runtime(): bool
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));

    return str_starts_with($host, 'test.')
        || str_contains($host, 'test.midwestmanagedit.com');
}

function mmit_qr_account_home(): string
{
    $dir = __DIR__;

    if (preg_match('#^(/home/[^/]+)#', $dir, $m)) {
        return $m[1];
    }

    $home = getenv('HOME');

    if (is_string($home) && $home !== '') {
        return rtrim($home, '/');
    }

    return dirname(__DIR__);
}

function mmit_qr_storage_dir(): string
{
    $home = mmit_qr_account_home();
    $default = mmit_qr_is_staging_runtime()
        ? $home . '/private/mmit-qr-campaigns-test'
        : $home . '/private/mmit-qr-campaigns';

    return getenv('MMIT_QR_LOG_DIR') ?: $default;
}

function mmit_qr_builtin_campaigns(): array
{
    return [
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
}

function mmit_qr_clean_code($value): string
{
    $value = strtolower(trim((string)$value));

    if (!preg_match('/^[a-z0-9_-]{1,80}$/', $value)) {
        return 'unknown';
    }

    return $value;
}

function mmit_qr_destination_is_allowed(string $path): bool
{
    $path = trim($path);

    if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
        return false;
    }

    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $path)) {
        return false;
    }

    return (bool)preg_match('/^\/[A-Za-z0-9._~\/?#=&%+\-]+$/', $path);
}

function mmit_qr_campaigns(): array
{
    $campaigns = mmit_qr_builtin_campaigns();
    $mapFile = mmit_qr_storage_dir() . '/campaigns.json';

    if (!is_file($mapFile)) {
        return $campaigns;
    }

    $json = json_decode((string)@file_get_contents($mapFile), true);

    if (!is_array($json) || !isset($json['campaigns']) || !is_array($json['campaigns'])) {
        return $campaigns;
    }

    foreach ($json['campaigns'] as $code => $campaign) {
        $cleanCode = mmit_qr_clean_code((string)$code);

        if ($cleanCode === 'unknown' || !is_array($campaign)) {
            continue;
        }

        $destination = (string)($campaign['destination'] ?? $campaign['destination_path'] ?? '');

        if (!mmit_qr_destination_is_allowed($destination)) {
            continue;
        }

        $campaigns[$cleanCode] = [
            'name' => trim((string)($campaign['name'] ?? $cleanCode)) ?: $cleanCode,
            'destination' => $destination,
            'utm_source' => mmit_qr_clean_code((string)($campaign['utm_source'] ?? 'qr')),
            'utm_medium' => mmit_qr_clean_code((string)($campaign['utm_medium'] ?? 'print')),
            'utm_campaign' => mmit_qr_clean_code((string)($campaign['utm_campaign'] ?? $cleanCode)),
            'utm_content' => mmit_qr_clean_code((string)($campaign['utm_content'] ?? $cleanCode)),
        ];
    }

    return $campaigns;
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
    $logDir = mmit_qr_storage_dir();

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
    @chmod($logFile, 0640);
}

$campaigns = mmit_qr_campaigns();

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
