-- database/migrate-db-sessions.sql
-- Stores PHP sessions in the database instead of files.
--
-- Usage: mysql -u root < database/migrate-db-sessions.sql
-- Reversible: DROP TABLE IF EXISTS `sessions`; (at the bottom, commented out)
--
-- The `id` column holds a SHA-256 hash of the session id, never the raw value
-- from the cookie, so a database dump cannot be replayed as valid sessions.
--
-- Safety: file-based sessions keep working until includes/session.php installs
-- this handler. Applying the migration changes nothing on its own.

USE `clinic`;

CREATE TABLE IF NOT EXISTS `sessions` (
  `id` CHAR(64) NOT NULL COMMENT 'SHA-256 of the PHP session id',
  `data` MEDIUMTEXT DEFAULT NULL COMMENT 'PHP-serialised session payload',
  `last_activity` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'UNIX timestamp of last write',
  PRIMARY KEY (`id`),
  KEY `idx_sessions_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- rollback
-- DROP TABLE IF EXISTS `sessions`;
