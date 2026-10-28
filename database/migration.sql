-- ============================================================
-- BR Dental Clinic - Database Migration (from draft schema)
-- Adds: archives table, dentists.user_id link, doctor role,
--        seed services + dentist, notification FKs
-- ============================================================
USE clinic;

-- 1. Extend users.role to include 'doctor'
ALTER TABLE users
  MODIFY COLUMN role ENUM('patient','super-admin','clinic_admin','master_admin','doctor') NOT NULL DEFAULT 'patient';

-- 2. Link dentists to users (a doctor profile = a user account with role 'doctor')
ALTER TABLE dentists
  ADD COLUMN user_id INT(10) UNSIGNED NULL AFTER id,
  ADD CONSTRAINT fk_dentists_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

-- 3. appointments: add created_at/updated_at for ordering & audit
ALTER TABLE appointments
  ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- 4. Archives table (soft delete storage)
CREATE TABLE IF NOT EXISTS archives (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  original_table VARCHAR(100) NOT NULL,
  record_id INT(10) UNSIGNED NOT NULL,
  record_data JSON NOT NULL,
  deleted_by INT(10) UNSIGNED NULL,
  deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_original_table (original_table),
  KEY idx_record_id (record_id),
  CONSTRAINT fk_archives_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5. Seed services (if empty)
INSERT INTO services (service_name, description, price)
SELECT * FROM (
  SELECT 'Dental Cleaning (Prophylaxis)', 'Removal of plaque and tartar deposits. Professional cleaning and polishing.', 800.00
  UNION ALL SELECT 'Fluoride Application', 'Preventive fluoride treatment to strengthen tooth enamel.', 500.00
  UNION ALL SELECT 'Composite Filling', 'Tooth-colored restoration for cavities.', 1500.00
  UNION ALL SELECT 'Tooth Extraction', 'Safe removal of compromised teeth.', 1000.00
  UNION ALL SELECT 'Removal of Impacted Molar', 'Surgical removal of impacted wisdom teeth.', 5000.00
  UNION ALL SELECT 'Complete Dentures', 'Full set of removable artificial teeth.', 30000.00
  UNION ALL SELECT 'Partial Dentures', 'Removable partial artificial teeth.', 15000.00
  UNION ALL SELECT 'Dental Crowns', 'Custom-fitted caps for damaged teeth.', 8000.00
  UNION ALL SELECT 'Orthodontic Treatment', 'Braces and alignment correction.', 50000.00
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM services LIMIT 1);

-- 6. Seed dentist: Dr. Ma. Elena B. Retuerna
--    (link to existing super-admin user? no - create standalone dentist linked later; use her own doctor account)
INSERT INTO dentists (user_id, full_name, specialization, phone_number)
SELECT * FROM (
  SELECT NULL AS user_id, 'Dr. Ma. Elena B. Retuerma' AS full_name, 'General Dentistry' AS specialization, '09066636112' AS phone_number
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM dentists LIMIT 1);
