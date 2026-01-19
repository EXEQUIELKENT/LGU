-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 19, 2026 at 04:40 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.1.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `cimm_lgu`
--

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `role` enum('Manager','Engineer','Office Staff','Super Admin') NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_first_login` tinyint(1) NOT NULL DEFAULT 1,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verification_token` varchar(64) DEFAULT NULL,
  `verification_token_expires` datetime DEFAULT NULL,
  `last_otp_verified_at` datetime DEFAULT NULL,
  `session_token` varchar(64) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `otp_resend_count` int(11) DEFAULT 0,
  `otp_last_sent` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`user_id`, `first_name`, `last_name`, `email`, `role`, `password`, `is_first_login`, `email_verified`, `verification_token`, `verification_token_expires`, `last_otp_verified_at`, `session_token`, `last_login`, `otp_resend_count`, `otp_last_sent`) VALUES
(1, 'Exequiel Kent', 'Bartolome', 'bartolomeexequielkent@gmail.com', 'Manager', '$2y$10$SWqpSqIHVrgmoa/TLlfGae9y/ftzABYfan.YVOv5Pv0dz/o836znW', 0, 1, NULL, NULL, '2026-01-19 02:48:43', NULL, NULL, 0, NULL),
(2, 'Warvie', 'Villa', 'villawarvie@gmail.com', 'Manager', '$2y$10$qzomsugzAuK1Mee9rEnHceYEo8T6DAAObMVtuc7zAdK2POw/INXou', 0, 1, NULL, NULL, NULL, NULL, NULL, 0, NULL),
(3, 'Jhoven', 'Las-ay', 'jhovenjadelas@gmail.com', 'Office Staff', '$2y$10$UYCT9LIpZ/ds4RH5gU3OCO6uhqbWeO5bqXbKL7hHzXOaf2.VAO1Ni', 0, 1, NULL, NULL, NULL, NULL, NULL, 0, NULL),
(5, 'Steven', 'Valera', 'stevennicole30@gmail.com', 'Engineer', '$2y$10$Yf48Xq/C6DnXo49WzPdRP.hbmQ1NjsTINi4.rXnrvyhnYSpHO0XPe', 0, 1, NULL, NULL, NULL, NULL, NULL, 0, NULL),
(8, 'Steph', 'Sagun', 'stephanie.saguns@gmail.com', 'Super Admin', '$2y$10$qIBFP60SxkAy0bclUHboieg7OM285p1AppOHiOTIEPLZ1UlLJrgd2', 0, 1, NULL, NULL, NULL, NULL, NULL, 0, NULL),
(10, 'Exequiel', 'Bartolome', 'bartolomstolome@gmail.com', 'Manager', '$2y$10$yUqHLy3zjrCeOwRscBC46.v4vAscDi1CiG/LDIuafaTq/tH52qkl2', 0, 1, NULL, NULL, '2026-01-19 01:48:43', NULL, NULL, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `evidence_images`
--

CREATE TABLE `evidence_images` (
  `img_id` int(10) UNSIGNED NOT NULL,
  `req_id` int(10) UNSIGNED NOT NULL,
  `img_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_logs`
--

CREATE TABLE `login_logs` (
  `log_id` int(10) UNSIGNED NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `success` tinyint(1) NOT NULL,
  `failure_reason` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(255) NOT NULL,
  `otp_used` tinyint(1) NOT NULL DEFAULT 0,
  `otp_resends` int(11) DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_logs`
--

INSERT INTO `login_logs` (`log_id`, `email`, `success`, `failure_reason`, `ip_address`, `user_agent`, `otp_used`, `otp_resends`, `created_at`) VALUES
(1, 'bartolomstolome@gmail.com', 0, 'Email not verified', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 08:45:31'),
(2, 'bartolomstolome@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 1, 0, '2026-01-19 08:46:38'),
(3, 'bartolomstolome@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 1, 0, '2026-01-19 08:48:43'),
(4, 'bartolomstolome@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 08:49:12'),
(5, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 1, 3, '2026-01-19 09:12:28'),
(6, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 09:12:47'),
(7, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 09:12:50'),
(8, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 1, 1, '2026-01-19 09:20:07'),
(9, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 1, 2, '2026-01-19 09:23:05'),
(10, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 1, 0, '2026-01-19 09:25:19'),
(11, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 09:25:26'),
(12, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 09:25:29'),
(13, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 09:25:32'),
(14, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 1, 3, '2026-01-19 09:42:25'),
(15, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 09:42:29'),
(16, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 09:43:22'),
(17, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 1, 5, '2026-01-19 09:48:43'),
(18, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 09:51:07'),
(19, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 09:51:11'),
(20, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 09:52:06'),
(21, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 09:52:09'),
(22, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 09:52:55'),
(23, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 09:53:00'),
(24, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 10:04:49'),
(25, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Mobile Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 10:29:55'),
(26, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 10:48:05'),
(27, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 10:48:19'),
(28, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 10:48:32'),
(29, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 11:20:53'),
(30, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 11:24:53');

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_schedule`
--

CREATE TABLE `maintenance_schedule` (
  `sched_id` int(10) UNSIGNED NOT NULL,
  `task` varchar(255) NOT NULL,
  `location` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `priority` enum('Low','Medium','High','Critical') NOT NULL,
  `status` enum('Scheduled','In Progress','Completed','Delayed') NOT NULL DEFAULT 'Scheduled',
  `engineer_id` int(10) UNSIGNED NOT NULL,
  `assigned_team` varchar(255) DEFAULT NULL,
  `budget` decimal(15,2) NOT NULL DEFAULT 0.00,
  `starting_date` datetime NOT NULL,
  `estimated_completion_date` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `materials_equipment_costs`
--

CREATE TABLE `materials_equipment_costs` (
  `cost_id` int(10) UNSIGNED NOT NULL,
  `item_name` varchar(100) NOT NULL,
  `item_type` enum('Materials','Equipment') NOT NULL,
  `supplier` varchar(150) DEFAULT NULL,
  `cost` decimal(15,2) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pending_registrations`
--

CREATE TABLE `pending_registrations` (
  `penreg_id` int(10) UNSIGNED NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `role` enum('Manager','Engineer','Office Staff','Super Admin') NOT NULL,
  `password` varchar(255) NOT NULL,
  `verification_token` varchar(64) NOT NULL,
  `verification_token_expires` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `repair_archive`
--

CREATE TABLE `repair_archive` (
  `arc_id` int(10) UNSIGNED NOT NULL,
  `task` varchar(255) NOT NULL,
  `location` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `assigned_team` varchar(255) DEFAULT NULL,
  `engineer_id` int(10) UNSIGNED NOT NULL,
  `budget` decimal(15,2) NOT NULL DEFAULT 0.00,
  `starting_date` datetime NOT NULL,
  `completed_date` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `requests`
--

CREATE TABLE `requests` (
  `req_id` int(10) UNSIGNED NOT NULL,
  `infrastructure` varchar(255) NOT NULL,
  `location` varchar(255) NOT NULL,
  `issue` text NOT NULL,
  `approval_status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `request_resolutions`
--

CREATE TABLE `request_resolutions` (
  `res_id` int(10) UNSIGNED NOT NULL,
  `req_id` int(10) UNSIGNED NOT NULL,
  `status` enum('Approved','Rejected') NOT NULL,
  `res_note` text DEFAULT NULL,
  `resolved_by` int(10) UNSIGNED NOT NULL,
  `resolved_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `login_logs`
--
ALTER TABLE `login_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `email` (`email`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `maintenance_schedule`
--
ALTER TABLE `maintenance_schedule`
  ADD PRIMARY KEY (`sched_id`),
  ADD KEY `fk_sched_engineer` (`engineer_id`);

--
-- Indexes for table `pending_registrations`
--
ALTER TABLE `pending_registrations`
  ADD PRIMARY KEY (`penreg_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `verification_token` (`verification_token`),
  ADD KEY `expires_idx` (`verification_token_expires`);

--
-- Indexes for table `repair_archive`
--
ALTER TABLE `repair_archive`
  ADD PRIMARY KEY (`arc_id`),
  ADD KEY `fk_arc_engineer` (`engineer_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `login_logs`
--
ALTER TABLE `login_logs`
  MODIFY `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `maintenance_schedule`
--
ALTER TABLE `maintenance_schedule`
  MODIFY `sched_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pending_registrations`
--
ALTER TABLE `pending_registrations`
  MODIFY `penreg_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `repair_archive`
--
ALTER TABLE `repair_archive`
  MODIFY `arc_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `maintenance_schedule`
--
ALTER TABLE `maintenance_schedule`
  ADD CONSTRAINT `fk_sched_engineer` FOREIGN KEY (`engineer_id`) REFERENCES `employees` (`user_id`);

--
-- Constraints for table `repair_archive`
--
ALTER TABLE `repair_archive`
  ADD CONSTRAINT `fk_arc_engineer` FOREIGN KEY (`engineer_id`) REFERENCES `employees` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
