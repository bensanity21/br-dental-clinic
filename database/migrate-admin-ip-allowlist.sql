-- database/migrate-admin-ip-allowlist.sql
-- Optional IP allowlist for administrator sign-in.
--
-- Usage: mysql -u root < database/migrate-admin-ip-allowlist.sql
-- Reversible: DROP TABLE IF EXISTS `admin_ip_allowlist`; (at the bottom)
--
-- The feature is OFF while this table is empty. Adding the first active row
-- turns it on. Emptying the table turns it off again, which is the escape
-- hatch if an administrator ever locks themselves out.

USE `clinic`;

CREATE TABLE IF NOT EXISTS `admin_ip_allowlist` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(64) NOT NULL COMMENT 'Single address or CIDR block, e.g. 203.0.113.7 or 203.0.113.0/24',
  `label` varchar(100) DEFAULT NULL COMMENT 'Who or where this entry is for',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- rollback
-- DROP TABLE IF EXISTS `admin_ip_allowlist`;
