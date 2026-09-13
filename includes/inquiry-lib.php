<?php
declare(strict_types=1);

function mmit_inquiry_validate(array $input): array
{
    $limits = ['name'=>100, 'company'=>150, 'email'=>254, 'phone'=>40, 'interest'=>40, 'message'=>5000, 'website'=>200];
    $fields = [];
    foreach ($limits as $key => $max) {
        $value = $input[$key] ?? '';
        if (!is_string($value) || !preg_match('//u', $value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Please check the ' . $key . ' field.');
        }
        $value = trim($value);
        if (preg_match_all('/./us', $value) > $max) throw new InvalidArgumentException('The ' . $key . ' field is too long.');
        $fields[$key] = $value;
    }
    if ($fields['website'] !== '') throw new InvalidArgumentException('Please use the inquiry form.');
    unset($fields['website']);
    if ($fields['name'] === '' || $fields['company'] === '') throw new InvalidArgumentException('Please enter your name and business name.');
    if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Please enter a valid email address.');
    if (preg_match_all('/./us', $fields['message']) < 10) throw new InvalidArgumentException('Please tell us a little more (at least 10 characters).');
    if (!in_array($fields['interest'], ['unsure','managed-it','security','microsoft-365','backup','support','guidance'], true)) {
        throw new InvalidArgumentException('Please select a service interest.');
    }
    return $fields;
}

function mmit_inquiry_write(string $path, array $data): void
{
    $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json)) {
        @unlink($tmp);
        throw new RuntimeException('Private storage write failed.');
    }
    if (!chmod($tmp, 0600) || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Private storage commit failed.');
    }
}

function mmit_inquiry_rate_limit(string $dir, string $ip, int $now): bool
{
    $path = $dir . '/rate-' . hash('sha256', $ip) . '.json';
    $handle = fopen($path, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Rate limit unavailable.');
    try {
        $raw = stream_get_contents($handle);
        $state = $raw ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        if (!$state || $now - $state['start'] >= 3600) $state = ['start'=>$now, 'count'=>0];
        if ($state['count'] >= 10) return false;
        $state['count']++;
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
            throw new RuntimeException('Rate limit write failed.');
        }
        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function mmit_inquiry_brevo(string $key, string $endpoint, array $payload): int
{
    if (!in_array($endpoint, ['contacts', 'events'], true)) throw new RuntimeException('Unsupported endpoint.');
    $curl = curl_init('https://api.brevo.com/v3/' . $endpoint);
    curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['api-key: ' . $key, 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15]);
    $response = curl_exec($curl);
    $code = $response === false ? 0 : (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return $code;
}

/** Caller must hold the per-inquiry lock. An uncertain event is never blindly resent. */
function mmit_inquiry_deliver(string $path, array $fields, string $id, string $environment, callable $transport): bool
{
    if (!in_array($environment, ['staging', 'production'], true)) throw new RuntimeException('Invalid environment.');
    $hash = hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
    if (is_file($path)) {
        $record = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($record['hash'] ?? '') !== $hash || ($record['id'] ?? '') !== $id || ($record['environment'] ?? '') !== $environment) {
            throw new InvalidArgumentException('This inquiry reference was already used. Reload before sending a different inquiry.');
        }
        if ($record['status'] === 'delivered') return true;
        if (in_array($record['status'], ['sending_event', 'needs_review'], true)) return false;
    } else {
        $record = ['id'=>$id, 'environment'=>$environment, 'hash'=>$hash, 'created_at'=>gmdate('c'), 'status'=>'received', 'fields'=>$fields];
        mmit_inquiry_write($path, $record);
    }
    if ($record['status'] !== 'contact_ready') {
        // Upsert identity only: no list enrollment, attribute overwrite or unsubscribe reset.
        $code = $transport('contacts', ['email'=>$fields['email'], 'updateEnabled'=>true]);
        if ($code < 200 || $code >= 300) return false;
        $record['status'] = 'contact_ready';
        mmit_inquiry_write($path, $record);
    }
    $record['status'] = 'sending_event';
    mmit_inquiry_write($path, $record);
    $code = $transport('events', [
        'event_name'=>$environment === 'staging' ? 'mmit_inquiry_staging' : 'mmit_inquiry_received',
        'event_date'=>$record['created_at'],
        'identifiers'=>['email_id'=>$fields['email']],
        'event_properties'=>array_merge($fields, ['inquiry_id'=>$id, 'source'=>'website_contact', 'environment'=>$environment, 'marketing_opt_in'=>false]),
    ]);
    $record['status'] = ($code >= 200 && $code < 300) ? 'delivered' : 'needs_review';
    $record['event_http_code'] = $code;
    mmit_inquiry_write($path, $record);
    return $record['status'] === 'delivered';
}
