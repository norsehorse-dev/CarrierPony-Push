<?php
// Drop a device on opt-out or relay change.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../respond.php';

$in = cp_input();
$a = cp_require($in, ['wake_token']);
if (!preg_match('/^[0-9a-f]{64}$/', (string) $a['wake_token'])) {
    cp_json(400, ['error' => 'bad_wake_token']);
}
cp_db()->prepare('DELETE FROM gateway_devices WHERE wake_token = ?')->execute([$a['wake_token']]);
cp_json(200, ['ok' => true]);
