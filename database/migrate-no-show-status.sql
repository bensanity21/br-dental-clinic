-- BR Dental Clinic: 'No-show' appointment status
-- ============================================================
-- A dentist marks an appointment No-show when the patient does not
-- arrive. api/reports/generate.php already queries for this status,
-- so without it the Cancelled / No-show report was always empty.
--
-- ORDER MATTERS: the generated column slot_key reads `status`, and
-- MySQL refuses to modify a column a generated column depends on while
-- that column exists. So we drop slot_key and its unique index, extend
-- the enum, then rebuild both.
--
-- slot_key stays NULL for Cancelled and No-show, which frees the time
-- slot for rebooking while 'Completed' still blocks it.
-- ============================================================
USE clinic;

-- 1. Drop the dependent generated column (the unique index goes with it).
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'
                  AND INDEX_NAME = 'uq_appointments_active_slot');
SET @sql = IF(@has_idx > 0, 'ALTER TABLE appointments DROP INDEX uq_appointments_active_slot', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @has_col = (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'slot_key');
SET @sql = IF(@has_col > 0, 'ALTER TABLE appointments DROP COLUMN slot_key', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. Extend the status enum.
ALTER TABLE appointments
  MODIFY COLUMN status ENUM('Pending','Approved','Completed','Cancelled','Rescheduled','No-show')
  NOT NULL DEFAULT 'Pending';

-- 3. Rebuild slot_key exactly as before. A no-show frees the slot.
ALTER TABLE appointments
  ADD COLUMN slot_key VARCHAR(120)
    GENERATED ALWAYS AS (
      CASE
        WHEN status IN ('Pending','Approved','Rescheduled','Completed')
          THEN CONCAT(dentist_id, '|', appointment_date, '|', time_slot)
        ELSE NULL
      END
    ) STORED;

ALTER TABLE appointments
  ADD UNIQUE KEY uq_appointments_active_slot (slot_key);
