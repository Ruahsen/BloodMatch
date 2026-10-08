# BloodMatch - Test Log: 018 Remediation Hardening (2026-10-01)

> Current regression baseline after the audit-driven remediation pass
> (migration 018 + lifecycle/concurrency/auth/privacy/feed/validation fixes).
> Historical baselines: `docs/test-log-phase17.md` (11/11, 316/316),
> `docs/test-log-location.md` (12/12, 343), `docs/test-log-feed.md` (13/13, 384).

## Environment

- PHP 8.2.12 (`D:\xampp\php\php.exe`), backend `http://127.0.0.1:8000`
  (PHP built-in server serving `backend/public`, reads current working tree).
- MariaDB via XAMPP, `127.0.0.1:3307`, database `bloodmatch_dev`.
- Migration state before run: `018_remediation_hardening.sql` applied
  (18/18 in `schema_migrations`).
- DB hygiene: all `*@test.local` fixture users from prior runs were deleted
  (cascade) after a full `mysqldump` backup
  (`bloodmatch_dev_preRemediationRun.sql` in the temp workspace); the 2
  real accounts were preserved. Order-sensitive suites (phase7/8/9,
  location, feed) assume a clean DB; re-runs on a dirty DB may fail.

## Master regression result

`powershell -ExecutionPolicy Bypass -File tests/run_all.ps1`
**15/15 suites green, 431 assertions, 0 failures:**

| Suite | Result |
|---|---|
| phase3.ps1 (auth/registration/reset) | 26 passed, 0 failed |
| phase4.ps1 (RBAC/chapters) | 23 passed, 0 failed |
| phase5.ps1 (profiles/verification) | 44 passed, 0 failed |
| phase6.ps1 (requests/lifecycle) | 35 passed, 0 failed |
| phase7.ps1 (matching engine) | 28 passed, 0 failed |
| phase8.ps1 (availability/donation) | 30 passed, 0 failed |
| phase9.ps1 (standby/cooldown) | 16 passed, 0 failed |
| phase10.ps1 (notifications) | 43 passed, 0 failed |
| phase11.ps1 (audit logging) | 31 passed, 0 failed |
| phase12.ps1 (dashboards/map/analytics) | 32 passed, 0 failed |
| location.ps1 (Bataan reference) | 20 passed, 0 failed |
| phase16_security.ps1 | 15 passed, 0 failed |
| feed.ps1 (Home feed + lifecycle) | 50 passed, 0 failed |
| profile_picture.ps1 (now in runner) | 13 passed, 0 failed |
| remediation.ps1 (new, audit follow-up) | 25 passed, 0 failed, 0 skipped |

## New remediation suite (tests/remediation.ps1, R01–R24)

| ID | Proves |
|---|---|
| R01 | Donation submit on CLOSED match rejected 409, no side effects |
| R02 | Confirm-after-WITHDRAWN rejected 409, WITHDRAWN stays terminal, no `last_verified_donation_at` write |
| R03 | Confirm-after-cancel rejected 409 |
| R04 | Capacity holds across accept (second accept 409) and confirm (RESPONDED confirm 409 at quota); `ACCEPTED+COMPLETED = 1+0` |
| R05 | Duplicate PENDING donation report rejected 409; exactly one row |
| R06 | WITHDRAWN terminal; re-respond 409 |
| R07 | Reset-request throttle identical for known/unknown (`200×5` then `429`; no oracle) |
| R08 | Two concurrent confirms on one token: exactly one 200 + one 400 (atomic consume, Start-Job) |
| R08b | Consumed token not reusable (400) |
| R09 | Reset confirm on deactivated account rejected 403 |
| R10 | Deactivated session revoked (version check, `/api/auth/me` 403) |
| R11 | Under-16 DOB change unenrolls donor; request-scoped respond 409 |
| R12 | Stale RESPONDED (availability OFF) cannot be accepted (409) |
| R13 | Rejected donor RESPONDED stays but cannot be accepted (409) |
| R14 | Request detail: owner sees coordinates, same-chapter officer gets labels only |
| R15 | Feed payload carries none of `email/phone/latitude/longitude/password/document/consents` (schema-aware key walk) |
| R16 | Contact matrix: stranger 404, pre-accept principal 403, ACCEPTED 200 with both emails, post-unaccept 403 |
| R17 | Fulfillment closes request and emits `request.fulfilled` to requester |
| R18 | `matches` + `my/requests` pagination metadata (`total/page/page_size`) |
| R19 | Phone overflow (profile + register), invalid verification decision → 400 with standard envelope |
| R20 | POST without `X-CSRF-Token` rejected 403 (session header-stripped probe) |
| R21 | `notifications.dedup_key/generation` are `NOT NULL` (schema guard) |
| R22 | Demand-map (`blood_type_counts`, `open_requests_count`) + analytics (`request_volume.total_requests`, `demand_by_blood_type.O+.requests`) contract keys present |
| R23 | `run_expiry.php` expires overdue request, closes matches, emits `request.expired` |
| R24 | Deactivation closes ACCEPTED and revokes contact (403) |

## Static verification (same date)

- `php -l` over all 81 `backend/**/*.php` + runners: **0 failures**.
- `npm run build` (Vite 5.4.21): **clean, 22.31s**, `dist/` emitted.
- Route table: **62** `method+path` registrations.
- Schema: 15 tables, `matches.status` 7-value enum,
  `notifications.dedup_key/generation NOT NULL`,
  indexes `idx_matches_donor_status`, `idx_drep_match_status` present.

## Notes / limitations

- Browser (Playwright) verification was not performed: no Python runtime
  in this environment. UI changes were verified via production build +
  API-level tests only.
- Audit fail-closed behavior is covered by code path + transaction design;
  no live sabotage test (would require breaking the audit table).
- True-concurrency coverage: R08 (reset confirm) and feed F23 (parallel
  accepts) use `Start-Job`; both passed with genuine `200+409`/`200+400`
  splits (no sequential fallback taken).
