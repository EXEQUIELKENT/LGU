-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 19, 2026 at 07:00 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

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
  `profile_picture` varchar(255) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `role` enum('Manager','Engineer','Office Staff','Admin','Super Admin') NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_first_login` tinyint(1) NOT NULL DEFAULT 1,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verification_token` varchar(64) DEFAULT NULL,
  `verification_token_expires` datetime DEFAULT NULL,
  `last_otp_verified_at` datetime DEFAULT NULL,
  `session_token` varchar(64) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `otp_resend_count` int(11) DEFAULT 0,
  `otp_last_sent` datetime DEFAULT NULL,
  `last_profile_update` datetime DEFAULT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `account_locked` tinyint(1) DEFAULT 0,
  `failed_login_attempts` int(11) DEFAULT 0,
  `unlock_token` varchar(64) DEFAULT NULL,
  `unlock_token_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`user_id`, `first_name`, `last_name`, `profile_picture`, `email`, `role`, `password`, `is_first_login`, `email_verified`, `verification_token`, `verification_token_expires`, `last_otp_verified_at`, `session_token`, `last_login`, `otp_resend_count`, `otp_last_sent`, `last_profile_update`, `reset_token`, `reset_token_expires`, `account_locked`, `failed_login_attempts`, `unlock_token`, `unlock_token_expires`) VALUES
(1, 'Kents', 'Bartolome', 'uploads/profile/profile_1_1769940651.jpeg', 'bartolomeexequielkent@gmail.com', 'Manager', '$2y$10$bSY2cv.EQHReBDrnc7iMZuLLl5fRKe4B0VjpdPaBV170u0zWr3wva', 0, 1, NULL, NULL, '2026-02-02 01:41:21', NULL, NULL, 0, NULL, '2026-02-02 08:41:28', NULL, NULL, 0, 0, NULL, NULL),
(2, 'Warvie', 'Villa', NULL, 'villawarvie@gmail.com', 'Manager', '$2y$10$qzomsugzAuK1Mee9rEnHceYEo8T6DAAObMVtuc7zAdK2POw/INXou', 0, 1, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(3, 'Jhoven', 'Las-ay', NULL, 'jhovenjadelas@gmail.com', 'Office Staff', '$2y$10$UYCT9LIpZ/ds4RH5gU3OCO6uhqbWeO5bqXbKL7hHzXOaf2.VAO1Ni', 0, 1, NULL, NULL, '2026-02-03 09:32:17', NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(5, 'Steven', 'Valera', NULL, 'stevennicole30@gmail.com', 'Engineer', '$2y$10$Yf48Xq/C6DnXo49WzPdRP.hbmQ1NjsTINi4.rXnrvyhnYSpHO0XPe', 0, 1, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(8, 'Steph', 'Sagun', NULL, 'stephanie.saguns@gmail.com', 'Super Admin', '$2y$10$IJVWo8Rq6kJq1QAOFYzW3eZB6UsBrVJe6mY7bdESPtgUz/Ld0XwE6', 0, 1, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(13, 'Exequiel', 'Bartolome', NULL, 'bartolomstolome@gmail.com', 'Engineer', '$2y$10$nGULzfEnsv7xugNhr33tPeMbH3KS.qCie3Dq1CA4GtzQ/oukDmVFa', 0, 1, NULL, NULL, '2026-02-02 01:42:22', NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(14, 'Warv', 'Villa', NULL, 'villawarv@gmail.com', 'Super Admin', '$2y$10$5VxEbVQGo6bZLhJ4TGSKVutVoH6/uzXnEm.FmlKovuZHQVbdUbUhS', 0, 1, NULL, NULL, '2026-02-03 09:33:18', NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(15, 'Exequiel', 'Bartolome', NULL, 'bartolomeexequielkent2003@gmail.com', 'Super Admin', '$2y$10$NnT5QBl0A66tRTJoYAgvc.NggVXq6jyym0g.WOUKkY4ddT4Zhl76S', 0, 1, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(16, 'Marycarl', 'Dagondong', NULL, 'marycarldagondong28@gmail.com', 'Super Admin', '$2y$10$nxvAvmwcwXVD.08n0fT.7eoyDHUCArLsH4IYgWXm/OM6xqT79vvcK', 0, 1, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(22, 'Michael', 'De santa', NULL, 'michaelgtav63@gmail.com', 'Engineer', '$2y$10$/IR22g.R4zU0vuFwbiWALu5J5B7XwD4vWIdqCNdaHn4fmPNqOjvLS', 0, 1, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(24, 'Kent', 'Tarroza', NULL, 'assassinscreedunity2026@gmail.com', 'Admin', '$2y$10$6RvnKcuRMRgKogdzHiit1ug0pI8drAPCdZa9yzhZDyFWEoaXElj9.', 0, 1, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `engineer_profiles`
--

CREATE TABLE `engineer_profiles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `full_name` varchar(200) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `address` text DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `engineering_discipline` varchar(100) DEFAULT NULL,
  `department` varchar(200) DEFAULT NULL,
  `years_of_experience` int(11) DEFAULT NULL,
  `areas_of_specialization` text DEFAULT NULL,
  `skill_structural_design` tinyint(1) DEFAULT 0,
  `skill_site_inspection` tinyint(1) DEFAULT 0,
  `skill_project_planning` tinyint(1) DEFAULT 0,
  `cad_software` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `engineer_profiles`
