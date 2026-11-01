-- BR Dental Clinic: activity log (audit trail)
-- ============================================================
-- Medical records need an audit trail: who changed what, and when.
-- Every meaningful mutation writes one row here through
-- logActivity() in includes/activity.php.
--
-- Kept deliberately append-only in normal use. The id and created_at
-- columns are indexed for the "newest first" listing.
-- ============================================================
USE clinic;

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Who acted. NULL when the account was later removed.
  `user_id` INT(10) UNSIGNED NULL,
  -- Denormalised so the log stays readable after an account is deleted.
  `user_name` VARCHAR(100) NOT NULL DEFAULT '',
  `user_role` VARCHAR(30) NOT NULL DEFAULT '',
  -- What happened, e.g. 'appointment.status', 'service.create'.
  `action` VARCHAR(60) NOT NULL,
  -- Which table the record belongs to, and its id.
  `entity` VARCHAR(40) NOT NULL DEFAULT '',
  `entity_id` INT(10) UNSIGNED NULL,
  -- Short human-readable description shown in the UI.
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  -- Optional JSON snapshot: {"from": ..., "to": ...} for status changes.
  `details` JSON NULL,
  `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_created` (`created_at`),
  KEY `idx_activity_user` (`user_id`),
  KEY `idx_activity_action` (`action`),
  KEY `idx_activity_entity` (`entity`, `entity_id`),
  CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
