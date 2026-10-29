-- Patient in-app notification preference. Existing patients default to enabled.
-- Safe to run more than once on MySQL and MariaDB.
USE clinic;

CREATE TABLE IF NOT EXISTS patient_notification_preferences (
  patient_id INT(10) UNSIGNED NOT NULL,
  in_app_enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (patient_id),
  CONSTRAINT fk_patient_notification_preferences_user
    FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
