-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
<<<<<<< Updated upstream
-- Host: 127.0.0.1
-- Generation Time: Feb 20, 2026 at 10:46 AM
=======
-- Host: localhost
-- Generation Time: Feb 23, 2026 at 03:51 PM
>>>>>>> Stashed changes
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
(11, 'Mary Carl', 'Dagondong', NULL, 'marycarldagondong28@gmail.com', 'Manager', '$2y$10$z8vP5dZk5Yqk4fY3J9vKTOwYzFQFZK2Yp4mR2Y3sY4J6P2d9r1mFi', 1, 0, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(13, 'Exequiel', 'Bartolome', NULL, 'bartolomstolome@gmail.com', 'Engineer', '$2y$10$nGULzfEnsv7xugNhr33tPeMbH3KS.qCie3Dq1CA4GtzQ/oukDmVFa', 0, 1, NULL, NULL, '2026-02-02 01:42:22', NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(14, 'Warv', 'Villa', NULL, 'villawarv@gmail.com', 'Super Admin', '$2y$10$5VxEbVQGo6bZLhJ4TGSKVutVoH6/uzXnEm.FmlKovuZHQVbdUbUhS', 0, 1, NULL, NULL, '2026-02-03 09:33:18', NULL, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL);

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

<<<<<<< Updated upstream
=======
--
-- Dumping data for table `login_logs`
--

INSERT INTO `login_logs` (`log_id`, `email`, `success`, `failure_reason`, `ip_address`, `user_agent`, `otp_used`, `otp_resends`, `created_at`) VALUES
(135, 'Villawarv@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 AVG/144.0.0.0', 1, 0, '2026-02-23 17:08:52'),
(136, 'Villawarv@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 AVG/144.0.0.0', 1, 0, '2026-02-23 17:17:27'),
(137, 'Villawarv@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 AVG/144.0.0.0', 1, 0, '2026-02-23 17:43:29'),
(138, 'Villawarv@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 AVG/144.0.0.0', 1, 0, '2026-02-23 18:01:49'),
(139, 'Villawarv@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 AVG/144.0.0.0', 1, 0, '2026-02-23 21:35:34'),
(140, 'Villawarv@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 AVG/144.0.0.0', 1, 0, '2026-02-23 22:30:25');

-- --------------------------------------------------------
>>>>>>> Stashed changes

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

<<<<<<< Updated upstream
=======
--
-- Dumping data for table `maintenance_schedule`
--

INSERT INTO `maintenance_schedule` (`sched_id`, `task`, `location`, `category`, `priority`, `status`, `engineer_id`, `assigned_team`, `budget`, `starting_date`, `estimated_completion_date`, `created_at`) VALUES
(34, 'HVAC System Inspection', 'Quezon City Hall, Elliptical Road, Diliman', 'HVAC', 'High', 'Scheduled', 5, 'Facilities Maintenance Team', 15000.00, '2026-03-03 08:00:00', '2026-03-03 17:00:00', '2026-02-23 08:03:50'),
(35, 'Electrical Panel Maintenance', 'Quezon City Hall Annex, Batasan Hills', 'Electrical', 'High', 'In Progress', 5, 'Electrical Team A', 22000.00, '2026-03-04 08:00:00', '2026-03-04 16:00:00', '2026-02-23 08:03:50'),
(36, 'Generator Load Testing', 'Quezon City Hall, Elliptical Road, Diliman', 'Mechanical', 'Critical', 'Scheduled', 5, 'Power & Mechanical Team', 18000.00, '2026-03-05 09:00:00', '2026-03-05 14:00:00', '2026-02-23 08:03:50'),
(37, 'Fire Suppression System Check', 'QC Hall of Justice, Batasan Complex, Batasan Hills', 'Safety', 'High', 'Scheduled', 5, 'Safety Compliance Team', 9500.00, '2026-03-06 08:30:00', '2026-03-06 12:00:00', '2026-02-23 08:03:50'),
(38, 'Plumbing Leak Repair', 'Quezon City Public Library, Elliptical Road', 'Plumbing', 'Medium', 'Completed', 5, 'Repair Team B', 5500.00, '2026-02-24 09:00:00', '2026-02-24 13:00:00', '2026-02-23 08:03:50'),
(39, 'Pothole Patching', 'Commonwealth Avenue, Batasan Hills', 'Roads', 'Critical', 'In Progress', 5, 'Road Repair Team Alpha', 85000.00, '2026-03-02 07:00:00', '2026-03-07 17:00:00', '2026-02-23 08:03:50'),
(40, 'Road Repainting & Lane Marking', 'Quezon Avenue, South Triangle', 'Roads', 'Medium', 'Scheduled', 5, 'Road Marking Team', 42000.00, '2026-03-10 06:00:00', '2026-03-11 18:00:00', '2026-02-23 08:03:50'),
(41, 'Sidewalk Repair', 'Tomas Morato Avenue, Sacred Heart', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team B', 31000.00, '2026-03-09 07:00:00', '2026-03-10 17:00:00', '2026-02-23 08:03:50'),
(42, 'Road Crack Sealing', 'Visayas Avenue, Vasra', 'Roads', 'High', 'Delayed', 5, 'Road Repair Team Beta', 67000.00, '2026-02-20 07:00:00', '2026-02-22 17:00:00', '2026-02-23 08:03:50'),
(43, 'Bridge Structural Inspection', 'Tandang Sora Bridge, Tandang Sora Avenue', 'Structural', 'Critical', 'Scheduled', 5, 'Structural Inspection Team', 55000.00, '2026-03-12 08:00:00', '2026-03-13 17:00:00', '2026-02-23 08:03:50'),
(44, 'Drainage Canal Desilting', 'Batasan Road, Batasan Hills', 'Drainage', 'Critical', 'In Progress', 5, 'Drainage Maintenance Team', 120000.00, '2026-03-01 06:00:00', '2026-03-05 18:00:00', '2026-02-23 08:03:50'),
(45, 'Drainage Pipe Replacement', 'Kalayaan Avenue, Pinyahan', 'Drainage', 'High', 'Scheduled', 5, 'Pipe Repair Team', 78000.00, '2026-03-08 07:00:00', '2026-03-10 17:00:00', '2026-02-23 08:03:50'),
(46, 'Catch Basin Cleaning', 'Examiner Street, West Triangle', 'Drainage', 'Medium', 'Completed', 5, 'Sanitation & Drainage Team', 14000.00, '2026-02-19 07:00:00', '2026-02-19 16:00:00', '2026-02-23 08:03:50'),
(47, 'Flood Control Gate Inspection', 'La Mesa Eco Park Entry, Novaliches', 'Drainage', 'High', 'Scheduled', 5, 'Flood Control Team', 35000.00, '2026-03-11 08:00:00', '2026-03-11 17:00:00', '2026-02-23 08:03:50'),
(48, 'Street Light Replacement', 'Mindanao Avenue, Project 8', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Team B', 48000.00, '2026-03-04 18:00:00', '2026-03-04 23:00:00', '2026-02-23 08:03:50'),
(49, 'LED Streetlight Upgrade', 'Aurora Boulevard, Cubao', 'Electrical', 'Medium', 'In Progress', 5, 'Electrical Upgrade Team', 95000.00, '2026-03-01 17:00:00', '2026-03-03 22:00:00', '2026-02-23 08:03:50'),
(50, 'Street Light Pole Repair', 'Sgt. Esguerra Avenue, South Triangle', 'Electrical', 'High', 'Delayed', 5, 'Electrical Repair Team', 27000.00, '2026-02-18 17:00:00', '2026-02-19 21:00:00', '2026-02-23 08:03:50'),
(51, 'Traffic Signal Maintenance', 'EDSA-Quezon Avenue Intersection, South Triangle', 'Electrical', 'Critical', 'Scheduled', 5, 'Traffic Signal Team', 65000.00, '2026-03-06 06:00:00', '2026-03-06 14:00:00', '2026-02-23 08:03:50'),
(52, 'Water Main Pipe Inspection', 'East Avenue, Diliman', 'Plumbing', 'High', 'Scheduled', 5, 'Water Supply Team', 43000.00, '2026-03-07 08:00:00', '2026-03-07 17:00:00', '2026-02-23 08:03:50'),
(53, 'Water Pump Station Maintenance', 'Novaliches Water Treatment Plant, Novaliches Proper', 'Mechanical', 'Critical', 'Scheduled', 5, 'Pump Maintenance Team', 150000.00, '2026-03-15 08:00:00', '2026-03-16 17:00:00', '2026-02-23 08:03:50'),
(54, 'Roof Repair', 'Farmer\'s Market, Araneta Center, Cubao', 'Structural', 'High', 'In Progress', 5, 'Structural Repair Team', 88000.00, '2026-03-02 08:00:00', '2026-03-04 17:00:00', '2026-02-23 08:03:50'),
(55, 'Electrical Rewiring', 'Cubao Public Market, General Romulo Avenue', 'Electrical', 'Critical', 'Scheduled', 5, 'Electrical Team A', 72000.00, '2026-03-16 08:00:00', '2026-03-18 17:00:00', '2026-02-23 08:03:50'),
(56, 'Plumbing Overhaul', 'Novaliches Public Market, Novaliches Proper', 'Plumbing', 'High', 'Scheduled', 5, 'Plumbing Overhaul Team', 54000.00, '2026-03-13 08:00:00', '2026-03-14 17:00:00', '2026-02-23 08:03:50'),
(57, 'Restroom Renovation', 'Quezon Memorial Circle, Elliptical Road, Diliman', 'Sanitation', 'Medium', 'Completed', 5, 'Sanitation Team', 39000.00, '2026-02-10 08:00:00', '2026-02-14 17:00:00', '2026-02-23 08:03:50'),
(58, 'Playground Equipment Check', 'Anonas Park, Anonas Road, Project 3', 'Safety', 'Medium', 'Scheduled', 5, 'Parks Maintenance Team', 12000.00, '2026-03-08 08:00:00', '2026-03-08 12:00:00', '2026-02-23 08:03:50'),
(59, 'Landscape & Tree Trimming', 'Quezon Memorial Circle, Elliptical Road, Diliman', 'Sanitation', 'Low', 'Scheduled', 5, 'Parks & Landscape Team', 28000.00, '2026-03-09 06:00:00', '2026-03-10 17:00:00', '2026-02-23 08:03:50'),
(60, 'Fountain Pump Repair', 'Welcome Rotonda, Quezon Avenue', 'Mechanical', 'Medium', 'Delayed', 5, 'Mechanical Repair Team', 21000.00, '2026-02-22 08:00:00', '2026-02-22 17:00:00', '2026-02-23 08:03:50'),
(61, 'Aircon Overhaul', 'Quezon City General Hospital, Seminary Road, Diliman', 'HVAC', 'Critical', 'Scheduled', 5, 'HVAC Specialist Team', 175000.00, '2026-03-17 08:00:00', '2026-03-19 17:00:00', '2026-02-23 08:03:50'),
(62, 'Elevator Maintenance', 'Quezon City General Hospital, Seminary Road, Diliman', 'Mechanical', 'Critical', 'Scheduled', 5, 'Elevator Service Team', 95000.00, '2026-03-20 08:00:00', '2026-03-20 17:00:00', '2026-02-23 08:03:50'),
(63, 'Roof Waterproofing', 'Batasan Hills National High School, Batasan Hills', 'Structural', 'High', 'Scheduled', 5, 'Civil Works Team A', 62000.00, '2026-03-21 08:00:00', '2026-03-22 17:00:00', '2026-02-23 08:03:50'),
(64, 'CCTV Network Upgrade', 'Cubao Station Area, EDSA, Cubao', 'Security', 'High', 'Scheduled', 5, 'Security Tech Team', 115000.00, '2026-03-23 08:00:00', '2026-03-24 17:00:00', '2026-02-23 08:03:50'),
(65, 'Security Perimeter Fence Repair', 'QC Circle Park, Elliptical Road, Diliman', 'Security', 'Medium', 'Scheduled', 5, 'Civil Works Team B', 33000.00, '2026-03-25 08:00:00', '2026-03-25 17:00:00', '2026-02-23 08:03:50'),
(66, 'Electrical Panel Safety Inspection', 'Quezon City Hall, Elliptical Road, Diliman', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Safety Team', 31000.00, '2026-03-15 07:00:00', '2026-03-15 11:00:00', '2026-02-23 09:42:43'),
(67, 'Street Light Pole Repainting', 'Commonwealth Avenue, Batasan Hills', 'Electrical', 'Low', 'Scheduled', 5, 'Electrical Team B', 14500.00, '2026-03-15 06:00:00', '2026-03-15 14:00:00', '2026-02-23 09:42:43'),
(68, 'Drainage Canal Flushing', 'Tandang Sora Avenue, Tandang Sora', 'Drainage', 'High', 'Scheduled', 5, 'Drainage Maintenance Team', 27000.00, '2026-03-15 07:30:00', '2026-03-15 15:00:00', '2026-02-23 09:42:43'),
(69, 'Barangay Hall Roof Gutter Clearing', 'Barangay Batasan Hills Hall, Batasan Hills', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team A', 9500.00, '2026-03-15 08:00:00', '2026-03-15 12:00:00', '2026-02-23 09:42:43'),
(70, 'Public Market Fire Extinguisher Refilling', 'Novaliches Public Market, Novaliches Proper', 'Safety', 'High', 'Scheduled', 5, 'Safety Compliance Team', 18500.00, '2026-03-15 08:00:00', '2026-03-15 13:00:00', '2026-02-23 09:42:43'),
(71, 'Health Center Aircon Filter Cleaning', 'Fairview Health Center, Fairview', 'HVAC', 'Medium', 'Scheduled', 5, 'HVAC Maintenance Team', 7500.00, '2026-03-15 09:00:00', '2026-03-15 12:00:00', '2026-02-23 09:42:43'),
(72, 'CCTV Hard Drive Replacement', 'Police Station 9, Cubao, Quezon City', 'Security', 'High', 'Scheduled', 5, 'Security Tech Team', 22000.00, '2026-03-15 09:00:00', '2026-03-15 16:00:00', '2026-02-23 09:42:43'),
(73, 'Pothole Emergency Patching', 'Visayas Avenue, Vasra, Quezon City', 'Roads', 'Critical', 'Scheduled', 5, 'Road Repair Team Alpha', 38000.00, '2026-03-15 06:00:00', '2026-03-15 18:00:00', '2026-02-23 09:42:43'),
(74, 'Generator Preventive Maintenance', 'Quezon City Hall, Elliptical Road, Diliman', 'Mechanical', 'Critical', 'Scheduled', 5, 'Power & Mechanical Team', 82000.00, '2026-03-03 08:00:00', '2026-03-03 17:00:00', '2026-02-23 14:24:07'),
(75, 'Electrical Panel Safety Inspection', 'Quezon City Hall, Elliptical Road, Diliman', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Safety Team', 31000.00, '2026-03-04 07:00:00', '2026-03-04 12:00:00', '2026-02-23 14:24:07'),
(76, 'Drainage Canal Desilting', 'Commonwealth Avenue, Batasan Hills', 'Drainage', 'Critical', 'Scheduled', 5, 'Drainage Maintenance Team', 120000.00, '2026-03-04 06:00:00', '2026-03-04 18:00:00', '2026-02-23 14:24:07'),
(77, 'Barangay Hall Roof Gutter Clearing', 'Barangay Batasan Hills Hall, Batasan Hills', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team A', 9500.00, '2026-03-04 08:00:00', '2026-03-04 13:00:00', '2026-02-23 14:24:07'),
(78, 'CCTV Hard Drive Replacement', 'Police Station 9, Cubao, Quezon City', 'Security', 'High', 'Scheduled', 5, 'Security Tech Team', 22000.00, '2026-03-04 09:00:00', '2026-03-04 16:00:00', '2026-02-23 14:24:07'),
(79, 'Fire Suppression System Check', 'QC Hall of Justice, Batasan Complex, Batasan Hills', 'Safety', 'High', 'Scheduled', 5, 'Safety Compliance Team', 9500.00, '2026-03-05 08:30:00', '2026-03-05 12:00:00', '2026-02-23 14:24:07'),
(80, 'Traffic Light Controller Replacement', 'Batasan Road - Commonwealth Avenue Intersection', 'Electrical', 'Critical', 'Scheduled', 5, 'Traffic Signal Team', 178000.00, '2026-03-06 07:00:00', '2026-03-06 18:00:00', '2026-02-23 14:24:07'),
(81, 'Pothole Emergency Patching', 'Visayas Avenue, Vasra, Quezon City', 'Roads', 'Critical', 'Scheduled', 5, 'Road Repair Team Alpha', 38000.00, '2026-03-09 06:00:00', '2026-03-09 18:00:00', '2026-02-23 14:24:07'),
(82, 'Cold Storage Unit Maintenance', 'Fairview Health Center, Fairview', 'Mechanical', 'Critical', 'Scheduled', 5, 'Refrigeration Team', 48000.00, '2026-03-10 08:00:00', '2026-03-10 17:00:00', '2026-02-23 14:24:07'),
(83, 'Roof Leak Patching', 'Barangay Holy Spirit Hall, Holy Spirit, Quezon City', 'Structural', 'High', 'Scheduled', 5, 'Structural Repair Team', 17500.00, '2026-03-11 07:00:00', '2026-03-11 15:00:00', '2026-02-23 14:24:07'),
(84, 'Water Main Pipe Inspection', 'East Avenue, Diliman', 'Plumbing', 'High', 'Scheduled', 5, 'Water Supply Team', 43000.00, '2026-03-11 08:00:00', '2026-03-11 17:00:00', '2026-02-23 14:24:07'),
(85, 'Public Market Fire Extinguisher Refilling', 'Novaliches Public Market, Novaliches Proper', 'Safety', 'High', 'Scheduled', 5, 'Safety Compliance Team', 18500.00, '2026-03-11 08:00:00', '2026-03-11 13:00:00', '2026-02-23 14:24:07'),
(86, 'Aircon Unit Cleaning', 'Barangay Bagumbuhay Hall, Bagumbuhay, Quezon City', 'HVAC', 'Low', 'Scheduled', 5, 'HVAC Maintenance Team', 8500.00, '2026-03-11 09:00:00', '2026-03-11 12:00:00', '2026-02-23 14:24:07'),
(87, 'Bridge Structural Inspection', 'Tandang Sora Bridge, Tandang Sora Avenue', 'Structural', 'Critical', 'Scheduled', 5, 'Structural Inspection Team', 55000.00, '2026-03-12 08:00:00', '2026-03-12 17:00:00', '2026-02-23 14:24:07'),
(88, 'Basketball Court Resurfacing', 'Barangay Holy Spirit Covered Court, Holy Spirit', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team C', 48000.00, '2026-03-13 08:00:00', '2026-03-13 17:00:00', '2026-02-23 14:24:07'),
(89, 'LED Streetlight Upgrade', 'Aurora Boulevard, Cubao', 'Electrical', 'Medium', 'Scheduled', 5, 'Electrical Upgrade Team', 95000.00, '2026-03-16 17:00:00', '2026-03-16 23:00:00', '2026-02-23 14:24:07'),
(90, 'Network Fiber Cabling Installation', 'QC Hall of Justice Annex, Batasan Complex', 'IT', 'High', 'Scheduled', 5, 'IT Cabling Team', 124000.00, '2026-03-17 08:00:00', '2026-03-17 17:00:00', '2026-02-23 14:24:07'),
(91, 'Sterilization Room Ventilation Upgrade', 'Payatas Health Center, Payatas Road, Quezon City', 'HVAC', 'High', 'Scheduled', 5, 'HVAC Specialist Team', 59000.00, '2026-03-18 08:00:00', '2026-03-18 17:00:00', '2026-02-23 14:24:07'),
(92, 'Detention Cell Plumbing Repair', 'Police Station 7, Kamuning, Quezon City', 'Plumbing', 'High', 'Scheduled', 5, 'Plumbing Repair Team', 29000.00, '2026-03-18 08:00:00', '2026-03-18 16:00:00', '2026-02-23 14:24:07'),
(93, 'Road Sign Replacement', 'Mindanao Avenue - Congressional Avenue Intersection', 'Roads', 'Medium', 'Scheduled', 5, 'Road Marking Team', 23000.00, '2026-03-18 07:00:00', '2026-03-18 15:00:00', '2026-02-23 14:24:07'),
(94, 'Compactor Truck Hydraulic Repair', 'QC Environmental Sanitation Division, Batasan Complex', 'Mechanical', 'Critical', 'Scheduled', 5, 'Vehicle Maintenance Team', 143000.00, '2026-03-19 07:00:00', '2026-03-19 17:00:00', '2026-02-23 14:24:07'),
(95, 'Classroom Ceiling Board Replacement', 'Commonwealth Elementary School, Commonwealth Avenue', 'Structural', 'High', 'Scheduled', 5, 'Civil Works Team A', 53000.00, '2026-03-20 08:00:00', '2026-03-20 17:00:00', '2026-02-23 14:24:07'),
(96, 'Swimming Pool Filtration System Repair', 'Quezon City Sports Complex, Elliptical Road', 'Mechanical', 'Critical', 'Scheduled', 5, 'Pump Maintenance Team', 115000.00, '2026-03-23 08:00:00', '2026-03-23 17:00:00', '2026-02-23 14:24:07'),
(97, 'Perimeter Wall Repair', 'Himlayang Pilipino, Quezon Avenue, Project 6', 'Structural', 'High', 'Scheduled', 5, 'Civil Works Team A', 67000.00, '2026-03-24 08:00:00', '2026-03-24 17:00:00', '2026-02-23 14:24:07'),
(98, 'Fire Truck Engine Overhaul', 'Quezon City Fire Station, Bureau of Fire Protection, Batasan Hills', 'Mechanical', 'Critical', 'Scheduled', 5, 'Vehicle Maintenance Team', 185000.00, '2026-03-25 08:00:00', '2026-03-25 17:00:00', '2026-02-23 14:24:07'),
(99, 'Server Room UPS Battery Replacement', 'QC City Hall IT Department, Elliptical Road, Diliman', 'IT', 'Critical', 'Scheduled', 5, 'IT Support Team', 88000.00, '2026-03-25 08:00:00', '2026-03-25 17:00:00', '2026-02-23 14:24:07'),
(100, 'Effluent Treatment System Repair', 'QC Abattoir, Marilaque Road, San Mateo Border', 'Sanitation', 'Critical', 'Scheduled', 5, 'Sanitation & Safety Team', 143000.00, '2026-03-25 07:00:00', '2026-03-25 17:00:00', '2026-02-23 14:24:07'),
(101, 'Medical Waste Disposal Area Repair', 'Novaliches District Hospital, Novaliches Proper', 'Sanitation', 'Critical', 'Scheduled', 5, 'Sanitation & Safety Team', 73000.00, '2026-03-25 08:00:00', '2026-03-25 17:00:00', '2026-02-23 14:24:07'),
(102, 'Flood Control Gate Inspection', 'La Mesa Eco Park Entry, Novaliches', 'Drainage', 'High', 'Scheduled', 5, 'Flood Control Team', 35000.00, '2026-03-26 08:00:00', '2026-03-26 17:00:00', '2026-02-23 14:24:07'),
(103, 'Stadium Floodlight Replacement', 'Quezon City Memorial Stadium, Elliptical Road, Diliman', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Team A', 89000.00, '2026-03-27 17:00:00', '2026-03-27 23:00:00', '2026-02-23 14:24:07'),
(104, 'Sanitary Landfill Leachate Pump Maintenance', 'Payatas Sanitary Landfill, Payatas', 'Mechanical', 'Critical', 'Scheduled', 5, 'Pump Maintenance Team', 195000.00, '2026-03-30 08:00:00', '2026-03-30 17:00:00', '2026-02-23 14:24:07'),
(105, 'Aircon Overhaul', 'Quezon City General Hospital, Seminary Road, Diliman', 'HVAC', 'Critical', 'Scheduled', 5, 'HVAC Specialist Team', 175000.00, '2026-04-01 08:00:00', '2026-04-01 17:00:00', '2026-02-23 14:24:07'),
(106, 'Drainage Pipe Replacement', 'Kalayaan Avenue, Pinyahan', 'Drainage', 'High', 'Scheduled', 5, 'Pipe Repair Team', 78000.00, '2026-04-01 07:00:00', '2026-04-01 17:00:00', '2026-02-23 14:24:07'),
(107, 'Road Repainting & Lane Marking', 'Quezon Avenue, South Triangle', 'Roads', 'Medium', 'Scheduled', 5, 'Road Marking Team', 42000.00, '2026-04-01 06:00:00', '2026-04-01 18:00:00', '2026-02-23 14:24:07'),
(108, 'Elevator Maintenance', 'Quezon City General Hospital, Seminary Road, Diliman', 'Mechanical', 'Critical', 'Scheduled', 5, 'Elevator Service Team', 95000.00, '2026-04-06 08:00:00', '2026-04-06 17:00:00', '2026-02-23 14:24:07'),
(109, 'Covered Court Roofing Sheet Replacement', 'QC Sports Club, Elliptical Road, Diliman', 'Structural', 'High', 'Scheduled', 5, 'Civil Works Team B', 72000.00, '2026-04-07 08:00:00', '2026-04-07 17:00:00', '2026-02-23 14:24:07'),
(110, 'Water Pump Station Maintenance', 'Novaliches Water Treatment Plant, Novaliches Proper', 'Mechanical', 'Critical', 'Scheduled', 5, 'Pump Maintenance Team', 150000.00, '2026-04-08 08:00:00', '2026-04-08 17:00:00', '2026-02-23 14:24:07'),
(111, 'MRF Conveyor Belt Repair', 'Materials Recovery Facility, Bagong Silangan', 'Mechanical', 'High', 'Scheduled', 5, 'Mechanical Repair Team', 77000.00, '2026-04-10 08:00:00', '2026-04-10 17:00:00', '2026-02-23 14:24:07'),
(112, 'Roofing Waterproof Membrane Repair', 'Amoranto Multi-Purpose Center, Amoranto Avenue', 'Structural', 'Medium', 'Scheduled', 5, 'Structural Repair Team', 39000.00, '2026-04-13 08:00:00', '2026-04-13 17:00:00', '2026-02-23 14:24:07'),
(113, 'Emergency Generator Installation', 'DSWD QC Temporary Shelter, Commonwealth Avenue', 'Electrical', 'Critical', 'Scheduled', 5, 'Power & Mechanical Team', 210000.00, '2026-04-14 08:00:00', '2026-04-14 17:00:00', '2026-02-23 14:24:07'),
(114, 'Dental Chair Hydraulic Repair', 'Batasan Hills Rural Health Unit, Batasan Hills', 'Mechanical', 'High', 'Scheduled', 5, 'Mechanical Repair Team', 32000.00, '2026-04-15 09:00:00', '2026-04-15 15:00:00', '2026-02-23 14:24:07'),
(115, 'Plumbing Overhaul', 'Novaliches Public Market, Novaliches Proper', 'Plumbing', 'High', 'Scheduled', 5, 'Plumbing Overhaul Team', 54000.00, '2026-04-16 08:00:00', '2026-04-16 17:00:00', '2026-02-23 14:24:07'),
(116, 'Overhead Hoist & Rail System Inspection', 'QC Abattoir, Marilaque Road, San Mateo Border', 'Mechanical', 'High', 'Scheduled', 5, 'Mechanical Inspection Team', 54000.00, '2026-04-17 08:00:00', '2026-04-17 17:00:00', '2026-02-23 14:24:07'),
(117, 'Guard Rail Replacement', 'Quirino Highway, San Bartolome, Novaliches', 'Structural', 'High', 'Scheduled', 5, 'Civil Works Team B', 62000.00, '2026-04-20 08:00:00', '2026-04-20 17:00:00', '2026-02-23 14:24:07'),
(118, 'Terminal Shed Roofing Repair', 'Cubao Bus Terminal, EDSA, Cubao', 'Structural', 'High', 'Scheduled', 5, 'Structural Repair Team', 58000.00, '2026-04-21 08:00:00', '2026-04-21 17:00:00', '2026-02-23 14:24:07'),
(119, 'Electrical Rewiring', 'Barangay Payatas Hall, Payatas, Quezon City', 'Electrical', 'Critical', 'Scheduled', 5, 'Electrical Team B', 43000.00, '2026-04-22 08:00:00', '2026-04-22 17:00:00', '2026-02-23 14:24:07'),
(120, 'Composting Area Drainage Repair', 'QC Composting Facility, Payatas', 'Drainage', 'Medium', 'Scheduled', 5, 'Drainage Maintenance Team', 34000.00, '2026-04-23 07:00:00', '2026-04-23 17:00:00', '2026-02-23 14:24:07'),
(121, 'Pathway Lighting Replacement', 'Novaliches Municipal Cemetery, Novaliches Proper', 'Electrical', 'Medium', 'Scheduled', 5, 'Electrical Team B', 38000.00, '2026-04-24 17:00:00', '2026-04-24 22:00:00', '2026-02-23 14:24:07'),
(122, 'Speed Hump Installation', 'Balintawak Street, Balintawak, Quezon City', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team A', 41000.00, '2026-04-27 07:00:00', '2026-04-27 17:00:00', '2026-02-23 14:24:07'),
(123, 'Hose & Equipment Pressure Testing', 'BFP Station 1, Kamuning Road, Kamuning', 'Safety', 'High', 'Scheduled', 5, 'Fire Equipment Team', 24000.00, '2026-04-28 08:00:00', '2026-04-28 16:00:00', '2026-02-23 14:24:07'),
(124, 'CCTV System Upgrade', 'Fairview Terminal, Quirino Highway, Fairview', 'Security', 'High', 'Scheduled', 5, 'Security Tech Team', 93000.00, '2026-04-29 08:00:00', '2026-04-29 17:00:00', '2026-02-23 14:24:07'),
(125, 'Sidewalk Repair', 'Tomas Morato Avenue, Sacred Heart', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team B', 31000.00, '2026-04-30 07:00:00', '2026-04-30 17:00:00', '2026-02-23 14:24:07'),
(126, 'Pothole Patching', 'Commonwealth Avenue Northbound, Batasan Hills, Quezon City', 'Roads', 'Critical', 'Scheduled', 5, 'Road Repair Team Alpha', 95000.00, '2026-03-03 06:00:00', '2026-03-03 14:00:00', '2026-02-23 14:46:29'),
(127, 'Road Crack Sealing', 'Commonwealth Avenue Southbound, Batasan Hills, Quezon City', 'Roads', 'High', 'Scheduled', 5, 'Road Repair Team Beta', 67000.00, '2026-03-03 06:30:00', '2026-03-03 14:30:00', '2026-02-23 14:46:29'),
(128, 'Road Repainting & Lane Marking', 'Commonwealth Avenue Intersection, Batasan Hills, Quezon City', 'Roads', 'Medium', 'Scheduled', 5, 'Road Marking Team', 42000.00, '2026-03-03 15:00:00', '2026-03-03 22:00:00', '2026-02-23 14:46:29'),
(129, 'Sidewalk Repair', 'Commonwealth Avenue Service Road, Batasan Hills, Quezon City', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team A', 31000.00, '2026-03-03 07:00:00', '2026-03-03 17:00:00', '2026-02-23 14:46:29'),
(130, 'Drainage Canal Desilting', 'Visayas Avenue, Vasra, Quezon City', 'Drainage', 'Critical', 'Scheduled', 5, 'Drainage Maintenance Team', 120000.00, '2026-03-04 06:00:00', '2026-03-04 17:00:00', '2026-02-23 14:46:29'),
(131, 'Catch Basin Cleaning', 'Visayas Avenue Corner Mindanao Avenue, Project 8', 'Drainage', 'High', 'Scheduled', 5, 'Sanitation & Drainage Team', 18000.00, '2026-03-04 07:00:00', '2026-03-04 15:00:00', '2026-02-23 14:46:29'),
(132, 'Drainage Pipe Replacement', 'Visayas Avenue Service Lane, Vasra, Quezon City', 'Drainage', 'High', 'Scheduled', 5, 'Pipe Repair Team', 78000.00, '2026-03-04 08:00:00', '2026-03-04 17:00:00', '2026-02-23 14:46:29'),
(133, 'Flood Control Gate Inspection', 'Visayas Avenue Underpass, Project 8, Quezon City', 'Drainage', 'Critical', 'Scheduled', 5, 'Flood Control Team', 35000.00, '2026-03-04 09:00:00', '2026-03-04 16:00:00', '2026-02-23 14:46:29'),
(134, 'Water Main Pipe Inspection', 'East Avenue Northbound, Diliman, Quezon City', 'Plumbing', 'High', 'Scheduled', 5, 'Water Supply Team', 43000.00, '2026-03-05 07:00:00', '2026-03-05 15:00:00', '2026-02-23 14:46:29'),
(135, 'Water Pipe Joint Sealing', 'East Avenue Corner Quezon Avenue, Diliman', 'Plumbing', 'Critical', 'Scheduled', 5, 'Pipe Repair Team', 55000.00, '2026-03-05 08:00:00', '2026-03-05 17:00:00', '2026-02-23 14:46:29'),
(136, 'Water Meter Box Replacement', 'East Avenue Service Road, Diliman, Quezon City', 'Plumbing', 'Medium', 'Scheduled', 5, 'Water Supply Team', 29000.00, '2026-03-05 09:00:00', '2026-03-05 16:00:00', '2026-02-23 14:46:29'),
(137, 'Street Light Pole Replacement', 'Quezon Avenue Northbound, South Triangle, Quezon City', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Team A', 85000.00, '2026-03-10 17:00:00', '2026-03-10 23:00:00', '2026-02-23 14:46:29'),
(138, 'LED Streetlight Upgrade', 'Quezon Avenue Southbound, South Triangle, Quezon City', 'Electrical', 'Medium', 'Scheduled', 5, 'Electrical Upgrade Team', 95000.00, '2026-03-10 17:00:00', '2026-03-10 23:00:00', '2026-02-23 14:46:29'),
(139, 'Traffic Signal Maintenance', 'Quezon Avenue–EDSA Intersection, South Triangle', 'Electrical', 'Critical', 'Scheduled', 5, 'Traffic Signal Team', 65000.00, '2026-03-10 06:00:00', '2026-03-10 12:00:00', '2026-02-23 14:46:29'),
(140, 'Street Light Wiring Inspection', 'Quezon Avenue Corner Timog Avenue, South Triangle', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Repair Team', 38000.00, '2026-03-10 07:00:00', '2026-03-10 14:00:00', '2026-02-23 14:46:29'),
(141, 'Bridge Structural Inspection', 'Tandang Sora Bridge, Tandang Sora Avenue, Quezon City', 'Structural', 'Critical', 'Scheduled', 5, 'Structural Inspection Team', 55000.00, '2026-03-11 08:00:00', '2026-03-11 17:00:00', '2026-02-23 14:46:29'),
(142, 'Bridge Expansion Joint Repair', 'Tandang Sora Bridge Approach, Tandang Sora Avenue', 'Structural', 'High', 'Scheduled', 5, 'Civil Works Team B', 72000.00, '2026-03-11 08:00:00', '2026-03-11 17:00:00', '2026-02-23 14:46:29'),
(143, 'Drainage Canal Flushing', 'Tandang Sora Avenue Canal, Tandang Sora, Quezon City', 'Drainage', 'High', 'Scheduled', 5, 'Drainage Maintenance Team', 27000.00, '2026-03-11 07:00:00', '2026-03-11 15:00:00', '2026-02-23 14:46:29'),
(144, 'Pothole Patching', 'Mindanao Avenue, Project 8, Quezon City', 'Roads', 'Critical', 'Scheduled', 5, 'Road Repair Team Alpha', 85000.00, '2026-03-16 06:00:00', '2026-03-16 17:00:00', '2026-02-23 14:46:29'),
(145, 'Road Base Repair', 'Mindanao Avenue Service Road, Project 8', 'Roads', 'High', 'Scheduled', 5, 'Road Repair Team Beta', 110000.00, '2026-03-16 06:00:00', '2026-03-16 18:00:00', '2026-02-23 14:46:29'),
(146, 'Water Supply Line Pressure Test', 'Mindanao Avenue Underground Line, Project 8', 'Plumbing', 'High', 'Scheduled', 5, 'Water Supply Team', 41000.00, '2026-03-16 08:00:00', '2026-03-16 16:00:00', '2026-02-23 14:46:29'),
(147, 'Catch Basin Cleaning', 'Mindanao Avenue Corner Congressional Avenue, QC', 'Drainage', 'Medium', 'Scheduled', 5, 'Sanitation & Drainage Team', 14000.00, '2026-03-16 07:00:00', '2026-03-16 13:00:00', '2026-02-23 14:46:29'),
(148, 'LED Streetlight Upgrade', 'Aurora Boulevard, Cubao, Quezon City', 'Electrical', 'Medium', 'Scheduled', 5, 'Electrical Upgrade Team', 95000.00, '2026-03-17 17:00:00', '2026-03-17 23:00:00', '2026-02-23 14:46:29'),
(149, 'Street Light Pole Repair', 'Aurora Boulevard Corner P. Tuazon, Cubao, Quezon City', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Repair Team', 37000.00, '2026-03-17 17:00:00', '2026-03-17 22:00:00', '2026-02-23 14:46:29'),
(150, 'Traffic Light Controller Replacement', 'Aurora Boulevard–EDSA Intersection, Cubao', 'Electrical', 'Critical', 'Scheduled', 5, 'Traffic Signal Team', 178000.00, '2026-03-17 06:00:00', '2026-03-17 14:00:00', '2026-02-23 14:46:29'),
(151, 'Road Repainting & Lane Marking', 'Batasan Road, Batasan Hills, Quezon City', 'Roads', 'Medium', 'Scheduled', 5, 'Road Marking Team', 42000.00, '2026-03-18 05:00:00', '2026-03-18 11:00:00', '2026-02-23 14:46:29'),
(152, 'Pothole Patching', 'Batasan Road near Batasan Complex, Batasan Hills', 'Roads', 'Critical', 'Scheduled', 5, 'Road Repair Team Alpha', 76000.00, '2026-03-18 06:00:00', '2026-03-18 15:00:00', '2026-02-23 14:46:29'),
(153, 'Sidewalk Repair', 'Batasan Road Service Lane, Batasan Hills, Quezon City', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team A', 31000.00, '2026-03-18 07:00:00', '2026-03-18 16:00:00', '2026-02-23 14:46:29'),
(154, 'Drainage Pipe Replacement', 'Batasan Road Drainage Line, Batasan Hills', 'Drainage', 'High', 'Scheduled', 5, 'Pipe Repair Team', 68000.00, '2026-03-18 08:00:00', '2026-03-18 17:00:00', '2026-02-23 14:46:29'),
(155, 'Guard Rail Replacement', 'Batasan Road Elevated Section, Batasan Hills', 'Structural', 'High', 'Scheduled', 5, 'Civil Works Team B', 62000.00, '2026-03-18 08:00:00', '2026-03-18 17:00:00', '2026-02-23 14:46:29'),
(156, 'Water Pump Station Maintenance', 'Novaliches Water Treatment Plant, Novaliches Proper', 'Mechanical', 'Critical', 'Scheduled', 5, 'Pump Maintenance Team', 150000.00, '2026-03-23 08:00:00', '2026-03-23 17:00:00', '2026-02-23 14:46:29'),
(157, 'Water Main Pipe Inspection', 'Quirino Highway Underground Line, Novaliches', 'Plumbing', 'High', 'Scheduled', 5, 'Water Supply Team', 43000.00, '2026-03-23 07:00:00', '2026-03-23 16:00:00', '2026-02-23 14:46:29'),
(158, 'Drainage Canal Desilting', 'Quirino Highway Canal, Novaliches Proper, Quezon City', 'Drainage', 'High', 'Scheduled', 5, 'Drainage Maintenance Team', 98000.00, '2026-03-23 06:00:00', '2026-03-23 17:00:00', '2026-02-23 14:46:29'),
(159, 'Street Light Replacement', 'Katipunan Avenue, Blue Ridge, Quezon City', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Team B', 48000.00, '2026-03-24 17:00:00', '2026-03-24 23:00:00', '2026-02-23 14:46:29'),
(160, 'Traffic Signal Maintenance', 'Katipunan Avenue–C.P. Garcia Intersection, Diliman', 'Electrical', 'Critical', 'Scheduled', 5, 'Traffic Signal Team', 65000.00, '2026-03-24 06:00:00', '2026-03-24 14:00:00', '2026-02-23 14:46:29'),
(161, 'Road Sign Replacement', 'Katipunan Avenue, Loyola Heights, Quezon City', 'Roads', 'Medium', 'Scheduled', 5, 'Road Marking Team', 23000.00, '2026-03-24 07:00:00', '2026-03-24 15:00:00', '2026-02-23 14:46:29'),
(162, 'Pedestrian Lane Repainting', 'Katipunan Avenue Corner Ateneo Gate, Loyola Heights', 'Roads', 'Medium', 'Scheduled', 5, 'Road Marking Team', 18500.00, '2026-03-24 15:00:00', '2026-03-24 22:00:00', '2026-02-23 14:46:29'),
(163, 'Bridge Structural Inspection', 'EDSA Overpass, Cubao, Quezon City', 'Structural', 'Critical', 'Scheduled', 5, 'Structural Inspection Team', 55000.00, '2026-03-25 08:00:00', '2026-03-25 17:00:00', '2026-02-23 14:46:29'),
(164, 'Bridge Expansion Joint Repair', 'EDSA–Aurora Boulevard Flyover, Cubao', 'Structural', 'High', 'Scheduled', 5, 'Civil Works Team C', 88000.00, '2026-03-25 08:00:00', '2026-03-25 17:00:00', '2026-02-23 14:46:29'),
(165, 'Guard Rail Replacement', 'EDSA Northbound Shoulder, Cubao, Quezon City', 'Structural', 'High', 'Scheduled', 5, 'Civil Works Team B', 62000.00, '2026-03-25 07:00:00', '2026-03-25 16:00:00', '2026-02-23 14:46:29'),
(166, 'Flood Control Gate Inspection', 'La Mesa Eco Park Entry Road, Novaliches, Quezon City', 'Drainage', 'High', 'Scheduled', 5, 'Flood Control Team', 35000.00, '2026-03-26 08:00:00', '2026-03-26 17:00:00', '2026-02-23 14:46:29'),
(167, 'Drainage Canal Flushing', 'Litex Road Canal, Commonwealth, Quezon City', 'Drainage', 'Medium', 'Scheduled', 5, 'Drainage Maintenance Team', 27000.00, '2026-03-26 07:00:00', '2026-03-26 15:00:00', '2026-02-23 14:46:29'),
(168, 'Catch Basin Cleaning', 'Batasan-San Mateo Road, Quezon City', 'Drainage', 'High', 'Scheduled', 5, 'Sanitation & Drainage Team', 16000.00, '2026-03-26 06:00:00', '2026-03-26 14:00:00', '2026-02-23 14:46:29'),
(169, 'Drainage Pipe Replacement', 'Batasan Hills Road Side Drain, Batasan Hills', 'Drainage', 'High', 'Scheduled', 5, 'Pipe Repair Team', 78000.00, '2026-03-26 08:00:00', '2026-03-26 17:00:00', '2026-02-23 14:46:29'),
(170, 'Road Crack Sealing', 'Tomas Morato Avenue, Sacred Heart, Quezon City', 'Roads', 'High', 'Scheduled', 5, 'Road Repair Team Beta', 67000.00, '2026-03-27 06:00:00', '2026-03-27 15:00:00', '2026-02-23 14:46:29'),
(171, 'Road Repainting & Lane Marking', 'Tomas Morato Avenue Corner Scout Borromeo', 'Roads', 'Medium', 'Scheduled', 5, 'Road Marking Team', 38000.00, '2026-03-27 15:00:00', '2026-03-27 22:00:00', '2026-02-23 14:46:29'),
(172, 'Sidewalk Repair', 'Tomas Morato Avenue, Laging Handa, Quezon City', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team A', 29000.00, '2026-03-27 07:00:00', '2026-03-27 16:00:00', '2026-02-23 14:46:29'),
(173, 'Street Light Replacement', 'Sgt. Esguerra Avenue, South Triangle, Quezon City', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Team A', 48000.00, '2026-04-01 17:00:00', '2026-04-01 23:00:00', '2026-02-23 14:46:29'),
(174, 'Catch Basin Cleaning', 'Sgt. Esguerra Avenue Canal, West Triangle, Quezon City', 'Drainage', 'Medium', 'Scheduled', 5, 'Sanitation & Drainage Team', 14000.00, '2026-04-01 07:00:00', '2026-04-01 13:00:00', '2026-02-23 14:46:29'),
(175, 'Pothole Patching', 'Sgt. Esguerra Avenue, West Triangle, Quezon City', 'Roads', 'High', 'Scheduled', 5, 'Road Repair Team Alpha', 54000.00, '2026-04-01 06:00:00', '2026-04-01 15:00:00', '2026-02-23 14:46:29'),
(176, 'Pothole Patching', 'Quirino Highway, Novaliches, Quezon City', 'Roads', 'Critical', 'Scheduled', 5, 'Road Repair Team Alpha', 92000.00, '2026-04-06 06:00:00', '2026-04-06 17:00:00', '2026-02-23 14:46:29'),
(177, 'Road Base Repair', 'Quirino Highway Service Road, San Bartolome', 'Roads', 'High', 'Scheduled', 5, 'Road Repair Team Beta', 115000.00, '2026-04-06 06:00:00', '2026-04-06 18:00:00', '2026-02-23 14:46:29'),
(178, 'Drainage Canal Desilting', 'Quirino Highway Canal, Novaliches, Quezon City', 'Drainage', 'High', 'Scheduled', 5, 'Drainage Maintenance Team', 110000.00, '2026-04-06 06:00:00', '2026-04-06 17:00:00', '2026-02-23 14:46:29'),
(179, 'Street Light Wiring Inspection', 'Quirino Highway, San Bartolome, Quezon City', 'Electrical', 'Medium', 'Scheduled', 5, 'Electrical Repair Team', 27000.00, '2026-04-06 17:00:00', '2026-04-06 22:00:00', '2026-02-23 14:46:29'),
(180, 'Water Pump Station Maintenance', 'Congressional Avenue Water Station, Quezon City', 'Mechanical', 'Critical', 'Scheduled', 5, 'Pump Maintenance Team', 150000.00, '2026-04-07 08:00:00', '2026-04-07 17:00:00', '2026-02-23 14:46:29'),
(181, 'Water Pipe Joint Sealing', 'Congressional Avenue Underground Line, Quezon City', 'Plumbing', 'High', 'Scheduled', 5, 'Pipe Repair Team', 55000.00, '2026-04-07 08:00:00', '2026-04-07 17:00:00', '2026-02-23 14:46:29'),
(182, 'Water Meter Box Replacement', 'Congressional Avenue Service Road, Quezon City', 'Plumbing', 'Medium', 'Scheduled', 5, 'Water Supply Team', 29000.00, '2026-04-07 09:00:00', '2026-04-07 16:00:00', '2026-02-23 14:46:29'),
(183, 'Road Crack Sealing', 'Kalayaan Avenue, Pinyahan, Quezon City', 'Roads', 'High', 'Scheduled', 5, 'Road Repair Team Beta', 67000.00, '2026-04-08 06:00:00', '2026-04-08 15:00:00', '2026-02-23 14:46:29'),
(184, 'Sidewalk Repair', 'Kalayaan Avenue Service Lane, Pinyahan', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team C', 28000.00, '2026-04-08 07:00:00', '2026-04-08 16:00:00', '2026-02-23 14:46:29'),
(185, 'Speed Hump Installation', 'Kalayaan Avenue near School Zone, Pinyahan', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team A', 41000.00, '2026-04-08 08:00:00', '2026-04-08 17:00:00', '2026-02-23 14:46:29'),
(186, 'Pedestrian Lane Repainting', 'Kalayaan Avenue Corner Mayaman Street, Pinyahan', 'Roads', 'Low', 'Scheduled', 5, 'Road Marking Team', 16000.00, '2026-04-08 15:00:00', '2026-04-08 21:00:00', '2026-02-23 14:46:29'),
(187, 'Street Light Replacement', 'Mindanao Avenue, Project 8, Quezon City', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Team B', 48000.00, '2026-04-10 17:00:00', '2026-04-10 23:00:00', '2026-02-23 14:46:29'),
(188, 'Traffic Signal Maintenance', 'Mindanao Avenue–EDSA Intersection, Project 8', 'Electrical', 'Critical', 'Scheduled', 5, 'Traffic Signal Team', 65000.00, '2026-04-10 06:00:00', '2026-04-10 14:00:00', '2026-02-23 14:46:29'),
(189, 'LED Streetlight Upgrade', 'Mindanao Avenue Southbound, Project 7, Quezon City', 'Electrical', 'Medium', 'Scheduled', 5, 'Electrical Upgrade Team', 95000.00, '2026-04-10 17:00:00', '2026-04-10 23:00:00', '2026-02-23 14:46:29'),
(190, 'Pothole Patching', 'A. Bonifacio Avenue, Balintawak, Quezon City', 'Roads', 'Critical', 'Scheduled', 5, 'Road Repair Team Alpha', 88000.00, '2026-04-13 06:00:00', '2026-04-13 15:00:00', '2026-02-23 14:50:39'),
(191, 'Drainage Canal Desilting', 'A. Bonifacio Avenue Canal, Balintawak, Quezon City', 'Drainage', 'High', 'Scheduled', 5, 'Drainage Maintenance Team', 105000.00, '2026-04-13 06:00:00', '2026-04-13 17:00:00', '2026-02-23 14:50:39'),
(192, 'Street Light Replacement', 'A. Bonifacio Avenue, Balintawak, Quezon City', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Team B', 52000.00, '2026-04-13 17:00:00', '2026-04-13 23:00:00', '2026-02-23 14:50:39'),
(193, 'Bridge Structural Inspection', 'Fairview Bridge, Regalado Avenue, Fairview, Quezon City', 'Structural', 'Critical', 'Scheduled', 5, 'Structural Inspection Team', 55000.00, '2026-04-14 08:00:00', '2026-04-14 17:00:00', '2026-02-23 14:50:39'),
(194, 'Guard Rail Replacement', 'Regalado Avenue Elevated Section, Fairview, Quezon City', 'Structural', 'High', 'Scheduled', 5, 'Civil Works Team B', 62000.00, '2026-04-14 08:00:00', '2026-04-14 17:00:00', '2026-02-23 14:50:39'),
(195, 'Water Pump Station Maintenance', 'Fairview Water Station, Regalado Avenue, Fairview', 'Mechanical', 'Critical', 'Scheduled', 5, 'Pump Maintenance Team', 150000.00, '2026-04-15 08:00:00', '2026-04-15 17:00:00', '2026-02-23 14:50:39'),
(196, 'Water Main Pipe Inspection', 'Regalado Avenue Underground Line, Fairview', 'Plumbing', 'High', 'Scheduled', 5, 'Water Supply Team', 43000.00, '2026-04-15 07:00:00', '2026-04-15 16:00:00', '2026-02-23 14:50:39'),
(197, 'Water Meter Box Replacement', 'Regalado Avenue Service Road, Fairview, Quezon City', 'Plumbing', 'Medium', 'Scheduled', 5, 'Water Supply Team', 29000.00, '2026-04-15 09:00:00', '2026-04-15 16:00:00', '2026-02-23 14:50:39'),
(198, 'Traffic Light Controller Replacement', 'Quirino Highway–Regalado Avenue Intersection, Novaliches', 'Electrical', 'Critical', 'Scheduled', 5, 'Traffic Signal Team', 178000.00, '2026-04-16 06:00:00', '2026-04-16 14:00:00', '2026-02-23 14:50:39'),
(199, 'LED Streetlight Upgrade', 'Quirino Highway, Novaliches Proper, Quezon City', 'Electrical', 'Medium', 'Scheduled', 5, 'Electrical Upgrade Team', 95000.00, '2026-04-16 17:00:00', '2026-04-16 23:00:00', '2026-02-23 14:50:39'),
(200, 'Flood Control Gate Inspection', 'Commonwealth Avenue Underpass, Batasan Hills', 'Drainage', 'Critical', 'Scheduled', 5, 'Flood Control Team', 35000.00, '2026-04-17 08:00:00', '2026-04-17 17:00:00', '2026-02-23 14:50:39'),
(201, 'Catch Basin Cleaning', 'Commonwealth Avenue Corner Batasan Road, Quezon City', 'Drainage', 'High', 'Scheduled', 5, 'Sanitation & Drainage Team', 18000.00, '2026-04-17 06:00:00', '2026-04-17 14:00:00', '2026-02-23 14:50:39'),
(202, 'Drainage Canal Flushing', 'Commonwealth Avenue Side Canal, Batasan Hills', 'Drainage', 'High', 'Scheduled', 5, 'Drainage Maintenance Team', 27000.00, '2026-04-17 07:00:00', '2026-04-17 15:00:00', '2026-02-23 14:50:39'),
(203, 'Road Base Repair', 'Tandang Sora Avenue, Tandang Sora, Quezon City', 'Roads', 'High', 'Scheduled', 5, 'Road Repair Team Beta', 110000.00, '2026-04-20 06:00:00', '2026-04-20 17:00:00', '2026-02-23 14:50:39'),
(204, 'Sidewalk Repair', 'Tandang Sora Avenue Service Lane, Quezon City', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team A', 31000.00, '2026-04-20 07:00:00', '2026-04-20 16:00:00', '2026-02-23 14:50:39'),
(205, 'Road Repainting & Lane Marking', 'Visayas Avenue, Project 8, Quezon City', 'Roads', 'Medium', 'Scheduled', 5, 'Road Marking Team', 42000.00, '2026-04-21 05:00:00', '2026-04-21 11:00:00', '2026-02-23 14:50:39'),
(206, 'Pedestrian Lane Repainting', 'Visayas Avenue Corner Mindanao Avenue, Project 8', 'Roads', 'Low', 'Scheduled', 5, 'Road Marking Team', 18500.00, '2026-04-21 11:00:00', '2026-04-21 18:00:00', '2026-02-23 14:50:39'),
(207, 'Speed Hump Installation', 'Visayas Avenue near School Zone, Project 8', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team C', 41000.00, '2026-04-21 07:00:00', '2026-04-21 17:00:00', '2026-02-23 14:50:39'),
(208, 'Drainage Pipe Replacement', 'Katipunan Avenue Drainage Line, Blue Ridge, Quezon City', 'Drainage', 'High', 'Scheduled', 5, 'Pipe Repair Team', 78000.00, '2026-04-22 07:00:00', '2026-04-22 17:00:00', '2026-02-23 14:50:39'),
(209, 'Catch Basin Cleaning', 'Katipunan Avenue Corner Ateneo Gate, Loyola Heights', 'Drainage', 'Medium', 'Scheduled', 5, 'Sanitation & Drainage Team', 14000.00, '2026-04-22 07:00:00', '2026-04-22 13:00:00', '2026-02-23 14:50:39'),
(210, 'Street Light Pole Replacement', 'Aurora Boulevard Extension, Cubao, Quezon City', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Team A', 85000.00, '2026-04-23 17:00:00', '2026-04-23 23:00:00', '2026-02-23 14:50:39'),
(211, 'Street Light Wiring Inspection', 'Aurora Boulevard Corner P. Tuazon, Cubao', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Repair Team', 38000.00, '2026-04-23 17:00:00', '2026-04-23 22:00:00', '2026-02-23 14:50:39'),
(212, 'Traffic Signal Maintenance', 'Aurora Boulevard–N. Domingo Intersection, Cubao', 'Electrical', 'Critical', 'Scheduled', 5, 'Traffic Signal Team', 65000.00, '2026-04-23 06:00:00', '2026-04-23 14:00:00', '2026-02-23 14:50:39'),
(213, 'Pothole Patching', 'Sgt. Esguerra Avenue, South Triangle, Quezon City', 'Roads', 'High', 'Scheduled', 5, 'Road Repair Team Alpha', 76000.00, '2026-04-24 06:00:00', '2026-04-24 15:00:00', '2026-02-23 14:50:39'),
(214, 'Road Sign Replacement', 'Sgt. Esguerra Avenue Corner Mother Ignacia, South Triangle', 'Roads', 'Medium', 'Scheduled', 5, 'Road Marking Team', 23000.00, '2026-04-24 07:00:00', '2026-04-24 14:00:00', '2026-02-23 14:50:39'),
(215, 'Water Main Pipe Inspection', 'Batasan Road Underground Line, Batasan Hills', 'Plumbing', 'High', 'Scheduled', 5, 'Water Supply Team', 43000.00, '2026-04-27 07:00:00', '2026-04-27 16:00:00', '2026-02-23 14:50:39'),
(216, 'Water Pipe Joint Sealing', 'Batasan Road Junction, Batasan Hills, Quezon City', 'Plumbing', 'Critical', 'Scheduled', 5, 'Pipe Repair Team', 55000.00, '2026-04-27 08:00:00', '2026-04-27 17:00:00', '2026-02-23 14:50:39'),
(217, 'Drainage Canal Desilting', 'Batasan Hills Road Side Canal, Batasan Hills', 'Drainage', 'High', 'Scheduled', 5, 'Drainage Maintenance Team', 98000.00, '2026-04-27 06:00:00', '2026-04-27 17:00:00', '2026-02-23 14:50:39'),
(218, 'Bridge Structural Inspection', 'Novaliches Bridge, Quirino Highway, Novaliches', 'Structural', 'Critical', 'Scheduled', 5, 'Structural Inspection Team', 55000.00, '2026-04-28 08:00:00', '2026-04-28 17:00:00', '2026-02-23 14:50:39'),
(219, 'Bridge Expansion Joint Repair', 'Novaliches Bridge Approach, Quirino Highway', 'Structural', 'High', 'Scheduled', 5, 'Civil Works Team B', 72000.00, '2026-04-28 08:00:00', '2026-04-28 17:00:00', '2026-02-23 14:50:39'),
(220, 'Road Crack Sealing', 'East Avenue, Diliman, Quezon City', 'Roads', 'High', 'Scheduled', 5, 'Road Repair Team Beta', 67000.00, '2026-04-29 06:00:00', '2026-04-29 15:00:00', '2026-02-23 14:50:39'),
(221, 'Sidewalk Repair', 'East Avenue Service Lane, Diliman, Quezon City', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team A', 31000.00, '2026-04-29 07:00:00', '2026-04-29 16:00:00', '2026-02-23 14:50:39'),
(222, 'Pedestrian Lane Repainting', 'East Avenue Corner Novenal Street, Diliman', 'Roads', 'Low', 'Scheduled', 5, 'Road Marking Team', 16000.00, '2026-04-29 15:00:00', '2026-04-29 21:00:00', '2026-02-23 14:50:39'),
(223, 'Flood Control Gate Inspection', 'Tandang Sora Creek Gate, Tandang Sora, Quezon City', 'Drainage', 'High', 'Scheduled', 5, 'Flood Control Team', 35000.00, '2026-04-30 08:00:00', '2026-04-30 17:00:00', '2026-02-23 14:50:39'),
(224, 'Drainage Canal Flushing', 'Tandang Sora Road Canal, Tandang Sora, Quezon City', 'Drainage', 'Medium', 'Scheduled', 5, 'Drainage Maintenance Team', 27000.00, '2026-04-30 07:00:00', '2026-04-30 15:00:00', '2026-02-23 14:50:39'),
(225, 'Pothole Patching', 'Quezon Avenue, South Triangle, Quezon City', 'Roads', 'Critical', 'Scheduled', 5, 'Road Repair Team Alpha', 88000.00, '2026-05-04 06:00:00', '2026-05-04 17:00:00', '2026-02-23 14:50:39'),
(226, 'Catch Basin Cleaning', 'Quezon Avenue Side Canal, South Triangle, Quezon City', 'Drainage', 'High', 'Scheduled', 5, 'Sanitation & Drainage Team', 16000.00, '2026-05-04 06:00:00', '2026-05-04 13:00:00', '2026-02-23 14:50:39'),
(227, 'Road Sign Replacement', 'Quezon Avenue Corner Timog Avenue, South Triangle', 'Roads', 'Medium', 'Scheduled', 5, 'Road Marking Team', 23000.00, '2026-05-04 07:00:00', '2026-05-04 14:00:00', '2026-02-23 14:50:39'),
(228, 'LED Streetlight Upgrade', 'Elliptical Road, Diliman, Quezon City', 'Electrical', 'Medium', 'Scheduled', 5, 'Electrical Upgrade Team', 95000.00, '2026-05-05 17:00:00', '2026-05-05 23:00:00', '2026-02-23 14:50:39'),
(229, 'Street Light Wiring Inspection', 'Elliptical Road Corner Commonwealth Avenue, Diliman', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Repair Team', 38000.00, '2026-05-05 17:00:00', '2026-05-05 22:00:00', '2026-02-23 14:50:39'),
(230, 'Water Pump Station Maintenance', 'Tomas Morato Water Station, Sacred Heart, Quezon City', 'Mechanical', 'Critical', 'Scheduled', 5, 'Pump Maintenance Team', 150000.00, '2026-05-06 08:00:00', '2026-05-06 17:00:00', '2026-02-23 14:50:39'),
(231, 'Drainage Pipe Replacement', 'Tomas Morato Avenue Drainage Line, Sacred Heart', 'Drainage', 'High', 'Scheduled', 5, 'Pipe Repair Team', 78000.00, '2026-05-06 07:00:00', '2026-05-06 17:00:00', '2026-02-23 14:50:39'),
(232, 'Water Pipe Joint Sealing', 'Tomas Morato Avenue Junction, Laging Handa', 'Plumbing', 'High', 'Scheduled', 5, 'Pipe Repair Team', 55000.00, '2026-05-06 08:00:00', '2026-05-06 17:00:00', '2026-02-23 14:50:39'),
(233, 'Bridge Structural Inspection', 'Mindanao Avenue Bridge, Project 8, Quezon City', 'Structural', 'Critical', 'Scheduled', 5, 'Structural Inspection Team', 55000.00, '2026-05-07 08:00:00', '2026-05-07 17:00:00', '2026-02-23 14:50:39'),
(234, 'Guard Rail Replacement', 'Mindanao Avenue Overpass, Project 8, Quezon City', 'Structural', 'High', 'Scheduled', 5, 'Civil Works Team B', 62000.00, '2026-05-07 08:00:00', '2026-05-07 17:00:00', '2026-02-23 14:50:39'),
(235, 'Road Base Repair', 'Batasan-San Mateo Road, Batasan Hills, Quezon City', 'Roads', 'High', 'Scheduled', 5, 'Road Repair Team Beta', 110000.00, '2026-05-08 06:00:00', '2026-05-08 17:00:00', '2026-02-23 14:50:39'),
(236, 'Road Repainting & Lane Marking', 'Batasan-San Mateo Road, Quezon City', 'Roads', 'Medium', 'Scheduled', 5, 'Road Marking Team', 42000.00, '2026-05-08 15:00:00', '2026-05-08 22:00:00', '2026-02-23 14:50:39'),
(237, 'Sidewalk Repair', 'Batasan-San Mateo Road Service Lane, Batasan Hills', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team C', 31000.00, '2026-05-08 07:00:00', '2026-05-08 16:00:00', '2026-02-23 14:50:39'),
(238, 'Traffic Signal Maintenance', 'Kalayaan Avenue–Edsa Intersection, Pinyahan', 'Electrical', 'Critical', 'Scheduled', 5, 'Traffic Signal Team', 65000.00, '2026-05-11 06:00:00', '2026-05-11 14:00:00', '2026-02-23 14:50:39'),
(239, 'Street Light Pole Replacement', 'Kalayaan Avenue, Pinyahan, Quezon City', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Team A', 85000.00, '2026-05-11 17:00:00', '2026-05-11 23:00:00', '2026-02-23 14:50:39'),
(240, 'Drainage Canal Desilting', 'Commonwealth Avenue Extension, North Fairview, Quezon City', 'Drainage', 'Critical', 'Scheduled', 5, 'Drainage Maintenance Team', 120000.00, '2026-05-12 06:00:00', '2026-05-12 17:00:00', '2026-02-23 14:50:39'),
(241, 'Flood Control Gate Inspection', 'Commonwealth Avenue Floodway, North Fairview', 'Drainage', 'High', 'Scheduled', 5, 'Flood Control Team', 35000.00, '2026-05-12 08:00:00', '2026-05-12 17:00:00', '2026-02-23 14:50:39'),
(242, 'Catch Basin Cleaning', 'Commonwealth Avenue Corner Batasan Road Extension, QC', 'Drainage', 'Medium', 'Scheduled', 5, 'Sanitation & Drainage Team', 16000.00, '2026-05-12 07:00:00', '2026-05-12 14:00:00', '2026-02-23 14:50:39'),
(243, 'Pothole Patching', 'Quirino Highway Extension, North Fairview, Quezon City', 'Roads', 'Critical', 'Scheduled', 5, 'Road Repair Team Alpha', 92000.00, '2026-05-13 06:00:00', '2026-05-13 17:00:00', '2026-02-23 14:50:39'),
(244, 'Speed Hump Installation', 'Quirino Highway near School Zone, Novaliches', 'Structural', 'Medium', 'Scheduled', 5, 'Civil Works Team A', 41000.00, '2026-05-13 08:00:00', '2026-05-13 17:00:00', '2026-02-23 14:50:39'),
(245, 'Water Main Pipe Inspection', 'C.P. Garcia Avenue Underground Line, Diliman', 'Plumbing', 'High', 'Scheduled', 5, 'Water Supply Team', 43000.00, '2026-05-14 07:00:00', '2026-05-14 16:00:00', '2026-02-23 14:50:39'),
(246, 'Water Meter Box Replacement', 'C.P. Garcia Avenue Service Road, Diliman, Quezon City', 'Plumbing', 'Medium', 'Scheduled', 5, 'Water Supply Team', 29000.00, '2026-05-14 09:00:00', '2026-05-14 16:00:00', '2026-02-23 14:50:39'),
(247, 'Drainage Canal Flushing', 'C.P. Garcia Avenue Canal, Diliman, Quezon City', 'Drainage', 'Medium', 'Scheduled', 5, 'Drainage Maintenance Team', 27000.00, '2026-05-14 07:00:00', '2026-05-14 15:00:00', '2026-02-23 14:50:39'),
(248, 'Bridge Expansion Joint Repair', 'Katipunan Avenue–C5 Road Flyover, Quezon City', 'Structural', 'High', 'Scheduled', 5, 'Civil Works Team B', 88000.00, '2026-05-15 08:00:00', '2026-05-15 17:00:00', '2026-02-23 14:50:39'),
(249, 'Pedestrian Lane Repainting', 'Katipunan Avenue Corner Miriam College Gate, QC', 'Roads', 'Low', 'Scheduled', 5, 'Road Marking Team', 16000.00, '2026-05-15 15:00:00', '2026-05-15 21:00:00', '2026-02-23 14:50:39');

-- --------------------------------------------------------
>>>>>>> Stashed changes

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
  `analyzed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `evidence_images`
--
ALTER TABLE `evidence_images`
  MODIFY `img_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=109;

--
-- AUTO_INCREMENT for table `login_logs`
--
ALTER TABLE `login_logs`
<<<<<<< Updated upstream
  MODIFY `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=120;
=======
  MODIFY `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=141;
>>>>>>> Stashed changes

--
-- AUTO_INCREMENT for table `maintenance_schedule`
--
ALTER TABLE `maintenance_schedule`
<<<<<<< Updated upstream
  MODIFY `sched_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;
=======
  MODIFY `sched_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=250;
>>>>>>> Stashed changes

--
-- AUTO_INCREMENT for table `materials_equipment_costs`
--
ALTER TABLE `materials_equipment_costs`
  MODIFY `cost_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=442;

--
-- AUTO_INCREMENT for table `pending_registrations`
--
ALTER TABLE `pending_registrations`
  MODIFY `penreg_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `repair_archive`
--
ALTER TABLE `repair_archive`
  MODIFY `arc_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `rep_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `requests`
--
ALTER TABLE `requests`
  MODIFY `req_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=111;

--
-- AUTO_INCREMENT for table `request_ai_analysis`
--
ALTER TABLE `request_ai_analysis`
  MODIFY `analysis_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `request_resolutions`
--
ALTER TABLE `request_resolutions`
  MODIFY `res_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

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
