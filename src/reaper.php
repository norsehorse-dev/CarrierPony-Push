<?php
// Expire idle devices: no register-push and no wake for token_idle_days.
require_once __DIR__ . '/db.php';

$days = (int) (cp_config()['token_idle_days'] ?? 30);
$n = cp_db()->prepare('DELETE FROM gateway_devices WHERE last_active < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)');
$n->execute([$days]);
printf("%s push-reaper: expired_devices=%d\n", gmdate('Y-m-d H:i:s'), $n->rowCount());