--

INSERT INTO `engineer_profiles` (`id`, `user_id`, `full_name`, `gender`, `date_of_birth`, `address`, `contact_number`, `engineering_discipline`, `department`, `years_of_experience`, `areas_of_specialization`, `skill_structural_design`, `skill_site_inspection`, `skill_project_planning`, `cad_software`, `created_at`, `updated_at`) VALUES
(1, 13, 'Exequiel Kent Tarroza Bartolome', 'Male', '2003-11-20', 'Bagong Silang Caloocan City', '0921-212-1212', 'Civil', 'Engineering Office', 1, 'Roads', 1, 0, 1, 'Civil 3D', '2026-03-17 13:38:33', '2026-03-17 13:38:33'),
(2, 22, 'Micheal De Santa', 'Male', '1984-10-22', 'Quezon City', '0921-212-2212', 'Civil', 'Infrastructure Unit', 1, 'Street Lights,Drainage,Public Facilities', 0, 0, 1, 'AutoCAD', '2026-03-19 17:59:59', '2026-03-19 17:59:59');

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

--
-- Dumping data for table `evidence_images`
--

INSERT INTO `evidence_images` (`img_id`, `req_id`, `img_path`, `uploaded_at`) VALUES
(112, 114, 'uploads/evidence/evidence_114_699c0a5230144.jpg', '2026-02-23 08:05:38'),
(113, 115, 'uploads/evidence/evidence_115_699c0b41a81bd.jpg', '2026-02-23 08:09:37'),
(114, 116, 'uploads/evidence/evidence_116_699c0bed09160.jpg', '2026-02-23 08:12:29'),
(115, 117, 'uploads/evidence/evidence_117_699c0c7806a9e.jpg', '2026-02-23 08:14:48'),
(116, 118, 'uploads/evidence/evidence_118_699c0d5bc4095.jpg', '2026-02-23 08:18:35'),
(117, 119, 'uploads/evidence/evidence_119_699c0e172ada4.jpg', '2026-02-23 08:21:43'),
(118, 120, 'uploads/evidence/evidence_120_699c0f4babdb5.jpg', '2026-02-23 08:26:51'),
(119, 121, 'uploads/evidence/evidence_121_699c106e86722.jpg', '2026-02-23 08:31:42'),
(121, 123, 'uploads/evidence/evidence_123_699c18e367413.jpg', '2026-02-23 09:07:47'),
(122, 124, 'uploads/evidence/evidence_124_699c1afaa3eb5.jpg', '2026-02-23 09:16:42'),
(123, 125, 'uploads/evidence/evidence_125_699c206a510e9.jpg', '2026-02-23 09:39:54'),
(124, 125, 'uploads/evidence/evidence_125_699c206a60fb8.jpg', '2026-02-23 09:39:54'),
(125, 125, 'uploads/evidence/evidence_125_699c206a65524.jpg', '2026-02-23 09:39:54');

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
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `request_type` varchar(50) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `employee_id`, `title`, `description`, `url`, `request_type`, `is_read`, `created_at`) VALUES
(466, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=114', 'Roads', 0, '2026-02-23 08:05:38'),
(467, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=114', 'Roads', 1, '2026-02-23 08:05:38'),
(468, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=114', 'Roads', 0, '2026-02-23 08:05:38'),
(469, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=114', 'Roads', 0, '2026-02-23 08:05:38'),
(470, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=114', 'Roads', 0, '2026-02-23 08:05:38'),
(471, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=114', 'Roads', 0, '2026-02-23 08:05:38'),
(472, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=114', 'Roads', 1, '2026-02-23 08:05:38'),
(473, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=114', 'Roads', 0, '2026-02-23 08:05:38'),
(474, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=114', 'Roads', 0, '2026-02-23 08:05:38'),
(475, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 0, '2026-02-23 08:09:37'),
(476, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 1, '2026-02-23 08:09:37'),
(477, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 0, '2026-02-23 08:09:37'),
(478, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 0, '2026-02-23 08:09:37'),
(479, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 0, '2026-02-23 08:09:37'),
(480, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 0, '2026-02-23 08:09:37'),
(481, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 1, '2026-02-23 08:09:37'),
(482, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 0, '2026-02-23 08:09:37'),
(483, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 0, '2026-02-23 08:09:37'),
(484, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 0, '2026-02-23 08:12:29'),
(485, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 1, '2026-02-23 08:12:29'),
(486, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 0, '2026-02-23 08:12:29'),
(487, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 0, '2026-02-23 08:12:29'),
(488, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 0, '2026-02-23 08:12:29'),
(489, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 0, '2026-02-23 08:12:29'),
(490, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 1, '2026-02-23 08:12:29'),
(491, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 0, '2026-02-23 08:12:29'),
(492, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 0, '2026-02-23 08:12:29'),
(493, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 0, '2026-02-23 08:14:48'),
(494, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 1, '2026-02-23 08:14:48'),
(495, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 0, '2026-02-23 08:14:48'),
(496, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 0, '2026-02-23 08:14:48'),
(497, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 0, '2026-02-23 08:14:48'),
(498, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 0, '2026-02-23 08:14:48'),
(499, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 1, '2026-02-23 08:14:48'),
(500, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 0, '2026-02-23 08:14:48'),
(501, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 0, '2026-02-23 08:14:48'),
(502, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 0, '2026-02-23 08:18:35'),
(503, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 1, '2026-02-23 08:18:35'),
(504, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 0, '2026-02-23 08:18:35'),
(505, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 0, '2026-02-23 08:18:35'),
(506, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 0, '2026-02-23 08:18:35'),
(507, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 0, '2026-02-23 08:18:35'),
(508, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 1, '2026-02-23 08:18:35'),
(509, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 0, '2026-02-23 08:18:35'),
(510, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 0, '2026-02-23 08:18:35'),
(511, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 0, '2026-02-23 08:21:43'),
(512, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 1, '2026-02-23 08:21:43'),
(513, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 0, '2026-02-23 08:21:44'),
(514, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 0, '2026-02-23 08:21:44'),
(515, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 0, '2026-02-23 08:21:45'),
(516, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 0, '2026-02-23 08:21:45'),
(517, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 1, '2026-02-23 08:21:45'),
(518, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 0, '2026-02-23 08:21:45'),
(519, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 0, '2026-02-23 08:21:45'),
(520, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 0, '2026-02-23 08:26:51'),
(521, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 1, '2026-02-23 08:26:51'),
(522, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 0, '2026-02-23 08:26:51'),
(523, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 0, '2026-02-23 08:26:51'),
(524, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 0, '2026-02-23 08:26:51'),
(525, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 0, '2026-02-23 08:26:51'),
(526, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 1, '2026-02-23 08:26:51'),
(527, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 0, '2026-02-23 08:26:51'),
(528, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 0, '2026-02-23 08:26:51'),
(529, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 0, '2026-02-23 08:31:42'),
(530, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 1, '2026-02-23 08:31:42'),
(531, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 0, '2026-02-23 08:31:42'),
(532, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 0, '2026-02-23 08:31:42'),
(533, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 0, '2026-02-23 08:31:42'),
(534, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 0, '2026-02-23 08:31:42'),
(535, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 1, '2026-02-23 08:31:42'),
(536, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 0, '2026-02-23 08:31:42'),
(537, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 0, '2026-02-23 08:31:42'),
(538, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 0, '2026-02-23 09:00:28'),
(539, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 1, '2026-02-23 09:00:28'),
(540, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 0, '2026-02-23 09:00:28'),
(541, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 0, '2026-02-23 09:00:28'),
(542, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 0, '2026-02-23 09:00:28'),
(543, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 0, '2026-02-23 09:00:28'),
(544, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 1, '2026-02-23 09:00:28'),
(545, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 0, '2026-02-23 09:00:28'),
(546, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 0, '2026-02-23 09:00:28'),
(547, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 0, '2026-02-23 09:07:47'),
(548, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 1, '2026-02-23 09:07:47'),
(549, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 0, '2026-02-23 09:07:47'),
(550, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 0, '2026-02-23 09:07:47'),
(551, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 0, '2026-02-23 09:07:47'),
(552, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 0, '2026-02-23 09:07:47'),
(553, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 1, '2026-02-23 09:07:47'),
(554, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 0, '2026-02-23 09:07:47'),
(555, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 0, '2026-02-23 09:07:47'),
(556, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 0, '2026-02-23 09:16:42'),
(557, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 1, '2026-02-23 09:16:42'),
(558, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 0, '2026-02-23 09:16:42'),
(559, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 0, '2026-02-23 09:16:42'),
(560, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 0, '2026-02-23 09:16:42'),
(561, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 0, '2026-02-23 09:16:42'),
(562, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 1, '2026-02-23 09:16:42'),
(563, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 0, '2026-02-23 09:16:42'),
(564, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 0, '2026-02-23 09:16:42'),
(565, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=125', 'Traffic light', 0, '2026-02-23 09:39:54'),
(566, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=125', 'Traffic light', 1, '2026-02-23 09:39:54'),
(567, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=125', 'Traffic light', 0, '2026-02-23 09:39:54'),
(568, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=125', 'Traffic light', 0, '2026-02-23 09:39:54'),
(569, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=125', 'Traffic light', 0, '2026-02-23 09:39:54'),
(570, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=125', 'Traffic light', 0, '2026-02-23 09:39:54'),
(571, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=125', 'Traffic light', 1, '2026-02-23 09:39:54'),
(572, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=125', 'Traffic light', 0, '2026-02-23 09:39:54'),
(573, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=125', 'Traffic light', 0, '2026-02-23 09:39:54');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(10) UNSIGNED NOT NULL,
  `email` varchar(100) NOT NULL,
  `token` varchar(128) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
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
  `role` enum('Manager','Engineer','Office Staff','Admin','Super Admin') NOT NULL,
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
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `rep_id` int(10) UNSIGNED NOT NULL,
  `res_id` int(10) UNSIGNED NOT NULL,
  `starting_date` date NOT NULL,
  `estimated_end_date` date NOT NULL,
  `engineer_id` int(10) UNSIGNED DEFAULT NULL,
  `report_by` int(10) UNSIGNED NOT NULL,
  `priority_lvl` varchar(50) DEFAULT NULL,
  `budget` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `engineer_accepted` tinyint(1) NOT NULL DEFAULT 0,
  `decline_reason` text DEFAULT NULL,
  `decline_reviewed` tinyint(1) DEFAULT NULL COMMENT '1=valid,0=invalid',
  `decline_review_note` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `report_daily_images`
--

CREATE TABLE `report_daily_images` (
  `id` int(11) NOT NULL,
  `rep_id` int(11) NOT NULL,
  `log_date` date NOT NULL,
  `img_path` varchar(500) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `report_daily_logs`
--

CREATE TABLE `report_daily_logs` (
  `id` int(11) NOT NULL,
  `rep_id` int(11) NOT NULL,
  `log_date` date NOT NULL,
  `description` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `report_progress_images`
--

CREATE TABLE `report_progress_images` (
  `id` int(11) NOT NULL,
  `rep_id` int(11) NOT NULL,
  `img_path` varchar(500) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
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
  `contact_number` varchar(20) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `approval_status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `coordinates` varchar(50) DEFAULT NULL COMMENT 'Stored as lat,lng — populated when citizen pins location on map',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `email` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `requests`
--

INSERT INTO `requests` (`req_id`, `infrastructure`, `location`, `issue`, `contact_number`, `name`, `approval_status`, `coordinates`, `created_at`, `email`) VALUES
(114, 'Roads', 'Diliman, Ugong Norte, Quezon City, Zamboanga Street', 'Nasira  daanan sa lugar namin.', '09876456355', 'Marites Santos', 'Pending', '14.6612,121.0534', '2026-02-23 08:05:38', NULL),
(115, 'Signage', 'Geronimo Compound, Santo Domingo (Matalahib), Quezon City, Polaris Street', 'nasira signage sa santo domingo road', '09874321334', 'Carlo Anotio', 'Pending', '14.6756,121.0312', '2026-02-23 08:09:37', NULL),
(116, 'Roads', 'Pasong Tamo, Quezon City', 'nasira daaan', '09875432456', 'Steph Dela cruz', 'Pending', '14.6845,121.0389', '2026-02-23 08:12:29', NULL),
(117, 'Street Lights', 'Project 7, N.S. Amoranto (Gintong Silahis), Quezon City, Miller Avenue', 'natumba street ligts banda dito sa Project 7', '09654432171', 'Jhoven Bartolome', 'Pending', '14.6391,121.0294', '2026-02-23 08:14:48', NULL),
(118, 'Public Facilities', 'Pasong Tamo, Sikatuna Village, Quezon City, Kasay-Kasay Street', 'nasira  cubicle sa pasong tamo public CR', '09856345143', 'Marisol Valencia', 'Pending', '14.6767,121.0623', '2026-02-23 08:18:35', NULL),
(119, 'Drainage', 'Libis, Quezon City', 'nagbara sa dami ng basura', '09786543456', 'Jeffrey Las-ay', 'Pending', '14.6345,121.0612', '2026-02-23 08:21:43', NULL),
(120, 'Electrical', 'Project 8, Santo Domingo (Matalahib), Quezon City, Mindanao Avenue', 'Bumagsak wiring dito sa project 8 santo domango', '09765432111', 'Marycarl Mallari', 'Pending', '14.6467,121.0334', '2026-02-23 08:26:51', NULL),
(121, 'Waiting Shed', 'Pansol, Krus Na Ligas, Quezon City, Montalban Street', 'bumagsak  yung bubong ng waiting shed sa panson', '09785634522', 'Jasmin Padilla', 'Pending', '14.6543,121.0721', '2026-02-23 08:31:42', NULL),
(123, 'traffic light', 'Project 8, Santo Domingo (Matalahib), Quezon City, Mindanao Avenue', 'nasira traffic light', '09123456785', 'Hannah Roxas', 'Pending', '14.6467,121.0330', '2026-02-23 09:07:47', NULL),
(124, 'Roads', 'Pasong Tamo, Quezon City', 'sira daanan banda dito sa pasong tamo.', '09765536274', 'Mark Santilan', 'Pending', '14.6845,121.0395', '2026-02-23 09:16:42', NULL),
(125, 'Traffic light', 'Santo Domingo (Matalahib), Quezon City', 'The Traffic light in the Santo domingo is broken.', '09009356577', 'Kent Bartolome', 'Pending', '14.6756,121.0309', '2026-02-23 09:39:54', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `request_ai_analysis`
--

CREATE TABLE `request_ai_analysis` (
  `analysis_id` int(10) UNSIGNED NOT NULL,
  `req_id` int(10) UNSIGNED NOT NULL,
  `declared_infrastructure` varchar(100) DEFAULT NULL,
  `detected_infrastructure` varchar(100) DEFAULT NULL,
  `infrastructure_match` tinyint(1) DEFAULT NULL,
  `match_confidence` decimal(4,3) DEFAULT NULL,
  `is_legitimate` tinyint(1) DEFAULT NULL,
  `legitimacy_score` decimal(4,3) DEFAULT NULL,
  `legitimacy_notes` varchar(255) DEFAULT NULL,
  `damage_severity` tinyint(2) DEFAULT NULL,
  `priority_recommendation` enum('Low','Medium','High','Critical') DEFAULT NULL,
  `damage_description` varchar(255) DEFAULT NULL,
  `confidence_score` decimal(4,3) DEFAULT NULL,
  `anomaly_flags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`anomaly_flags`)),
  `combined_assessment` text DEFAULT NULL,
  `estimated_repair_complexity` enum('Simple','Moderate','Complex','Major') DEFAULT NULL,
  `requires_immediate_action` tinyint(1) DEFAULT 0,
  `images_analyzed` tinyint(2) DEFAULT 0,
  `analysis_status` enum('pending','completed','failed') DEFAULT 'pending',
  `ai_cost_estimation` varchar(100) DEFAULT NULL COMMENT 'AI-estimated repair cost range in Philippine Pesos (e.g. P5,000 - P25,000)',
  `analyzed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `request_resolutions`
--

CREATE TABLE `request_resolutions` (
  `res_id` int(10) UNSIGNED NOT NULL,
  `req_id` int(10) UNSIGNED NOT NULL,
  `status` enum('Approved','Rejected','Scheduled','In Progress','Completed','Cancelled','Pending Completion') NOT NULL DEFAULT 'Approved',
  `res_note` text DEFAULT NULL,
  `resolved_by` int(10) UNSIGNED NOT NULL,
  `resolved_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `admin_return_note` text DEFAULT NULL,
  `highlight_fields` text DEFAULT NULL,
  `highlight_days` text DEFAULT NULL
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
-- Indexes for table `engineer_profiles`
--
ALTER TABLE `engineer_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `evidence_images`
--
ALTER TABLE `evidence_images`
  ADD PRIMARY KEY (`img_id`),
  ADD KEY `fk_evidence_request` (`req_id`);

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
-- Indexes for table `materials_equipment_costs`
--
ALTER TABLE `materials_equipment_costs`
  ADD PRIMARY KEY (`cost_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`);

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
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`rep_id`),
  ADD KEY `fk_report_res` (`res_id`),
  ADD KEY `fk_report_engineer` (`engineer_id`),
  ADD KEY `fk_report_reporter` (`report_by`);

--
-- Indexes for table `report_daily_images`
--
ALTER TABLE `report_daily_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rdi` (`rep_id`,`log_date`);

--
-- Indexes for table `report_daily_logs`
--
ALTER TABLE `report_daily_logs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_rdl` (`rep_id`,`log_date`),
  ADD KEY `idx_rdl_rep` (`rep_id`);

--
-- Indexes for table `report_progress_images`
--
ALTER TABLE `report_progress_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rep_id` (`rep_id`);

--
-- Indexes for table `requests`
--
ALTER TABLE `requests`
  ADD PRIMARY KEY (`req_id`);

--
-- Indexes for table `request_ai_analysis`
--
ALTER TABLE `request_ai_analysis`
  ADD PRIMARY KEY (`analysis_id`),
  ADD UNIQUE KEY `uq_req` (`req_id`),
  ADD KEY `idx_status` (`analysis_status`),
  ADD KEY `idx_priority` (`priority_recommendation`),
  ADD KEY `idx_legitimate` (`is_legitimate`),
  ADD KEY `idx_severity` (`damage_severity`);

--
-- Indexes for table `request_resolutions`
--
ALTER TABLE `request_resolutions`
  ADD PRIMARY KEY (`res_id`),
  ADD KEY `fk_res_request` (`req_id`),
  ADD KEY `fk_res_employee` (`resolved_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `engineer_profiles`
--
ALTER TABLE `engineer_profiles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `evidence_images`
--
ALTER TABLE `evidence_images`
  MODIFY `img_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=128;

--
-- AUTO_INCREMENT for table `login_logs`
--
ALTER TABLE `login_logs`
  MODIFY `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=354;

--
-- AUTO_INCREMENT for table `maintenance_schedule`
--
ALTER TABLE `maintenance_schedule`
  MODIFY `sched_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=74;

--
-- AUTO_INCREMENT for table `materials_equipment_costs`
--
ALTER TABLE `materials_equipment_costs`
  MODIFY `cost_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=680;

--
-- AUTO_INCREMENT for table `pending_registrations`
--
ALTER TABLE `pending_registrations`
  MODIFY `penreg_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `repair_archive`
--
ALTER TABLE `repair_archive`
  MODIFY `arc_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `rep_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `report_daily_images`
--
ALTER TABLE `report_daily_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `report_daily_logs`
--
ALTER TABLE `report_daily_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `report_progress_images`
--
ALTER TABLE `report_progress_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `requests`
--
ALTER TABLE `requests`
  MODIFY `req_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=128;

--
-- AUTO_INCREMENT for table `request_ai_analysis`
--
ALTER TABLE `request_ai_analysis`
  MODIFY `analysis_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `request_resolutions`
--
ALTER TABLE `request_resolutions`
  MODIFY `res_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `evidence_images`
--
ALTER TABLE `evidence_images`
  ADD CONSTRAINT `fk_evidence_request` FOREIGN KEY (`req_id`) REFERENCES `requests` (`req_id`) ON DELETE CASCADE;

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

--
-- Constraints for table `request_ai_analysis`
--
ALTER TABLE `request_ai_analysis`
  ADD CONSTRAINT `fk_ai_request` FOREIGN KEY (`req_id`) REFERENCES `requests` (`req_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
