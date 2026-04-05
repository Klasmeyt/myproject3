
-- Create Database
CREATE DATABASE `myproject4` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `myproject4`;

-- Users Table
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firstName` varchar(100) NOT NULL,
  `lastName` varchar(100) NOT NULL,
  `email` varchar(255) UNIQUE NOT NULL,
  `password` varchar(255) NOT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `role` enum('Farmer','Agriculture Official','Admin') NOT NULL DEFAULT 'Farmer',
  `status` enum('Active','Inactive','Pending') DEFAULT 'Active',
  `createdAt` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `resetToken` varchar(255) DEFAULT NULL,
  `resetExpires` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_email` (`email`),
  INDEX `idx_role_status` (`role`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Farms Table
CREATE TABLE `farms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `ownerId` int(11) NOT NULL,
  `ownerName` varchar(255) NOT NULL,
  `type` enum('Cattle','Swine','Poultry','Goat','Mixed') NOT NULL,
  `address` text,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `area` decimal(8,2) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `createdAt` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ownerId` (`ownerId`),
  FOREIGN KEY (`ownerId`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Livestock Table
CREATE TABLE `livestock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `farmId` int(11) NOT NULL,
  `type` enum('Cattle','Swine','Poultry','Goat','Sheep') NOT NULL,
  `tagId` varchar(100) NOT NULL,
  `breed` varchar(100) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `weight` decimal(6,2) DEFAULT NULL,
  `healthStatus` enum('Healthy','Sick','Recovered','Deceased') DEFAULT 'Healthy',
  `vaccinationStatus` enum('Complete','Partial','None') DEFAULT 'None',
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `qty` int(11) DEFAULT 1,
  `notes` text,
  `createdAt` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `farmId` (`farmId`),
  FOREIGN KEY (`farmId`) REFERENCES `farms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Incidents Table
CREATE TABLE `incidents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `farmId` int(11) DEFAULT NULL,
  `reporterId` int(11) DEFAULT NULL,
  `type` enum('Sick','Dead','Stray','Disease','Others') NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `status` enum('Pending','In Progress','Resolved','Closed') DEFAULT 'Pending',
  `priority` enum('Low','Medium','High','Critical') DEFAULT 'Medium',
  `photoUrl` varchar(500) DEFAULT NULL,
  `videoUrl` varchar(500) DEFAULT NULL,
  `createdAt` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `resolvedAt` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `farmId` (`farmId`),
  KEY `reporterId` (`reporterId`),
  FOREIGN KEY (`farmId`) REFERENCES `farms` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`reporterId`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Public Reports Table
CREATE TABLE `public_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reportType` enum('Sick','Dead','Stray','Disease','Others') NOT NULL,
  `otherType` varchar(255) DEFAULT NULL,
  `description` text NOT NULL,
  `contactPhone` varchar(20) NOT NULL,
  `contactEmail` varchar(255) DEFAULT NULL,
  `idPhotoUrl` varchar(500) DEFAULT NULL,
  `facePhotoUrl` varchar(500) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `status` enum('Pending','Reviewed','Resolved','Closed') DEFAULT 'Pending',
  `createdAt` timestamp DEFAULT CURRENT_TIMESTAMP,
  `reviewedAt` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit Log Table
CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userId` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `tableName` varchar(50) DEFAULT NULL,
  `recordId` int(11) DEFAULT NULL,
  `ipAddress` varchar(45) DEFAULT NULL,
  `userAgent` text,
  `details` json DEFAULT NULL,
  `createdAt` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `userId` (`userId`),
  FOREIGN KEY (`userId`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions table to store officer-specific permissions
CREATE TABLE `officer_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `officer_id` int(11) NOT NULL,
  `permission_key` varchar(100) NOT NULL,
  `is_enabled` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_officer_perm` (`officer_id`, `permission_key`),
  KEY `officer_id` (`officer_id`),
  FOREIGN KEY (`officer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Officer Profiles Table (for extended user profile data)
CREATE TABLE `officer_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `gov_id` varchar(100) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL,
  `office` varchar(255) DEFAULT NULL,
  `assigned_region` varchar(255) DEFAULT NULL,
  `municipality` varchar(255) DEFAULT NULL,
  `province` varchar(255) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_profile` (`user_id`),
  KEY `user_id` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert Sample Data
INSERT INTO `users` (`firstName`, `lastName`, `email`, `password`, `mobile`, `role`, `status`) VALUES
('System', 'Admin', 'admin@agritrace.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '+63 999 000 0001', 'Admin', 'Active');

-- Password for all users: 'password' (hashed)



SELECT farms.*, users.firstName, users.lastName, 
       (SELECT SUM(qty) FROM livestock WHERE farmId = farms.id) as total_livestock
FROM farms 
LEFT JOIN users ON farms.ownerId = users.id;




-- Create a Trigger to auto-log INSERT/UPDATE/DELETE on Users table
DELIMITER //
CREATE TRIGGER trg_audit_users
AFTER INSERT ON users
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (userId, action, tableName, recordId, details, ipAddress)
    VALUES (NEW.id, 'CREATE', 'users', NEW.id, JSON_OBJECT('email', NEW.email, 'role', NEW.role), 'SYSTEM');
END//

CREATE TRIGGER trg_audit_users_update
AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (userId, action, tableName, recordId, details, ipAddress)
    VALUES (NEW.id, 'UPDATE', 'users', NEW.id, JSON_OBJECT('old_role', OLD.role, 'new_role', NEW.role), 'SYSTEM');
END//
DELIMITER ;


-- Update ALL users with proper password hash ('password')
UPDATE `users` SET 
    `password` = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
WHERE `email` IN ('admin@agritrace.ph', 'vennethcuala@gmail.com', 'markreagan@gmail.com', 'laniecuala@gmail.com');

-- Add mobile column to users table if needed
ALTER TABLE users ADD COLUMN mobile VARCHAR(20) NULL;

ALTER TABLE `officer_profiles` 
ADD COLUMN `profile_pix` VARCHAR(255) DEFAULT NULL AFTER `user_id`;

-- Ensure the column exists
ALTER TABLE `officer_profiles` 
ADD COLUMN IF NOT EXISTS `profile_pix` VARCHAR(255) DEFAULT NULL AFTER `user_id`;

-- Insert a profile record for the user if it doesn't exist (assuming user ID 2 for Venneth)
INSERT IGNORE INTO `officer_profiles` (user_id) VALUES (2);