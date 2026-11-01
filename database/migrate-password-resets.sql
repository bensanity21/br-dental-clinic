-- database/migrate-password-resets.sql
-- Adds single-use, time-limited password reset tokens.
--
-- Usage: mysql -u root < database/migrate-password-resets.sql
-- Reversible: see the DROP statement at the bottom, commented out.
--
-- Design notes:
--  * Only a SHA-256 hash of the token is stored. A leaked database therefore
--    cannot be used to reset anybody's password.
--  * used_at enforces single use; expires_at enforces the time limit.
--  * user_id is not a foreign key with ON DELETE CASCADE on purpose - deleting
--    a user must not silently erase the audit trail of reset attempts.

USE `clinic`;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 of the emailed token, never the token itself',
  `requested_by_ip` VARCHAR(45) DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_password_resets_token` (`token_hash`),
  KEY `idx_password_resets_user` (`user_id`, `created_at`),
  KEY `idx_password_resets_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Throttle: how many reset requests one email+IP may make per window.
-- Reuses login_attempts with the pseudo-email 'reset', mirroring how signup
-- already does it, so no extra table is needed.

-- ---------------------------------------------------------------- rollback
-- DROP TABLE IF EXISTS `password_resets`;
