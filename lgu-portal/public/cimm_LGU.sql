-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 16, 2026 at 01:51 AM
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
(1, 'Kents', 'Bartolome', 'uploads/profile/profile_1_1769940651.jpeg', 'bartolomeexequielkent@gmail.com', 'Manager', '$2y$10$SWqpSqIHVrgmoa/TLlfGae9y/ftzABYfan.YVOv5Pv0dz/o836znW', 0, 1, NULL, NULL, '2026-02-02 01:41:21', NULL, NULL, 0, NULL, '2026-02-02 08:41:28', NULL, NULL, 0, 0, NULL, NULL),
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
-- Dumping data for table `evidence_images`
--

INSERT INTO `evidence_images` (`img_id`, `req_id`, `img_path`, `uploaded_at`) VALUES
(59, 67, 'uploads/evidence/evidence_67_69799eeb82839.jpeg', '2026-01-28 05:30:19'),
(60, 68, 'uploads/evidence/evidence_68_69799f0b31531.jpeg', '2026-01-28 05:30:51'),
(61, 69, 'uploads/evidence/evidence_69_69799fbf56ba4.png', '2026-01-28 05:33:51'),
(62, 70, 'uploads/evidence/evidence_70_6979a0a7b6cd8.png', '2026-01-28 05:37:43'),
(63, 71, 'uploads/evidence/evidence_71_6979a0c1575a7.jpeg', '2026-01-28 05:38:09'),
(64, 72, 'uploads/evidence/evidence_72_6979a19f5912c.png', '2026-01-28 05:41:51'),
(65, 73, 'uploads/evidence/evidence_73_6979a1f8df748.jpeg', '2026-01-28 05:43:20'),
(66, 74, 'uploads/evidence/evidence_74_6979a2102e2a4.jpeg', '2026-01-28 05:43:44'),
(67, 75, 'uploads/evidence/evidence_75_6979a2ca99469.png', '2026-01-28 05:46:50'),
(68, 76, 'uploads/evidence/evidence_76_6979a36f82be0.png', '2026-01-28 05:49:35'),
(69, 77, 'uploads/evidence/evidence_77_6979a3b015c7a.png', '2026-01-28 05:50:40'),
(70, 78, 'uploads/evidence/evidence_78_6979a43ae8e41.png', '2026-01-28 05:52:58'),
(71, 79, 'uploads/evidence/evidence_79_6979a5a302c19.jpeg', '2026-01-28 05:58:59'),
(72, 80, 'uploads/evidence/evidence_80_6979a5eec26ec.jpeg', '2026-01-28 06:00:14'),
(73, 81, 'uploads/evidence/evidence_81_6979a6076468b.jpeg', '2026-01-28 06:00:39'),
(74, 82, 'uploads/evidence/evidence_82_6979a6d5476c3.jpeg', '2026-01-28 06:04:05'),
(75, 83, 'uploads/evidence/evidence_83_6979a75406fbb.jpeg', '2026-01-28 06:06:12'),
(76, 84, 'uploads/evidence/evidence_84_6979daf3d0ef5.jpeg', '2026-01-28 09:46:27'),
(77, 85, 'uploads/evidence/evidence_85_6979db74d0eb0.jpeg', '2026-01-28 09:48:36'),
(78, 86, 'uploads/evidence/evidence_86_6979db8c4f736.jpeg', '2026-01-28 09:49:00');

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
(30, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 11:24:53'),
(31, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 11:54:48'),
(32, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 13:15:53'),
(33, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 13:40:10'),
(34, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 0, 0, '2026-01-19 15:07:17'),
(35, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 1, 0, '2026-01-23 14:15:40'),
(36, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-23 14:15:44'),
(37, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-23 14:45:12'),
(38, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-23 15:18:12'),
(39, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-23 16:06:07'),
(40, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-23 16:39:26'),
(41, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-23 20:50:14'),
(42, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-23 22:12:24'),
(43, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-23 23:24:57'),
(44, 'bartolomeexequielkent@gmail.com', 0, 'OTP expired', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 1, 0, '2026-01-26 08:59:23'),
(45, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 1, 0, '2026-01-26 08:59:36'),
(46, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-26 09:39:48'),
(47, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-26 10:55:34'),
(48, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-26 11:11:06'),
(49, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-26 13:04:50'),
(50, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-26 13:20:22'),
(51, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-26 15:17:10'),
(52, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-26 15:25:12'),
(53, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-26 15:38:11'),
(54, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-26 16:39:16'),
(55, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 1, 0, '2026-01-27 19:10:19'),
(56, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 20:23:09'),
(57, 'bartolomstolome@gmail.com', 0, 'Incorrect password', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 20:27:20'),
(58, 'bartolomeexequielkent@gmail.com', 0, 'Incorrect password', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 20:27:27'),
(59, 'bartolomstolome@gmail.com', 0, 'Incorrect password', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 20:27:43'),
(60, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 20:27:50'),
(61, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 21:06:59'),
(62, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 21:07:02'),
(63, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 21:07:27'),
(64, 'bartolomstolome@gmail.com', 0, 'Email not verified', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 21:10:53'),
(65, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 21:11:03'),
(66, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 21:36:14'),
(67, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 21:47:20'),
(68, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 21:50:47'),
(69, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 21:55:24'),
(70, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 22:46:27'),
(71, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 23:25:50'),
(72, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 23:35:21'),
(73, 'bartolomstolome@gmail.com', 0, 'Email not verified', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 23:35:39'),
(74, 'bartolomstolome@gmail.com', 0, 'Incorrect password', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 23:36:45'),
(75, 'bartolomstolome@gmail.com', 0, 'Incorrect password', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 23:36:57'),
(76, 'bartolomstolome@gmail.com', 0, 'Incorrect password', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 23:37:35'),
(77, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-27 23:37:39'),
(78, 'bartolomstolome@gmail.com', 0, 'Incorrect password', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-28 00:18:23'),
(79, 'bartolomstolome@gmail.com', 0, 'Incorrect password', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-28 00:19:01'),
(80, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-28 00:21:04'),
(81, 'bartolomstolome@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 1, 0, '2026-01-28 00:21:56'),
(82, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-28 00:22:54'),
(83, 'bartolomstolome@gmail.com', 0, 'Incorrect password', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-28 00:23:13'),
(84, 'bartolomstolome@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-28 00:23:23'),
(85, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-28 00:23:53'),
(86, 'bartolomstolome@gmail.com', 0, 'Incorrect password', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-28 09:14:24'),
(87, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 1, 0, '2026-01-28 09:14:46'),
(88, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-28 09:27:59'),
(89, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-28 10:58:33'),
(90, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-28 13:23:52'),
(91, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-28 13:43:11'),
(92, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-28 14:00:25'),
(93, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-01-28 17:43:39'),
(94, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 1, 0, '2026-02-01 10:33:06'),
(95, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-02-01 10:37:52'),
(96, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-02-01 16:24:04'),
(97, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-02-01 16:53:54'),
(98, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 1, 0, '2026-02-02 08:41:21'),
(99, 'bartolomstolome@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 1, 0, '2026-02-02 08:42:22'),
(100, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-02-02 08:42:47'),
(101, 'bartolomstolome@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-02-02 08:45:32'),
(102, 'bartolomeexequielkent@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-02-02 08:56:08'),
(103, 'bartolomstolome@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-02-02 08:59:25'),
(104, 'jhovenjadelas@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 1, 0, '2026-02-02 09:06:14'),
(105, 'bartolomstolome@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-02-02 11:11:25'),
(106, 'villawarv@gmail.com', 0, 'Incorrect password', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-02-03 16:29:47'),
(107, 'jhovenjadelas@gmail.com', 0, 'Incorrect password', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-02-03 16:30:32'),
(108, 'villawarv@gmail.com', 0, 'Incorrect password', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 0, 0, '2026-02-03 16:30:51'),
(109, 'jhovenjadelas@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 1, 0, '2026-02-03 16:32:17'),
(110, 'villawarv@gmail.com', 1, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 1, 0, '2026-02-03 16:33:18');

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
(1, 'HVAC System Check', 'Building A', 'Electrical', 'High', 'Scheduled', 5, 'Team Alpha', 5000.00, '2026-01-20 09:00:00', '2026-01-20 17:00:00', '2026-01-19 03:54:43'),
(2, 'Elevator Maintenance', 'Building B', 'Mechanical', 'Critical', 'Scheduled', 5, 'Team Beta', 12000.00, '2026-01-20 10:00:00', '2026-01-20 15:00:00', '2026-01-19 03:54:43'),
(3, 'Fire Alarm Inspection', 'Building C', 'Safety', 'Medium', 'Scheduled', 5, 'Team Gamma', 3000.00, '2026-01-20 08:30:00', '2026-01-20 12:00:00', '2026-01-19 03:54:43'),
(4, 'Plumbing Check', 'Building D', 'Plumbing', 'Low', 'Scheduled', 5, 'Team Delta', 1500.00, '2026-01-20 13:00:00', '2026-01-20 16:00:00', '2026-01-19 03:54:43'),
(5, 'Roof Inspection', 'Building E', 'Structural', 'High', 'Scheduled', 5, 'Team Epsilon', 4000.00, '2026-01-20 09:30:00', '2026-01-20 14:00:00', '2026-01-19 03:54:43'),
(6, 'Aircon Filter Cleaning', 'City Hall – 2nd Floor', 'HVAC', 'Medium', 'Scheduled', 5, 'Maintenance Team A', 0.00, '2026-01-25 08:00:00', '2026-01-25 10:00:00', '2026-01-19 03:56:24'),
(7, 'Electrical Panel Inspection', 'City Hall – Electrical Room', 'Electrical', 'High', 'Scheduled', 5, 'Electrical Team', 0.00, '2026-01-25 09:30:00', '2026-01-25 12:00:00', '2026-01-19 03:56:24'),
(8, 'Plumbing Leak Repair', 'Public Market – Stall Area', 'Plumbing', 'Critical', 'In Progress', 5, 'Repair Team B', 0.00, '2026-01-25 11:00:00', '2026-01-25 15:00:00', '2026-01-19 03:56:24'),
(9, 'Fire Alarm System Testing', 'Municipal Library', 'Safety', 'Low', 'Completed', 5, 'Inspection Team', 0.00, '2026-01-25 13:00:00', '2026-01-25 16:00:00', '2026-01-19 03:56:24'),
(10, 'Generator Routine Check', 'City Hall – Basement', 'Mechanical', 'High', 'Scheduled', 5, 'Power Team', 0.00, '2026-01-25 14:30:00', '2026-01-25 18:00:00', '2026-01-19 03:56:24'),
(11, 'CCTV Camera Alignment', 'City Hall – Parking Area', 'Security', 'Medium', 'Scheduled', 5, 'Security Tech Team', 0.00, '2026-01-25 07:30:00', '2026-01-25 09:00:00', '2026-01-19 03:56:24'),
(12, 'Water Tank Cleaning', 'City Hall – Rooftop', 'Sanitation', 'High', 'Scheduled', 5, 'Sanitation Team', 0.00, '2026-01-25 15:00:00', '2026-01-25 18:30:00', '2026-01-19 03:56:24'),
(13, 'Network Cable Testing', 'City Hall – IT Office', 'IT', 'Medium', 'In Progress', 5, 'IT Support Team', 0.00, '2026-01-25 10:00:00', '2026-01-25 13:00:00', '2026-01-19 03:56:24'),
(14, 'Aircon Filter Cleaning', 'City Hall – 2nd Floor', 'HVAC', 'Medium', 'Scheduled', 5, 'Maintenance Team A', 0.00, '2026-01-19 08:00:00', '2026-01-19 10:00:00', '2026-01-19 05:17:35'),
(15, 'Electrical Panel Inspection', 'City Hall – Electrical Room', 'Electrical', 'High', 'In Progress', 5, 'Electrical Team', 0.00, '2026-01-19 09:00:00', '2026-01-19 12:00:00', '2026-01-19 05:17:35'),
(16, 'Plumbing Leak Repair', 'Public Market – Stall Area', 'Plumbing', 'Critical', 'Completed', 5, 'Repair Team B', 0.00, '2026-01-19 11:00:00', '2026-01-19 15:00:00', '2026-01-19 05:17:35'),
(17, 'Fire Alarm System Testing', 'Municipal Library', 'Safety', 'Low', 'Delayed', 5, 'Inspection Team', 0.00, '2026-01-20 13:00:00', '2026-01-20 16:00:00', '2026-01-19 05:17:35'),
(18, 'Generator Routine Check', 'City Hall – Basement', 'Mechanical', 'High', 'Scheduled', 5, 'Power Team', 0.00, '2026-01-20 14:30:00', '2026-01-20 18:00:00', '2026-01-19 05:17:35'),
(19, 'CCTV Camera Alignment', 'City Hall – Parking Area', 'Security', 'Medium', 'Scheduled', 5, 'Security Tech Team', 0.00, '2026-01-21 07:30:00', '2026-01-21 09:00:00', '2026-01-19 05:17:35'),
(20, 'Water Tank Cleaning', 'City Hall – Rooftop', 'Sanitation', 'High', 'In Progress', 5, 'Sanitation Team', 0.00, '2026-01-21 15:00:00', '2026-01-21 18:30:00', '2026-01-19 05:17:35'),
(21, 'Network Cable Testing', 'City Hall – IT Office', 'IT', 'Medium', 'Completed', 5, 'IT Support Team', 0.00, '2026-01-22 10:00:00', '2026-01-22 13:00:00', '2026-01-19 05:17:35'),
(22, 'HVAC Duct Cleaning', 'City Hall – 3rd Floor', 'HVAC', 'High', 'Scheduled', 5, 'Maintenance Team B', 0.00, '2026-01-22 08:00:00', '2026-01-22 11:00:00', '2026-01-19 05:17:35'),
(23, 'Elevator Safety Inspection', 'Building B', 'Mechanical', 'Critical', 'Delayed', 5, 'Team Beta', 0.00, '2026-01-23 09:00:00', '2026-01-23 12:30:00', '2026-01-19 05:17:35'),
(24, 'Roof Leak Repair', 'City Hall – Roof', 'Structural', 'High', 'In Progress', 5, 'Repair Team C', 0.00, '2026-01-23 13:00:00', '2026-01-23 17:00:00', '2026-01-19 05:17:35'),
(25, 'Fire Extinguisher Check', 'City Hall – All Floors', 'Safety', 'Medium', 'Completed', 5, 'Safety Team', 0.00, '2026-01-24 09:00:00', '2026-01-24 11:30:00', '2026-01-19 05:17:35'),
(26, 'Server Room Cooling Check', 'City Hall – IT Room', 'IT', 'High', 'Scheduled', 5, 'IT Support Team', 0.00, '2026-01-24 12:00:00', '2026-01-24 15:00:00', '2026-01-19 05:17:35'),
(27, 'Plumbing Pipe Replacement', 'Public Market – Restrooms', 'Plumbing', 'Critical', 'Scheduled', 5, 'Repair Team B', 0.00, '2026-01-25 08:30:00', '2026-01-25 12:00:00', '2026-01-19 05:17:35'),
(28, 'Security Alarm Testing', 'City Hall – Entrances', 'Security', 'Medium', 'In Progress', 5, 'Security Tech Team', 0.00, '2026-01-25 10:00:00', '2026-01-25 12:30:00', '2026-01-19 05:17:35'),
(29, 'Window Glass Replacement', 'City Hall – 1st Floor', 'Structural', 'Low', 'Completed', 5, 'Maintenance Team A', 0.00, '2026-01-26 09:00:00', '2026-01-26 11:00:00', '2026-01-19 05:17:35'),
(30, 'UPS Battery Replacement', 'City Hall – IT Room', 'Electrical', 'High', 'Delayed', 5, 'Electrical Team', 0.00, '2026-01-26 13:00:00', '2026-01-26 16:00:00', '2026-01-19 05:17:35'),
(31, 'Parking Lot Light Maintenance', 'City Hall – Parking', 'Electrical', 'Medium', 'Scheduled', 5, 'Electrical Team', 0.00, '2026-01-27 07:30:00', '2026-01-27 10:00:00', '2026-01-19 05:17:35'),
(32, 'Janitorial Supplies Restock', 'City Hall – Storage', 'Sanitation', 'Low', 'Completed', 5, 'Sanitation Team', 0.00, '2026-01-27 11:00:00', '2026-01-27 13:00:00', '2026-01-19 05:17:35'),
(33, 'IT Workstation Setup', 'City Hall – IT Office', 'IT', 'High', 'Scheduled', 5, 'IT Support Team', 0.00, '2026-01-28 08:00:00', '2026-01-28 12:00:00', '2026-01-19 05:17:35');

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
(150, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=67', 'Street Lights', 0, '2026-01-28 05:30:19'),
(151, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=67', 'Street Lights', 0, '2026-01-28 05:30:19'),
(152, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=67', 'Street Lights', 0, '2026-01-28 05:30:19'),
(153, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=67', 'Street Lights', 0, '2026-01-28 05:30:19'),
(154, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=67', 'Street Lights', 0, '2026-01-28 05:30:19'),
(155, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=68', 'Roads', 0, '2026-01-28 05:30:51'),
(156, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=68', 'Roads', 0, '2026-01-28 05:30:51'),
(157, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=68', 'Roads', 0, '2026-01-28 05:30:51'),
(158, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=68', 'Roads', 0, '2026-01-28 05:30:51'),
(159, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=68', 'Roads', 0, '2026-01-28 05:30:51'),
(160, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=69', 'Roads', 0, '2026-01-28 05:33:51'),
(161, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=69', 'Roads', 0, '2026-01-28 05:33:51'),
(162, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=69', 'Roads', 0, '2026-01-28 05:33:51'),
(163, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=69', 'Roads', 0, '2026-01-28 05:33:51'),
(164, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=69', 'Roads', 0, '2026-01-28 05:33:51'),
(165, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=70', 'Street Lights', 0, '2026-01-28 05:37:43'),
(166, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=70', 'Street Lights', 0, '2026-01-28 05:37:43'),
(167, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=70', 'Street Lights', 0, '2026-01-28 05:37:43'),
(168, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=70', 'Street Lights', 0, '2026-01-28 05:37:43'),
(169, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=70', 'Street Lights', 0, '2026-01-28 05:37:43'),
(170, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=71', 'Water Supply', 0, '2026-01-28 05:38:09'),
(171, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=71', 'Water Supply', 0, '2026-01-28 05:38:09'),
(172, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=71', 'Water Supply', 0, '2026-01-28 05:38:09'),
(173, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=71', 'Water Supply', 0, '2026-01-28 05:38:09'),
(174, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=71', 'Water Supply', 0, '2026-01-28 05:38:09'),
(175, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=72', 'Drainage', 0, '2026-01-28 05:41:51'),
(176, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=72', 'Drainage', 0, '2026-01-28 05:41:51'),
(177, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=72', 'Drainage', 0, '2026-01-28 05:41:51'),
(178, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=72', 'Drainage', 0, '2026-01-28 05:41:51'),
(179, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=72', 'Drainage', 0, '2026-01-28 05:41:51'),
(180, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=73', 'Street Lights', 0, '2026-01-28 05:43:20'),
(181, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=73', 'Street Lights', 0, '2026-01-28 05:43:20'),
(182, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=73', 'Street Lights', 0, '2026-01-28 05:43:20'),
(183, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=73', 'Street Lights', 0, '2026-01-28 05:43:20'),
(184, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=73', 'Street Lights', 0, '2026-01-28 05:43:20'),
(185, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=74', 'Street Lights', 0, '2026-01-28 05:43:44'),
(186, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=74', 'Street Lights', 0, '2026-01-28 05:43:44'),
(187, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=74', 'Street Lights', 0, '2026-01-28 05:43:44'),
(188, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=74', 'Street Lights', 0, '2026-01-28 05:43:44'),
(189, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=74', 'Street Lights', 0, '2026-01-28 05:43:44'),
(190, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=75', 'Street Lights', 0, '2026-01-28 05:46:50'),
(191, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=75', 'Street Lights', 0, '2026-01-28 05:46:50'),
(192, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=75', 'Street Lights', 0, '2026-01-28 05:46:50'),
(193, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=75', 'Street Lights', 0, '2026-01-28 05:46:50'),
(194, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=75', 'Street Lights', 0, '2026-01-28 05:46:50'),
(195, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=76', 'Roads', 0, '2026-01-28 05:49:35'),
(196, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=76', 'Roads', 0, '2026-01-28 05:49:35'),
(197, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=76', 'Roads', 0, '2026-01-28 05:49:35'),
(198, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=76', 'Roads', 0, '2026-01-28 05:49:35'),
(199, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=76', 'Roads', 0, '2026-01-28 05:49:35'),
(200, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=77', 'Roads', 0, '2026-01-28 05:50:40'),
(201, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=77', 'Roads', 0, '2026-01-28 05:50:40'),
(202, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=77', 'Roads', 0, '2026-01-28 05:50:40'),
(203, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=77', 'Roads', 0, '2026-01-28 05:50:40'),
(204, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=77', 'Roads', 0, '2026-01-28 05:50:40'),
(205, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=78', 'Street Lights', 0, '2026-01-28 05:52:58'),
(206, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=78', 'Street Lights', 0, '2026-01-28 05:52:58'),
(207, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=78', 'Street Lights', 0, '2026-01-28 05:52:58'),
(208, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=78', 'Street Lights', 0, '2026-01-28 05:52:58'),
(209, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=78', 'Street Lights', 0, '2026-01-28 05:52:58'),
(210, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=79', 'Street Lights', 0, '2026-01-28 05:58:59'),
(211, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=79', 'Street Lights', 0, '2026-01-28 05:58:59'),
(212, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=79', 'Street Lights', 0, '2026-01-28 05:58:59'),
(213, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=79', 'Street Lights', 0, '2026-01-28 05:58:59'),
(214, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=79', 'Street Lights', 0, '2026-01-28 05:58:59'),
(215, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=80', 'Street Lights', 0, '2026-01-28 06:00:14'),
(216, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=80', 'Street Lights', 0, '2026-01-28 06:00:14'),
(217, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=80', 'Street Lights', 0, '2026-01-28 06:00:14'),
(218, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=80', 'Street Lights', 0, '2026-01-28 06:00:14'),
(219, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=80', 'Street Lights', 0, '2026-01-28 06:00:14'),
(220, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=81', 'Roads', 0, '2026-01-28 06:00:39'),
(221, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=81', 'Roads', 0, '2026-01-28 06:00:39'),
(222, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=81', 'Roads', 0, '2026-01-28 06:00:39'),
(223, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=81', 'Roads', 0, '2026-01-28 06:00:39'),
(224, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=81', 'Roads', 0, '2026-01-28 06:00:39'),
(225, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=82', 'Roads', 0, '2026-01-28 06:04:05'),
(226, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=82', 'Roads', 0, '2026-01-28 06:04:05'),
(227, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=82', 'Roads', 0, '2026-01-28 06:04:05'),
(228, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=82', 'Roads', 0, '2026-01-28 06:04:05'),
(229, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=82', 'Roads', 0, '2026-01-28 06:04:05'),
(230, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=83', 'Roads', 0, '2026-01-28 06:06:12'),
(231, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=83', 'Roads', 0, '2026-01-28 06:06:12'),
(232, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=83', 'Roads', 0, '2026-01-28 06:06:12'),
(233, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=83', 'Roads', 0, '2026-01-28 06:06:12'),
(234, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=83', 'Roads', 0, '2026-01-28 06:06:12'),
(235, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=84', 'Street Lights', 0, '2026-01-28 09:46:27'),
(236, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=84', 'Street Lights', 0, '2026-01-28 09:46:27'),
(237, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=84', 'Street Lights', 0, '2026-01-28 09:46:27'),
(238, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=84', 'Street Lights', 0, '2026-01-28 09:46:27'),
(239, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=84', 'Street Lights', 0, '2026-01-28 09:46:27'),
(240, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=85', 'Street Lights', 0, '2026-01-28 09:48:36'),
(241, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=85', 'Street Lights', 0, '2026-01-28 09:48:36'),
(242, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=85', 'Street Lights', 0, '2026-01-28 09:48:36'),
(243, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=85', 'Street Lights', 0, '2026-01-28 09:48:36'),
(244, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=85', 'Street Lights', 0, '2026-01-28 09:48:36'),
(245, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=86', 'Street Lights', 0, '2026-01-28 09:49:00'),
(246, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=86', 'Street Lights', 0, '2026-01-28 09:49:00'),
(247, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=86', 'Street Lights', 0, '2026-01-28 09:49:00'),
(248, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=86', 'Street Lights', 0, '2026-01-28 09:49:00'),
(249, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=86', 'Street Lights', 0, '2026-01-28 09:49:00');

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
(67, 'Street Lights', '122121', '1212', '09201212121', 'Kent', 'Pending', '2026-01-28 05:30:19'),
(68, 'Roads', 'Kwww', '212', '09201920212', 'Kent', 'Pending', '2026-01-28 05:30:51'),
(69, 'Roads', 'SSS, Fernando Poe Jr. Avenue, Paraiso, San Francisco del Monte, 1st District, Quezon City, Eastern Manila District, Metro Manila, 1104, Philippines', '1212', '09212121212', '12122', 'Pending', '2026-01-28 05:33:51'),
(70, 'Street Lights', 'ss', '2121221', '09212121212', 'sss', 'Pending', '2026-01-28 05:37:43'),
(71, 'Water Supply', 'ssss', '1211221', '09209209121', '092121212121212122121122', 'Pending', '2026-01-28 05:38:09'),
(72, 'Drainage', 'sss', '122112', '09212122122', 'sss', 'Pending', '2026-01-28 05:41:51'),
(73, 'Street Lights', 'ss', 'sss', '09019222212', 'ss', 'Pending', '2026-01-28 05:43:20'),
(74, 'Street Lights', 'ss', '21212', '09091212122', 'sss', 'Pending', '2026-01-28 05:43:44'),
(75, 'Street Lights', '09212012921', '12121', '09221212121', '21212122', 'Pending', '2026-01-28 05:46:50'),
(76, 'Roads', 'SSS, Fernando Poe Jr. Avenue, Paraiso, San Francisco del Monte, 1st District, Quezon City, Eastern Manila District, Metro Manila, 1104, Philippines', '21121', '09090090990', 'sss', 'Pending', '2026-01-28 05:49:35'),
(77, 'Roads', 'ss', '2212', '09090092929', 'ss', 'Pending', '2026-01-28 05:50:40'),
(78, 'Street Lights', 'SSS, Fernando Poe Jr. Avenue, Paraiso, San Francisco del Monte, 1st District, Quezon City, Eastern Manila District, Metro Manila, 1104, Philippines', '321212', '09090900900', 'ss', 'Pending', '2026-01-28 05:52:58'),
(79, 'Street Lights', 'ssss', '21', '09212122121', '121212', 'Pending', '2026-01-28 05:58:59'),
(80, 'Street Lights', 'ss', '212', '09090909090', 'w121', 'Pending', '2026-01-28 06:00:14'),
(81, 'Roads', 'ss', '121212', '09090099009', 'ss', 'Pending', '2026-01-28 06:00:39'),
(82, 'Roads', '12121212', '89889', '09090909090', '212112', 'Pending', '2026-01-28 06:04:05'),
(83, 'Roads', 'SSS, Fernando Poe Jr. Avenue, Paraiso, San Francisco del Monte, 1st District, Quezon City, Eastern Manila District, Metro Manila, 1104, Philippines', '090090', '09090909090', 'ss', 'Pending', '2026-01-28 06:06:12'),
(84, 'Street Lights', 'SSS, Fernando Poe Jr. Avenue, Paraiso, San Francisco del Monte, 1st District, Quezon City, Eastern Manila District, Metro Manila, 1104, Philippines', '1212', '09019212121', '1212', 'Pending', '2026-01-28 09:46:27'),
(85, 'Street Lights', 'ss', '122112', '09212211212', 'ss', 'Pending', '2026-01-28 09:48:36'),
(86, 'Street Lights', '2121212', '121212', '09212121212', '1212', 'Pending', '2026-01-28 09:49:00');

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
  MODIFY `img_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- AUTO_INCREMENT for table `login_logs`
--
ALTER TABLE `login_logs`
  MODIFY `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=111;

--
-- AUTO_INCREMENT for table `maintenance_schedule`
--
ALTER TABLE `maintenance_schedule`
  MODIFY `sched_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `materials_equipment_costs`
--
ALTER TABLE `materials_equipment_costs`
  MODIFY `cost_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=250;

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
  MODIFY `req_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=87;

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
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
