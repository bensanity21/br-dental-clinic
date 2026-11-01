# Security review - verified findings

Everything below was tested on 2026-11-01 against the running XAMPP install.
Confirmed problems are separated from optional improvements, and anything I
could not verify is marked as such.

---

## Confirmed problems

### 1. `users.phone_number` is stored in plain text (MEDIUM)

**Status: NOT fixed. Deliberate. Reasoning below.**

Every other sensitive patient field is encrypted before it is written:

| Column | Stored as |
|---|---|
| `home_address` | `v2:<ciphertext>` (AES-256-GCM) |
| `date_of_birth` | `v2:<ciphertext>` |
| `emergency_contact` | `v2:<ciphertext>` |
| `clinical_notes` | `v2:<ciphertext>` |
| **`phone_number`** | **plain text**, e.g. `09171234567` |

Verified: `api/auth/profile.php` line 84 writes `phone_number` without calling
`encryptData()`, while lines 88-90 encrypt the three neighbouring fields.

**Why it was not simply encrypted**

`phone_number` is not only displayed. It is used for **exact-match lookup**:

- `api/records/add.php:53` — `WHERE full_name = ? AND phone_number = ?` to attach
  a clinical record to the right patient account.
- `api/records/add.php:80` — `AND (? = '' OR p.phone_number = ?)` to confirm a
  doctor is recording against their own patient.

`encryptData()` uses a **random 12-byte nonce per call**, so encrypting the same
number twice produces different ciphertext. A `WHERE phone_number = ?` compare
would never match again, and patient linking would stop working — silently.
That is a clinical-data error, which is worse than the exposure.

There were 2 non-null values in the live database at review time.

**Options, in order of preference**

1. **Accept it.** This is a student thesis system on a local/clinic network. A
   phone number is far less sensitive than a home address or clinical notes, and
   the database is already protected by the app's authentication. Document the
   decision. *Cost: none.*
2. **Blind index.** Add a `phone_number_hash` column (`hash_hmac('sha256',
   normalized, KEY)`) and match on that, keeping `phone_number` encrypted. This
   preserves equality search with encryption at rest. *Cost: a migration, a
   column, changes to 2 queries and 1 write path, plus a backfill.*
3. **Deterministic encryption** of the phone column only. *Not recommended* —
   a deterministic scheme weakens AES-GCM to ECB-like leakage.

I did not implement 2 or 3 because they touch the records-linking path, which is
the one place where a silent mistake corrupts clinical data. If you want it,
say so and it will be done with its own tests and a restore drill first.

### 2. `X-Powered-By: PHP/8.3.36` is exposed (LOW, server config)

`expose_php=On` in `php.ini`. Leaks only the PHP version. Not application code.

Fix on the live host: `expose_php = Off`. On XAMPP it is harmless.

### 3. `Strict-Transport-Security` is not sent (LOW, by design)

It is deliberately absent. Sending HSTS over plain HTTP would pin the browser to
HTTPS for the whole domain and break local development. Add it on the live host
only after HTTPS is confirmed working.

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
| Doctor isolation | doctor attempting to modify another doctor's appointment | refused with "You can only modify your own appointments."; row unchanged |
| Privilege escalation | doctor PATCHing `users/update.php` to `super-admin` | 403 |
| Role-gated endpoints | 18 endpoints x 4 roles | matrix correct |
| Doctor navigation | visible nav items in a real browser | 5 items; no Reports/Users/Archives/Settings |
| Deep-link bypass | doctor opening `#view-admin-reports` etc. | redirected to dashboard |
| Password reset | single-use, expiry, reuse, enumeration | all enforced |
| Rate limiting | login and signup | enforced |
| File exposure | 10 sensitive paths over HTTP | all 403 |
| Backups over HTTP | `backups/*.sql` | 403 |
| Secrets in Git | `git ls-files` | none; `secrets.local.php` ignored |

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
