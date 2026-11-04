# Setup guide

From nothing to a running clinic system on XAMPP. Roughly 15 minutes.

If you are deploying to a real server instead, read `DEPLOYMENT.md` after this.

---

## 1. Requirements

| Component | Version used | Notes |
|---|---|---|
| XAMPP | 8.2+ | Bundles Apache, MySQL/MariaDB and PHP |
| PHP | 8.3 | Needs `pdo_mysql`, `openssl`, `mbstring` |
| MySQL / MariaDB | 10.4+ | InnoDB, `utf8mb4` |
| Browser | any current | Chrome, Edge, Firefox |

No Composer. No Node build step. No npm.

Check PHP has what it needs:

```bash
php -m | findstr /I "pdo_mysql openssl mbstring"
```

You should see all three.

---

## 2. Start the servers

XAMPP on Windows has no Windows service by default, so start the binaries:

```bash
cd C:\xampp\mysql\bin
mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini
```

```bash
cd C:\xampp\apache\bin
httpd.exe
```

Or just use the XAMPP Control Panel and press **Start** on Apache and MySQL.

> Port 80 in use? Change Apache to 8080 in `httpd.conf`, then use
> `http://localhost:8080/draft%20for%20clinic/` everywhere below.

---

## 3. Import the database

```bash
cd "C:\xampp\htdocs\draft for clinic"
C:\xampp\mysql\bin\mysql.exe -u root < clinic.sql
```

This creates the `clinic` database with 17 tables.

**No passwords are included.** Every seeded account starts with the placeholder
`CHANGE_ME`, which can never be matched by `password_verify()`. You must set a
real password before anyone can sign in — step 5.

Verify:

```bash
C:\xampp\mysql\bin\mysql.exe -u root -e "USE clinic; SHOW TABLES;"
```

Expect 17 tables including `sessions`, `password_resets`, `admin_ip_allowlist`.

---

## 4. Configure the encryption key

The application refuses to start without a 32-byte key. Generate one:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Create `config/secrets.local.php`:

```php
<?php
return [
    'current_key' => 'paste-the-64-hex-characters-here',
];
```

**Back this key up before you enter any patient data.** It is not stored in the
database, and without it every encrypted field (phone, address, date of birth,
emergency contact, clinical notes) is permanently unreadable.

`config/secrets.local.php` is gitignored. Never commit it.

Verify:

```bash
php tools/export-key.php --print
```

---

## 5. Set the account passwords

Three accounts are seeded with no usable password:

| Account | Role |
|---|---|
| `admin@brdental.com` | Super Admin |
| `elena@brdental.com` | Dentist |
| `smith@brdental.com` | Dentist |

```bash
php database/set-password.php admin@brdental.com --generate
php database/set-password.php elena@brdental.com --generate
php database/set-password.php smith@brdental.com --generate
```

Each prints a strong password **once**. Save them, then change them from
Account Settings after signing in.

The tool refuses passwords under 12 characters and refuses the old published
defaults (`Admin123!`, `Dentist123!`).

<details>
<summary>Alternative: browser setup for the first admin</summary>

Open `setup.html`. It only works while the super-admin still has the
`CHANGE_ME` placeholder, and closes permanently once a real password is saved.
It cannot be used to take over a running site.
</details>

---

## 6. Encrypt any pre-existing phone numbers

Skip this on a brand-new install — there is nothing to encrypt yet.

**If you imported an older database** that already has patient phone numbers in
plain text, run this. It is safe and reversible:

```bash
# 1. Preview exactly what would change. Writes nothing.
php tools/encrypt-phones.php --dry-run

# 2. Apply. Takes a backup first, then encrypts row by row.
php tools/encrypt-phones.php
```

What it does:

- Takes a full backup before touching anything, and aborts if that fails.
- Encrypts each number and stores a keyed hash alongside it, so matching by
  phone keeps working.
- Reads each row back and decrypts it before committing. A row that does not
  round-trip is rolled back, never saved.
- Skips rows that are already encrypted, so running it twice is harmless.
- Also re-encrypts the phone numbers inside archive snapshots.

To undo it, restore the backup it names:

```bash
php tools/restore.php --file=backups/backup_clinic_<timestamp>.sql
```

