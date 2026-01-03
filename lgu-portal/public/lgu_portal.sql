-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 03, 2026 at 02:00 PM
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
-- Database: `lgu_portal`
--

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(10) UNSIGNED NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `role` enum('Manager','Engineer','Office Staff','Super Admin') NOT NULL,
  `password` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `first_name`, `last_name`, `email`, `role`, `password`) VALUES
(1, 'Exequiel Kent', 'Bartolome', 'bartolomeexequielkent@gmail.com', 'Manager', '$2y$10$SWqpSqIHVrgmoa/TLlfGae9y/ftzABYfan.YVOv5Pv0dz/o836znW'),
(2, 'Warvie', 'Villa', 'villawarvie@gmail.com', 'Manager', '$2y$10$qzomsugzAuK1Mee9rEnHceYEo8T6DAAObMVtuc7zAdK2POw/INXou'),
(3, 'Jhoven', 'Las-ay', 'jhovenjadelas@gmail.com', 'Office Staff', '$2y$10$UYCT9LIpZ/ds4RH5gU3OCO6uhqbWeO5bqXbKL7hHzXOaf2.VAO1Ni'),
(4, 'Exequiel', 'Kent', 'bartolomstolome@gmail.com', 'Office Staff', '$2y$10$X8trSEoZq0vlFBZdawxm1Oao.71Ncn3AqnrRke6CEh5Z/t15122xK'),
(5, 'Jhoven', 'Las-ay', 'crackencontrol@gmail.com', 'Office Staff', '$2y$10$2Wdsn3eLiI5R2blRfrTQwe85kjtHrd8CM92OljFkSO0vqhEe//wcu');

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_schedule`
--

CREATE TABLE `maintenance_schedule` (
  `id` int(11) NOT NULL,
  `task` varchar(150) DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `schedule_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `maintenance_schedule`
--

INSERT INTO `maintenance_schedule` (`id`, `task`, `location`, `schedule_date`) VALUES
(1, 'Aircon Maintenance', 'City Hall Room 101', '2026-01-05'),
(2, 'Generator Inspection', 'City Hall Basement', '2026-01-07'),
(3, 'Fire Extinguisher Check', 'City Hall Lobby', '2026-01-10'),
(4, 'Garden Landscaping', 'City Hall Garden', '2026-01-12'),
(5, 'Elevator Safety Test', 'City Hall Tower A', '2026-01-15'),
(6, 'Aircons Maintenance', 'City Hall Room 101', '2026-01-05');

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `id` int(11) NOT NULL,
  `infrastructure` varchar(100) DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `work_done` varchar(255) DEFAULT NULL,
  `date_completed` date DEFAULT NULL,
  `status` enum('Completed','On-Going') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `requests`
--

CREATE TABLE `requests` (
  `id` int(11) NOT NULL,
  `infrastructure` varchar(100) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `issue` varchar(255) DEFAULT NULL,
  `date_submitted` date DEFAULT NULL,
  `evidence` varchar(255) DEFAULT NULL,
  `status` enum('Pending','In Progress','Completed') DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `maintenance_schedule`
--
ALTER TABLE `maintenance_schedule`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `requests`
--
ALTER TABLE `requests`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `maintenance_schedule`
--
ALTER TABLE `maintenance_schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `requests`
--
ALTER TABLE `requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
