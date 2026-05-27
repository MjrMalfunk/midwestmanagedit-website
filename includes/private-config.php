<?php
declare(strict_types=1);

function mmit_detect_host(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
    $host = strtolower(trim($host));
    if ($host === '') {
        return 'midwestmanagedit.com';
    }

    $colonPos = strpos($host, ':');
    if ($colonPos !== false) {
        $host = substr($host, 0, $colonPos);
    }

    return $host;
}

function mmit_is_staging_host(?string $host = null): bool
{
    $normalized = $host === null ? mmit_detect_host() : strtolower(trim($host));
    return $normalized === 'test.midwestmanagedit.com';
}

function mmit_load_secrets_config(): array
{
    $host = mmit_detect_host();

    if (mmit_is_staging_host($host)) {
        $stagingPath = '/home/mjrmstlj/private/mmit/secrets.staging.php';
        if (!is_file($stagingPath)) {
            throw new RuntimeException('Missing MMIT staging config.');
        }

        $config = require $stagingPath;
        if (!is_array($config)) {
            throw new RuntimeException('MMIT staging config is invalid.');
        }

        return $config;
    }

    $candidates = [
        '/home/mjrmstlj/private/mmit/secrets.php',
        '/home/mjrmstlj/private/mmit-secrets.php', // Deprecated legacy fallback (production-only during migration).
    ];

    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }

        $config = require $path;
        if (is_array($config)) {
            return $config;
        }

        throw new RuntimeException('MMIT config is invalid.');
    }

    throw new RuntimeException('Server configuration is missing.');
}

function mmit_scheduler_config_candidates(): array
{
    $host = mmit_detect_host();

    $envPath = getenv('MMIT_SCHEDULER_CONFIG');
    if (is_string($envPath) && trim($envPath) !== '') {
        return [trim($envPath)];
    }

    if (mmit_is_staging_host($host)) {
        return [
            '/home/mjrmstlj/private/mmit/scheduler.staging.php',
        ];
    }

    return [
        '/home/mjrmstlj/private/mmit/scheduler.php',
        '/home/mjrmstlj/private/mmit-scheduler.php', // Deprecated legacy fallback (production-only during migration).
        dirname(__DIR__) . '/api/scheduler/config.local.php',
        dirname(__DIR__) . '/private/mmit-scheduler.php',
        dirname(__DIR__, 2) . '/private/mmit-scheduler.php',
    ];
}
