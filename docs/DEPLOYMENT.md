# Deployment guide

How to take this project from XAMPP to a live server, and what must change first.

---

## 1. Set passwords (there are none in the database file)

`clinic.sql` deliberately contains **no usable passwords**. Every seeded account
has the placeholder `CHANGE_ME`, which `password_verify()` can never match.

See `SETUP.md` steps 3-5 for the full sequence. In short:

```bash
php -r "echo bin2hex(random_bytes(32));"          # 1. encryption key -> config/secrets.local.php
php database/set-password.php admin@brdental.com --generate
php database/set-password.php elena@brdental.com --generate
php database/set-password.php smith@brdental.com --generate
```

Each prints a strong password once, saves the hash, and signs out any existing
session for that account.

> The browser alternative is `setup.html`, which works **only** while the
> administrator still has the `CHANGE_ME` placeholder and closes permanently
> the first time a real password is saved.

---

## 1a. Phone numbers are encrypted

Patient phone numbers are stored as AES-256-GCM ciphertext, with a keyed
HMAC-SHA256 blind index in `phone_number_hash` so staff can still find a
patient by name + phone when adding a clinical record.

Nothing to configure — it is automatic on every write path. If you import an
older database that holds plain-text numbers, run:

```bash
php tools/encrypt-phones.php --dry-run   # preview
php tools/encrypt-phones.php             # apply (takes a backup first)
```

The index is derived from the encryption key. **If you change
`current_key` without keeping the old one as `previous_key`, existing blind
indexes stop matching** and patients can no longer be found by phone. Rotate
keys by adding `previous_key`, not by replacing it outright.

---

## 2. Configure mail (password reset delivery)

Without mail, reset emails cannot be delivered. No token is written to the PHP
error log. A local offline setup can explicitly show the link in the request
page with `APP_ENV=development` and `ALLOW_RESET_LINK_IN_RESPONSE=1`; never
enable that option on a public host.

```bash
cp .env.example config/secrets.local.php
```

Fill in the `SMTP_*` values, then test:

```bash
php tools/test-mail.php you@example.com
```

Sample Gmail settings:

| Setting | Value |
|---|---|
| `SMTP_HOST` | `smtp.gmail.com` |
| `SMTP_PORT` | `465` |
| `SMTP_SECURE` | `tls` |
| `SMTP_USER` | your Gmail address |
| `SMTP_PASS` | an **App Password**, not your login password |
| `SMTP_FROM` | the same Gmail address |

Gmail requires 2-Step Verification before App Passwords are available.

The reset API never returns the link to the browser by default. For a local,
offline development instance only, set `APP_ENV=development` and
`ALLOW_RESET_LINK_IN_RESPONSE=1`; this is ignored if SMTP is configured and
must never be set on a public host. Reset tokens use a URL fragment (not sent
to the web server), are removed from the address bar after the page reads
them, and the reset page is marked no-referrer and no-store.

---

## 3. Set the environment to production

On the live host, set these as real environment variables (most panels have an
"Environment Variables" section):

```
APP_ENV=production
APP_BASE_URL=https://your-clinic-domain.example
DB_HOST=...
DB_NAME=...
DB_USER=...
DB_PASSWORD=...
```

`APP_ENV=production` does three things:

1. **Refuses to start** if `DB_USER`/`DB_PASSWORD` are missing, instead of
   silently falling back to `root` with no password.
2. Stops the reset endpoint from ever returning the link in the response.
3. Stops PHP notices from being shown.
4. Forces session cookies to use the `Secure` flag, so they are never sent over
   plain HTTP. Configure HTTPS and an HTTP-to-HTTPS redirect before enabling
   production mode.
5. Requires `APP_BASE_URL` to be the canonical HTTPS URL, preventing reset
   links from being built from an untrusted Host header.

If HTTPS terminates at a reverse proxy and Apache receives plain HTTP, keep
`TRUST_PROXY_HEADERS` unset unless that proxy always overwrites
`X-Forwarded-Proto`. When it does, set `TRUST_PROXY_HEADERS=1` so PHP can
recognize HTTPS for development-mode sessions. Production cookies are Secure
regardless. Never enable this setting when clients can supply the forwarded
header directly.

> Verified: `APP_ENV=production` with no DB credentials returns a fatal error
> naming the missing configuration rather than connecting as root.

---

## 4. Database user

Never deploy as `root`. Create a dedicated account limited to this schema:

