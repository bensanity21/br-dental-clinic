-- BR Dental Clinic: Rescheduled status + encrypted profile fields + login attempts table
USE clinic;

-- 1. Add 'Rescheduled' to appointment status enum
SET @enum_before = (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'status'
);
SET @alter_status = IF(
  @enum_before NOT LIKE '%Rescheduled%',
  "ALTER TABLE appointments MODIFY COLUMN status ENUM('Pending','Approved','Completed','Cancelled','Rescheduled') NOT NULL DEFAULT 'Pending'",
  'SELECT 1'
);
PREPARE st FROM @alter_status; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. Encrypted profile fields on users (emergency contact, address, date of birth)
SET @has_emergency = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'emergency_contact');
SET @sql_emergency = IF(@has_emergency = 0, "ALTER TABLE users ADD COLUMN emergency_contact TEXT NULL AFTER phone_number", 'SELECT 1');
PREPARE st FROM @sql_emergency; EXECUTE st; DEALLOCATE PREPARE st;

SET @has_address = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'home_address');
SET @sql_address = IF(@has_address = 0, "ALTER TABLE users ADD COLUMN home_address TEXT NULL AFTER emergency_contact", 'SELECT 1');
PREPARE st FROM @sql_address; EXECUTE st; DEALLOCATE PREPARE st;

SET @has_dob = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'date_of_birth');
SET @sql_dob = IF(@has_dob = 0, "ALTER TABLE users ADD COLUMN date_of_birth TEXT NULL AFTER home_address", 'SELECT 1');
PREPARE st FROM @sql_dob; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. Login rate limiting table
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(150) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_email_ip (email, ip_address),
  KEY idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
