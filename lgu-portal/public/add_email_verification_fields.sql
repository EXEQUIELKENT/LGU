-- Migration: Add email verification fields to employees table
-- This adds email verification functionality for account activation

ALTER TABLE `employees` 
ADD COLUMN `email_verified` TINYINT(1) NOT NULL DEFAULT 0 
AFTER `is_first_login`;

ALTER TABLE `employees` 
ADD COLUMN `verification_token` VARCHAR(64) NULL 
AFTER `email_verified`;

ALTER TABLE `employees` 
ADD COLUMN `verification_token_expires` DATETIME NULL 
AFTER `verification_token`;

-- Set existing accounts as verified (email_verified = 1)
UPDATE `employees` SET `email_verified` = 1;

