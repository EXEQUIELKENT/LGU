-- Safe Database Update for LGU Road and Infrastructure Department
-- This script updates existing database without breaking foreign keys

USE lgu_road_infra;

-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

-- Add new columns to existing users table if they don't exist
ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `middle_name` varchar(50) DEFAULT NULL AFTER `first_name`,
ADD COLUMN IF NOT EXISTS `phone` varchar(20) DEFAULT NULL AFTER `email`,
ADD COLUMN IF NOT EXISTS `address` text DEFAULT NULL AFTER `phone`,
MODIFY COLUMN `status` enum('active','inactive','suspended','pending') NOT NULL DEFAULT 'pending';

-- Create new tables if they don't exist
CREATE TABLE IF NOT EXISTS `damage_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_id` varchar(20) NOT NULL,
  `reporter_id` int(11) NOT NULL,
  `location` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `status` enum('pending','in_progress','resolved','closed') NOT NULL DEFAULT 'pending',
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `estimated_cost` decimal(10,2) DEFAULT NULL,
  `images` text DEFAULT NULL,
  `reported_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `assigned_to` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `report_id` (`report_id`),
  KEY `reporter_id` (`reporter_id`),
  KEY `status` (`status`),
  KEY `severity` (`severity`),
  KEY `reported_at` (`reported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `cost_assessments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `assessment_id` varchar(20) NOT NULL,
  `damage_report_id` int(11) NOT NULL,
  `assessor_id` int(11) NOT NULL,
  `labor_cost` decimal(10,2) DEFAULT NULL,
  `material_cost` decimal(10,2) DEFAULT NULL,
  `equipment_cost` decimal(10,2) DEFAULT NULL,
  `total_cost` decimal(10,2) NOT NULL,
  `assessment_notes` text DEFAULT NULL,
  `status` enum('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
  `assessment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `assessment_id` (`assessment_id`),
  KEY `damage_report_id` (`damage_report_id`),
  KEY `assessor_id` (`assessor_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `inspection_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inspection_id` varchar(20) NOT NULL,
  `inspector_id` int(11) NOT NULL,
  `damage_report_id` int(11) DEFAULT NULL,
  `location` varchar(255) NOT NULL,
  `inspection_type` enum('initial','follow_up','final','special') NOT NULL DEFAULT 'initial',
  `findings` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `inspection_status` enum('scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  `scheduled_date` date DEFAULT NULL,
  `completed_date` timestamp NULL DEFAULT NULL,
  `next_inspection_date` date DEFAULT NULL,
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `images` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `inspection_id` (`inspection_id`),
  KEY `inspector_id` (`inspector_id`),
  KEY `damage_report_id` (`damage_report_id`),
  KEY `inspection_status` (`inspection_status`),
  KEY `scheduled_date` (`scheduled_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `gis_data` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `feature_id` varchar(20) NOT NULL,
  `feature_type` enum('damage','infrastructure','maintenance','zone') NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `properties` json DEFAULT NULL,
  `status` enum('active','inactive','maintenance') NOT NULL DEFAULT 'active',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `feature_id` (`feature_id`),
  KEY `feature_type` (`feature_type`),
  KEY `status` (`status`),
  KEY `location` (`latitude`, `longitude`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` varchar(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `document_type` enum('report','image','video','pdf','other') NOT NULL,
  `category` enum('damage_report','cost_assessment','inspection','maintenance','general') NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` bigint DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `related_id` int(11) DEFAULT NULL,
  `related_type` varchar(50) DEFAULT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `download_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_id` (`document_id`),
  KEY `document_type` (`document_type`),
  KEY `category` (`category`),
  KEY `uploaded_by` (`uploaded_by`),
  KEY `related_id` (`related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `maintenance_schedule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` varchar(20) NOT NULL,
  `task_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `location` varchar(255) NOT NULL,
  `task_type` enum('routine','emergency','inspection','repair') NOT NULL DEFAULT 'routine',
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `status` enum('scheduled','in_progress','completed','cancelled','postponed') NOT NULL DEFAULT 'scheduled',
  `scheduled_date` datetime NOT NULL,
  `estimated_duration` int DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `completed_date` timestamp NULL DEFAULT NULL,
  `actual_duration` int DEFAULT NULL,
  `cost` decimal(10,2) DEFAULT NULL,
  `materials_used` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `task_id` (`task_id`),
  KEY `task_type` (`task_type`),
  KEY `status` (`status`),
  KEY `scheduled_date` (`scheduled_date`),
  KEY `assigned_to` (`assigned_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `public_announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `announcement_id` varchar(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `announcement_type` enum('general','maintenance','alert','holiday') NOT NULL DEFAULT 'general',
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `start_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `end_date` timestamp NULL DEFAULT NULL,
  `target_audience` enum('all','citizens','staff','engineers','lgu_officers') NOT NULL DEFAULT 'all',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `announcement_id` (`announcement_id`),
  KEY `announcement_type` (`announcement_type`),
  KEY `priority` (`priority`),
  KEY `is_active` (`is_active`),
  KEY `start_date` (`start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add foreign key constraints only if tables are empty or new
ALTER TABLE `damage_reports` 
ADD CONSTRAINT IF NOT EXISTS `fk_damage_reports_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
ADD CONSTRAINT IF NOT EXISTS `fk_damage_reports_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `cost_assessments` 
ADD CONSTRAINT IF NOT EXISTS `fk_cost_assessments_report` FOREIGN KEY (`damage_report_id`) REFERENCES `damage_reports` (`id`) ON DELETE CASCADE,
ADD CONSTRAINT IF NOT EXISTS `fk_cost_assessments_assessor` FOREIGN KEY (`assessor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
ADD CONSTRAINT IF NOT EXISTS `fk_cost_assessments_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `inspection_reports` 
ADD CONSTRAINT IF NOT EXISTS `fk_inspection_reports_inspector` FOREIGN KEY (`inspector_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
ADD CONSTRAINT IF NOT EXISTS `fk_inspection_reports_damage` FOREIGN KEY (`damage_report_id`) REFERENCES `damage_reports` (`id`) ON DELETE SET NULL;

ALTER TABLE `gis_data` 
ADD CONSTRAINT IF NOT EXISTS `fk_gis_data_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `documents` 
ADD CONSTRAINT IF NOT EXISTS `fk_documents_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `maintenance_schedule` 
ADD CONSTRAINT IF NOT EXISTS `fk_maintenance_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
ADD CONSTRAINT IF NOT EXISTS `fk_maintenance_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `public_announcements` 
ADD CONSTRAINT IF NOT EXISTS `fk_announcements_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Insert sample data only if tables are empty
INSERT IGNORE INTO `users` (`first_name`, `middle_name`, `last_name`, `email`, `password`, `role`, `status`, `email_verified`) 
VALUES 
('Juan', 'Ching', 'De la Cruz', 'juan.delacruz@lgu.gov.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'engineer', 'pending', 0),
('Maria', 'Clara', 'Reyes', 'maria.reyes@lgu.gov.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'citizen', 'active', 1),
('Carlos', 'Luis', 'Garcia', 'carlos.garcia@lgu.gov.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'lgu_officer', 'active', 1);

INSERT IGNORE INTO `damage_reports` (`report_id`, `reporter_id`, `location`, `description`, `severity`, `status`, `latitude`, `longitude`, `estimated_cost`) 
VALUES 
('DR-2025-001', 2, 'Commonwealth Avenue', 'Large pothole causing traffic disruption', 'high', 'pending', 14.6355, 121.0320, 150000.00),
('DR-2025-002', 2, 'EDSA Complex', 'Road crack spreading', 'medium', 'in_progress', 14.6400, 121.0400, 75000.00),
('DR-2025-003', 3, 'Quezon City Circle', 'Multiple small potholes', 'low', 'resolved', 14.6300, 121.0200, 25000.00);

INSERT IGNORE INTO `cost_assessments` (`assessment_id`, `damage_report_id`, `assessor_id`, `labor_cost`, `material_cost`, `equipment_cost`, `total_cost`, `status`) 
VALUES 
('CA-2025-001', 1, 3, 50000.00, 80000.00, 20000.00, 150000.00, 'approved'),
('CA-2025-002', 2, 3, 30000.00, 35000.00, 10000.00, 75000.00, 'submitted');

INSERT IGNORE INTO `inspection_reports` (`inspection_id`, `inspector_id`, `location`, `inspection_type`, `findings`, `inspection_status`, `scheduled_date`, `priority`) 
VALUES 
('IN-2025-001', 3, 'Commonwealth Avenue', 'initial', 'Large pothole requires immediate repair', 'completed', '2025-01-15', 'high'),
('IN-2025-002', 3, 'EDSA Complex', 'follow_up', 'Repair work completed successfully', 'in_progress', '2025-01-20', 'medium');

INSERT IGNORE INTO `gis_data` (`feature_id`, `feature_type`, `name`, `description`, `latitude`, `longitude`, `properties`, `created_by`) 
VALUES 
('GIS-001', 'damage', 'Commonwealth Pothole', 'Large pothole on main road', 14.6355, 121.0320, '{"severity": "high", "estimated_cost": 150000}', 3),
('GIS-002', 'infrastructure', 'EDSA Bridge', 'Main bridge structure', 14.6400, 121.0400, '{"type": "bridge", "year_built": 1998}', 3);

INSERT IGNORE INTO `maintenance_schedule` (`task_id`, `task_name`, `location`, `task_type`, `priority`, `status`, `scheduled_date`, `assigned_to`, `created_by`) 
VALUES 
('MT-2025-001', 'Road Repair - Commonwealth', 'Commonwealth Avenue', 'repair', 'high', 'scheduled', '2025-01-25 09:00:00', 3, 1),
('MT-2025-002', 'Bridge Inspection', 'EDSA Bridge', 'inspection', 'medium', 'scheduled', '2025-01-30 14:00:00', 3, 1);

INSERT IGNORE INTO `public_announcements` (`announcement_id`, `title`, `content`, `announcement_type`, `priority`, `target_audience`, `created_by`) 
VALUES 
('ANN-2025-001', 'Scheduled Road Maintenance', 'Road repair work scheduled on Commonwealth Avenue from Jan 25-27, 2025', 'maintenance', 'medium', 'all', 1),
('ANN-2025-002', 'Holiday Schedule', 'LGU offices will be closed on Feb 1, 2025', 'holiday', 'low', 'staff', 1);
