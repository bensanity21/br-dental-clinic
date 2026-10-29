-- Add the date/time and contact snapshot fields used by the Dental Records form.
-- Safe to run more than once on MySQL and MariaDB.
USE clinic;

SET @has_record_time = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dental_records' AND COLUMN_NAME = 'record_time'
);
SET @record_time_ddl = IF(@has_record_time = 0,
  'ALTER TABLE dental_records ADD COLUMN record_time TIME NULL AFTER record_date',
  'SELECT 1'
);
PREPARE record_time_stmt FROM @record_time_ddl;
EXECUTE record_time_stmt;
DEALLOCATE PREPARE record_time_stmt;

SET @has_patient_contact = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dental_records' AND COLUMN_NAME = 'patient_contact'
);
SET @patient_contact_ddl = IF(@has_patient_contact = 0,
  'ALTER TABLE dental_records ADD COLUMN patient_contact VARCHAR(40) NULL AFTER record_time',
  'SELECT 1'
);
PREPARE patient_contact_stmt FROM @patient_contact_ddl;
EXECUTE patient_contact_stmt;
DEALLOCATE PREPARE patient_contact_stmt;

SET @has_patient_name = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dental_records' AND COLUMN_NAME = 'patient_name'
);
SET @patient_name_ddl = IF(@has_patient_name = 0,
  'ALTER TABLE dental_records ADD COLUMN patient_name VARCHAR(255) NULL AFTER patient_id',
  'SELECT 1'
);
PREPARE patient_name_stmt FROM @patient_name_ddl;
EXECUTE patient_name_stmt;
DEALLOCATE PREPARE patient_name_stmt;

SET @has_record_status = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dental_records' AND COLUMN_NAME = 'status'
);
SET @record_status_ddl = IF(@has_record_status = 0,
  "ALTER TABLE dental_records ADD COLUMN status ENUM('In Progress','Completed') NOT NULL DEFAULT 'In Progress' AFTER patient_contact",
  'SELECT 1'
);
PREPARE record_status_stmt FROM @record_status_ddl;
EXECUTE record_status_stmt;
DEALLOCATE PREPARE record_status_stmt;

SET @patient_id_nullable = (
  SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dental_records' AND COLUMN_NAME = 'patient_id'
  LIMIT 1
);
SET @patient_id_ddl = IF(@patient_id_nullable = 'NO',
  'ALTER TABLE dental_records MODIFY COLUMN patient_id INT(10) UNSIGNED NULL',
  'SELECT 1'
);
PREPARE patient_id_stmt FROM @patient_id_ddl;
EXECUTE patient_id_stmt;
DEALLOCATE PREPARE patient_id_stmt;
