-- ========================================================
-- Hospital Queue Management System Database Schema
-- Database Name: hospital_queue_db
-- Target Environment: MySQL / MariaDB (XAMPP)
-- ========================================================

CREATE DATABASE IF NOT EXISTS `hospital_queue_db` 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `hospital_queue_db`;

-- --------------------------------------------------------
-- Table structure for table `users`
-- Stores login accounts, roles and authentication data
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `user_id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'receptionist', 'operator') NOT NULL DEFAULT 'receptionist',
    `full_name` VARCHAR(120) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `departments`
-- Stores clinical departments with unique name constraint
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `departments` (
    `department_id` INT AUTO_INCREMENT PRIMARY KEY,
    `department_name` VARCHAR(100) NOT NULL UNIQUE,
    `department_code` VARCHAR(10) NOT NULL UNIQUE,
    `description` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `patients`
-- Stores basic demographic and contact details for patients
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `patients` (
    `patient_id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `age` INT NOT NULL,
    `gender` ENUM('Male', 'Female', 'Other') NOT NULL,
    `phone` VARCHAR(30) NULL,
    `address` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `doctors`
-- Stores doctor profiles, department links, and availability
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `doctors` (
    `doctor_id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `department_id` INT NOT NULL,
    `specialization` VARCHAR(120) NULL,
    `availability` ENUM('Available', 'Busy', 'Off Duty') NOT NULL DEFAULT 'Available',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_doctor_department` FOREIGN KEY (`department_id`) 
        REFERENCES `departments`(`department_id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `queue_entries`
-- Core table representing queue visits and token lifecycle
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `queue_entries` (
    `queue_id` INT AUTO_INCREMENT PRIMARY KEY,
    `patient_id` INT NOT NULL,
    `department_id` INT NOT NULL,
    `doctor_id` INT NULL,
    `token_number` VARCHAR(30) NOT NULL UNIQUE,
    `urgency_level` TINYINT NULL,
    `queue_type` ENUM('Normal', 'Emergency') NOT NULL DEFAULT 'Normal',
    `arrival_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `status` ENUM('Waiting', 'Called', 'In Consultation', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Waiting',
    CONSTRAINT `fk_queue_patient` FOREIGN KEY (`patient_id`) 
        REFERENCES `patients`(`patient_id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_queue_department` FOREIGN KEY (`department_id`) 
        REFERENCES `departments`(`department_id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_queue_doctor` FOREIGN KEY (`doctor_id`) 
        REFERENCES `doctors`(`doctor_id`) 
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_queue_department_status` (`department_id`, `status`, `arrival_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `queue_logs`
-- Audit trail of all queue operations (calls, completions, cancels, undos)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `queue_logs` (
    `log_id` INT AUTO_INCREMENT PRIMARY KEY,
    `queue_id` INT NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `action_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `performed_by` INT NULL,
    `notes` TEXT NULL,
    CONSTRAINT `fk_log_queue` FOREIGN KEY (`queue_id`) 
        REFERENCES `queue_entries`(`queue_id`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_log_user` FOREIGN KEY (`performed_by`) 
        REFERENCES `users`(`user_id`) 
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ========================================================
-- Seed Data
-- ========================================================

-- Demo Users (passwords hashed using PHP password_hash(..., PASSWORD_BCRYPT))
-- admin: admin123
-- receptionist: reception123
-- operator: operator123
INSERT INTO `users` (`user_id`, `username`, `password_hash`, `role`, `full_name`, `created_at`) VALUES
(1, 'admin', '$2y$10$xyWc013iW.zOrzDhydqY5.CZwLmkOxOyiICCUkdWKQQKDkpUD68vK', 'admin', 'System Administrator', NOW()),
(2, 'receptionist', '$2y$10$bNzfRSUkTBmkR4XAhQYHZOrCY7VhgHpEWE2S5fEvAA7gE5.N.9WIa', 'receptionist', 'Front Desk Receptionist', NOW()),
(3, 'operator', '$2y$10$Wo6DsrwoRIm4hh287xvd8.8yJMyBzdfADrqHfrSx.6KJEestZjq/u', 'operator', 'Queue Operator', NOW())
ON DUPLICATE KEY UPDATE `username`=`username`;

-- Hospital Departments
INSERT INTO `departments` (`department_id`, `department_name`, `department_code`, `description`, `created_at`) VALUES
(1, 'General Medicine', 'GM', 'Primary health concerns, general health checkups and consultations', NOW()),
(2, 'Paediatrics', 'PD', 'Medical care for infants, children, and adolescents', NOW()),
(3, 'Orthopaedics', 'OR', 'Musculoskeletal system, bone fractures, joints and spine care', NOW()),
(4, 'Cardiology', 'CD', 'Heart health, cardiovascular diseases and ECG assessment', NOW()),
(5, 'Dermatology', 'DM', 'Skin, hair, nail conditions and dermatological procedures', NOW())
ON DUPLICATE KEY UPDATE `department_name`=`department_name`;

-- Sample Doctors
INSERT INTO `doctors` (`doctor_id`, `name`, `department_id`, `specialization`, `availability`, `created_at`) VALUES
(1, 'Dr. Alice Johnson', 1, 'General Physician', 'Available', NOW()),
(2, 'Dr. Mark Stevens', 1, 'Internal Medicine', 'Busy', NOW()),
(3, 'Dr. Robert Taylor', 1, 'Family Medicine', 'Available', NOW()),
(4, 'Dr. Sarah Jenkins', 2, 'Child Specialist & Paediatrician', 'Available', NOW()),
(5, 'Dr. James Wilson', 2, 'Neonatologist', 'Off Duty', NOW()),
(6, 'Dr. David Miller', 3, 'Orthopaedic Surgeon', 'Available', NOW()),
(7, 'Dr. Emily Clark', 3, 'Joint Replacement Specialist', 'Available', NOW()),
(8, 'Dr. Karen Evans', 4, 'Consultant Cardiologist', 'Available', NOW()),
(9, 'Dr. Thomas Moore', 5, 'Consultant Dermatologist', 'Available', NOW())
ON DUPLICATE KEY UPDATE `name`=`name`;

-- Sample Patients
INSERT INTO `patients` (`patient_id`, `name`, `age`, `gender`, `phone`, `address`, `created_at`) VALUES
(1, 'John Doe', 34, 'Male', '0300-1234567', 'House #12, Street 4, Sector G-8, Islamabad', NOW() - INTERVAL 45 MINUTE),
(2, 'Jane Smith', 28, 'Female', '0301-2345678', 'Flat 4B, Hill View Apts, F-10, Islamabad', NOW() - INTERVAL 40 MINUTE),
(3, 'Ahmed Khan', 6, 'Male', '0312-3456789', 'House 56, Block C, Blue Area, Islamabad', NOW() - INTERVAL 35 MINUTE),
(4, 'Fatima Bibi', 62, 'Female', '0321-4567890', 'House 78, Street 9, I-8/2, Islamabad', NOW() - INTERVAL 30 MINUTE),
(5, 'Ali Raza', 45, 'Male', '0333-5678901', 'House 22-B, Commercial Market, Rawalpindi', NOW() - INTERVAL 25 MINUTE),
(6, 'Zainab Noor', 31, 'Female', '0345-6789012', 'House 101, Phase 4, Bahria Town, Rawalpindi', NOW() - INTERVAL 15 MINUTE)
ON DUPLICATE KEY UPDATE `name`=`name`;

-- Sample Queue Entries
-- Note: Includes normal queue entries, emergency-designated entry with urgency_level, and doctor assignment
INSERT INTO `queue_entries` (`queue_id`, `patient_id`, `department_id`, `doctor_id`, `token_number`, `urgency_level`, `queue_type`, `arrival_time`, `status`) VALUES
(1, 1, 1, NULL, 'GM-001', NULL, 'Normal', NOW() - INTERVAL 40 MINUTE, 'Waiting'),
(2, 2, 1, NULL, 'GM-002', 5, 'Emergency', NOW() - INTERVAL 35 MINUTE, 'Waiting'),
(3, 3, 2, NULL, 'PD-001', NULL, 'Normal', NOW() - INTERVAL 30 MINUTE, 'Waiting'),
(4, 4, 3, 6, 'OR-001', NULL, 'Normal', NOW() - INTERVAL 28 MINUTE, 'Called'),
(5, 5, 1, 1, 'GM-003', NULL, 'Normal', NOW() - INTERVAL 25 MINUTE, 'Completed'),
(6, 6, 1, NULL, 'GM-004', NULL, 'Normal', NOW() - INTERVAL 10 MINUTE, 'Waiting')
ON DUPLICATE KEY UPDATE `token_number`=`token_number`;

-- Sample Queue Logs
INSERT INTO `queue_logs` (`log_id`, `queue_id`, `action`, `action_time`, `performed_by`, `notes`) VALUES
(1, 1, 'Registered', NOW() - INTERVAL 40 MINUTE, 2, 'Normal patient registered for General Medicine'),
(2, 2, 'Registered', NOW() - INTERVAL 35 MINUTE, 2, 'Emergency patient registered with Urgency Level 5'),
(3, 3, 'Registered', NOW() - INTERVAL 30 MINUTE, 2, 'Child patient registered for Paediatrics'),
(4, 4, 'Registered', NOW() - INTERVAL 28 MINUTE, 2, 'Patient registered for Orthopaedics'),
(5, 4, 'Called', NOW() - INTERVAL 12 MINUTE, 3, 'Called to Consultation Room with Dr. David Miller'),
(6, 5, 'Registered', NOW() - INTERVAL 25 MINUTE, 2, 'Routine follow-up registration'),
(7, 5, 'Called', NOW() - INTERVAL 20 MINUTE, 3, 'Assigned to Dr. Alice Johnson'),
(8, 5, 'Completed', NOW() - INTERVAL 5 MINUTE, 3, 'Consultation completed successfully'),
(9, 6, 'Registered', NOW() - INTERVAL 10 MINUTE, 2, 'General checkup registration')
ON DUPLICATE KEY UPDATE `action`=`action`;
