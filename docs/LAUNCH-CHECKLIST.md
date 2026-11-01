# Launch checklist

Two phases: things that must happen before the site holds real patient data
(the password rotation and the key move), and things that depend on where you
host it.

Nothing here is automated for you. **Every step that touches a real account or
real patient data is performed by you, through the interface named in the step.**
The project tools are read-only or verifiable-by-design wherever possible.

---

# Phase 1 — rotate every exposed password

**Why:** four live accounts still accept passwords that are committed to Git
history. This was verified by testing each published password against each
account, not assumed.

| Account | Role | Password in history |
|---|---|---|
| `admin@brdental.com` | Super Admin | published |
| `elena@brdental.com` | Doctor | published |
| `smith@brdental.com` | Doctor | published |
| `cagurol2benedict1@gmail.com` | Patient | published |

`jane@test.com` and `jhayron@gmail.com` are not affected.

### The safest rotation method

Two options. Pick by how much you trust the machine you are typing on.

**Option A — command line (recommended, nothing appears on screen)**

```bash
cd "C:\xampp\htdocs\draft for clinic"
php database/set-password.php admin@brdental.com
```

You are prompted twice with hidden input. The password is never echoed, never
printed, never logged. Use your own password, at least 12 characters.

**Option B — in the portal (convenient, 8 characters minimum)**

Sign in as that user, open the profile card, use **Change Password**. It verifies
the current password first.

> The portal only requires 8 characters while the CLI requires 12. For the Super
> Admin account, use Option A.

**Do not use `--generate` unless you are ready to paste the result straight into
a password manager.** It prints the password once to the terminal, which defeats
the point if your terminal is being recorded or is in scrollback.

### What each rotation does, and why it is safe

- Replaces `password_hash` with a bcrypt hash of your new password.
- **Terminates every other active session for that account**
  (`DELETE FROM sessions`), so anyone already signed in with the old password is
  logged out immediately. The current session is also invalidated, so you sign in
  again.
- Does not touch any other table, any other account, or any patient record.
- Refuses passwords under 12 characters and refuses the known defaults.

### Order matters

Rotate the Super Admin **first**, then the two dentists, then the patient.

Rotating a doctor's password does not affect their clinic data — appointments and
clinical records reference the dentist row, not the password.

### Verify the old passwords are dead

After each rotation, run this. It is read-only and prints no password:

```bash
php tools/check-exposed.php
```

You will see one line per account: `EXPOSED` or `ok`. Expect
`accounts still accepting a published password : 0`.

Then confirm in a browser: sign out, try the old password at
`http://localhost/draft%20for%20clinic/login.html`. It must be rejected.

### What could go wrong

| Problem | Cause | Fix |
|---|---|---|
| "No account with that email" | typo in the address | copy the address from Users & Roles |
| "Use at least 12 characters" | too short | use a longer password |
| "known default and is refused" | contains `admin`, `password`, `changeme` | pick something unrelated |
| Locked out after rotating | old password rejected as expected | sign in with the new one |
| Forgot what you just set | no copy saved | `php database/set-password.php <email>` again |

### ⚠️ If you rotate and then lose the password

There is no self-service recovery if SMTP is not configured. Recovery is
`php database/set-password.php <email>` from a terminal on that machine. That is
acceptable for a single-operator clinic system; it is not acceptable if staff
must self-recover. If that matters, configure SMTP (Phase 3).

---

# Phase 2 — move the encryption key off the database machine

**Why:** the key exists in exactly one place, on the same disk as MySQL and the
backups. A single disk failure loses the database, the backups, and the key.
Restored data is then permanently unreadable — verified: decryption with the
wrong key hard-fails.

### Step 2.1 — establish a baseline first

```bash
php tools/check-key.php
```

Expect `5 of 5 encrypted values decrypted` and `VERIFIED`. Write that number
down. It is your reference point for every later check.

### Step 2.2 — export the key

```bash
php tools/export-key.php
```

It writes `backups/encryption-key_<timestamp>.txt` and prints the path. The file
contains the active key and the retired one.

### Step 2.3 — copy it to the offline location

Your choice of medium. Copy the **file**, do not open and retype it.

- USB drive, unplugged after copying
- Password manager, as a secure note
- Printed and sealed in an envelope, if you prefer paper

### Step 2.4 — prove the copy is correct before deleting anything

```bash
php tools/verify-key-backup.php E:\encryption-key_2026-11-01_125814.txt
```

Point it at the copy, not the original. Expect:

```
Contains the ACTIVE key   : YES
VERIFIED - this file holds the key that decrypts the current database.
```

This reads both files and compares them without printing either key.

### Step 2.5 — delete the local copy

Only after 2.4 says VERIFIED:

```bash
del backups\encryption-key_*.txt
```

The key remains in `config/secrets.local.php`, which is where the application
reads it. What you removed is the loose exported copy sitting next to the
database backups.

### Step 2.6 — confirm the app still works

```bash
php tools/check-key.php
```

Still `VERIFIED`, same count as 2.1. Then sign in and open a patient profile —
phone and address must still display.

### Step 2.7 — prove you can restore AND decrypt

This is the step people skip, and it is the only one that actually proves the
key file is useful.

