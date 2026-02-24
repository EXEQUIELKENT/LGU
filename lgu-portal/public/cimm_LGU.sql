-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 24, 2026 at 02:26 AM
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
  `profile_picture` varchar(255) DEFAULT NULL,
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
(8, 'Steph', 'Sagun', NULL, 'stephanie.saguns@gmail.com', 'Super Admin', '$2y$10$qIBFP60SxkAy0bclUHboieg7OM285p1AppOHiOTIEPLZ1UlLJrgd2', 0, 1, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(13, 'Exequiel', 'Bartolome', NULL, 'bartolomstolome@gmail.com', 'Engineer', '$2y$10$nGULzfEnsv7xugNhr33tPeMbH3KS.qCie3Dq1CA4GtzQ/oukDmVFa', 0, 1, NULL, NULL, '2026-02-02 01:42:22', NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(14, 'Warv', 'Villa', NULL, 'villawarv@gmail.com', 'Super Admin', '$2y$10$5VxEbVQGo6bZLhJ4TGSKVutVoH6/uzXnEm.FmlKovuZHQVbdUbUhS', 0, 1, NULL, NULL, '2026-02-03 09:33:18', NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(15, 'Exequiel', 'Bartolome', NULL, 'bartolomeexequielkent2003@gmail.com', 'Super Admin', '$2y$10$NnT5QBl0A66tRTJoYAgvc.NggVXq6jyym0g.WOUKkY4ddT4Zhl76S', 0, 1, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(16, 'Marycarl', 'Dagondong', NULL, 'marycarldagondong28@gmail.com', 'Super Admin', '$2y$10$nxvAvmwcwXVD.08n0fT.7eoyDHUCArLsH4IYgWXm/OM6xqT79vvcK', 0, 1, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL);

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

--
-- Dumping data for table `login_logs`
--

INSERT INTO `login_logs` (`log_id`, `email`, `success`, `failure_reason`, `ip_address`, `user_agent`, `otp_used`, `otp_resends`, `created_at`) VALUES
(135, 'Villawarv@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 AVG/144.0.0.0', 1, 0, '2026-02-23 17:08:52'),
(136, 'Villawarv@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 AVG/144.0.0.0', 1, 0, '2026-02-23 17:17:27'),
(137, 'Villawarv@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 AVG/144.0.0.0', 1, 0, '2026-02-23 17:43:29'),
(138, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', 1, 0, '2026-02-24 09:20:12');

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

--
-- Dumping data for table `maintenance_schedule`
--

INSERT INTO `maintenance_schedule` (`sched_id`, `task`, `location`, `category`, `priority`, `status`, `engineer_id`, `assigned_team`, `budget`, `starting_date`, `estimated_completion_date`, `created_at`) VALUES
(34, 'HVAC System Inspection', 'Quezon City Hall, Elliptical Road, Diliman', 'HVAC', 'High', 'Scheduled', 5, 'Facilities Maintenance Team', 15000.00, '2026-03-03 08:00:00', '2026-03-03 17:00:00', '2026-02-23 08:03:50'),
(35, 'Electrical Panel Maintenance', 'Quezon City Hall Annex, Batasan Hills', 'Electrical', 'High', 'In Progress', 5, 'Electrical Team A', 22000.00, '2026-03-04 08:00:00', '2026-03-04 16:00:00', '2026-02-23 08:03:50'),
(36, 'Generator Load Testing', 'Quezon City Hall, Elliptical Road, Diliman', 'Mechanical', 'Critical', 'Scheduled', 5, 'Power & Mechanical Team', 18000.00, '2026-03-05 09:00:00', '2026-03-05 14:00:00', '2026-02-23 08:03:50'),
(37, 'Fire Suppression System Check', 'QC Hall of Justice, Batasan Complex, Batasan Hills', 'Safety', 'High', 'Scheduled', 5, 'Safety Compliance Team', 9500.00, '2026-03-06 08:30:00', '2026-03-06 12:00:00', '2026-02-23 08:03:50'),
(38, 'Plumbing Leak Repair', 'Quezon City Public Library, Elliptical Road', 'Plumbing', 'Medium', 'Completed', 5, 'Repair Team B', 5500.00, '2026-02-24 09:00:00', '2026-02-24 13:00:00', '2026-02-23 08:03:50'),
(39, 'Pothole Patching', 'Commonwealth Avenue, Batasan Hills', 'Roads', 'Critical', 'In Progress', 5, 'Road Repair Team Alpha', 85000.00, '2026-03-02 07:00:00', '2026-03-09 17:00:00', '2026-02-23 08:03:50'),
(40, 'Road Repainting & Lane Marking', 'Quezon Avenue, South Triangle', 'Roads', 'Medium', 'Scheduled', 5, 'Road Marking Team', 42000.00, '2026-03-10 06:00:00', '2026-03-11 18:00:00', '2026-02-23 08:03:50'),
(41, 'Sidewalk Repair', 'Tomas Morato Avenue, Sacred Heart', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team B', 31000.00, '2026-03-09 07:00:00', '2026-03-10 17:00:00', '2026-02-23 08:03:50'),
(42, 'Road Crack Sealing', 'Visayas Avenue, Vasra', 'Roads', 'High', 'Delayed', 5, 'Road Repair Team Beta', 67000.00, '2026-02-20 07:00:00', '2026-02-23 17:00:00', '2026-02-23 08:03:50'),
(43, 'Bridge Structural Inspection', 'Tandang Sora Bridge, Tandang Sora Avenue', 'Structural', 'Critical', 'Scheduled', 5, 'Structural Inspection Team', 55000.00, '2026-03-12 08:00:00', '2026-03-13 17:00:00', '2026-02-23 08:03:50'),
(44, 'Drainage Canal Desilting', 'Batasan Road, Batasan Hills', 'Drainage', 'Critical', 'In Progress', 5, 'Drainage Maintenance Team', 120000.00, '2026-03-02 06:00:00', '2026-03-05 18:00:00', '2026-02-23 08:03:50'),
(45, 'Drainage Pipe Replacement', 'Kalayaan Avenue, Pinyahan', 'Drainage', 'High', 'Scheduled', 5, 'Pipe Repair Team', 78000.00, '2026-03-09 07:00:00', '2026-03-10 17:00:00', '2026-02-23 08:03:50'),
(46, 'Catch Basin Cleaning', 'Examiner Street, West Triangle', 'Drainage', 'Medium', 'Completed', 5, 'Sanitation & Drainage Team', 14000.00, '2026-02-19 07:00:00', '2026-02-19 16:00:00', '2026-02-23 08:03:50'),
(47, 'Flood Control Gate Inspection', 'La Mesa Eco Park Entry, Novaliches', 'Drainage', 'High', 'Scheduled', 5, 'Flood Control Team', 35000.00, '2026-03-11 08:00:00', '2026-03-11 17:00:00', '2026-02-23 08:03:50'),
(48, 'Street Light Replacement', 'Mindanao Avenue, Project 8', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Team B', 48000.00, '2026-03-04 18:00:00', '2026-03-04 23:00:00', '2026-02-23 08:03:50'),
(49, 'LED Streetlight Upgrade', 'Aurora Boulevard, Cubao', 'Electrical', 'Medium', 'In Progress', 5, 'Electrical Upgrade Team', 95000.00, '2026-03-02 17:00:00', '2026-03-03 22:00:00', '2026-02-23 08:03:50'),
(50, 'Street Light Pole Repair', 'Sgt. Esguerra Avenue, South Triangle', 'Electrical', 'High', 'Delayed', 5, 'Electrical Repair Team', 27000.00, '2026-02-18 17:00:00', '2026-02-19 21:00:00', '2026-02-23 08:03:50'),
(51, 'Traffic Signal Maintenance', 'EDSA-Quezon Avenue Intersection, South Triangle', 'Electrical', 'Critical', 'Scheduled', 5, 'Traffic Signal Team', 65000.00, '2026-03-06 06:00:00', '2026-03-06 14:00:00', '2026-02-23 08:03:50'),
(52, 'Water Main Pipe Inspection', 'East Avenue, Diliman', 'Plumbing', 'High', 'Scheduled', 5, 'Water Supply Team', 43000.00, '2026-03-09 08:00:00', '2026-03-09 17:00:00', '2026-02-23 08:03:50'),
(53, 'Water Pump Station Maintenance', 'Novaliches Water Treatment Plant, Novaliches Proper', 'Mechanical', 'Critical', 'Scheduled', 5, 'Pump Maintenance Team', 150000.00, '2026-03-16 08:00:00', '2026-03-16 17:00:00', '2026-02-23 08:03:50'),
(54, 'Roof Repair', 'Farmer\'s Market, Araneta Center, Cubao', 'Structural', 'High', 'In Progress', 5, 'Structural Repair Team', 88000.00, '2026-03-02 08:00:00', '2026-03-04 17:00:00', '2026-02-23 08:03:50'),
(55, 'Electrical Rewiring', 'Cubao Public Market, General Romulo Avenue', 'Electrical', 'Critical', 'Scheduled', 5, 'Electrical Team A', 72000.00, '2026-03-16 08:00:00', '2026-03-18 17:00:00', '2026-02-23 08:03:50'),
(56, 'Plumbing Overhaul', 'Novaliches Public Market, Novaliches Proper', 'Plumbing', 'High', 'Scheduled', 5, 'Plumbing Overhaul Team', 54000.00, '2026-03-13 08:00:00', '2026-03-16 17:00:00', '2026-02-23 08:03:50'),
(57, 'Restroom Renovation', 'Quezon Memorial Circle, Elliptical Road, Diliman', 'Sanitation', 'Medium', 'Completed', 5, 'Sanitation Team', 39000.00, '2026-02-10 08:00:00', '2026-02-16 17:00:00', '2026-02-23 08:03:50'),
(58, 'Playground Equipment Check', 'Anonas Park, Anonas Road, Project 3', 'Safety', 'Medium', 'Scheduled', 5, 'Parks Maintenance Team', 12000.00, '2026-03-09 08:00:00', '2026-03-09 12:00:00', '2026-02-23 08:03:50'),
(59, 'Landscape & Tree Trimming', 'Quezon Memorial Circle, Elliptical Road, Diliman', 'Sanitation', 'Low', 'Scheduled', 5, 'Parks & Landscape Team', 28000.00, '2026-03-09 06:00:00', '2026-03-10 17:00:00', '2026-02-23 08:03:50'),
(60, 'Fountain Pump Repair', 'Welcome Rotonda, Quezon Avenue', 'Mechanical', 'Medium', 'Delayed', 5, 'Mechanical Repair Team', 21000.00, '2026-02-23 08:00:00', '2026-02-23 17:00:00', '2026-02-23 08:03:50'),
(61, 'Aircon Overhaul', 'Quezon City General Hospital, Seminary Road, Diliman', 'HVAC', 'Critical', 'Scheduled', 5, 'HVAC Specialist Team', 175000.00, '2026-03-17 08:00:00', '2026-03-19 17:00:00', '2026-02-23 08:03:50'),
(62, 'Elevator Maintenance', 'Quezon City General Hospital, Seminary Road, Diliman', 'Mechanical', 'Critical', 'Scheduled', 5, 'Elevator Service Team', 95000.00, '2026-03-20 08:00:00', '2026-03-20 17:00:00', '2026-02-23 08:03:50'),
(63, 'Roof Waterproofing', 'Batasan Hills National High School, Batasan Hills', 'Structural', 'High', 'Scheduled', 5, 'Civil Works Team A', 62000.00, '2026-03-23 08:00:00', '2026-03-23 17:00:00', '2026-02-23 08:03:50'),
(64, 'CCTV Network Upgrade', 'Cubao Station Area, EDSA, Cubao', 'Security', 'High', 'Scheduled', 5, 'Security Tech Team', 115000.00, '2026-03-23 08:00:00', '2026-03-24 17:00:00', '2026-02-23 08:03:50'),
(65, 'Security Perimeter Fence Repair', 'QC Circle Park, Elliptical Road, Diliman', 'Security', 'Medium', 'Scheduled', 5, 'Civil Works Team B', 33000.00, '2026-03-25 08:00:00', '2026-03-25 17:00:00', '2026-02-23 08:03:50'),
(66, 'Electrical Panel Safety Inspection', 'Quezon City Hall, Elliptical Road, Diliman', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Safety Team', 31000.00, '2026-03-16 07:00:00', '2026-03-16 11:00:00', '2026-02-23 09:42:43'),
(67, 'Street Light Pole Repainting', 'Commonwealth Avenue, Batasan Hills', 'Electrical', 'Low', 'Scheduled', 5, 'Electrical Team B', 14500.00, '2026-03-16 06:00:00', '2026-03-16 14:00:00', '2026-02-23 09:42:43'),
(68, 'Drainage Canal Flushing', 'Tandang Sora Avenue, Tandang Sora', 'Drainage', 'High', 'Scheduled', 5, 'Drainage Maintenance Team', 27000.00, '2026-03-16 07:30:00', '2026-03-16 15:00:00', '2026-02-23 09:42:43'),
(69, 'Barangay Hall Roof Gutter Clearing', 'Barangay Batasan Hills Hall, Batasan Hills', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team A', 9500.00, '2026-03-16 08:00:00', '2026-03-16 12:00:00', '2026-02-23 09:42:43'),
(70, 'Public Market Fire Extinguisher Refilling', 'Novaliches Public Market, Novaliches Proper', 'Safety', 'High', 'Scheduled', 5, 'Safety Compliance Team', 18500.00, '2026-03-16 08:00:00', '2026-03-16 13:00:00', '2026-02-23 09:42:43'),
(71, 'Health Center Aircon Filter Cleaning', 'Fairview Health Center, Fairview', 'HVAC', 'Medium', 'Scheduled', 5, 'HVAC Maintenance Team', 7500.00, '2026-03-16 09:00:00', '2026-03-16 12:00:00', '2026-02-23 09:42:43'),
(72, 'CCTV Hard Drive Replacement', 'Police Station 9, Cubao, Quezon City', 'Security', 'High', 'Scheduled', 5, 'Security Tech Team', 22000.00, '2026-03-16 09:00:00', '2026-03-16 16:00:00', '2026-02-23 09:42:43'),
(73, 'Pothole Emergency Patching', 'Visayas Avenue, Vasra, Quezon City', 'Roads', 'Critical', 'Scheduled', 5, 'Road Repair Team Alpha', 38000.00, '2026-03-16 06:00:00', '2026-03-16 18:00:00', '2026-02-23 09:42:43');

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
(467, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=114', 'Roads', 0, '2026-02-23 08:05:38'),
(468, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=114', 'Roads', 0, '2026-02-23 08:05:38'),
(469, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=114', 'Roads', 0, '2026-02-23 08:05:38'),
(470, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=114', 'Roads', 0, '2026-02-23 08:05:38'),
(471, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=114', 'Roads', 0, '2026-02-23 08:05:38'),
(472, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=114', 'Roads', 0, '2026-02-23 08:05:38'),
(473, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=114', 'Roads', 0, '2026-02-23 08:05:38'),
(474, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=114', 'Roads', 0, '2026-02-23 08:05:38'),
(475, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 0, '2026-02-23 08:09:37'),
(476, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 0, '2026-02-23 08:09:37'),
(477, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 0, '2026-02-23 08:09:37'),
(478, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 0, '2026-02-23 08:09:37'),
(479, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 0, '2026-02-23 08:09:37'),
(480, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 0, '2026-02-23 08:09:37'),
(481, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 0, '2026-02-23 08:09:37'),
(482, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 0, '2026-02-23 08:09:37'),
(483, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=115', 'Signage', 0, '2026-02-23 08:09:37'),
(484, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 0, '2026-02-23 08:12:29'),
(485, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 0, '2026-02-23 08:12:29'),
(486, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 0, '2026-02-23 08:12:29'),
(487, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 0, '2026-02-23 08:12:29'),
(488, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 0, '2026-02-23 08:12:29'),
(489, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 0, '2026-02-23 08:12:29'),
(490, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 0, '2026-02-23 08:12:29'),
(491, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 0, '2026-02-23 08:12:29'),
(492, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=116', 'Roads', 0, '2026-02-23 08:12:29'),
(493, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 0, '2026-02-23 08:14:48'),
(494, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 0, '2026-02-23 08:14:48'),
(495, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 0, '2026-02-23 08:14:48'),
(496, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 0, '2026-02-23 08:14:48'),
(497, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 0, '2026-02-23 08:14:48'),
(498, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 0, '2026-02-23 08:14:48'),
(499, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 0, '2026-02-23 08:14:48'),
(500, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 0, '2026-02-23 08:14:48'),
(501, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=117', 'Street Lights', 0, '2026-02-23 08:14:48'),
(502, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 0, '2026-02-23 08:18:35'),
(503, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 0, '2026-02-23 08:18:35'),
(504, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 0, '2026-02-23 08:18:35'),
(505, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 0, '2026-02-23 08:18:35'),
(506, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 0, '2026-02-23 08:18:35'),
(507, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 0, '2026-02-23 08:18:35'),
(508, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 0, '2026-02-23 08:18:35'),
(509, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 0, '2026-02-23 08:18:35'),
(510, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=118', 'Public Facilities', 0, '2026-02-23 08:18:35'),
(511, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 0, '2026-02-23 08:21:43'),
(512, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 0, '2026-02-23 08:21:43'),
(513, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 0, '2026-02-23 08:21:44'),
(514, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 0, '2026-02-23 08:21:44'),
(515, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 0, '2026-02-23 08:21:45'),
(516, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 0, '2026-02-23 08:21:45'),
(517, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 0, '2026-02-23 08:21:45'),
(518, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 0, '2026-02-23 08:21:45'),
(519, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=119', 'Drainage', 0, '2026-02-23 08:21:45'),
(520, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 0, '2026-02-23 08:26:51'),
(521, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 0, '2026-02-23 08:26:51'),
(522, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 0, '2026-02-23 08:26:51'),
(523, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 0, '2026-02-23 08:26:51'),
(524, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 0, '2026-02-23 08:26:51'),
(525, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 0, '2026-02-23 08:26:51'),
(526, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 0, '2026-02-23 08:26:51'),
(527, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 0, '2026-02-23 08:26:51'),
(528, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=120', 'Electrical', 0, '2026-02-23 08:26:51'),
(529, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 0, '2026-02-23 08:31:42'),
(530, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 0, '2026-02-23 08:31:42'),
(531, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 0, '2026-02-23 08:31:42'),
(532, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 0, '2026-02-23 08:31:42'),
(533, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 0, '2026-02-23 08:31:42'),
(534, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 0, '2026-02-23 08:31:42'),
(535, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 0, '2026-02-23 08:31:42'),
(536, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 0, '2026-02-23 08:31:42'),
(537, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=121', 'Waiting Shed', 0, '2026-02-23 08:31:42'),
(538, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 0, '2026-02-23 09:00:28'),
(539, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 0, '2026-02-23 09:00:28'),
(540, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 0, '2026-02-23 09:00:28'),
(541, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 0, '2026-02-23 09:00:28'),
(542, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 0, '2026-02-23 09:00:28'),
(543, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 0, '2026-02-23 09:00:28'),
(544, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 0, '2026-02-23 09:00:28'),
(545, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 0, '2026-02-23 09:00:28'),
(546, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=122', 'Traffic light', 0, '2026-02-23 09:00:28'),
(547, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 0, '2026-02-23 09:07:47'),
(548, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 0, '2026-02-23 09:07:47'),
(549, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 0, '2026-02-23 09:07:47'),
(550, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 0, '2026-02-23 09:07:47'),
(551, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 0, '2026-02-23 09:07:47'),
(552, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 0, '2026-02-23 09:07:47'),
(553, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 0, '2026-02-23 09:07:47'),
(554, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 0, '2026-02-23 09:07:47'),
(555, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=123', 'traffic light', 0, '2026-02-23 09:07:47'),
(556, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 0, '2026-02-23 09:16:42'),
(557, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 0, '2026-02-23 09:16:42'),
(558, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 0, '2026-02-23 09:16:42'),
(559, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 0, '2026-02-23 09:16:42'),
(560, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 0, '2026-02-23 09:16:42'),
(561, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 0, '2026-02-23 09:16:42'),
(562, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 0, '2026-02-23 09:16:42'),
(563, 14, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 0, '2026-02-23 09:16:42'),
(564, 15, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=124', 'Roads', 0, '2026-02-23 09:16:42'),
(565, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=125', 'Traffic light', 0, '2026-02-23 09:39:54'),
(566, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=125', 'Traffic light', 0, '2026-02-23 09:39:54'),
(567, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=125', 'Traffic light', 0, '2026-02-23 09:39:54'),
(568, 5, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=125', 'Traffic light', 0, '2026-02-23 09:39:54'),
(569, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=125', 'Traffic light', 0, '2026-02-23 09:39:54'),
(570, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=125', 'Traffic light', 0, '2026-02-23 09:39:54'),
(571, 13, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=125', 'Traffic light', 0, '2026-02-23 09:39:54'),
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
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `rep_id` int(10) UNSIGNED NOT NULL,
  `res_id` int(10) UNSIGNED NOT NULL,
  `starting_date` date NOT NULL,
  `estimated_end_date` date NOT NULL,
  `engineer_id` int(10) UNSIGNED NOT NULL,
  `report_by` int(10) UNSIGNED NOT NULL,
  `priority_lvl` varchar(50) DEFAULT NULL,
  `budget` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `requests`
--

INSERT INTO `requests` (`req_id`, `infrastructure`, `location`, `issue`, `contact_number`, `name`, `approval_status`, `created_at`) VALUES
(114, 'Roads', 'Diliman, Ugong Norte, Quezon City, Zamboanga Street', 'Nasira  daanan sa lugar namin.', '09876456355', 'Marites Santos', 'Pending', '2026-02-23 08:05:38'),
(115, 'Signage', 'Geronimo Compound, Santo Domingo (Matalahib), Quezon City, Polaris Street', 'nasira signage sa santo domingo road', '09874321334', 'Carlo Anotio', 'Pending', '2026-02-23 08:09:37'),
(116, 'Roads', 'Pasong Tamo, Quezon City', 'nasira daaan', '09875432456', 'Steph Dela cruz', 'Pending', '2026-02-23 08:12:29'),
(117, 'Street Lights', 'Project 7, N.S. Amoranto (Gintong Silahis), Quezon City, Miller Avenue', 'natumba street ligts banda dito sa Project 7', '09654432171', 'Jhoven Bartolome', 'Pending', '2026-02-23 08:14:48'),
(118, 'Public Facilities', 'Pasong Tamo, Sikatuna Village, Quezon City, Kasay-Kasay Street', 'nasira  cubicle sa pasong tamo public CR', '09856345143', 'Marisol Valencia', 'Pending', '2026-02-23 08:18:35'),
(119, 'Drainage', 'Libis, Quezon City', 'nagbara sa dami ng basura', '09786543456', 'Jeffrey Las-ay', 'Pending', '2026-02-23 08:21:43'),
(120, 'Electrical', 'Project 8, Santo Domingo (Matalahib), Quezon City, Mindanao Avenue', 'Bumagsak wiring dito sa project 8 santo domango', '09765432111', 'Marycarl Mallari', 'Pending', '2026-02-23 08:26:51'),
(121, 'Waiting Shed', 'Pansol, Krus Na Ligas, Quezon City, Montalban Street', 'bumagsak  yung bubong ng waiting shed sa panson', '09785634522', 'Jasmin Padilla', 'Pending', '2026-02-23 08:31:42'),
(123, 'traffic light', 'Project 8, Santo Domingo (Matalahib), Quezon City, Mindanao Avenue', 'nasira traffic light', '09123456785', 'Hannah Roxas', 'Pending', '2026-02-23 09:07:47'),
(124, 'Roads', 'Pasong Tamo, Quezon City', 'sira daanan banda dito sa pasong tamo.', '09765536274', 'Mark Santilan', 'Pending', '2026-02-23 09:16:42'),
(125, 'Traffic light', 'Santo Domingo (Matalahib), Quezon City', 'The Traffic light in the Santo domingo is broken.', '09009356577', 'Kent Bartolome', 'Pending', '2026-02-23 09:39:54');

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

--
-- Dumping data for table `request_ai_analysis`
--

INSERT INTO `request_ai_analysis` (`analysis_id`, `req_id`, `declared_infrastructure`, `detected_infrastructure`, `infrastructure_match`, `match_confidence`, `is_legitimate`, `legitimacy_score`, `legitimacy_notes`, `damage_severity`, `priority_recommendation`, `damage_description`, `confidence_score`, `anomaly_flags`, `combined_assessment`, `estimated_repair_complexity`, `requires_immediate_action`, `images_analyzed`, `analysis_status`, `ai_cost_estimation`, `analyzed_at`, `created_at`) VALUES
(28, 114, '', 'Roads', 1, 0.596, 1, 0.546, 'Road structural damage detected via pixel analysis. Manual review recommended.', 10, 'Critical', 'Roads issue across 1 image. Detected: traffic light, car. Road structural damage detected (cracks/heaving). Severe - immediate action required.', 0.496, '[\"immediate_action_required\",\"road_structural_damage\"]', 'traffic light, traffic signal, stoplight (9%); minivan (7%); parking meter (7%); jigsaw puzzle (6%); car mirror (3%); alp (3%)', 'Major', 1, 1, 'completed', 'P4,480,000 - P6,000,000', '2026-02-23 08:05:39', '2026-02-23 08:05:39'),
(29, 115, '', 'Roads', 0, 0.986, 1, 0.936, 'AI confidence: 89%. Infrastructure indicators detected.', 5, 'Medium', 'Roads issue across 1 image. Detected: street sign, stop sign. Rust/corrosion visible. Moderate damage.', 0.886, '[\"rust_detected\"]', 'street sign (68%); birdhouse (4%); flagpole, flagstaff (3%); mailbox, letter box (2%); mortarboard (1%); traffic light, traffic signal, stoplight (1%)', 'Moderate', 0, 1, 'completed', 'P50,000 - P70,000', '2026-02-23 08:09:38', '2026-02-23 08:09:38'),
(30, 116, '', 'Roads', 1, 0.100, 1, 0.050, 'Road structural damage detected via pixel analysis. Manual review recommended.', 10, 'Critical', 'Roads issue across 1 image. Road structural damage detected (cracks/heaving). Severe - immediate action required.', 0.000, '[\"immediate_action_required\",\"road_structural_damage\"]', 'stone wall (46%); patio, terrace (4%); bannister, banister, balustrade, balusters, handrail (3%); picket fence, paling (3%); cliff, drop, drop-off (2%); valley, vale (2%)', 'Major', 1, 1, 'completed', 'P3,370,000 - P6,000,000', '2026-02-23 08:12:29', '2026-02-23 08:12:29'),
(31, 117, '', 'Street Lights', 1, 0.225, 1, 0.175, 'Road structural damage detected via pixel analysis. Manual review recommended.', 10, 'Critical', 'Street Lights issue across 1 image. Detected: pole. Road structural damage detected (cracks/heaving). Severe - immediate action required.', 0.125, '[\"immediate_action_required\",\"road_structural_damage\"]', 'parking meter (20%); bubble (18%); pole (5%); syringe (4%); water bottle (3%); reel (3%)', 'Major', 1, 1, 'completed', 'P410,000 - P750,000', '2026-02-23 08:14:48', '2026-02-23 08:14:48'),
(32, 118, '', 'Public Facilities', 1, 1.000, 1, 1.000, 'Road structural damage detected via pixel analysis. Manual review recommended.', 10, 'Critical', 'Public Facilities issue across 1 image. Detected: toilet seat, toilet. Road structural damage detected (cracks/heaving). Severe - immediate action required.', 1.000, '[\"immediate_action_required\",\"road_structural_damage\"]', 'toilet seat (59%); toilet tissue, toilet paper, bathroom tissue (13%); soap dispenser (12%); washbasin, handbasin, washbowl, lavabo, wash-hand basin (4%); switch, electric switch, electrical switch (2%); paper towel (1%)', 'Major', 1, 1, 'completed', 'P1,520,000 - P1,800,000', '2026-02-23 08:18:36', '2026-02-23 08:18:36'),
(33, 119, '', 'Drainage', 1, 0.414, 1, 0.364, 'Road structural damage detected via pixel analysis. Manual review recommended.', 10, 'Critical', 'Drainage issue across 1 image. Detected: dam. Road structural damage detected (cracks/heaving). Severe - immediate action required.', 0.314, '[\"immediate_action_required\",\"road_structural_damage\"]', 'patio, terrace (23%); dam, dike, dyke (16%); pier (3%); greenhouse, nursery, glasshouse (2%); pot, flowerpot (2%); boathouse (2%)', 'Major', 1, 1, 'completed', 'P2,480,000 - P4,000,000', '2026-02-23 08:21:46', '2026-02-23 08:21:46'),
(34, 120, '', 'Roads', 0, 0.303, 1, 0.253, 'Road structural damage detected via pixel analysis. Manual review recommended.', 10, 'Critical', 'Roads issue across 1 image. Detected: cable, car. Road structural damage detected (cracks/heaving). Severe - immediate action required.', 0.203, '[\"immediate_action_required\",\"road_structural_damage\"]', 'sports car, sport car (22%); jinrikisha, ricksha, rickshaw (7%); cab, hack, taxi, taxicab (4%); convertible (3%); chain (3%); parking meter (2%)', 'Major', 1, 1, 'completed', 'P3,590,000 - P6,000,000', '2026-02-23 08:26:52', '2026-02-23 08:26:52'),
(35, 121, '', 'Roads', 0, 0.100, 1, 0.050, 'Road structural damage detected via pixel analysis. Manual review recommended.', 10, 'Critical', 'Roads issue across 1 image. Road structural damage detected (cracks/heaving). Severe - immediate action required.', 0.000, '[\"immediate_action_required\",\"road_structural_damage\"]', 'solar dish, solar collector, solar furnace (35%); bullet train, bullet (4%); cab, hack, taxi, taxicab (2%); drilling platform, offshore rig (2%); bobsled, bobsleigh, bob (2%); dock, dockage, docking facility (2%)', 'Major', 1, 1, 'completed', 'P3,370,000 - P6,000,000', '2026-02-23 08:31:43', '2026-02-23 08:31:43'),
(37, 123, '', 'Roads', 0, 0.772, 1, 0.722, 'Road structural damage detected via pixel analysis. Manual review recommended.', 10, 'Critical', 'Roads issue across 1 image. Detected: traffic light. Road structural damage detected (cracks/heaving). Burn/char marks detected. Severe - immediate action required.', 0.672, '[\"immediate_action_required\",\"road_structural_damage\"]', 'traffic light, traffic signal, stoplight (84%); horizontal bar, high bar (4%); pole (2%); parallel bars, bars (1%); spotlight, spot (1%); bow (0%)', 'Major', 1, 1, 'completed', 'P5,260,000 - P6,000,000', '2026-02-23 09:07:48', '2026-02-23 09:07:48'),
(38, 124, '', 'Roads', 1, 0.100, 1, 0.050, 'Road structural damage detected via pixel analysis. Manual review recommended.', 10, 'Critical', 'Roads issue across 1 image. Road structural damage detected (cracks/heaving). Severe - immediate action required.', 0.000, '[\"immediate_action_required\",\"road_structural_damage\"]', 'vault (6%); monastery (5%); umbrella (3%); trimaran (3%); hammer (2%); sundial (2%)', 'Major', 1, 1, 'completed', 'P3,370,000 - P6,000,000', '2026-02-23 09:16:44', '2026-02-23 09:16:44'),
(39, 125, '', 'Roads', 0, 0.731, 1, 0.681, 'Road structural damage detected via pixel analysis. Manual review recommended.', 10, 'Critical', 'Roads issue across 3 images. Detected: traffic light. Road structural damage detected (cracks/heaving). Severe - immediate action required.', 0.631, '[\"immediate_action_required\",\"road_structural_damage\"]', 'traffic light, traffic signal, stoplight (94%); traffic light, traffic signal, stoplight (85%); traffic light, traffic signal, stoplight (57%); horizontal bar, high bar (4%); crane (3%); street sign (3%)', 'Major', 1, 3, 'completed', 'P4,650,000 - P6,000,000', '2026-02-23 09:39:55', '2026-02-23 09:39:55');

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
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `evidence_images`
--
ALTER TABLE `evidence_images`
  MODIFY `img_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=126;

--
-- AUTO_INCREMENT for table `login_logs`
--
ALTER TABLE `login_logs`
  MODIFY `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=139;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=574;

--
-- AUTO_INCREMENT for table `pending_registrations`
--
ALTER TABLE `pending_registrations`
  MODIFY `penreg_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `repair_archive`
--
ALTER TABLE `repair_archive`
  MODIFY `arc_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `rep_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `requests`
--
ALTER TABLE `requests`
  MODIFY `req_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=126;

--
-- AUTO_INCREMENT for table `request_ai_analysis`
--
ALTER TABLE `request_ai_analysis`
  MODIFY `analysis_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `request_resolutions`
--
ALTER TABLE `request_resolutions`
  MODIFY `res_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

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
