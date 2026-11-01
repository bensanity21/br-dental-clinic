-- ============================================================
-- BR Dental Clinic - Complete Database Schema
-- Import this one file for a fresh install: it matches the code.
--
-- NO PASSWORDS ARE INCLUDED IN THIS FILE.
--
-- Every seeded account starts in a "no usable password" state, so importing
-- this file never creates an account anyone can sign in to. Set a real
-- password for each account once, using either:
--
--   php database/set-password.php                  (command line, recommended)
--   setup.html                                     (browser, fresh install only)
--
-- The browser setup page stops working permanently as soon as one
-- administrator has a usable password, so it cannot be used to take over a
-- live site.
--
-- SEEDED ACCOUNTS
--   admin@brdental.com    Super Admin   (the only privileged account)
--   elena@brdental.com    Dentist       Dr. Ma. Elena B. Retuerna
--   smith@brdental.com    Dentist       Dr. John Smith
--
-- Patient accounts create themselves through public sign-up.
-- Dentist accounts are created by the Super Admin from Users & Roles.
-- ============================================================

CREATE DATABASE IF NOT EXISTS `clinic` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `clinic`;

/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_ip_allowlist` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(64) NOT NULL COMMENT 'Single address or CIDR block, e.g. 203.0.113.7 or 203.0.113.0/24',
  `label` varchar(100) DEFAULT NULL COMMENT 'Who or where this entry is for',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `user_name` varchar(100) NOT NULL DEFAULT '',
  `user_role` varchar(30) NOT NULL DEFAULT '',
  `action` varchar(60) NOT NULL,
  `entity` varchar(40) NOT NULL DEFAULT '',
  `entity_id` int(10) unsigned DEFAULT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_activity_created` (`created_at`),
  KEY `idx_activity_user` (`user_id`),
  KEY `idx_activity_action` (`action`),
  KEY `idx_activity_entity` (`entity`,`entity_id`),
  CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` int(10) unsigned NOT NULL,
  `service_id` int(10) unsigned NOT NULL,
  `dentist_id` int(10) unsigned NOT NULL,
  `appointment_date` date NOT NULL,
  `time_slot` varchar(50) NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('Pending','Approved','Completed','Cancelled','Rescheduled','No-show') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `slot_key` varchar(120) GENERATED ALWAYS AS (case when `status` in ('Pending','Approved','Rescheduled','Completed') then concat(`dentist_id`,'|',`appointment_date`,'|',`time_slot`) else NULL end) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_appointments_active_slot` (`slot_key`),
  KEY `fk_appointments_service` (`service_id`),
  KEY `idx_appt_date_status` (`appointment_date`,`status`),
  KEY `idx_appt_dentist_date` (`dentist_id`,`appointment_date`),
  KEY `idx_appt_patient_date` (`patient_id`,`appointment_date`),
  CONSTRAINT `fk_appointments_dentist` FOREIGN KEY (`dentist_id`) REFERENCES `dentists` (`id`),
  CONSTRAINT `fk_appointments_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_appointments_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `archives`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `archives` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `original_table` varchar(100) NOT NULL,
  `record_id` int(10) unsigned NOT NULL,
  `record_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`record_data`)),
  `deleted_by` int(10) unsigned DEFAULT NULL,
  `deleted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_original_table` (`original_table`),
  KEY `idx_record_id` (`record_id`),
  KEY `fk_archives_deleted_by` (`deleted_by`),
  CONSTRAINT `fk_archives_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clinic_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clinic_schedules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `operating_days` varchar(100) NOT NULL,
  `operating_hours` varchar(100) NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clinic_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clinic_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `clinic_name` varchar(150) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `address` text NOT NULL,
  `operating_days` varchar(100) NOT NULL DEFAULT 'Mon-Sat',
  `operating_hours` varchar(100) NOT NULL DEFAULT '8:00 AM - 6:00 PM',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dental_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dental_records` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` int(10) unsigned DEFAULT NULL,
  `patient_name` varchar(255) DEFAULT NULL,
  `dentist_id` int(10) unsigned NOT NULL,
  `treatment_procedure` varchar(255) NOT NULL,
  `clinical_notes` text DEFAULT NULL,
  `record_date` date NOT NULL,
  `record_time` time DEFAULT NULL,
  `patient_contact` varchar(40) DEFAULT NULL,
  `status` enum('In Progress','Completed') NOT NULL DEFAULT 'In Progress',
  PRIMARY KEY (`id`),
  KEY `fk_dental_records_dentist` (`dentist_id`),
  KEY `idx_records_patient_date` (`patient_id`,`record_date`),
  CONSTRAINT `fk_dental_records_dentist` FOREIGN KEY (`dentist_id`) REFERENCES `dentists` (`id`),
  CONSTRAINT `fk_dental_records_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dentist_availability`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dentist_availability` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dentist_id` int(10) unsigned NOT NULL,
  `weekday` tinyint(3) unsigned NOT NULL,
  `start_minute` smallint(5) unsigned NOT NULL,
  `end_minute` smallint(5) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dentist_weekday` (`dentist_id`,`weekday`),
  CONSTRAINT `fk_availability_dentist` FOREIGN KEY (`dentist_id`) REFERENCES `dentists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_weekday` CHECK (`weekday` between 1 and 7),
  CONSTRAINT `chk_minutes` CHECK (`end_minute` > `start_minute`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dentist_days_off`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dentist_days_off` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dentist_id` int(10) unsigned NOT NULL,
  `off_date` date NOT NULL,
  `reason` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dentist_day_off` (`dentist_id`,`off_date`),
  KEY `idx_days_off_date` (`off_date`),
  CONSTRAINT `fk_days_off_dentist` FOREIGN KEY (`dentist_id`) REFERENCES `dentists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dentists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dentists` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `full_name` varchar(100) NOT NULL,
  `specialization` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_dentists_user` (`user_id`),
  CONSTRAINT `fk_dentists_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email_ip` (`email`,`ip_address`),
  KEY `idx_attempted_at` (`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_read` (`user_id`,`is_read`,`created_at`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=86 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `patient_notification_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_notification_preferences` (
  `patient_id` int(10) unsigned NOT NULL,
  `in_app_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`patient_id`),
  CONSTRAINT `fk_patient_notification_preferences_user` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 of the emailed token, never the token itself',
  `requested_by_ip` varchar(45) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_password_resets_token` (`token_hash`),
  KEY `idx_password_resets_user` (`user_id`,`created_at`),
  KEY `idx_password_resets_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` char(64) NOT NULL COMMENT 'SHA-256 of the PHP session id',
  `data` mediumtext DEFAULT NULL COMMENT 'PHP-serialised session payload',
  `last_activity` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'UNIX timestamp of last write',
  PRIMARY KEY (`id`),
  KEY `idx_sessions_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `services` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `service_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `duration_minutes` int(10) unsigned NOT NULL DEFAULT 60,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_services_active` (`is_active`,`service_name`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `emergency_contact` text DEFAULT NULL,
  `home_address` text DEFAULT NULL,
  `date_of_birth` text DEFAULT NULL,
  `language_preference` varchar(5) NOT NULL DEFAULT 'en',
  `role` enum('patient','super-admin','clinic_admin','master_admin','doctor') NOT NULL DEFAULT 'patient',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


-- Seed data
-- ============================================================

-- Super Admin (the only privileged account)
INSERT INTO `users` (`id`, `full_name`, `email`, `password_hash`, `role`) VALUES
(1, 'Super Admin', 'admin@brdental.com', 'CHANGE_ME', 'super-admin');

-- Dentist account for Dr. Ma. Elena B. Retuerna
INSERT INTO `users` (`id`, `full_name`, `email`, `password_hash`, `role`) VALUES
(2, 'Dr. Ma. Elena B. Retuerna', 'elena@brdental.com', 'CHANGE_ME', 'doctor');

-- Dentist account for Dr. John Smith
INSERT INTO `users` (`id`, `full_name`, `email`, `password_hash`, `role`) VALUES
(3, 'Dr. John Smith', 'smith@brdental.com', 'CHANGE_ME', 'doctor');

INSERT INTO `dentists` (`id`, `user_id`, `full_name`, `specialization`, `phone_number`) VALUES
(1, 2, 'Dr. Ma. Elena B. Retuerna', 'General Dentistry', '09066636112'),
(2, 3, 'Dr. John Smith', 'Oral Surgery', '0917999888');

-- Services
INSERT INTO `services` (`service_name`, `description`, `price`, `duration_minutes`, `is_active`) VALUES
('Dental Cleaning (Prophylaxis)', 'Removal of plaque and tartar deposits. Professional cleaning and polishing.', 800.00, 45, 1),
('Fluoride Application', 'Preventive fluoride treatment to strengthen tooth enamel.', 500.00, 30, 1),
('Composite Filling', 'Tooth-colored restoration for cavities.', 1500.00, 60, 1),
('Tooth Extraction', 'Safe removal of compromised teeth.', 1000.00, 45, 1),
('Removal of Impacted Molar', 'Surgical removal of impacted wisdom teeth.', 5000.00, 90, 1),
('Complete Dentures', 'Full set of removable artificial teeth.', 30000.00, 90, 1),
('Partial Dentures', 'Removable partial artificial teeth.', 15000.00, 60, 1),
('Dental Crowns', 'Custom-fitted caps for damaged teeth.', 8000.00, 90, 1),
('Orthodontic Treatment', 'Braces and alignment correction.', 50000.00, 60, 1);

-- Clinic details
INSERT INTO `clinic_settings` (`id`, `clinic_name`, `phone_number`, `address`) VALUES
(1, 'BR Dental Clinic', '09066636112', '73 Nyugan Road, Paliparan 1, Dasmariñas City, Cavite');

-- Clinic hours
INSERT INTO `clinic_schedules` (`id`, `operating_days`, `operating_hours`) VALUES
(1, 'Monday to Saturday', '8:00 AM - 5:00 PM');

-- Dentist availability: Monday to Saturday, 8:00 AM - 5:00 PM
-- (minute 480 = 08:00, minute 1020 = 17:00)
INSERT INTO `dentist_availability` (`dentist_id`, `weekday`, `start_minute`, `end_minute`) VALUES
(1, 1, 480, 1020), (1, 2, 480, 1020), (1, 3, 480, 1020),
(1, 4, 480, 1020), (1, 5, 480, 1020), (1, 6, 480, 1020),
(2, 1, 480, 1020), (2, 2, 480, 1020), (2, 3, 480, 1020),
(2, 4, 480, 1020), (2, 5, 480, 1020), (2, 6, 480, 1020);