```bash
php tools/backup.php
```

Then restore into a **separate database** so live data is untouched. Ask me and I
will give you the exact commands for your setup, or:

```bash
# 1. find the newest backup
php tools/backup.php --list
```

```bash
# 2. restore it into a scratch database (NOT clinic)
#    ask for these commands tailored to your shell
```

The rule: **never test a restore by overwriting the live database.** Restore to a
copy, then verify:

```bash
DB_NAME=clinic_restoretest php tools/check-key.php
```

Same count as 2.1 means the backup and the key together are sufficient. Only then
delete the scratch database.

> This exact cycle was rehearsed on 2026-11-01: restored a backup into a separate
> database, then decrypted — 5 of 5 values recovered. The procedure works. Yours
> re-proves it on your machine.

### What could go wrong

| Problem | Cause | Fix |
|---|---|---|
| `MISMATCH` from verify-key-backup | wrong file, or truncated copy | re-copy; do not delete the original yet |
| `File not found` | drive not mounted | plug it in, retry |
| `check-key` shows PARTIAL | key rotated without `previous_key` | add the old key as `previous_key` |
| Restored data shows blanks | key on the new machine is not the same | copy the verified key file across |
| Lost the key entirely | no offline copy | **unrecoverable.** Every encrypted field is gone |

That last row is the whole reason this phase exists.

---

# Phase 3 — email delivery (only if real patients will use it)

Skip entirely for a local demo: reset links go to the PHP error log and show on
the request page.

For real use you need an SMTP account. Gmail requires 2-Step Verification, then
an **App Password** — your normal password will be rejected.

```bash
copy .env.example config\secrets.local.php
```

Edit that file on your own machine. Add `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`,
`SMTP_PASS`, `SMTP_FROM`. **Do not paste these into any chat.**

```bash
php tools/test-mail.php your.address@gmail.com
```

Expect `SUCCESS`, then check the actual inbox.

---

# Phase 4 — depends on where you host

You left the hosting field blank, so both branches are below. Tell me which one
and I will cut this down to the applicable half.

## If you run it on your own computer only

| Step | Needed | Verify | Risk if skipped |
|---|---|---|---|
| Keep Apache + MySQL running | XAMPP Control Panel | site loads | nothing works |
| Schedule nightly backup | Task Scheduler | `backups/` grows | data loss on disk failure |
| Copy backups OFF this machine | USB / cloud | file exists elsewhere | disk failure = everything gone |
| Keep the key on separate media | already done in Phase 2 | verify-key-backup says YES | same |
| Rotate passwords | Phase 1 | check-exposed says 0 | **published admin password** |

**Not applicable locally:** HTTPS, HSTS, dedicated DB user, nginx rules,
`expose_php`. Change them anyway when you deploy.

## If you deploy to shared hosting or a VPS

| # | Step | You need | Verify | Risk if skipped |
|---|---|---|---|---|
| 4.1 | `APP_ENV=production`, DB creds as env vars | hosting panel | app loads, no fatal | runs as `root`, no password |
| 4.2 | Dedicated MySQL user | cPanel/panel | app connects | one SQL bug compromises the server |
| 4.3 | **Recreate `.htaccess` rules if not Apache** | host type | `clinic.sql` → 403 | **full schema downloadable** |
| 4.4 | HTTPS + HSTS | domain + cert | padlock, HTTP redirects | patient data in cleartext |
| 4.5 | `expose_php=Off`, `display_errors=Off` | php.ini | no `X-Powered-By` | version leak, error spill |
| 4.6 | Restore drill on the server | SSH/panel | `check-key` VERIFIED | backup never proven |
| 4.7 | Off-server backups | S3/rsync/panel | file exists off the box | disk failure = total loss |

**4.3 is the highest-risk item.** Every file-protection rule in this project is
`.htaccess`. On nginx or a host that ignores it, those rules do nothing and
`clinic.sql` — the entire schema plus seed data — is downloadable.

---

# What "ready" means

**Verified by execution on 2026-11-01:**

- 173/173 API assertions · 29/29 phone-index checks · full PHP lint
- 10 sensitive paths blocked, 12 app routes reachable
- Role isolation, CSRF, session handling, password reset, rate limiting
- Phone encryption round-trips; matching survives reformatting; key-rotation repair
- Backup restores byte-identically across ten tables
- A restored copy decrypts with the exported key: 5 of 5
- Key file verification rejects a wrong key
- The database backup provably does not contain the key

**Not verified — no claim made:**

1. Email to a real inbox. TLS handshakes to Gmail on 465 and 587 succeed;
   delivery does not.
2. Any non-Apache host.
3. Restore on a different physical machine.
4. Load or concurrency.
5. Encryption at thousands of rows (exercised on 2).

**So:** the code is ready. **Your instance is not**, until Phase 1 is done —
because four accounts still accept published passwords — and Phase 2 is done —
because the key lives on one disk.

---

# Two facts still needed

1. **Is `bensanity21/br-dental-clinic` public?** Phase 1 is urgent if yes,
   housekeeping if no.
2. **Local, shared hosting, or VPS?** Cuts Phase 4 in half.

Answer those in chat — no credentials, no keys.
