CREATE TABLE IF NOT EXISTS gateway_devices (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id    CHAR(32)     NOT NULL,
  wake_token   CHAR(64)     NOT NULL,
  push_token   VARCHAR(255)     NULL,
  platform     VARCHAR(8)   NOT NULL DEFAULT 'apns',
  apns_env     VARCHAR(10)      NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_active  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_wake_at DATETIME         NULL,
  last_alert_at DATETIME        NULL,
  UNIQUE KEY uq_device (device_id),
  UNIQUE KEY uq_wake (wake_token),
  KEY k_active (last_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
