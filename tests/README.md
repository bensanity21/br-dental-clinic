# Automated tests

Dependency-free test suite: plain PHP + the curl extension, no Composer, no
PHPUnit. Run it with the XAMPP PHP binary.

## Running

```bash
php tests/run.php              # every suite
php tests/run.php auth         # one suite
php tests/run.php authz
php tests/run.php appointments
php tests/run.php users
php tests/run.php reports
php tests/run.php reset
php tests/run.php sessions
php tests/run.php regression

php tests/check-htaccess.php   # Apache-level file protection
php tests/stop-server.php      # stop the test server
```

## How it works

The suite never touches the live `clinic` database.

1. `tests/bootstrap.php` rebuilds a separate **`clinic_test`** database from
   `clinic.sql`, then seeds two patient accounts and lower-cost password
   hashes so the run is fast.
2. It starts PHP's built-in server on **port 8099** with `DB_NAME=clinic_test`,
   so the endpoints under test read and write only the test database.
3. Each simulated user gets its own cookie jar, so sessions and CSRF tokens
   stay isolated per role.
4. Assertions run over real HTTP against real endpoints - nothing is mocked.

The live database is never opened, and no test performs a destructive
operation against it.

## Suites

| Suite | Covers |
|---|---|
| `auth` | login, logout, wrong password, unknown email, no-enumeration message, CSRF token issuance |
| `authz` | role enforcement, self-promotion blocked, super-admin protected, dentist isolation |
| `appointments` | booking, double-booking refusal, hourly slot rule, status changes, No-show, slot availability |
| `users` | listing, dentist creation, super-admin creation refused, duplicate email, signup forces patient |
| `reports` | all five report types, date validation, unknown type, CSV export permission |
| `reset` | password reset request, single-use token, expiry, enumeration safety |
| `regression` | public endpoints, CSRF enforcement, HTTP method enforcement |

`reset` is added alongside that feature. Suites restore any account they
modify, so a full run can be repeated without side effects.

## Notes

- PHP's built-in server ignores `.htaccess`, so protected-file rules cannot be
  checked there. `tests/check-htaccess.php` verifies them against the real
  Apache site instead.
- Booking is hourly, and existing slots are unique per dentist/date/time. Tests
  that book must pick an offered slot and an **unused** one.
- The login rate limiter is per email + IP, and every test shares `127.0.0.1`.
  `testClearRateLimits()` is called between logins for that reason.
- Service and dentist IDs are resolved from the database at run time; the seed
  file's AUTO_INCREMENT values are not guaranteed to start at 1.
