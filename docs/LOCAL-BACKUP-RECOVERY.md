# Local XAMPP backup and restore drill

This procedure is for the local XAMPP installation only. Do not run a restore
against `clinic` unless you intentionally mean to replace that database.

## Create an encrypted backup

Open Command Prompt or PowerShell in the project folder and run:

```bat
C:\xampp\php\php.exe tools\backup.php --encrypt --no-prune
C:\xampp\php\php.exe tools\backup.php --list
```

The encrypted file uses AES-256-GCM with authenticated chunks. Its backup key
is derived from the configured current encryption key (or the previous key for
decryption during a key rotation). Keep the key in `config/secrets.local.php`
and store a separately protected recovery copy outside this computer. The key
is not included in the backup. Never commit `.sql`, `.sql.enc`, or key files.

## Restore into a separate test database

1. Start Apache and MySQL in the XAMPP Control Panel.
2. In phpMyAdmin, create a new empty database named `clinic_restoretest` using
   `utf8mb4_general_ci`. Do not select or drop `clinic`.
3. Ensure the credentials used by `tools/restore.php` can dump and import that
   database. The normal app account may not have DDL privileges. For a local
   drill only, set `RESTORE_DB_USER` and `RESTORE_DB_PASSWORD` in the process
   environment (or add `restore_db_user` and `restore_db_password` to the
   ignored `config/secrets.local.php`). Use a local restore operator with the
   needed privileges; never commit these values.
4. Find the backup filename from `php tools\backup.php --list`, then preview:

```bat
C:\xampp\php\php.exe tools\restore.php --file=backups\backup_clinic_YYYY-MM-DD_HHMMSS.sql.enc --target=clinic_restoretest --dry-run
```

5. If the preview names the encrypted backup and `clinic_restoretest`, run the
   same command without `--dry-run`. Type exactly `clinic_restoretest` when
   prompted. The tool first writes a safety dump of the target database.
6. In phpMyAdmin, inspect `clinic_restoretest` tables and expected test data.
   You can point a separate local app config at this database to check that
   encrypted clinical fields decrypt with the configured key.
7. After the drill, remove the temporary database in phpMyAdmin if you no
   longer need it. Keep or securely delete the safety dump according to your
   local retention policy.

Restore imports replace matching tables in the selected target database.
Encrypted backups are decrypted to a temporary SQL file in `backups/` only for
the duration of the restore; the tool removes that temporary file on normal
completion, cancellation, or reported failure. If PHP is force-terminated,
inspect `backups/` and remove any `restore_*.sql.tmp` file after ensuring no
restore process is still running.

## Legacy plain SQL backups

The existing `php tools/backup.php` command still creates a plaintext `.sql`
file for compatibility. Prefer `--encrypt` for new backups and treat legacy
SQL files as sensitive data because they contain patient data and password
hashes.
