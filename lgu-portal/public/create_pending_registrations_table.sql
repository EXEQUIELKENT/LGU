-- Migration: Create pending_registrations table for email verification before account creation
-- This table stores account data temporarily until email is verified

CREATE TABLE IF NOT EXISTS `pending_registrations` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `role` enum('Manager','Engineer','Office Staff','Super Admin') NOT NULL,
  `password` varchar(255) NOT NULL,
  `verification_token` VARCHAR(64) NOT NULL,
  `verification_token_expires` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `verification_token` (`verification_token`),
  KEY `expires_idx` (`verification_token_expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Cleanup: Delete any pending registrations older than 24 hours
DELETE FROM `pending_registrations` WHERE `verification_token_expires` < NOW();