> ### Risk note
> This is the only operation in the project that rewrites patient data in place.
> The risk is a number becoming unreadable or unmatchable. It is mitigated by the
> pre-change backup, the per-row transaction with a read-back check, and
> idempotency. On a system that already holds real patient records, run
> `--dry-run` first, then apply it **outside clinic hours**, and confirm
> afterwards that a known patient can still be found by their phone number.

---

## 7. Open the site

```
http://localhost/draft%20for%20clinic/
```

| Page | Purpose |
|---|---|
| `index.html` | Public landing page |
| `services.html` / `dentists.html` / `about.html` | Public information |
| `login.html` | Sign in |
| `signup.html` | Patient self-registration |
| `user.html` | Patient portal |
| `admin.html` | Staff portal (Super Admin and dentists) |
| `forgot-password.html` | Request a reset link |
| `setup.html` | One-time first-admin setup |

Sign in as the Super Admin with the password from step 5.

---

## 8. Optional: email delivery

Without SMTP, reset emails cannot be delivered. For an offline local setup,
the request page can show a reset link only when `APP_ENV=development` and
`ALLOW_RESET_LINK_IN_RESPONSE=1` are explicitly set. Reset tokens are never
written to the PHP error log. Do not enable this option on a public host.

On local XAMPP, add these SMTP entries inside the existing `return [...]` array
in `config/secrets.local.php` (do not replace its encryption key):

```php
'SMTP_HOST'      => 'smtp.gmail.com',
'SMTP_PORT'      => '465',
'SMTP_SECURE'    => 'tls',
'SMTP_USER'      => 'your.address@gmail.com',
'SMTP_PASS'      => 'your-app-password',
'SMTP_FROM'      => 'your.address@gmail.com',
'SMTP_FROM_NAME' => 'BR Dental Clinic',
```

Gmail needs an **App Password** (Google Account → Security → 2-Step
Verification → App passwords). Your normal password is rejected.

`.env.example` is a reference for environment-variable names; PHP does not
load that file. On a hosted server, set the values through the host's secret or
environment-variable manager instead.

Test it:

```bash
php tools/test-mail.php your.address@gmail.com
```

---

## 9. Optional: automated backups

```bash
php tools/backup.php
php tools/backup.php --list
php tools/backup.php --encrypt
```

The default command writes a plain `.sql` file for compatibility. Use
`--encrypt` for an authenticated `.sql.enc` file; the temporary SQL dump is
removed after encryption. See `LOCAL-BACKUP-RECOVERY.md` before restoring.

Schedule it nightly. In an **Administrator** command prompt:

```bat
schtasks /Create /TN "BR Dental Clinic Backup" ^
  /TR "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\draft for clinic\tools\backup.php\"" ^
  /SC DAILY /ST 20:00 /RU SYSTEM
```

Backups land in `backups/`, which is gitignored and blocked from HTTP.

A backup on the same disk as the database is not a backup. Copy `backups/` and
the exported key to somewhere else.

---

## 10. Confirm everything works

```bash
php tests/run.php                  # latest recorded result: ALL PASSED 175/175
php tests/check-htaccess.php       # expect: File protection OK
php tests/tools/phone-index.php    # expect: ALL PHONE INDEX CHECKS PASSED
php tests/tools/smtp-matrix.php    # only meaningful once SMTP is configured
```

Then in a browser:

1. Sign in as Super Admin → the dashboard loads with counts.
2. Open **Users & Roles** → the seeded accounts are listed.
3. Open **Settings** → clinic name, phone and address are filled from the database.
4. Sign out, sign in as a patient → the patient portal loads.
5. Request a password reset from `forgot-password.html`.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Blank page / 500 | No encryption key | Step 4 |
| "A 32-byte BR Dental encryption key must be configured" | Key missing or wrong length | Step 4 |
| "Invalid email or password" for a seeded account | Password still `CHANGE_ME` | Step 5 |
| Database connection failed | MySQL not running | Step 2 |
| Session expired immediately | Clock skew, or `sessions` table missing | Re-import `clinic.sql` |
| Port 80 in use | Another web server | Use `http://localhost:8080/...` |
| Locked out of admin by the IP allowlist | Empty the table | `DELETE FROM admin_ip_allowlist;` |
| Password reset link not emailed | SMTP not configured | Step 8 |

More detail: `docs/DEPLOYMENT.md`, `docs/SECURITY-FINDINGS.md`,
`docs/ADMIN-IP-ALLOWLIST.md`, `docs/REMAINING-WORK.md`.