```sql
CREATE USER 'br_clinic'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinic.* TO 'br_clinic'@'localhost';
FLUSH PRIVILEGES;
```

Note there is no `DROP` or `ALTER` in that grant. Schema changes should be run
deliberately with the migrations in `database/`, not by the web application.

---

## 5. Encryption key

`config/config.php` reads the AES-256 key from `config/secrets.local.php`
(gitignored) or from an `ENCRYPTION_KEY` environment variable.

**On a new deployment, generate a new key rather than reusing this one:**

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Put it in `config/secrets.local.php` as `current_key`. Move any key you are
replacing to `previous_key` so existing rows still decrypt.

If you lose the key, encrypted patient fields (phone, address, birthdate,
medical history, allergies, clinical notes) are **unrecoverable**. Back it up
somewhere safe and separate from the database.

---

## 6. HTTPS

The session cookie sets `secure` automatically when the request is HTTPS, but
HTTPS must be terminated by the web server or proxy.

Minimum on Apache:

- A certificate (Let's Encrypt is fine)
- `Redirect permanent / https://your-domain/` for port 80
- `Header always set Strict-Transport-Security "max-age=31536000"` once HTTPS works

> Note: `Strict-Transport-Security` is **not** currently set in `.htaccess`,
> because sending it over plain HTTP on a development machine can poison a
> browser for the whole domain. Add it only after HTTPS is confirmed working.

---

## 7. Server configuration checklist

These are PHP/Apache settings, not application code:

| Setting | Why | Where |
|---|---|---|
| `expose_php = Off` | Stops `X-Powered-By: PHP/8.3.36` advertising the version | `php.ini` |
| `display_errors = Off` | Errors should never render into a JSON response | `php.ini` |
| `log_errors = On` | Keep the detail, in the log only | `php.ini` |
| `session.cookie_secure = 1` | Belt-and-braces with the app-level flag | `php.ini` |
| `ServerTokens Prod` | Hides the Apache version banner | `httpd.conf` |

> Currently `expose_php` is **On** in this XAMPP install. It leaks only the PHP
> version, but turn it off on the live host.

---

## 8. Files that must not be reachable

`.htaccess` already blocks them, and `php tests/check-htaccess.php` proves it:

- `clinic.sql`, any `.sql`
- `config/secrets.local.php`
- `.env` and `.env.example`
- `database/`, `tools/`, `docs/` directories
- `.gitignore`, `.md` and `.txt` files

If the host is **nginx** or another server that ignores `.htaccess`, these rules
do not apply. Recreate them in the server config, for example:

```nginx
location ~* \.(sql|env|log|bak|md|txt|example)$ { deny all; }
location ^~ /database/ { deny all; }
location ^~ /tools/    { deny all; }
location ^~ /docs/     { deny all; }
location ^~ /config/   { deny all; }
```

**This is the single biggest deployment risk if the host is not Apache.**

---

## 9. Before going live

- [ ] `clinic.sql` imported, then a real password set for every staff account
- [ ] `APP_ENV=production` and DB credentials in environment variables
- [ ] Dedicated database user, not `root`
- [ ] A new `ENCRYPTION_KEY`, backed up offline
- [ ] SMTP configured and `php tools/test-mail.php` succeeds
- [ ] HTTPS working, HTTP redirects, HSTS added
- [ ] `php tests/check-htaccess.php` all green
- [ ] `php tests/run.php` all green
- [ ] `expose_php = Off`, `display_errors = Off`
- [ ] `.git` directory not uploaded (or blocked by the server config)
- [ ] Decide whether the IP allowlist is on (see `docs/ADMIN-IP-ALLOWLIST.md`)

---

## 10. What is still not production-grade

Stated plainly, because these matter more than a checklist:

1. **No backup strategy.** Nothing here backs up the database or the encryption
   key. A clinic losing patient records is a serious event. Use `mysqldump` on a
   schedule and keep copies off the server.
2. **No CSRF rotation.** The token is per-session and never rotated. Acceptable
   with `SameSite=Lax` and the existing checks, but not ideal.
3. **Single-server sessions.** Sessions live in MySQL, which works across
   multiple web servers only if they share that database.
4. **No automated test run on deploy.** `tests/run.php` is manual. Wire it into a
   deploy step if you want a real safety net.
5. **Email is fire-and-forget.** A failed send is logged, not retried or queued.
6. **The PHP built-in server must not be used in production.** Only Apache or
   nginx.
