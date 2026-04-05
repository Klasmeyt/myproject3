
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

-- Insert Sample Data
INSERT INTO `users` (`firstName`, `lastName`, `email`, `password`, `mobile`, `role`, `status`) VALUES
('System', 'Admin', 'admin@agritrace.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '+63 999 000 0001', 'Admin', 'Active');

-- Password for all users: 'password' (hashed)