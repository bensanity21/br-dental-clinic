# Administrator IP allowlist

Restricts administrator sign-in to approved addresses or networks. The feature
is **off until you deliberately add the first entry**, and it is designed so a
mistake cannot lock you out permanently.

---

## The short answer

**Do not enable this before your defense.** It adds a failure mode with no
benefit on a laptop demo, and a wrong entry means you cannot sign in as admin.

If you want it anyway, use **Settings → Administrator Access → Add My Current
Address**. That button reads your current address from the server and cannot
produce a lockout.

---

## How to enable it safely

1. Sign in as **Super Admin**.
2. Open **Settings → Administrator Access**.
3. Click **Add My Current Address**, confirm.
   - The card shows the address the *server* sees, not what your browser thinks.
   - After this, the card reads "Administrator sign-in is restricted... your
     current address is allowed."
4. Add any other networks you need (see formats below).
5. Sign out and sign back in to confirm it still works **before** you rely on it.

### Accepted formats

| Format | Example | Matches |
|---|---|---|
| Single IPv4 | `203.0.113.7` | That one address |
| Single IPv6 | `2001:db8::5` | That one address |
| IPv4 network | `203.0.113.0/24` | 256 addresses |
| IPv6 network | `2001:db8::/32` | A whole block |

Wrong entries with a typo are rejected before they are saved.

---

## Why it cannot lock you out

Five separate safeguards:

1. **Empty table = off.** No entries means no restriction at all. Deleting every
   row turns the feature off again.
2. **First entry checked against you.** If the entry you are adding does not
   include your own current address, the request is **refused** and the message
   names your address so you can correct it.
3. **Fails open.** If the table is missing or the database errors, the check
   allows the login and writes to the error log. A database problem can never
   shut every administrator out.
4. **Disable, don't delete.** Each row has a Disable button. A disabled row is
   ignored but still there, so a mistake is one click to reverse.
5. **Patients unaffected.** Only `super-admin` and `doctor` sign-in is checked.
   Patients can always sign in.

## If you still lock yourself out

You have three recovery paths, in order of ease:

**1. Disable the entry over the network you still have access to**

If any other device on an allowlisted network can reach the site, sign in there
and disable the offending entry.

**2. Empty the table in phpMyAdmin**

```
http://localhost/phpmyadmin
→ database: clinic
→ table: admin_ip_allowlist
→ Empty (or run: DELETE FROM admin_ip_allowlist;)
```

The moment the table is empty, administrator sign-in is unrestricted again.
**This always works** — it is the documented escape hatch.

**3. Reset the password from the command line**

If you also lost the password:

```bash
php database/set-password.php admin@brdental.com --generate
```

If the page itself is unreachable, run the SQL directly:

```sql
UPDATE admin_ip_allowlist SET is_active = 0;
```

---

## Dynamic and shared IP addresses

Home and mobile connections often change address, which will break a strict
allowlist without warning. Options:

- **Use a network range** rather than a single address, e.g. your ISP's block.
- **Leave the feature off** and rely on strong passwords, rate limiting and the
  activity log — all of which are already in place.
- **Enable it only when you need it**, for example while travelling.

This is a real limitation, not a theoretical one. A clinic on a residential
connection with a rotating IP will fight this feature.

---

## Proxies and load balancers

`X-Forwarded-For` is **ignored** unless the direct connection comes from an
address listed in `TRUSTED_PROXIES` in `includes/admin_ip.php` (currently
`127.0.0.1` and `::1`).

If you deploy behind a reverse proxy or CDN, you must add the proxy's address
there, or every request will be judged by the proxy's IP and the allowlist will
behave wrongly. If you are unsure, do not enable the allowlist.

---

## What it does and does not protect

**Protects against:** password guessing from an unrelated network, even if the
attacker has the correct password.

**Does not protect against:** someone on an allowlisted network, a stolen
session cookie from an allowlisted address, or any attack on a patient account.

It is a second lock on one door, not a replacement for the password. The
existing controls that matter more:

- 5 failed attempts per email+IP locks sign-in for 15 minutes
- Every successful and blocked administrator sign-in is in the activity log
- Session ids are stored hashed and can be revoked
- Changing a password signs out every other session
