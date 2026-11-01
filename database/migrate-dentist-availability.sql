-- BR Dental Clinic: dentist availability
-- ============================================================
-- Until now every dentist inherited the one clinic-wide schedule, so the
-- system would happily book a dentist on a day off. This adds per-dentist
-- working hours plus dated days off.
--
--   dentist_availability : weekly recurring hours, one row per weekday
--   dentist_days_off     : specific dates a dentist is unavailable
--
-- A dentist with no availability rows is treated as following the clinic
-- schedule, so existing accounts keep working without configuration.
-- ============================================================
USE clinic;

CREATE TABLE IF NOT EXISTS `dentist_availability` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `dentist_id` INT(10) UNSIGNED NOT NULL,
  -- 1 = Monday ... 7 = Sunday, matching PHP date('N')
  `weekday` TINYINT UNSIGNED NOT NULL,
  -- Minutes from midnight, so 8:00 AM = 480 and 5:00 PM = 1020.
  `start_minute` SMALLINT UNSIGNED NOT NULL,
  `end_minute` SMALLINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dentist_weekday` (`dentist_id`, `weekday`),
  CONSTRAINT `fk_availability_dentist` FOREIGN KEY (`dentist_id`)
    REFERENCES `dentists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_weekday` CHECK (`weekday` BETWEEN 1 AND 7),
  CONSTRAINT `chk_minutes` CHECK (`end_minute` > `start_minute`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `dentist_days_off` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `dentist_id` INT(10) UNSIGNED NOT NULL,
  `off_date` DATE NOT NULL,
  `reason` VARCHAR(150) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dentist_day_off` (`dentist_id`, `off_date`),
  KEY `idx_days_off_date` (`off_date`),
  CONSTRAINT `fk_days_off_dentist` FOREIGN KEY (`dentist_id`)
    REFERENCES `dentists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed both dentists with the current clinic hours (Mon-Sat, 8:00 AM - 5:00 PM)
-- so their availability matches what the clinic already advertises.
INSERT INTO `dentist_availability` (`dentist_id`, `weekday`, `start_minute`, `end_minute`)
SELECT d.id, w.weekday, 480, 1020
FROM `dentists` d
CROSS JOIN (
  SELECT 1 AS weekday UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
  SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
) w
WHERE NOT EXISTS (
  SELECT 1 FROM `dentist_availability` a WHERE a.dentist_id = d.id
);
