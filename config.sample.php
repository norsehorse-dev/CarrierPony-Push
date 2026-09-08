<?php

// CarrierPony Push Gateway configuration. Copy to config.php and fill in.
// config.php sits above the web root and is never served.
//
// The APNs and FCM credentials are the SAME ones the messaging relay uses.
// This service only ever sends content-free pushes, but it needs the publisher
// credentials to reach the published app, which is the whole reason it exists.

return [
    'db' => [
        'host'    => '127.0.0.1',
        'name'    => 'carrierpony_push',
        'user'    => 'carrierpony_push',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // At most one wake per device per this many seconds. A burst of messages
    // becomes a single nudge; the app polls everything waiting on wake.
    'wake_coalesce_seconds' => 45,

    // Drop a device with no register-push and no wake for this many days. The
    // app re-registers silently on next launch if its token was expired.
    'token_idle_days'       => 30,

    'apns' => [
        'enabled'     => true,
        'key_file'    => '/etc/carrierpony/AuthKey_XXXXXXXXXX.p8',
        'key_id'      => 'XXXXXXXXXX',
        'team_id'     => 'XXXXXXXXXX',
        'bundle_id'   => 'com.carrierpony.app',
        'environment' => 'production',
    ],

    'fcm' => [
        'enabled'              => true,
        'service_account_file' => '/etc/carrierpony/fcm-service-account.json',
        'token_cache'          => '/var/lib/carrierpony-push/fcm_token.json',
    ],
];
