-- AirX Database Schema
-- Compatible with MySQL 5.7+ / 8.0+ / MariaDB 10.3+

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------
-- Table: hospital
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hospital` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `addressLine1` VARCHAR(250) NOT NULL DEFAULT '',
  `addressLine2` VARCHAR(250) NOT NULL DEFAULT '',
  `city` VARCHAR(100) NOT NULL DEFAULT '',
  `state` VARCHAR(60) NOT NULL DEFAULT '',
  `hospitals_type` VARCHAR(100) NOT NULL DEFAULT '',
  `bedCap` INT(11) NOT NULL DEFAULT 0,
  `departs` VARCHAR(255) NOT NULL DEFAULT '',
  `oxygenSource` VARCHAR(50) NOT NULL DEFAULT '',
  `powerBackup` TINYINT(4) NOT NULL DEFAULT 0,
  `technicals` VARCHAR(50) NOT NULL DEFAULT '',
  `contactPerson` VARCHAR(150) NOT NULL DEFAULT '',
  `contactRole` VARCHAR(100) NOT NULL DEFAULT '',
  `contactPhone` VARCHAR(25) NOT NULL DEFAULT '',
  `contactEmail` VARCHAR(255) NOT NULL DEFAULT '',
  `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` VARCHAR(200) NOT NULL DEFAULT 'active',
  `address_line1` VARCHAR(191) DEFAULT NULL,
  `address_line2` VARCHAR(191) DEFAULT NULL,
  `bed_cap` INT(11) UNSIGNED DEFAULT NULL,
  `oxygen_source` VARCHAR(191) DEFAULT NULL,
  `power_backup` VARCHAR(191) DEFAULT NULL,
  `contact_person` VARCHAR(191) DEFAULT NULL,
  `contact_role` VARCHAR(191) DEFAULT NULL,
  `contact_phone` VARCHAR(191) DEFAULT NULL,
  `contact_email` VARCHAR(191) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: user
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(200) NOT NULL,
  `pwd` VARCHAR(255) NOT NULL,
  `privileges` VARCHAR(50) NOT NULL DEFAULT 'hospital',
  `org_id` INT(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_email` (`email`),
  KEY `idx_user_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: facility_monthly_usage
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `facility_monthly_usage` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `hospital_id` INT(11) NOT NULL,
  `hospitalID` INT(11) NOT NULL,
  `period` VARCHAR(7) NOT NULL,
  `oxygen_used_m3` DECIMAL(10,2) NOT NULL,
  `created_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_hospital_period` (`hospital_id`, `period`),
  KEY `idx_hospital_id` (`hospital_id`),
  KEY `idx_hospitalID` (`hospitalID`),
  KEY `idx_period` (`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: predictions
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `predictions` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `hospital_id` INT(11) UNSIGNED DEFAULT NULL,
  `predictions` DECIMAL(10,2) DEFAULT NULL,
  `accuracy` DECIMAL(6,2) DEFAULT 0.00,
  `tym` INT(11) UNSIGNED DEFAULT NULL,
  `method` VARCHAR(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_predictions_hospital` (`hospital_id`),
  KEY `idx_predictions_tym` (`tym`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: oxygenorder
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `oxygenorder` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_by` VARCHAR(191) DEFAULT NULL,
  `payment` VARCHAR(191) DEFAULT 'Pending',
  `qty` INT(11) UNSIGNED DEFAULT NULL,
  `product` INT(11) UNSIGNED DEFAULT NULL,
  `discount` DOUBLE DEFAULT 0,
  `tym` INT(11) UNSIGNED DEFAULT NULL,
  `urgency` VARCHAR(191) DEFAULT 'Standard',
  `order_type` VARCHAR(191) DEFAULT 'Refill',
  `schedule_date` DATE DEFAULT NULL,
  `schedule_time` VARCHAR(191) DEFAULT NULL,
  `order_state` VARCHAR(191) DEFAULT 'Pending',
  `personnel_name` VARCHAR(191) DEFAULT NULL,
  `usage_info` VARCHAR(191) DEFAULT NULL,
  `channel` VARCHAR(191) DEFAULT 'AirX',
  `order_source` VARCHAR(191) DEFAULT 'web',
  `price` VARCHAR(191) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order_by` (`order_by`),
  KEY `idx_order_tym` (`tym`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: support
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `support` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject` VARCHAR(191) DEFAULT NULL,
  `category` VARCHAR(191) DEFAULT NULL,
  `message` TEXT DEFAULT NULL,
  `ref_id` INT(11) UNSIGNED DEFAULT NULL,
  `created_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_support_ref` (`ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: login_attempts (Rate Limiting)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `ip` VARCHAR(64) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `tym` INT(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip_tym` (`ip`, `tym`),
  KEY `idx_email_tym` (`email`, `tym`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: oxygen (Standard Cylinder Catalog)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `oxygen` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `size` VARCHAR(100) NOT NULL,
  `volume_m3` DECIMAL(5,2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default cylinder sizes
INSERT IGNORE INTO `oxygen` (`id`, `name`, `size`, `volume_m3`) VALUES
  (1, 'Large Cylinder', '8 Cubic Meter', 8.00),
  (2, 'Medium Cylinder', '6 Cubic Meter', 6.00),
  (3, 'Small Cylinder', '2 Cubic Meter', 2.00);

SET FOREIGN_KEY_CHECKS = 1;
