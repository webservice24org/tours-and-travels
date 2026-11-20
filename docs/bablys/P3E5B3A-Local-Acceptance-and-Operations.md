# P3E.5B.3A — local acceptance and operations

## Before installing

- Confirm earlier P3E.5B.2B tests, PHPStan, TS and production build were green.
- Back up the MySQL database and Bablys application source. **Do not run RefreshDatabase tests against production.**
- Extract ZIP outside `C:\xampp\htdocs\bablys-travel` and preview with the installer `-WhatIf` flag.
- Verify that `routes/console.php` exists and uses standard Laravel 13 scheduling. The installer only appends a `require` statement, never replaces the file.

## Safe QA commands

```powershell
cd C:\xampp\htdocs\bablys-travel
php artisan migrate:status
php artisan migrate --pretend
php artisan migrate
php artisan route:clear
php artisan bablys:bookings:expire-requests --limit=10 --dry-run
php artisan schedule:list
php artisan test --filter=TourBookingRequestExpiryTest
php artisan test --filter=TourBookingRequestManagerTest
php artisan test --filter=BookingRequestHttpInterfaceTest
php artisan test --filter=TourBookingLifecycleTest
php artisan test --filter=TourSeatAllocationManagerTest
php artisan test
vendor\bin\phpstan analyse
npm.cmd run types:check
npm.cmd run build
php artisan bablys:audit:readiness
```

If the `schedule:list` command doesn't show the expiry task, check the include line in `routes/console.php` and that `bootstrap/app.php` registers the console routes. If `php artisan list` doesn't show `bablys:bookings:expire-requests`, inspect the application command-discovery configuration (`app/Console/Commands`) before altering routes.

## Manual validation in local/staging environment

1. Create an unverified guest request. In a disposable DB set `verification_expires_at_utc` to the past; run `--dry-run`, then run the command normally. Expect `expired`, `expired_reason=verification_timeout`, both token hashes null and unchanged seat allocation count.
2. Create a verified customer request and set `expires_at_utc` to the past. Expect `expired`, `expired_reason=request_timeout`; customer history can still display the reference/status, but cannot withdraw or convert it.
3. Create a verified guest request whose verification deadline passed but 7-day request deadline did not. Expect it to stay `verified`.
4. Withdraw one pending guest request before sweeping; its terminal `withdrawn` state must not change.
5. Convert one verified customer request through a **trusted internal test only**, then set its request deadline to the past and sweep; its `converted` state, booking `payment_state=not_collected` and committed seats must not change.
6. Run the command twice. Second invocation must report `expired=0` for already expired records.
7. Use `--limit=1` on two due requests; one expires per invocation.
8. In staging, start two separate processes both running the expiry command; observe no duplicate transition or sensitive output. This is an operational smoke test, **not** full MySQL parallel-connection conversion proof.

## Before production scheduling

Make sure there is an operating-system schedule launching `php artisan schedule:run` every minute (Windows Task Scheduler or Linux cron). Set up monitoring/alerts for missed scheduler runs and command failures; use one primary scheduler in simpler deployments. Ensure a shared cache when multi-server scheduler locking matters. Keep database backups, log access controls, and the data-retention policy intact.

No proactive email or SMS about expiry is sent in this stage, avoiding plaintext guest PII in jobs/logs. A later notification stage should use an explicit consent/transport/retry design and must not resurrect an expired access token.

## Accept P3E.5B.3A when

- New feature tests and full existing test suite are green on a disposable database.
- PHPStan, TS type checking, production build and audit readiness are green.
- `--dry-run` makes no mutations and scheduler reports the 10-minute task.
- Expiry leaves booked seat ledgers, actual booking status and `payment_state` unchanged.
- Owner accepts the lack of automatic email reminders and the 3B/3C/3D release guardrails.

After these conditions are met, mark **only P3E.5B.3A complete** and proceed to **P3E.5B.3B — Accepted Quote / Price-Change Reapproval**.
