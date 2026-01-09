-- --------------------------------------------------------
-- Database: caams_fyp - OPTIMIZED VERSION
-- Admin: NO service_type, NO rank_level
-- Rankholder & Cadet: REQUIRED service_type & rank_level
-- --------------------------------------------------------

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------
-- Create database if not exists
-- --------------------------------------------------------
CREATE DATABASE IF NOT EXISTS `caams_fyp` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `caams_fyp`;

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `military_number` varchar(20) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','rankholder','cadet') NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `service_type` enum('darat','laut','udara') DEFAULT NULL,
  `rank_level` enum('junior','intermediate','senior') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `military_number` (`military_number`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Dumping data for table `users`
-- --------------------------------------------------------
INSERT INTO `users` (`user_id`, `military_number`, `password`, `role`, `name`, `email`, `service_type`, `rank_level`, `created_at`) VALUES
(1, 'ADM001', '$2y$10$zBvyVvJX1VLdZ3YzwFoW2eTj63AJZ/Ad7.YJkybLoUwAb/2TZZi6q', 'admin', 'Admin System', 'admin@fyp.com', NULL, NULL, '2026-01-07 14:00:00'),
(2, 'RH001', '$2y$10$zBvyVvJX1VLdZ3YzwFoW2eTj63AJZ/Ad7.YJkybLoUwAb/2TZZi6q', 'rankholder', 'Kapten Ali Ahmad', 'ali.rank@fyp.com', 'darat', 'senior', '2026-01-07 14:00:00'),
(3, 'RH002', '$2y$10$zBvyVvJX1VLdZ3YzwFoW2eTj63AJZ/Ad7.YJkybLoUwAb/2TZZi6q', 'rankholder', 'Leftenan Mei Ling', 'mei.rank@fyp.com', 'udara', 'senior', '2026-01-07 14:00:00'),
(4, 'CD001', '$2y$10$zBvyVvJX1VLdZ3YzwFoW2eTj63AJZ/Ad7.YJkybLoUwAb/2TZZi6q', 'cadet', 'Ahmad Lee', 'ahmad@fyp.com', 'laut', 'intermediate', '2026-01-07 14:00:00'),
(5, 'CD002', '$2y$10$zBvyVvJX1VLdZ3YzwFoW2eTj63AJZ/Ad7.YJkybLoUwAb/2TZZi6q', 'cadet', 'Siti Sarah', 'siti@fyp.com', 'udara', 'junior', '2026-01-07 14:00:00'),
(6, 'CD003', '$2y$10$zBvyVvJX1VLdZ3YzwFoW2eTj63AJZ/Ad7.YJkybLoUwAb/2TZZi6q', 'cadet', 'Raju Kumar', 'raju@fyp.com', 'darat', 'junior', '2026-01-07 14:00:00');

-- --------------------------------------------------------
-- Table structure for table `training_sessions`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `training_sessions`;
CREATE TABLE `training_sessions` (
  `session_id` int(11) NOT NULL AUTO_INCREMENT,
  `location` varchar(100) NOT NULL,
  `training_date` date NOT NULL,
  `training_type` varchar(50) NOT NULL,
  `qr_token` varchar(50) NOT NULL,
  `created_by` int(11) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`session_id`),
  UNIQUE KEY `qr_token` (`qr_token`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `training_sessions_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Dumping data for table `training_sessions`
-- --------------------------------------------------------
INSERT INTO `training_sessions` (`session_id`, `location`, `training_date`, `training_type`, `qr_token`, `created_by`, `expires_at`, `created_at`) VALUES
(1, 'Padang Kota', '2026-01-10', 'Fizikal', 'qrcode_abc123xyz', 1, '2026-01-10 08:01:00', '2026-01-07 14:00:00'),
(2, 'Dewan Utama', '2026-01-11', 'Teori', 'qrcode_def456uvw', 1, '2026-01-11 10:01:00', '2026-01-07 14:00:00'),
(3, 'Makmal Komputer', '2026-01-12', 'ICT', 'qrcode_ghi789rst', 1, '2026-01-12 14:01:00', '2026-01-07 14:00:00');

-- --------------------------------------------------------
-- Table structure for table `attendance`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `attendance`;
CREATE TABLE `attendance` (
  `attendance_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `status` enum('present','absent','late','excused') DEFAULT 'absent',
  `proof_file` varchar(255) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `checked_by` int(11) DEFAULT NULL,
  `checked_at` datetime DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attendance_id`),
  UNIQUE KEY `user_session` (`user_id`,`session_id`),
  KEY `session_id` (`session_id`),
  KEY `checked_by` (`checked_by`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`session_id`) REFERENCES `training_sessions` (`session_id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_3` FOREIGN KEY (`checked_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Dumping data for table `attendance`
-- --------------------------------------------------------
INSERT INTO `attendance` (`attendance_id`, `user_id`, `session_id`, `date`, `status`, `proof_file`, `reason`, `checked_by`, `checked_at`, `recorded_at`) VALUES
(1, 4, 1, '2026-01-10', 'present', NULL, NULL, 2, '2026-01-10 08:05:00', '2026-01-07 14:00:00'),
(2, 5, 1, '2026-01-10', 'late', NULL, 'Traffic jam', 2, '2026-01-10 08:15:00', '2026-01-07 14:00:00'),
(3, 6, 1, '2026-01-10', 'excused', 'medical_cert.pdf', 'Demam panas', 2, '2026-01-10 09:00:00', '2026-01-07 14:00:00'),
(4, 4, 2, '2026-01-11', 'present', NULL, NULL, 3, '2026-01-11 10:05:00', '2026-01-07 14:00:00'),
(5, 5, 2, '2026-01-11', 'absent', NULL, NULL, NULL, NULL, '2026-01-07 14:00:00'),
(6, 6, 2, '2026-01-11', 'present', NULL, NULL, 3, '2026-01-11 10:07:00', '2026-01-07 14:00:00');

-- --------------------------------------------------------
-- Table structure for table `allowance_calculations`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `allowance_calculations`;
CREATE TABLE `allowance_calculations` (
  `calc_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `month_year` varchar(7) NOT NULL,
  `attendance_rate` decimal(5,2) NOT NULL,
  `base_amount` decimal(10,2) NOT NULL DEFAULT 100.00,
  `calculated_amount` decimal(10,2) NOT NULL,
  `performance_bonus` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL,
  `calculated_by` int(11) NOT NULL,
  `calculated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`calc_id`),
  UNIQUE KEY `user_month` (`user_id`,`month_year`),
  KEY `calculated_by` (`calculated_by`),
  CONSTRAINT `allowance_calculations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `allowance_calculations_ibfk_2` FOREIGN KEY (`calculated_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Dumping data for table `allowance_calculations`
-- --------------------------------------------------------
INSERT INTO `allowance_calculations` (`calc_id`, `user_id`, `month_year`, `attendance_rate`, `base_amount`, `calculated_amount`, `performance_bonus`, `total_amount`, `calculated_by`, `calculated_at`) VALUES
(1, 4, '2026-01', 100.00, 100.00, 100.00, 20.00, 120.00, 1, '2026-01-07 14:00:00'),
(2, 5, '2026-01', 50.00, 100.00, 50.00, 10.00, 60.00, 1, '2026-01-07 14:00:00'),
(3, 6, '2026-01', 100.00, 100.00, 100.00, 15.00, 115.00, 1, '2026-01-07 14:00:00');

-- --------------------------------------------------------
-- Indexes for dumped tables
-- --------------------------------------------------------

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `military_number` (`military_number`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `training_sessions`
--
ALTER TABLE `training_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD UNIQUE KEY `qr_token` (`qr_token`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`attendance_id`),
  ADD UNIQUE KEY `user_session` (`user_id`,`session_id`),
  ADD KEY `session_id` (`session_id`),
  ADD KEY `checked_by` (`checked_by`);

--
-- Indexes for table `allowance_calculations`
--
ALTER TABLE `allowance_calculations`
  ADD PRIMARY KEY (`calc_id`),
  ADD UNIQUE KEY `user_month` (`user_id`,`month_year`),
  ADD KEY `calculated_by` (`calculated_by`);

-- --------------------------------------------------------
-- AUTO_INCREMENT for dumped tables
-- --------------------------------------------------------

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `training_sessions`
--
ALTER TABLE `training_sessions`
  MODIFY `session_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `attendance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `allowance_calculations`
--
ALTER TABLE `allowance_calculations`
  MODIFY `calc_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

-- --------------------------------------------------------
-- Constraints for dumped tables
-- --------------------------------------------------------

--
-- Constraints for table `training_sessions`
--
ALTER TABLE `training_sessions`
  ADD CONSTRAINT `training_sessions_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`session_id`) REFERENCES `training_sessions` (`session_id`),
  ADD CONSTRAINT `attendance_ibfk_3` FOREIGN KEY (`checked_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `allowance_calculations`
--
ALTER TABLE `allowance_calculations`
  ADD CONSTRAINT `allowance_calculations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `allowance_calculations_ibfk_2` FOREIGN KEY (`calculated_by`) REFERENCES `users` (`user_id`);

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;