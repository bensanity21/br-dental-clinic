-- ============================================================
-- database/migrate-app-db-user.sql
-- ============================================================
-- Creates a least-privilege MySQL account for the application and stops
-- the app from connecting as `root`.
--
-- WHY
--   The app shipped connecting as root with an empty password. On a shared
--   host or VPS that is the highest-value mistake in the build: root can read
--   every schema on the server, and any single injection or filename bug
--   becomes total compromise rather than damage confined to one database.
--
-- WHAT THIS GRANTS
--   SELECT, INSERT, UPDATE, DELETE on the `clinic` schema only.
--   Nothing else: no DDL, no DROP, no GRANT, no access to other schemas.
--   The application needs no runtime DDL (verified: no CREATE/ALTER/DROP in
--   api/, includes/ or connectdb.php), so DML is sufficient.
--
-- PASSWORD
--   Not in this file. Supply it at run time:
--     mysql -u root < database/migrate-app-db-user.sql          -- prompts? no
--   Instead use tools/create-db-user.php, which generates a strong password,
--   applies it, writes config/secrets.local.php, and verifies the connection.
--   This .sql file is the reference definition for manual/hosted setups.
--
-- REVERSIBLE
--   To undo: DROP USER 'br_dental_app'@'localhost';
--   The app falls back to reading config/secrets.local.php, so removing the
--   user without removing the credentials file produces a connection error,
--   not a silent root login.
-- ============================================================

-- Replace CHANGE_ME_WITH_A_GENERATED_PASSWORD before running by hand.
-- tools/create-db-user.php does this automatically.

CREATE USER IF NOT EXISTS 'br_dental_app'@'localhost'
    IDENTIFIED BY 'CHANGE_ME_WITH_A_GENERATED_PASSWORD';

CREATE USER IF NOT EXISTS 'br_dental_app'@'127.0.0.1'
    IDENTIFIED BY 'CHANGE_ME_WITH_A_GENERATED_PASSWORD';

GRANT SELECT, INSERT, UPDATE, DELETE ON `clinic`.* TO 'br_dental_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `clinic`.* TO 'br_dental_app'@'127.0.0.1';

-- Sessions and login attempts are pruned with DELETE, so no TRIGGER/EXECUTE
-- privileges are needed. Deliberately not granting:
--   *.*             (wildcard access across every schema)
--   GRANT OPTION    (lets the account create more accounts)
--   CREATE/DROP     (would let an injection reshape the schema)
--   FILE            (would let an injection read or write server files)

FLUSH PRIVILEGES;
