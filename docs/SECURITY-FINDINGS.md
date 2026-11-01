# Security review - verified findings

Everything below was tested on 2026-11-01 against the running XAMPP install.
Confirmed problems are separated from optional improvements, and anything I
could not verify is marked as unverified.

---

## Resolved since the first review

### `users.phone_number` was stored in plain text — FIXED

Both phone columns now hold AES-256-GCM ciphertext, with a keyed blind index for
matching:

| Column | Before | Now |
|---|---|---|
| `users.phone_number` | plain text | `v2:<ciphertext>` |
| `users.phone_number_hash` | — | HMAC-SHA256 blind index (new) |
| `dental_records.patient_contact` | plain text | `v2:<ciphertext>` |
| `dental_records.patient_contact_index` | — | HMAC-SHA256 blind index (new) |
| `archives.record_data` (JSON snapshot) | plain text | ciphertext + index |

Implementation: `includes/phone.php`. Migration:
`database/migrate-phone-encryption.sql`. Data tool: `tools/encrypt-phones.php`.

**Why a blind index rather than plain encryption.** Encryption uses a fresh
random nonce per save, so the same number produces different ciphertext each
time and `WHERE phone_number = ?` would never match again. Patient linking in
`api/records/add.php` depends on that equality. `phone_number_hash` is a
deterministic HMAC-SHA256 of the normalised number, so matching still works
while the database alone cannot be brute-forced back to a phone number.

**Phone numbers are normalised** before hashing, so `09171234567`,
`0917 123 4567`, `+639171234567` and `9171234567` all match the same patient.

**Verified end to end:** every format of a number resolves to the same patient;
a clinical record created with differently-formatted input links to the right
account; changing a number reindexes it and the old number stops matching;
clearing it clears both columns; archive snapshots encrypt and still restore;
and the operation is idempotent.

### Blank `Cache-Control` / no leaked ciphertext to the UI — verified

No page or API response exposes `v2:` ciphertext; all read paths decrypt first.

---

## Confirmed problems still open

### 1. `X-Powered-By: PHP/8.3.36` is exposed (LOW, server config)

`expose_php=On` in `php.ini`. Leaks only the PHP version. Not application code.

Fix on the live host: `expose_php = Off`. On XAMPP it is harmless.

### 2. `Strict-Transport-Security` is not sent (LOW, by design)

Deliberately absent. Sending HSTS over plain HTTP would pin the browser to HTTPS
for the whole domain and break local development. Add it on the live host only
after HTTPS is confirmed working.

### 3. Blind indexes are key-dependent (INFO — operational, not a flaw)

`phone_number_hash` is derived from `ENCRYPTION_KEY`. Rotating the key without
keeping the old one as `previous_key` stops existing indexes matching. This is
inherent to keyed blind indexes, not a defect.

Mitigated by `tools/reindex-phones.php`, which rebuilds every index from the
still-decryptable ciphertext and refuses to guess when a row cannot be decrypted.
Verified by simulating a stale index set and repairing it.

---

## Verified clean

| Check | Method | Result |
|---|---|---|
| SQL injection | reviewed every `prepare()`; dynamic SQL uses whitelisted table names and `information_schema`-verified column names | no injectable input |
| Stored XSS | submitted `<img onerror>`/`<script>` through a real form | escaped in both public site and admin portal; 0 injected nodes |
| CSRF | 3-way test (missing / valid / forged token) | 403 / success / 403 |
| Session fixation | `session_regenerate_id(true)` on login | verified |
| Session storage | ids stored as SHA-256; cookie differs from row | verified |
| Password storage | bcrypt; `password_verify()` checked | verified |
| Patient isolation | patient attempting to read another patient's records/profile/language | all confined to own `user_id` |
| Doctor isolation | doctor attempting to modify another doctor's appointment | refused; row unchanged |
| Privilege escalation | doctor PATCHing `users/update.php` to `super-admin` | 403 |
| Role-gated endpoints | 18 endpoints x 4 roles | matrix correct |
| Doctor navigation | visible nav items in a real browser | 5 items; no Reports/Users/Archives/Settings |
| Deep-link bypass | doctor opening `#view-admin-reports` etc. | redirected to dashboard |
| Password reset | single-use, expiry, reuse, enumeration | all enforced |
| Rate limiting | login and signup | enforced |
| File exposure | 10 sensitive paths over HTTP | all 403 |
| Backups over HTTP | `backups/*.sql` | 403 |
| Secrets in Git | `git ls-files` | none; `secrets.local.php` ignored |
| Phone data at rest | direct column inspection | ciphertext only; no plain numbers |

---

## Not verified

1. **Real email delivery to a real inbox.** The SMTP client was tested against a
   mock server (protocol, auth, message format) and TLS to `smtp.gmail.com:465`
   and `:587` both handshake successfully from this machine. Actual delivery,
   spam placement, and whether your provider accepts the From address are
   unverified — that needs your credentials.
2. **Behaviour on nginx or shared hosting.** All file-protection rules are
   `.htaccess` (Apache only).
3. **Backup restore on a different machine.** Restore was verified locally into a
   separate database with byte-identical results. A cross-machine restore also
   needs the encryption key in place.
4. **Concurrent load.** No load testing was performed.
5. **Encryption on a database with thousands of records.** Tested with the live
   dataset only; the tool is per-row with a transaction and would be slower at
   scale, though no less safe.
