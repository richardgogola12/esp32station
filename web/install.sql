-- ESP32 DHT Station – databázová schéma (prefix tabuliek: esp32dth_)
-- Import cez phpMyAdmin, alebo otvor v prehliadači install.php

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `esp32dth_users` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`           VARCHAR(32)  NOT NULL,
  `email`              VARCHAR(190) NOT NULL,
  `password_hash`      VARCHAR(255) NOT NULL,
  `widget_token_hash`  CHAR(64)     NULL DEFAULT NULL,
  `widget_token_hint`  VARCHAR(8)   NULL DEFAULT NULL,
  `widget_token_enc`   VARCHAR(255) NULL DEFAULT NULL,
  `widget_refresh_min` SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  `created_at`         DATETIME     NOT NULL,
  `last_login_at`      DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_esp32dth_users_username` (`username`),
  UNIQUE KEY `uq_esp32dth_users_email` (`email`),
  UNIQUE KEY `uq_esp32dth_users_widget` (`widget_token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `esp32dth_devices` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          INT UNSIGNED NOT NULL,
  `slug`             VARCHAR(40)  NOT NULL,
  `name`             VARCHAR(60)  NOT NULL,
  `description`      VARCHAR(500) NULL DEFAULT NULL,
  `location`         VARCHAR(80)  NULL DEFAULT NULL,
  `color`            VARCHAR(7)   NOT NULL DEFAULT '#6366f1',
  `api_key_hash`     CHAR(64)     NOT NULL,
  `api_key_hint`     VARCHAR(8)   NOT NULL,
  `api_key_enc`      VARCHAR(255) NULL DEFAULT NULL,
  `interval_sec`     INT UNSIGNED NOT NULL DEFAULT 60,
  `temp_offset`      DECIMAL(4,1) NOT NULL DEFAULT 0.0,
  `hum_offset`       DECIMAL(4,1) NOT NULL DEFAULT 0.0,
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`       INT          NOT NULL DEFAULT 0,
  `last_temperature` DECIMAL(5,2) NULL DEFAULT NULL,
  `last_humidity`    DECIMAL(5,2) NULL DEFAULT NULL,
  `last_rssi`        SMALLINT     NULL DEFAULT NULL,
  `last_seen_at`     DATETIME     NULL DEFAULT NULL,
  `last_ip`          VARCHAR(45)  NULL DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_esp32dth_devices_slug` (`slug`),
  KEY `ix_esp32dth_devices_user` (`user_id`),
  CONSTRAINT `fk_esp32dth_devices_user` FOREIGN KEY (`user_id`) REFERENCES `esp32dth_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `esp32dth_readings` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`   INT UNSIGNED NOT NULL,
  `temperature` DECIMAL(5,2) NOT NULL,
  `humidity`    DECIMAL(5,2) NOT NULL,
  `rssi`        SMALLINT     NULL DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_esp32dth_readings_dev_time` (`device_id`, `created_at`),
  KEY `ix_esp32dth_readings_time` (`created_at`),
  CONSTRAINT `fk_esp32dth_readings_device` FOREIGN KEY (`device_id`) REFERENCES `esp32dth_devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `esp32dth_webauthn` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `credential_id`  VARCHAR(255) NOT NULL,
  `public_key_pem` TEXT         NOT NULL,
  `sign_count`     INT UNSIGNED NOT NULL DEFAULT 0,
  `name`           VARCHAR(80)  NOT NULL,
  `created_at`     DATETIME     NOT NULL,
  `last_used_at`   DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_esp32dth_webauthn_cred` (`credential_id`),
  KEY `ix_esp32dth_webauthn_user` (`user_id`),
  CONSTRAINT `fk_esp32dth_webauthn_user` FOREIGN KEY (`user_id`) REFERENCES `esp32dth_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `esp32dth_login_attempts` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`         VARCHAR(45)  NOT NULL,
  `username`   VARCHAR(100) NOT NULL DEFAULT '',
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_esp32dth_attempts_ip_time` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
