<?php
declare(strict_types=1);

/**
 * MMIT QR campaign CLI report.
 *
 * Usage:
 * php scripts/qr_campaign_report.php
 */

$home = getenv('HOME') ?: dirname(__DIR__);
$logDir = getenv('MMIT_QR_LOG_DIR') ?: $home . '/private/mmit-qr-campaigns';
$logFile = $argv[1] ?? ($logDir . '/qr-clicks.jsonl');

if (!is_file($logFile)) {
    echo "No QR log found at: {$logFile}" . PHP_EOL;
    exit(0);
}

$total = 0;
$byCode = [];
$byDay = [];
$known = 0;
$unknown = 0;

$fh = fopen($logFile, 'rb');

while (($line = fgets($fh)) !== false) {
    $row = json_decode($line, true);

    if (!is_array($row)) {
        continue;
    }

    $total++;

    $code = $row['code'] ?? 'unknown';
    $day = substr((string)($row['ts_utc'] ?? ''), 0, 10) ?: 'unknown';

    $byCode[$code] = ($byCode[$code] ?? 0) + 1;
    $byDay[$day] = ($byDay[$day] ?? 0) + 1;

    if (!empty($row['known'])) {
        $known++;
    } else {
        $unknown++;
    }
}

fclose($fh);

arsort($byCode);
ksort($byDay);

echo "MMIT QR Campaign Report" . PHP_EOL;
echo "=======================" . PHP_EOL;
echo "Log: {$logFile}" . PHP_EOL;
echo "Total scans: {$total}" . PHP_EOL;
echo "Known scans: {$known}" . PHP_EOL;
echo "Unknown scans: {$unknown}" . PHP_EOL;
echo PHP_EOL;

echo "By campaign code:" . PHP_EOL;
foreach ($byCode as $code => $count) {
    echo "  {$code}: {$count}" . PHP_EOL;
}

echo PHP_EOL;
echo "By day:" . PHP_EOL;
foreach ($byDay as $day => $count) {
    echo "  {$day}: {$count}" . PHP_EOL;
}
