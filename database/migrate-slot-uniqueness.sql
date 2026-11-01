-- BR Dental Clinic: enforce one active appointment per dentist + date + slot
-- ============================================================
-- The PHP booking code uses a transaction with SELECT ... FOR UPDATE,
-- but a unique index is the only airtight guarantee under concurrency.
-- Cancelled appointments are excluded by making slot_key NULL for them
-- (MySQL allows many NULLs in a unique index).
-- ============================================================
USE clinic;

-- Drop the stored column first if a previous attempt left it behind.
SET @has_col = (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'slot_key');
SET @sql = IF(@has_col > 0,
  'ALTER TABLE appointments DROP COLUMN slot_key',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

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
