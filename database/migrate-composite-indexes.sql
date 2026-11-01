-- BR Dental Clinic: composite indexes for the real query patterns
-- ============================================================
-- The application filters appointments by date, status and dentist on
-- almost every screen (dashboard KPIs, slot checks, reports, lists).
-- Single-column foreign keys do not help those combined filters, so add
-- composite indexes matching what is actually asked for.
-- ============================================================
USE clinic;

-- "appointments on this date with this status" (dashboard, lists, reports)
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'
                  AND INDEX_NAME = 'idx_appt_date_status');
SET @sql = IF(@has_idx = 0,
  'ALTER TABLE appointments ADD INDEX idx_appt_date_status (appointment_date, status)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- "this dentist's appointments" ordered by date (slot checks, dentist views)
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'
                  AND INDEX_NAME = 'idx_appt_dentist_date');
SET @sql = IF(@has_idx = 0,
  'ALTER TABLE appointments ADD INDEX idx_appt_dentist_date (dentist_id, appointment_date)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- "this patient's appointments" ordered by date (patient dashboard, history)
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'
                  AND INDEX_NAME = 'idx_appt_patient_date');
SET @sql = IF(@has_idx = 0,
  'ALTER TABLE appointments ADD INDEX idx_appt_patient_date (patient_id, appointment_date)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Unread badge count: WHERE user_id = ? AND is_read = 0
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
                  AND INDEX_NAME = 'idx_notif_user_read');
SET @sql = IF(@has_idx = 0,
  'ALTER TABLE notifications ADD INDEX idx_notif_user_read (user_id, is_read, created_at)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Patient record lookups ordered by date.
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dental_records'
                  AND INDEX_NAME = 'idx_records_patient_date');
SET @sql = IF(@has_idx = 0,
  'ALTER TABLE dental_records ADD INDEX idx_records_patient_date (patient_id, record_date)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
