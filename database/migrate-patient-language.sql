-- Add a per-account patient portal language preference; safe to run repeatedly.
USE clinic;

SET @has_language_preference = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'language_preference'
);
SET @language_preference_sql = IF(
  @has_language_preference = 0,
  'ALTER TABLE users ADD COLUMN language_preference VARCHAR(5) NOT NULL DEFAULT ''en'' AFTER phone_number',
  'SELECT 1'
);
PREPARE language_preference_stmt FROM @language_preference_sql;
EXECUTE language_preference_stmt;
DEALLOCATE PREPARE language_preference_stmt;
