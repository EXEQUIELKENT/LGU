-- Migration: Add is_first_login field to employees table
-- This field tracks if a user needs to change their password on first login

ALTER TABLE `employees` 
ADD COLUMN `is_first_login` TINYINT(1) NOT NULL DEFAULT 1 
AFTER `password`;

-- Set existing accounts as having already changed password (is_first_login = 0)
UPDATE `employees` SET `is_first_login` = 0;

