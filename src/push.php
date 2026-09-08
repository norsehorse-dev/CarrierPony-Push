<?php

// Content-free push senders, reused from the CarrierPony relay. The gateway
// sends the same two payload shapes the relay does: a "New message" alert for a
// real message, a silent background wake for a control message. Neither carries
// a sender or any content. The only difference from the relay is the table:
// tokens and apns_env writebacks live in gateway_devices.

require_once __DIR__ . '/db.php';

// ── APNs ───────────────────────────────────────────────────────────────

function cp_apns_jwt(): ?string
{
    static $cached = null;
    static $cachedAt = 0;

    $cfg = cp_config()['apns'] ?? null;
    if (!$cfg || empty($cfg['enabled'])) {
        return null;
    }
    if ($cached !== null && (time() - $cachedAt) < 3000) {
        return $cached;
    }

    $header = ['alg' => 'ES256', 'kid' => $cfg['key_id']];
    $claims = ['iss' => $cfg['team_id'], 'iat' => time()];
    $seg = static fn($d) => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
    $signingInput = $seg($header) . '.' . $seg($claims);

    $pkey = @file_get_contents($cfg['key_file']);
    if ($pkey === false) {
        return null;
    }
    if (!openssl_sign($signingInput, $der, $pkey, OPENSSL_ALGO_SHA256)) {
        return null;
    }
    $raw = cp_der_to_raw_sig($der);
    if ($raw === null) {
        return null;
    }
    $jwt = $signingInput . '.' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    $cached = $jwt;
    $cachedAt = time();
    return $jwt;
}

