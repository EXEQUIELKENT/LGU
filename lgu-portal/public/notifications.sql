-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 27, 2026 at 04:04 PM
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
(1, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=35', 'Roads', 0, '2026-01-27 13:07:46'),
(2, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=36', 'Roads', 0, '2026-01-27 13:31:19'),
(3, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=37', 'Roads', 0, '2026-01-27 13:31:48'),
(4, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=38', 'Roads', 0, '2026-01-27 13:36:04'),
(5, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=38', 'Roads', 1, '2026-01-27 13:36:04'),
(6, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=38', 'Roads', 0, '2026-01-27 13:36:04'),
(7, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=38', 'Roads', 0, '2026-01-27 13:36:04'),
(8, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=38', 'Roads', 0, '2026-01-27 13:36:04'),
(9, 12, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=38', 'Roads', 0, '2026-01-27 13:36:04'),
(10, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=39', 'Roads', 0, '2026-01-27 13:49:22'),
(11, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=39', 'Roads', 1, '2026-01-27 13:49:22'),
(12, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=39', 'Roads', 0, '2026-01-27 13:49:22'),
(13, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=39', 'Roads', 0, '2026-01-27 13:49:22'),
(14, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=39', 'Roads', 0, '2026-01-27 13:49:22'),
(15, 12, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=39', 'Roads', 0, '2026-01-27 13:49:22'),
(16, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=40', 'Roads', 0, '2026-01-27 13:52:48'),
(17, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=40', 'Roads', 1, '2026-01-27 13:52:48'),
(18, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=40', 'Roads', 0, '2026-01-27 13:52:48'),
(19, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=40', 'Roads', 0, '2026-01-27 13:52:48'),
(20, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=40', 'Roads', 0, '2026-01-27 13:52:48'),
(21, 12, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=40', 'Roads', 0, '2026-01-27 13:52:48'),
(22, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=41', 'Roads', 0, '2026-01-27 14:08:51'),
(23, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=41', 'Roads', 1, '2026-01-27 14:08:51'),
(24, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=41', 'Roads', 0, '2026-01-27 14:08:51'),
(25, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=41', 'Roads', 0, '2026-01-27 14:08:51'),
(26, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=41', 'Roads', 0, '2026-01-27 14:08:51'),
(27, 12, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=41', 'Roads', 0, '2026-01-27 14:08:51'),
(28, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=42', 'Roads', 0, '2026-01-27 14:12:01'),
(29, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=42', 'Roads', 1, '2026-01-27 14:12:01'),
(30, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=42', 'Roads', 0, '2026-01-27 14:12:01'),
(31, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=42', 'Roads', 0, '2026-01-27 14:12:01'),
(32, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=42', 'Roads', 0, '2026-01-27 14:12:01'),
(33, 12, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=42', 'Roads', 0, '2026-01-27 14:12:01'),
(34, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=43', 'Roads', 0, '2026-01-27 14:12:53'),
(35, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=43', 'Roads', 1, '2026-01-27 14:12:53'),
(36, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=43', 'Roads', 0, '2026-01-27 14:12:53'),
(37, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=43', 'Roads', 0, '2026-01-27 14:12:53'),
(38, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=43', 'Roads', 0, '2026-01-27 14:12:53'),
(39, 12, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=43', 'Roads', 0, '2026-01-27 14:12:53'),
(40, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=44', 'Electrical', 0, '2026-01-27 14:41:21'),
(41, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=44', 'Electrical', 0, '2026-01-27 14:41:21'),
(42, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=44', 'Electrical', 0, '2026-01-27 14:41:21'),
(43, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=44', 'Electrical', 0, '2026-01-27 14:41:21'),
(44, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=44', 'Electrical', 0, '2026-01-27 14:41:21'),
(45, 12, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=44', 'Electrical', 0, '2026-01-27 14:41:21'),
(46, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=45', 'Electrical', 0, '2026-01-27 14:46:57'),
(47, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=45', 'Electrical', 0, '2026-01-27 14:46:57'),
(48, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=45', 'Electrical', 0, '2026-01-27 14:46:57'),
(49, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=45', 'Electrical', 0, '2026-01-27 14:46:57'),
(50, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=45', 'Electrical', 0, '2026-01-27 14:46:57'),
(51, 12, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=45', 'Electrical', 0, '2026-01-27 14:46:57'),
(52, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=46', 'Electrical', 0, '2026-01-27 14:52:50'),
(53, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=46', 'Electrical', 0, '2026-01-27 14:52:50'),
(54, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=46', 'Electrical', 0, '2026-01-27 14:52:50'),
(55, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=46', 'Electrical', 0, '2026-01-27 14:52:50'),
(56, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=46', 'Electrical', 0, '2026-01-27 14:52:50'),
(57, 12, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=46', 'Electrical', 0, '2026-01-27 14:52:50'),
(58, 3, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=48', 'Water Supply', 0, '2026-01-27 15:03:23'),
(59, 1, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=48', 'Water Supply', 0, '2026-01-27 15:03:23'),
(60, 2, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=48', 'Water Supply', 0, '2026-01-27 15:03:23'),
(61, 8, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=48', 'Water Supply', 0, '2026-01-27 15:03:23'),
(62, 11, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=48', 'Water Supply', 0, '2026-01-27 15:03:23'),
(63, 12, 'New Citizen Request', 'A new request has been submitted and requires your review.', 'employee.php?request_id=48', 'Water Supply', 0, '2026-01-27 15:03:23');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
