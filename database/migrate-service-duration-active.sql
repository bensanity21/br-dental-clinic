-- BR Dental Clinic: service duration and active flag
-- ============================================================
-- The thesis spec requires each service to carry an estimated
-- duration and an active/inactive status; the public services page
-- must list only active services.
-- ============================================================
USE clinic;

-- estimated duration in minutes
SET @has_duration = (SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'duration_minutes');
SET @sql = IF(@has_duration = 0,
  'ALTER TABLE services ADD COLUMN duration_minutes INT UNSIGNED NOT NULL DEFAULT 60 AFTER price',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- active / inactive status
SET @has_active = (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'is_active');
SET @sql = IF(@has_active = 0,
  'ALTER TABLE services ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER duration_minutes',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Existing rows keep working; give the seeded services realistic durations.
UPDATE services SET duration_minutes = 60 WHERE duration_minutes = 0;
UPDATE services SET duration_minutes = 30 WHERE service_name = 'Fluoride Application';
UPDATE services SET duration_minutes = 45 WHERE service_name = 'Dental Cleaning (Prophylaxis)';
UPDATE services SET duration_minutes = 60 WHERE service_name = 'Composite Filling';
UPDATE services SET duration_minutes = 45 WHERE service_name = 'Tooth Extraction';
UPDATE services SET duration_minutes = 90 WHERE service_name = 'Removal of Impacted Molar';
UPDATE services SET duration_minutes = 90 WHERE service_name = 'Complete Dentures';
UPDATE services SET duration_minutes = 60 WHERE service_name = 'Partial Dentures';
UPDATE services SET duration_minutes = 90 WHERE service_name = 'Dental Crowns';
UPDATE services SET duration_minutes = 60 WHERE service_name = 'Orthodontic Treatment';

-- Index helps the public list filter by status.
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND INDEX_NAME = 'idx_services_active');
SET @sql = IF(@has_idx = 0,
  'CREATE INDEX idx_services_active ON services (is_active, service_name)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
