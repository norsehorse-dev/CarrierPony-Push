<?php
// Wake one device. The wake token is the whole credential: a valid token maps
// to exactly one device, so no token means no wake. Coalesced to at most one
// push per device per window. Content-free: the optional silent flag only
// picks the payload shape (alert vs background), never carries any content.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../respond.php';
require_once __DIR__ . '/../push.php';

$in = cp_input();
$a = cp_require($in, ['wake_token']);
if (!preg_match('/^[0-9a-f]{64}$/', (string) $a['wake_token'])) {
    cp_json(400, ['error' => 'bad_wake_token']);
}
$silent = !empty($in['silent']);

$db = cp_db();
$sel = $db->prepare(
    "SELECT id, push_token, platform, apns_env, last_wake_at
     FROM gateway_devices
     WHERE wake_token = ? AND push_token IS NOT NULL AND push_token <> ''"
);
$sel->execute([$a['wake_token']]);
$row = $sel->fetch();

// Unknown or tokenless device: answer ok without saying which, so the endpoint
// can't be used to probe for valid tokens.
if (!$row) {
    cp_json(200, ['ok' => true, 'woke' => false]);
}

$window = (int) (cp_config()['wake_coalesce_seconds'] ?? 45);
$chk = $db->prepare(
    'SELECT (last_wake_at IS NOT NULL AND last_wake_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)) AS recent
     FROM gateway_devices WHERE id = ?'
);
$chk->execute([$window, (int) $row['id']]);
$recent = (int) ($chk->fetchColumn() ?: 0);

if ($recent === 1) {
    $db->prepare('UPDATE gateway_devices SET last_active = UTC_TIMESTAMP() WHERE id = ?')
        ->execute([(int) $row['id']]);
    cp_json(200, ['ok' => true, 'woke' => false, 'coalesced' => true]);
}

cp_gw_wake($row, $silent);
$db->prepare('UPDATE gateway_devices SET last_wake_at = UTC_TIMESTAMP(), last_active = UTC_TIMESTAMP() WHERE id = ?')
    ->execute([(int) $row['id']]);

cp_json(200, ['ok' => true, 'woke' => true]);
