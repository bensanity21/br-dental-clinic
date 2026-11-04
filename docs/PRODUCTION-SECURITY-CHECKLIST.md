# Production security setup

Use this checklist before the site accepts real patient data. Do not commit
passwords, encryption keys, SMTP credentials, or populated environment files.
`.env.example` is a reference only; PHP does not load it automatically.

## 1. Rotate exposed account passwords

The accounts listed in `LAUNCH-CHECKLIST.md` were reported to accept passwords
that appeared in Git history. Check the current database first, then rotate
each affected account before exposing the site to the internet:

```powershell
Set-Location -LiteralPath 'C:\xampp\htdocs\draft for clinic'
php tools/check-exposed.php
```

This read-only command prints account email addresses in the local terminal.
Keep its output private; if you need help, share only the number of affected
accounts, not the email list.

For each account reported as exposed, run the command below. The CLI prompts
for the new password without echoing it:

```powershell
php database/set-password.php admin@brdental.com
```

Repeat for each affected account. Use a unique password of at least 12
characters and save it directly into the clinic's password manager. This
invalidates existing sessions for the changed account. Then verify:

```powershell
php tools/check-exposed.php
```

Proceed only when it reports zero accounts still accepting a published
password. Do not put new passwords in a command argument, script, chat, or Git.

## 2. Create the least-privilege MySQL account

The web application needs only `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on
the `clinic` schema. Do this using the database administrator account in the
host's database console. Replace the password placeholder with a newly
generated secret; do not run this SQL unchanged:

```sql
CREATE USER 'br_dental_app'@'localhost' IDENTIFIED BY 'REPLACE_WITH_A_GENERATED_SECRET';
GRANT SELECT, INSERT, UPDATE, DELETE ON `clinic`.* TO 'br_dental_app'@'localhost';
SHOW GRANTS FOR 'br_dental_app'@'localhost';
```

For a database on a separate host, use the host pattern required by the
provider, restricted to the application server's address when possible. Do
not grant `DROP`, `ALTER`, `CREATE`, `FILE`, `GRANT OPTION`, or global `*.*`
access to the application account. Run schema migrations separately with an
administrator account.

The local helper `php tools/create-db-user.php` generates a strong password,
applies these four grants, verifies them, and writes local credentials to the
gitignored `config/secrets.local.php`. On hosted production, store the account
credentials in the provider's secret/environment-variable manager instead.

## 3. Configure production environment variables

Set these in the hosting panel, service manager, or PHP-FPM/Apache environment
configuration. Do not put real values in `.env.example`, `.htaccess`, or a
tracked file:

```text
APP_ENV=production
APP_BASE_URL=https://your-real-clinic-domain.example
DB_HOST=127.0.0.1
DB_NAME=clinic
DB_USER=br_dental_app
DB_PASSWORD=<generated database password>
BR_DENTAL_ENCRYPTION_KEY=<64 hexadecimal characters>
SMTP_HOST=<provider SMTP host>
SMTP_PORT=465
SMTP_SECURE=tls
SMTP_USER=<clinic mailbox>
SMTP_PASS=<mail provider secret or app password>
SMTP_FROM=<clinic mailbox>
SMTP_FROM_NAME=BR Dental Clinic
```

Generate a new 32-byte encryption key for a new installation:

```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Store it in the secret manager as `BR_DENTAL_ENCRYPTION_KEY`. Keep a protected
offline copy separate from the server and database backups. If restoring
existing encrypted records, use the key that encrypted those records; do not
replace it with a new key. For planned rotation, set the former key as
`BR_DENTAL_ENCRYPTION_KEY_PREVIOUS` and follow the key-rotation procedure.

Only set `TRUST_PROXY_HEADERS=1` when a trusted reverse proxy strips any
client-supplied `X-Forwarded-Proto` and writes its own value. Never set
`ALLOW_RESET_LINK_IN_RESPONSE` on a public or production server.

Configure all required variables before setting `APP_ENV=production`; the app
refuses startup if the database credentials, canonical HTTPS URL, or encryption
key are missing. Restart PHP/Apache after changing service-level variables.

## 4. Require HTTPS

Install and verify the TLS certificate first. On Apache, configure the port 80
virtual host to redirect to the canonical HTTPS host:

```apache
<VirtualHost *:80>
    ServerName your-real-clinic-domain.example
    Redirect permanent / https://your-real-clinic-domain.example/
</VirtualHost>
```

After HTTPS and the redirect work, confirm the application sends HSTS on the
HTTPS response. This repository's `.htaccess` sends HSTS only when Apache
detects HTTPS. If TLS ends at a reverse proxy, configure HSTS at that proxy and
follow its trusted-proxy guidance. Do not enable HSTS until the HTTPS hostname
is stable and valid.

## 5. Verify the live configuration

- [ ] Rotate affected account passwords; `php tools/check-exposed.php` reports 0.
- [ ] Application connects as the dedicated database user, never `root`.
- [ ] `SHOW GRANTS` shows only the four required privileges on `clinic`.
- [ ] Production variables are set in the host's secret manager; no secret is in Git.
- [ ] `APP_BASE_URL` is the canonical HTTPS URL and has no query or fragment.
- [ ] HTTP redirects to HTTPS; session cookies have `Secure`, `HttpOnly`, and `SameSite=Lax`.
- [ ] SMTP test succeeds and a reset message arrives at a real inbox.
- [ ] `expose_php=Off`, `display_errors=Off`, and `log_errors=On` on the host.
- [ ] Server blocks `.git`, database dumps, `config`, `database`, `tools`, `docs`, and backups.
- [ ] HSTS is present on HTTPS responses only after HTTPS was confirmed.

Do not begin real-patient use until every item is complete. Continue with the
separate backup and recovery procedure in `LAUNCH-CHECKLIST.md` and
`DEPLOYMENT.md` before considering the system operationally ready.
