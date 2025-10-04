-- MySQL 8.x schema for SagipHub backend
-- Charset/collation ensures full Unicode support

CREATE DATABASE IF NOT EXISTS `sagiphub` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `sagiphub`;

-- Table: help_requests
-- Stores submitted requests. PII fields should never be exposed via public API.
CREATE TABLE IF NOT EXISTS `help_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(36) NOT NULL, -- UUID v4 (or ULID)
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT NOT NULL,
  `request_type` ENUM('medical','rescue','food','shelter','supplies','other') NOT NULL,
  `urgency` ENUM('critical','high','medium','low') NOT NULL,
  `people_affected` INT UNSIGNED NOT NULL DEFAULT 1,
  `latitude` DECIMAL(9,6) NOT NULL,
  `longitude` DECIMAL(9,6) NOT NULL,
  `location` POINT NOT NULL,
  -- PII: store encrypted where possible (application-level crypto)
  `contact_number` VARBINARY(256) NULL, -- ciphertext (AES-GCM) recommended
  `contact_last4` VARCHAR(8) NULL, -- for minimal display when needed
  `edit_token_hash` VARBINARY(32) NULL, -- SHA-256(token)
  `status` ENUM('active','withdrawn','resolved') NOT NULL DEFAULT 'active',
  `submitted_ip` VARBINARY(16) NULL, -- INET6_ATON(ip)
  `submitted_user_agent` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_help_requests_public_id` (`public_id`),
  KEY `ix_help_requests_created_at` (`created_at`),
  KEY `ix_help_requests_status_urgency` (`status`, `urgency`),
  KEY `ix_help_requests_request_type` (`request_type`),
  KEY `ix_help_requests_edit_token_hash` (`edit_token_hash`),
  SPATIAL INDEX `sp_help_requests_location` (`location`)
) ENGINE=InnoDB;

-- Table: request_events
-- Immutable audit trail of state changes and important actions.
CREATE TABLE IF NOT EXISTS `request_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT UNSIGNED NOT NULL,
  `event_type` ENUM('created','updated','withdrawn','resolved','note') NOT NULL,
  `event_data` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_request_events_request_id_created_at` (`request_id`, `created_at`),
  CONSTRAINT `fk_request_events_request_id`
    FOREIGN KEY (`request_id`) REFERENCES `help_requests` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- Table: rate_limits
-- Generic sliding-window counters for IPs or fingerprints
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key_hash` VARBINARY(32) NOT NULL, -- SHA-256 of key (e.g., IP or IP+UA)
  `window_start` DATETIME(3) NOT NULL,
  `count` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_rate_limits_key_window` (`key_hash`, `window_start`),
  KEY `ix_rate_limits_window_start` (`window_start`)
) ENGINE=InnoDB;

-- Table: turnstile_verifications
-- Optional logging for CAPTCHA verification results (monitoring/debugging)
CREATE TABLE IF NOT EXISTS `turnstile_verifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip` VARBINARY(16) NULL,
  `token_hash` VARBINARY(32) NULL, -- SHA-256 of token
  `success` TINYINT(1) NOT NULL,
  `error_codes` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_turnstile_created_at` (`created_at`)
) ENGINE=InnoDB;

-- Helper generated columns or triggers (optional)
-- If desired, you can maintain POINT from lat/lng automatically via triggers.
-- Example triggers to sync `location` from lat/lng:
DELIMITER $$
CREATE TRIGGER `bi_help_requests_location`
BEFORE INSERT ON `help_requests`
FOR EACH ROW
BEGIN
  SET NEW.`location` = POINT(NEW.`longitude`, NEW.`latitude`);
END$$

CREATE TRIGGER `bu_help_requests_location`
BEFORE UPDATE ON `help_requests`
FOR EACH ROW
BEGIN
  IF NEW.`latitude` <> OLD.`latitude` OR NEW.`longitude` <> OLD.`longitude` THEN
    SET NEW.`location` = POINT(NEW.`longitude`, NEW.`latitude`);
  END IF;
END$$
DELIMITER ;

-- Views for public read API (omits PII)
CREATE OR REPLACE VIEW `v_public_help_requests` AS
SELECT
  `public_id`,
  `title`,
  `description`,
  `request_type`,
  `urgency`,
  `people_affected`,
  `latitude`,
  `longitude`,
  `status`,
  `created_at`,
  `updated_at`
FROM `help_requests`
WHERE `status` IN ('active','resolved');


