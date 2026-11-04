# Remaining work before production

An honest, ordered list. Nothing here is claimed as done.

---

## Must do before real patient use (manual hosting/account steps)

| # | Task | Who | Time |
|---|---|---|---|
| 1 | **Rotate every account still using a password exposed in Git history** | you | 10 min |
| 2 | Set `APP_ENV=production` and DB credentials as **environment variables** | you | 10 min |
| 3 | Create a **dedicated MySQL user** (not `root`) | you | 5 min |
| 4 | **Configure SMTP** and confirm with `php tools/test-mail.php` | you | 15 min |
| 5 | **Export the encryption key** to offline storage | you | 5 min |
| 6 | Enable **HTTPS** and add HSTS | you / host | 30 min |
| 7 | **Rewrite the `.htaccess` rules for your host** if it is not Apache | you | 30 min |
| 8 | Schedule the **daily backup** task | you | 5 min |
| 9 | Back up **off the server** — a backup on the same disk is not a backup | you | ongoing |

## Should do (high value, low risk)

| # | Task | Notes |
|---|---|---|
| 10 | Test a **restore on the target server** before real patients use it | the drill is verified locally; prove it where it matters |
| 11 | `expose_php = Off`, `display_errors = Off` on the live host | php.ini |
| 12 | Verify with `php tools/check-exposed.php` that no account accepts an exposed password | run after rotation |
| 13 | Write down the **key custody** procedure: who holds it, where | a clinic that loses the key loses records |
| 14 | If you rotate the encryption key, run `php tools/reindex-phones.php` | or patients stop being findable by phone |

## Optional (nice to have, not blockers)

| # | Task | Value |
|---|---|---|
| 15 | CSRF token rotation per request | minor hardening |
| 16 | Mail retry queue | reset emails survive a mail outage |
| 17 | Split `portal.js` further (still 2,198 lines) | maintainability |
| 18 | Automated test run on deploy | prevents regressions |
| 19 | Database read replica / multi-server sessions | only matters at scale |
| 20 | Patient-facing appointment reminders | product feature |

---

## Note on passwords already in Git history

`Admin123!` and `Dentist123!` appeared in `clinic.sql` in earlier commits and
are in the repository history even though the current file has none. On a public
repository you should assume they are known.

- **If your live accounts still use them, change them.** They are demo
  credentials on a demo system today, but not on a real clinic.
- Rewriting Git history to remove them is possible but invasive and would
  invalidate your rollback tags. **Changing the passwords is simpler and
  sufficient** — the exposed hashes become worthless.

---

## Implemented in code during this review

- Phone numbers encrypted at rest with a keyed blind index, so patient matching
  by name + phone still works; migration, backfill tool and reindex tool included
- Backup and restore, with a restore drill proving byte-identical recovery
- SMTP client with env-var credentials, mock-verified
- No passwords in `clinic.sql`
- Session storage in MySQL with hashed ids
- Optional IP allowlist with lockout protection
- 166 automated assertions
- 10 sensitive paths blocked at the web server
- Three seeded accounts with no usable password until you set one
- CSV report values are escaped against spreadsheet formula injection
- Public clinic-hour fallbacks use the same configured default as booking
- Reset links are not returned to clients unless an explicit development-only flag is set
- Reset tokens are removed from the address bar and the reset page is no-referrer/no-store
- Production session cookies are Secure; proxy headers are ignored unless explicitly trusted
- Clinic address rendering uses text nodes rather than HTML insertion

## Still requires account or hosting access

The code changes above do not rotate passwords in the live database, enable
HTTPS on a hosting account, configure real SMTP credentials, schedule backups,
or copy backups and the encryption key off the server. Complete those steps in
`LAUNCH-CHECKLIST.md` and `DEPLOYMENT.md` before real patient use. The HTTPS
server must also redirect HTTP to HTTPS; production cookies are now Secure and
will not work over plain HTTP.
- Setup guide (`SETUP.md`) and deployment guide (`DEPLOYMENT.md`)
