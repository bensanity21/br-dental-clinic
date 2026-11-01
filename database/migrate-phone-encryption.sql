-- database/migrate-phone-encryption.sql
-- Encrypt patient phone numbers while keeping them searchable.
--
-- Usage:
--   mysql -u root < database/migrate-phone-encryption.sql          (schema only)
--   php tools/encrypt-phones.php --dry-run                          (preview rows)
--   php tools/encrypt-phones.php                                    (encrypt rows)
--
-- This file changes STRUCTURE ONLY. It adds two nullable columns and never
-- modifies or removes an existing value. No patient data is touched by running
-- it, so it is safe to apply before, during or after a working day.
--
-- Rollback (only safe BEFORE tools/encrypt-phones.php has run):
--   ALTER TABLE `users` DROP COLUMN `phone_number_hash`;
--   ALTER TABLE `dental_records` DROP COLUMN `patient_contact_index`;
-- After the data tool has run, `phone_number` holds ciphertext, so dropping the
-- hash column would make those numbers unmatchable. Keep both.

USE `clinic`;

-- ---------------------------------------------------------------- users
-- phone_number keeps its name and type. It will hold "v2:<ciphertext>" instead
-- of plain text once tools/encrypt-phones.php runs. varchar(20) is too small
-- for ciphertext of a 20-character number, so widen it first.
ALTER TABLE `users`
  MODIFY COLUMN `phone_number` VARCHAR(255) DEFAULT NULL
    COMMENT 'AES-256-GCM ciphertext (v2:...) or legacy plain text';

-- Deterministic HMAC-SHA256 of the normalised number, used only for equality
-- matching. Never displayed.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `phone_number_hash` CHAR(64) DEFAULT NULL
    COMMENT 'HMAC-SHA256 blind index for phone_number; matching only' AFTER `phone_number`;

ALTER TABLE `users`
  ADD KEY IF NOT EXISTS `idx_users_phone_hash` (`phone_number_hash`);

-- --------------------------------------------------------- dental_records
-- The contact captured against a clinical record is also personal data and was
-- stored in plain text. Same treatment: wide column for ciphertext, plus an
-- index column so a record can still be matched to an account by phone.
ALTER TABLE `dental_records`
  MODIFY COLUMN `patient_contact` VARCHAR(255) DEFAULT NULL
    COMMENT 'AES-256-GCM ciphertext (v2:...) or legacy plain text';

ALTER TABLE `dental_records`
  ADD COLUMN IF NOT EXISTS `patient_contact_index` CHAR(64) DEFAULT NULL
    COMMENT 'HMAC-SHA256 blind index for patient_contact; matching only' AFTER `patient_contact`;

ALTER TABLE `dental_records`
  ADD KEY IF NOT EXISTS `idx_records_contact_index` (`patient_contact_index`);

-- ---------------------------------------------------------------- rollback
-- Kept commented so it is never applied by an accident.
--
-- ALTER TABLE `users` DROP KEY `idx_users_phone_hash`;
-- ALTER TABLE `users` DROP COLUMN `phone_number_hash`;
-- ALTER TABLE `dental_records` DROP KEY `idx_records_contact_index`;
-- ALTER TABLE `dental_records` DROP COLUMN `patient_contact_index`;
