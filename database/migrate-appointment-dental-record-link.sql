-- Associate automatically generated Dental Records with their source visit.
-- Safe to run more than once on MySQL/MariaDB.
USE clinic;

SET @has_appointment_id = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dental_records' AND COLUMN_NAME = 'appointment_id'
);
SET @appointment_id_ddl = IF(@has_appointment_id = 0,
  'ALTER TABLE dental_records ADD COLUMN appointment_id INT(10) UNSIGNED NULL AFTER id',
  'SELECT 1'
);
PREPARE appointment_id_stmt FROM @appointment_id_ddl;
EXECUTE appointment_id_stmt;
DEALLOCATE PREPARE appointment_id_stmt;

SET @has_appointment_unique = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dental_records' AND INDEX_NAME = 'uq_dental_records_appointment'
);
SET @appointment_unique_ddl = IF(@has_appointment_unique = 0,
  'ALTER TABLE dental_records ADD UNIQUE KEY uq_dental_records_appointment (appointment_id)',
  'SELECT 1'
);
PREPARE appointment_unique_stmt FROM @appointment_unique_ddl;
EXECUTE appointment_unique_stmt;
DEALLOCATE PREPARE appointment_unique_stmt;

SET @has_appointment_fk = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'dental_records' AND CONSTRAINT_NAME = 'fk_dental_records_appointment'
);
SET @appointment_fk_ddl = IF(@has_appointment_fk = 0,
  'ALTER TABLE dental_records ADD CONSTRAINT fk_dental_records_appointment FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE appointment_fk_stmt FROM @appointment_fk_ddl;
EXECUTE appointment_fk_stmt;
DEALLOCATE PREPARE appointment_fk_stmt;
