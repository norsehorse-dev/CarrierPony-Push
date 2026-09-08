<?php
// Register (or refresh) a device's push token, and return its stable wake token.
// No PGP identity here: the gateway holds only push tokens and the opaque wake
// tokens that authorize a nudge. A device is keyed by its 32-hex device_id.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../respond.php';

$in = cp_input();
$a = cp_require($in, ['device_id', 'push_token']);

if (!preg_match('/^[0-9a-f]{32}$/', (string) $a['device_id'])) {
    cp_json(400, ['error' => 'bad_device_id']);
}
$token = substr((string) $a['push_token'], 0, 255);

$platform = strtolower((string) ($in['platform'] ?? 'apns'));
if (!in_array($platform, ['apns', 'fcm'], true)) {
    cp_json(400, ['error' => 'bad_platform']);
}
$apnsEnv = isset($in['apns_env']) ? substr((string) $in['apns_env'], 0, 10) : null;

$db = cp_db();
$sel = $db->prepare('SELECT wake_token FROM gateway_devices WHERE device_id = ?');
$sel->execute([$a['device_id']]);
$row = $sel->fetch();

if ($row) {
    $wake = (string) $row['wake_token'];
    $db->prepare(
        'UPDATE gateway_devices SET push_token = ?, platform = ?, apns_env = ?, last_active = UTC_TIMESTAMP() WHERE device_id = ?'
    )->execute([$token, $platform, $apnsEnv, $a['device_id']]);
} else {
    $wake = bin2hex(random_bytes(32));
    $db->prepare(
        'INSERT INTO gateway_devices (device_id, wake_token, push_token, platform, apns_env) VALUES (?, ?, ?, ?, ?)'
    )->execute([$a['device_id'], $wake, $token, $platform, $apnsEnv]);
}

cp_json(200, ['ok' => true, 'wake_token' => $wake]);
