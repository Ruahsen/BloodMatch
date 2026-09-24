# Phase 3 Verification Log — Authentication & Registration

Executed: 2026-08-26 · Suite: `tests/phase3.ps1` (23 assertions) · **Result: 23 passed, 0 failed**
Environment: PHP 8.2.12 dev server (127.0.0.1:8000) → MariaDB 10.4 @ 127.0.0.1:**3307**, DB `bloodmatch_dev`

## Endpoints verified

| Endpoint | Behavior confirmed |
|---|---|
| `GET /api/csrf` | issues per-session CSRF token |
| `POST /api/register` | 201 + user pending/active; field-level 400s; duplicate 409; CSRF-less → 403 |
| `POST /api/login` | 200 + session; uniform 401 for unknown/wrong; 429 lockout after 5 fails (correct creds also blocked while locked); 403 deactivated |
| `POST /api/logout` | session destroyed (`/api/auth/me` → 401) |
| `GET /api/auth/me` | returns authenticated user incl. verification_status |
| `POST /api/password-reset/request` | generic 200 (no enumeration); sha256-hex(64) token_hash stored, unused, ~30-min expiry (UTC-consistent); throttled |
| `POST /api/password-reset/confirm` | wrong/garbage token 400; valid token → password rehash + single-use (`used_at`) + other unused tokens purged; reuse → 400; expired → 400 |

## Security checks

- Passwords bcrypt-hashed; old password rejected after reset
- Plaintext tokens never stored/logged — DB contains only 64-hex hashes (T23)
- Session ID regenerated on login
- Audit trail written for: `user.registered`, `auth.login.success`, `auth.login.failed`, `auth.logout`, `auth.password_reset.requested`, `auth.password_reset.completed`, `auth.login.blocked_deactivated` (6+ distinct actions in append-only `audit_log`)

## Bugs found & fixed during verification

1. DOB timezone bug (strtotime local vs gmdate UTC) → `DateTimeImmutable::createFromFormat('!Y-m-d')`
2. Throttle SQL `IF()` missing third argument (MariaDB strict)
3. Clock mismatch PHP-UTC vs MariaDB session TZ → `SET time_zone='+00:00'` on connect + UTC_TIMESTAMP in tests
4. Missing `use BloodMatch\Http\Session` import (wrong-class resolution)
5. RegisterController caught non-existent exception class (wrong namespace)

## Known gaps / deferred

- **Reset-token email delivery NOT implemented** — no mail transport configured in XAMPP dev; flow verified via direct DB token injection. Delivery channel is the only missing piece of §8.6 behavior.
- **Donor-endpoint authorization gating not executable yet** — no donor-only endpoints exist in Phase 3 by design; full capability-matrix enforcement deferred to Phase 5 (per plan). Sanity check limited to: pending user authenticates and passes `/api/auth/me`.
- Registration endpoint itself is not rate-limited (login/reset are); revisit in Phase 16 hardening.
