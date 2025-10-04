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

-- Table: users
-- Stores both admin and public user accounts with role-based access
CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(36) NOT NULL, -- UUID v4 for public references
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL, -- bcrypt/Argon2 hash
  `first_name` VARCHAR(100) NULL,
  `last_name` VARCHAR(100) NULL,
  `phone` VARBINARY(256) NULL, -- encrypted phone number
  `phone_last4` VARCHAR(8) NULL, -- last 4 digits for display
  `role` ENUM('admin','moderator','volunteer','public') NOT NULL DEFAULT 'public',
  `status` ENUM('active','inactive','suspended','pending_verification') NOT NULL DEFAULT 'pending_verification',
  `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `phone_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `last_login_at` TIMESTAMP NULL,
  `last_login_ip` VARBINARY(16) NULL, -- INET6_ATON(ip)
  `failed_login_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` TIMESTAMP NULL, -- account lockout timestamp
  `password_reset_token` VARCHAR(255) NULL,
  `password_reset_expires` TIMESTAMP NULL,
  `email_verification_token` VARCHAR(255) NULL,
  `email_verification_expires` TIMESTAMP NULL,
  `preferences` JSON NULL, -- user preferences and settings
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_users_public_id` (`public_id`),
  UNIQUE KEY `ux_users_username` (`username`),
  UNIQUE KEY `ux_users_email` (`email`),
  KEY `ix_users_role_status` (`role`, `status`),
  KEY `ix_users_email_verified` (`email_verified`),
  KEY `ix_users_created_at` (`created_at`),
  KEY `ix_users_last_login` (`last_login_at`),
  KEY `ix_users_password_reset_token` (`password_reset_token`),
  KEY `ix_users_email_verification_token` (`email_verification_token`)
) ENGINE=InnoDB;

-- Table: user_sessions
-- Stores active user sessions for security and session management
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `session_token` VARCHAR(255) NOT NULL, -- hashed session token
  `ip_address` VARBINARY(16) NULL, -- INET6_ATON(ip)
  `user_agent` VARCHAR(500) NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_user_sessions_token` (`session_token`),
  KEY `ix_user_sessions_user_id` (`user_id`),
  KEY `ix_user_sessions_expires_at` (`expires_at`),
  KEY `ix_user_sessions_last_activity` (`last_activity`),
  CONSTRAINT `fk_user_sessions_user_id`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- Table: user_activity_log
-- Audit trail for user actions and security events
CREATE TABLE IF NOT EXISTS `user_activity_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL, -- NULL for anonymous actions
  `action` VARCHAR(100) NOT NULL, -- login, logout, password_change, etc.
  `ip_address` VARBINARY(16) NULL, -- INET6_ATON(ip)
  `user_agent` VARCHAR(500) NULL,
  `details` JSON NULL, -- additional action details
  `success` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_user_activity_user_id` (`user_id`),
  KEY `ix_user_activity_action` (`action`),
  KEY `ix_user_activity_created_at` (`created_at`),
  KEY `ix_user_activity_success` (`success`),
  CONSTRAINT `fk_user_activity_user_id`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
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

-- View for user management (admin only - includes sensitive data)
CREATE OR REPLACE VIEW `v_admin_users` AS
SELECT
  `id`,
  `public_id`,
  `username`,
  `email`,
  `first_name`,
  `last_name`,
  `phone_last4`,
  `role`,
  `status`,
  `email_verified`,
  `phone_verified`,
  `last_login_at`,
  `last_login_ip`,
  `failed_login_attempts`,
  `locked_until`,
  `created_at`,
  `updated_at`
FROM `users`;

-- View for public user profile (limited data)
CREATE OR REPLACE VIEW `v_public_user_profile` AS
SELECT
  `public_id`,
  `username`,
  `first_name`,
  `last_name`,
  `role`,
  `status`,
  `created_at`
FROM `users`
WHERE `status` = 'active';

-- Insert default admin user
-- Password: admin123 (bcrypt hash)
INSERT IGNORE INTO `users` (
  `public_id`,
  `username`,
  `email`,
  `password_hash`,
  `first_name`,
  `last_name`,
  `role`,
  `status`,
  `email_verified`,
  `created_at`
) VALUES (
  '550e8400-e29b-41d4-a716-446655440000',
  'admin',
  'admin@reliefhub.local',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- admin123
  'System',
  'Administrator',
  'admin',
  'active',
  1,
  NOW()
);


