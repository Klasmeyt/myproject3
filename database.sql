-- ============================================================
-- AgriTrace+ Camarines Sur — Full Database Schema
-- Database: myproject4
-- Run this in phpMyAdmin on XAMPP
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+08:00";
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `myagri1` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `myagri1`;

-- ────────────────────────────────────────────────────────────
-- 1. USERS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`               INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  `firstName`        VARCHAR(80)     NOT NULL,
  `lastName`         VARCHAR(80)     NOT NULL,
  `email`            VARCHAR(180)    NOT NULL UNIQUE,
  `mobile`           VARCHAR(20)     DEFAULT NULL,
  `password`         VARCHAR(255)    NOT NULL,
  `role`             ENUM('Admin','DA_Officer','Farmer') NOT NULL DEFAULT 'Farmer',
  `status`           ENUM('Active','Inactive','Suspended','Pending') NOT NULL DEFAULT 'Pending',
  `email_verified`   TINYINT(1)      NOT NULL DEFAULT 0,
  `mobile_verified`  TINYINT(1)      NOT NULL DEFAULT 0,
  `profile_image`    VARCHAR(255)    DEFAULT NULL,
  `last_login`       DATETIME        DEFAULT NULL,
  `login_attempts`   TINYINT         NOT NULL DEFAULT 0,
  `locked_until`     DATETIME        DEFAULT NULL,
  `createdAt`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_role`   (`role`),
  INDEX `idx_status` (`status`),
  INDEX `idx_mobile` (`mobile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- 2. OTP / VERIFICATION TOKENS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `otp_tokens` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED    DEFAULT NULL,
  `identifier`  VARCHAR(180)    NOT NULL COMMENT 'email or mobile',
  `otp_code`    VARCHAR(10)     NOT NULL,
  `type`        ENUM('email_verify','sms_verify','forgot_password','login_mfa') NOT NULL,
  `expires_at`  DATETIME        NOT NULL,
  `used`        TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_identifier` (`identifier`),
  INDEX `idx_type`       (`type`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- 3. OFFICER PROFILES
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `officer_profiles` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`         INT UNSIGNED NOT NULL UNIQUE,
  `gov_id`          VARCHAR(60)  DEFAULT NULL,
  `department`      VARCHAR(120) DEFAULT NULL,
  `position`        VARCHAR(120) DEFAULT NULL,
  `office`          VARCHAR(120) DEFAULT NULL,
  `assigned_region` VARCHAR(80)  DEFAULT NULL,
  `province`        VARCHAR(80)  DEFAULT NULL,
  `municipality`    VARCHAR(80)  DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_op_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- 4. FARMER PROFILES
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `farmer_profiles` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL UNIQUE,
  `profile_pix` VARCHAR(255) DEFAULT NULL,
  `barangay`    VARCHAR(80)  DEFAULT NULL,
  `municipality`VARCHAR(80)  DEFAULT NULL,
  `province`    VARCHAR(80)  DEFAULT NULL,
  `bio`         TEXT         DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_fp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- 5. ROLES & PERMISSIONS (for DA Officers)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `roles` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(80)  NOT NULL UNIQUE,
  `description` TEXT         DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `permissions` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `module`      VARCHAR(80)  NOT NULL,
  `action`      VARCHAR(80)  NOT NULL,
  `label`       VARCHAR(120) DEFAULT NULL,
  UNIQUE KEY `uq_mod_act` (`module`,`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id`       INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_roles` (
  `user_id` INT UNSIGNED NOT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`,`role_id`),
  CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- 6. FARMS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `farms` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ownerId`          INT UNSIGNED NOT NULL,
  `name`             VARCHAR(160) NOT NULL,
  `farmType`         VARCHAR(60)  NOT NULL,
  `address`          TEXT         DEFAULT NULL,
  `barangay`         VARCHAR(80)  DEFAULT NULL,
  `municipality`     VARCHAR(80)  DEFAULT NULL,
  `province`         VARCHAR(80)  NOT NULL DEFAULT 'Camarines Sur',
  `area_ha`          DECIMAL(10,2) DEFAULT NULL,
  `latitude`         DECIMAL(10,8) DEFAULT NULL,
  `longitude`        DECIMAL(11,8) DEFAULT NULL,
  `photo_path`       VARCHAR(255) DEFAULT NULL,
  `status`           ENUM('Pending','Approved','Rejected','Under Appeal','Suspended') NOT NULL DEFAULT 'Pending',
  `rejection_reason` TEXT         DEFAULT NULL,
  `appeal_id`        INT UNSIGNED DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `createdAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updatedAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_owner`  (`ownerId`),
  INDEX `idx_status` (`status`),
  INDEX `idx_coords` (`latitude`,`longitude`),
  CONSTRAINT `fk_farm_owner` FOREIGN KEY (`ownerId`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- 7. FARM APPEALS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `farm_appeals` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `farm_id`          INT UNSIGNED NOT NULL,
  `user_id`          INT UNSIGNED NOT NULL,
  `farm_name`        VARCHAR(160) DEFAULT NULL,
  `original_status`  VARCHAR(40)  DEFAULT NULL,
  `rejection_reason` TEXT         DEFAULT NULL,
  `appeal_status`    ENUM('Pending','Under Review','Approved','Denied') NOT NULL DEFAULT 'Pending',
  `appeal_notes`     TEXT         DEFAULT NULL,
  `reviewed_by`      INT UNSIGNED DEFAULT NULL,
  `reviewed_at`      DATETIME     DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_farm`   (`farm_id`),
  INDEX `idx_status` (`appeal_status`),
  CONSTRAINT `fk_appeal_farm` FOREIGN KEY (`farm_id`) REFERENCES `farms`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_appeal_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- 8. LIVESTOCK
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `livestock` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `farmId`      INT UNSIGNED NOT NULL,
  `type`        VARCHAR(60)  NOT NULL,
  `breed`       VARCHAR(80)  DEFAULT NULL,
  `qty`         INT UNSIGNED NOT NULL DEFAULT 0,
  `age_months`  INT          DEFAULT NULL,
  `health`      ENUM('Healthy','At Risk','Sick','Dead') NOT NULL DEFAULT 'Healthy',
  `notes`       TEXT         DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_farm` (`farmId`),
  INDEX `idx_type` (`type`),
  CONSTRAINT `fk_ls_farm` FOREIGN KEY (`farmId`) REFERENCES `farms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- 9. INCIDENTS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `incidents` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `farmId`      INT UNSIGNED NOT NULL,
  `userId`      INT UNSIGNED NOT NULL,
  `title`       VARCHAR(200) NOT NULL,
  `description` TEXT         NOT NULL,
  `type`        VARCHAR(80)  DEFAULT NULL,
  `priority`    ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'Medium',
  `status`      ENUM('Pending','Under Review','Resolved','Closed') NOT NULL DEFAULT 'Pending',
  `latitude`    DECIMAL(10,8) DEFAULT NULL,
  `longitude`   DECIMAL(11,8) DEFAULT NULL,
  `media_path`  VARCHAR(255) DEFAULT NULL,
  `resolved_by` INT UNSIGNED DEFAULT NULL,
  `resolved_at` DATETIME     DEFAULT NULL,
  `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_farm`   (`farmId`),
  INDEX `idx_status` (`status`),
  CONSTRAINT `fk_inc_farm` FOREIGN KEY (`farmId`) REFERENCES `farms`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inc_user` FOREIGN KEY (`userId`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- 10. PUBLIC REPORTS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `public_reports` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `report_type`   VARCHAR(60)  NOT NULL,
  `other_type`    VARCHAR(120) DEFAULT NULL,
  `description`   TEXT         NOT NULL,
  `contact_phone` VARCHAR(25)  NOT NULL,
  `contact_email` VARCHAR(180) DEFAULT NULL,
  `latitude`      DECIMAL(10,8) DEFAULT NULL,
  `longitude`     DECIMAL(11,8) DEFAULT NULL,
  `location_text` TEXT          DEFAULT NULL,
  `media_path`    VARCHAR(255) DEFAULT NULL,
  `id_photo`      VARCHAR(255) DEFAULT NULL,
  `face_photo`    VARCHAR(255) DEFAULT NULL,
  `status`        ENUM('Pending','Under Review','Resolved','Dismissed') NOT NULL DEFAULT 'Pending',
  `assigned_to`   INT UNSIGNED DEFAULT NULL,
  `notes`         TEXT         DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- 11. FARM CERTIFICATES
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `farm_certificates` (
  `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `farm_id`            INT UNSIGNED NOT NULL,
  `certificate_number` VARCHAR(60)  NOT NULL UNIQUE,
  `certificate_type`   VARCHAR(120) NOT NULL,
  `issued_by`          INT UNSIGNED NOT NULL,
  `issued_date`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `valid_from`         DATE         NOT NULL,
  `valid_until`        DATE         NOT NULL,
  `status`             ENUM('Active','Expired','Revoked') NOT NULL DEFAULT 'Active',
  `revoke_reason`      TEXT         DEFAULT NULL,
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_farm_id`  (`farm_id`),
  INDEX `idx_status`   (`status`),
  CONSTRAINT `fk_cert_farm`    FOREIGN KEY (`farm_id`)   REFERENCES `farms`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cert_officer` FOREIGN KEY (`issued_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- 12. CHAT MESSAGES
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sender_id`   INT UNSIGNED NOT NULL,
  `receiver_id` INT UNSIGNED NOT NULL,
  `message`     TEXT         NOT NULL,
  `is_read`     TINYINT(1)   NOT NULL DEFAULT 0,
  `read_at`     DATETIME     DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sender`   (`sender_id`),
  INDEX `idx_receiver` (`receiver_id`),
  INDEX `idx_convo`    (`sender_id`,`receiver_id`),
  CONSTRAINT `fk_cm_sender`   FOREIGN KEY (`sender_id`)   REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cm_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- 13. NOTIFICATIONS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = admin broadcast',
  `type`         VARCHAR(60)  NOT NULL,
  `title`        VARCHAR(200) NOT NULL,
  `message`      TEXT         NOT NULL,
  `related_id`   INT UNSIGNED DEFAULT NULL,
  `related_type` VARCHAR(40)  DEFAULT NULL,
  `status`       ENUM('Unread','Read') NOT NULL DEFAULT 'Unread',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user`   (`user_id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- 14. AUDIT LOG
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `userId`     INT UNSIGNED DEFAULT NULL,
  `action`     VARCHAR(80)  NOT NULL,
  `tableName`  VARCHAR(60)  DEFAULT NULL,
  `recordId`   INT UNSIGNED DEFAULT NULL,
  `ipAddress`  VARCHAR(45)  DEFAULT NULL,
  `userAgent`  VARCHAR(255) DEFAULT NULL,
  `details`    JSON         DEFAULT NULL,
  `createdAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user`   (`userId`),
  INDEX `idx_action` (`action`),
  INDEX `idx_date`   (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- 15. SYSTEM CONFIG
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `config` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key`        VARCHAR(80)  NOT NULL UNIQUE,
  `value`      TEXT         DEFAULT NULL,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default config (no demo accounts, just settings)
INSERT IGNORE INTO `config` (`key`, `value`) VALUES
('system_name',       'AgriTrace+'),
('system_region',     'Camarines Sur'),
('smtp_host',         'smtp.gmail.com'),
('smtp_port',         '587'),
('smtp_encryption',   'TLS'),
('smtp_username',     ''),
('smtp_password',     ''),
('smtp_from_email',   'noreply@agritrace.ph'),
('smtp_from_name',    'AgriTrace+ Camarines Sur'),
('sms_provider',      'Semaphore'),
('sms_api_key',       ''),
('sms_sender_id',     'AgriTrace'),
('contact_email',     'support@agritrace.ph'),
('contact_phone',     '+63 2 8XXX XXXX'),
('session_timeout',   '30'),
('max_login_attempts','5'),
('otp_expiry_minutes','10'),
('backup_frequency',  '7');

-- ────────────────────────────────────────────────────────────
-- 16. CONTACT MESSAGES
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `contact_messages` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(120) NOT NULL,
  `email`      VARCHAR(180) NOT NULL,
  `subject`    VARCHAR(200) NOT NULL,
  `message`    TEXT         NOT NULL,
  `status`     ENUM('New','Read','Replied') NOT NULL DEFAULT 'New',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- 17. INSPECTION RECORDS (DA Officer)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `inspections` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `farm_id`       INT UNSIGNED NOT NULL,
  `officer_id`    INT UNSIGNED NOT NULL,
  `inspection_date` DATE       NOT NULL,
  `findings`      TEXT         DEFAULT NULL,
  `result`        ENUM('Pass','Fail','Conditional') NOT NULL DEFAULT 'Pass',
  `notes`         TEXT         DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_farm`    (`farm_id`),
  INDEX `idx_officer` (`officer_id`),
  CONSTRAINT `fk_insp_farm`    FOREIGN KEY (`farm_id`)    REFERENCES `farms`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_insp_officer` FOREIGN KEY (`officer_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- AgriTrace+ Camarines Sur — E-Certificate Migration
-- Run this on your myproject4 database
-- ============================================================

CREATE TABLE IF NOT EXISTS `farm_certificates` (
    `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `farm_id`            INT UNSIGNED NOT NULL,
    `certificate_number` VARCHAR(60)  NOT NULL UNIQUE,
    `certificate_type`   VARCHAR(120) NOT NULL,
    `issued_by`          INT UNSIGNED NOT NULL COMMENT 'users.id of issuing officer',
    `issued_date`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `valid_from`         DATE         NOT NULL,
    `valid_until`        DATE         NOT NULL,
    `status`             ENUM('Active','Expired','Revoked') NOT NULL DEFAULT 'Active',
    `revoke_reason`      TEXT         NULL,
    `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_farm_id`   (`farm_id`),
    INDEX `idx_status`    (`status`),
    INDEX `idx_valid`     (`valid_until`),

    CONSTRAINT `fk_cert_farm`
        FOREIGN KEY (`farm_id`)   REFERENCES `farms`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_cert_officer`
        FOREIGN KEY (`issued_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auto-expire cron: run daily via MySQL event or PHP cron
-- UPDATE farm_certificates SET status = 'Expired' WHERE valid_until < CURDATE() AND status = 'Active';

-- Event scheduler (enable in MySQL if needed: SET GLOBAL event_scheduler = ON;)
CREATE EVENT IF NOT EXISTS `expire_certificates`
    ON SCHEDULE EVERY 1 DAY
    STARTS CURRENT_TIMESTAMP
    DO
        UPDATE `farm_certificates`
        SET `status` = 'Expired'
        WHERE `valid_until` < CURDATE()
          AND `status` = 'Active';
-- ────────────────────────────────────────────────────────────
-- 18. AUTO-EXPIRE CERTIFICATES (MySQL Event)
-- ────────────────────────────────────────────────────────────
-- Enable event scheduler: SET GLOBAL event_scheduler = ON;
CREATE EVENT IF NOT EXISTS `expire_certificates`
  ON SCHEDULE EVERY 1 DAY STARTS CURRENT_TIMESTAMP
  DO UPDATE `farm_certificates` SET `status`='Expired'
     WHERE `valid_until` < CURDATE() AND `status`='Active';

SET FOREIGN_KEY_CHECKS = 1;


USE `myagri1`;

-- Insert Admin User ONLY
INSERT INTO `users` (
    `firstName`, `lastName`, `email`, `mobile`, `password`, 
    `role`, `status`, `email_verified`, `mobile_verified`, 
    `profile_image`, `last_login`
) VALUES (
    'Admin', 'User', 'admin@agritrace.ph', '+639171234567', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: admin123
    'Admin', 'Active', 1, 1, 
    NULL, NOW()
);

-- Verify admin was created
SELECT 
    id, email, firstName, lastName, role, status,
    'admin123' as `plain_password`
FROM users 
WHERE email = 'admin@agritrace.ph';