function cp_der_to_raw_sig(string $der): ?string
{
    $off = 0;
    $len = strlen($der);
    if ($len < 8 || ord($der[$off++]) !== 0x30) {
        return null;
    }
    $seqLen = ord($der[$off++]);
    if ($seqLen & 0x80) {
        $n = $seqLen & 0x7f;
        $seqLen = 0;
        while ($n-- > 0) {
            $seqLen = ($seqLen << 8) | ord($der[$off++]);
        }
    }
    if (ord($der[$off++]) !== 0x02) {
        return null;
    }
    $rlen = ord($der[$off++]);
    $r = substr($der, $off, $rlen);
    $off += $rlen;
    if (ord($der[$off++]) !== 0x02) {
        return null;
    }
    $slen = ord($der[$off++]);
    $s = substr($der, $off, $slen);

    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    if (strlen($r) > 32 || strlen($s) > 32) {
        return null;
    }
    $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

function cp_apns_send(string $token, string $payload, string $environment, string $pushType = 'alert', int $priority = 10): array
{
    $cfg = cp_config()['apns'] ?? null;
    if (!$cfg) {
        return ['code' => 0, 'reason' => ''];
    }
    $jwt = cp_apns_jwt();
    if ($jwt === null) {
        return ['code' => 0, 'reason' => ''];
    }
    $host = ($environment === 'sandbox')
        ? 'api.sandbox.push.apple.com'
        : 'api.push.apple.com';

    $ch = curl_init("https://{$host}/3/device/{$token}");
    curl_setopt_array($ch, [
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'authorization: bearer ' . $jwt,
            'apns-topic: ' . $cfg['bundle_id'],
            'apns-push-type: ' . $pushType,
            'apns-priority: ' . $priority,
            'content-type: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $reason = '';
    if ($code >= 400) {
        $body = @json_decode((string) $resp, true);
        if (is_array($body)) {
            $reason = (string) ($body['reason'] ?? '');
        }
    }
    return ['code' => $code, 'reason' => $reason];
}

/** @return array{delivered:bool, dead:bool} */
function cp_gw_apns_deliver(int $devicePk, string $token, string $payload, ?string $known, string $default, string $pushType = 'alert', int $priority = 10): array
{
    $candidates = [];
    foreach ([$known, $default, 'production', 'sandbox'] as $env) {
        if ($env !== null && $env !== '' && !in_array($env, $candidates, true)) {
            $candidates[] = $env;
        }
    }

    foreach ($candidates as $env) {
        $result = cp_apns_send($token, $payload, $env, $pushType, $priority);

        if ($result['code'] === 200) {
            if ($known !== $env) {
                cp_db()->prepare('UPDATE gateway_devices SET apns_env = ? WHERE id = ?')
                    ->execute([$env, $devicePk]);
            }
            return ['delivered' => true, 'dead' => false];
        }
        if ($result['code'] === 410 || $result['reason'] === 'Unregistered') {
            return ['delivered' => false, 'dead' => true];
        }
        if ($result['code'] === 400 && $result['reason'] === 'BadDeviceToken') {
            continue;
        }
        return ['delivered' => false, 'dead' => false];
    }

    return ['delivered' => false, 'dead' => false];
}

// ── FCM (HTTP v1) ──────────────────────────────────────────────────────

function cp_fcm_access_token(): ?string
{
    $cfg = cp_config()['fcm'] ?? null;
    if (!$cfg || empty($cfg['enabled'])) {
        return null;
    }

    $cachePath = $cfg['token_cache'] ?? (sys_get_temp_dir() . '/cp_push_fcm_token.json');
    $cached = @json_decode((string) @file_get_contents($cachePath), true);
    if (is_array($cached) && isset($cached['token'], $cached['expires_at']) && time() < (int) $cached['expires_at'] - 60) {
        return (string) $cached['token'];
    }

    $sa = @json_decode((string) @file_get_contents($cfg['service_account_file']), true);
    if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
        return null;
    }

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];
    $seg = static fn($d) => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
    $signingInput = $seg($header) . '.' . $seg($claims);
    if (!openssl_sign($signingInput, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
        return null;
    }
    $jwt = $signingInput . '.' . rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $body = @json_decode((string) $resp, true);
    if (!is_array($body) || empty($body['access_token'])) {
        return null;
    }
    $token = (string) $body['access_token'];
    $ttl = isset($body['expires_in']) ? (int) $body['expires_in'] : 3600;

    @file_put_contents($cachePath, json_encode(['token' => $token, 'expires_at' => time() + $ttl]), LOCK_EX);
    @chmod($cachePath, 0600);

    return $token;
}

/** @return array{code:int, unregistered:bool} */
function cp_fcm_send(string $token, bool $silent = false): array
{
    $cfg = cp_config()['fcm'] ?? null;
    if (!$cfg || empty($cfg['enabled'])) {
        return ['code' => 0, 'unregistered' => false];
    }
    $sa = @json_decode((string) @file_get_contents($cfg['service_account_file']), true);
    $projectId = is_array($sa) ? ($sa['project_id'] ?? null) : null;
    $access = cp_fcm_access_token();
    if ($access === null || !$projectId) {
        return ['code' => 0, 'unregistered' => false];
    }

    $payload = json_encode([
        'message' => [
            'token'   => $token,
            'data'    => $silent ? ['wake' => '1', 'silent' => '1'] : ['wake' => '1'],
            'android' => ['priority' => 'HIGH'],
        ],
    ]);

    $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'authorization: Bearer ' . $access,
            'content-type: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $unregistered = false;
    if ($code === 404) {
        $unregistered = true;
    } elseif ($code >= 400) {
        $body = @json_decode((string) $resp, true);
        $status = $body['error']['details'][0]['errorCode'] ?? ($body['error']['status'] ?? '');
        if ($status === 'UNREGISTERED' || $status === 'NOT_FOUND') {
            $unregistered = true;
        }
    }
    return ['code' => $code, 'unregistered' => $unregistered];
}

// ── Dispatch ───────────────────────────────────────────────────────────

/**
 * Send one content-free push to a gateway_devices row. Clears the token if the
 * platform reports it dead. Returns true if a push was attempted.
 */
function cp_gw_wake(array $row, bool $silent = false): bool
{
    $token = (string) ($row['push_token'] ?? '');
    if ($token === '') {
        return false;
    }
    $cfg = cp_config();

    if ($silent) {
        $apnsPayload = json_encode(['aps' => ['content-available' => 1]]);
        $apnsPushType = 'background';
        $apnsPriority = 5;
    } else {
        $apnsPayload = json_encode([
            'aps' => [
                'alert'             => ['title' => 'CarrierPony', 'body' => 'New message'],
                'sound'             => 'default',
                'content-available' => 1,
            ],
        ]);
        $apnsPushType = 'alert';
        $apnsPriority = 10;
    }

    $dead = false;
    try {
        if (($row['platform'] ?? 'apns') === 'fcm') {
            if (empty($cfg['fcm']['enabled'])) {
                return false;
            }
            $r = cp_fcm_send($token, $silent);
            $dead = $r['unregistered'];
        } else {
            if (empty($cfg['apns']['enabled'])) {
                return false;
            }
            $known = isset($row['apns_env']) && $row['apns_env'] !== '' ? (string) $row['apns_env'] : null;
            $default = (string) ($cfg['apns']['environment'] ?? 'production');
            $r = cp_gw_apns_deliver((int) $row['id'], $token, $apnsPayload, $known, $default, $apnsPushType, $apnsPriority);
            $dead = $r['dead'];
        }
    } catch (\Throwable $e) {
        // best-effort
    }

    if ($dead) {
        cp_db()->prepare('UPDATE gateway_devices SET push_token = NULL WHERE id = ?')
            ->execute([(int) $row['id']]);
    }
    return true;
}
