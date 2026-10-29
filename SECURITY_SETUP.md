# Security setup notes

## Local XAMPP

`config/secrets.local.php` is intentionally ignored by Git and denied to direct HTTP requests. Keep a protected backup of it. It contains the new encryption key and, temporarily, the previous key needed to read existing encrypted clinical notes.

Before migrating notes, make a private database backup outside `htdocs`. Then start MySQL and run:

```powershell
C:\xampp\php\php.exe database\migrate-clinical-note-encryption.php
```

The migration is transactional and aborts without committing if any old note cannot be decrypted. After it succeeds and you have verified the clinic can read the notes, remove the `previous_key` entry from `config/secrets.local.php` and securely retain the backup. Do not commit the secret file or database backup.

The SQL dump no longer creates public sample accounts. After importing it into a fresh local database, create the first administrator from PowerShell in the project directory with `C:\xampp\php\php.exe database\bootstrap-first-admin.php`. The console password prompt is visible while typing; choose a private terminal. Delete the bootstrap script after creating the first account.

## Production

Set `APP_ENV=production`, `BR_DENTAL_ENCRYPTION_KEY`, `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` in the server's protected environment. Use a dedicated MySQL user limited to this database; do not use MySQL `root`. Serve the site over HTTPS. Never put the production secrets file under the public web root.

The old encryption key was present in earlier source history. After all existing notes are migrated, it must remain retired; removing it from current files does not erase it from prior Git commits.
