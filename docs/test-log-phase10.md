# Phase 10 Verification Log — In-App Notifications & Best-Effort Email

Executed: 2026-08-27 · Suite: `tests/phase10.ps1` (43 assertions) · **Result: 43 passed, 0 failed**
Regressions: phase3 **23/23**, phase4 **23/23**, phase5 **40/40**, phase6 **35/35**, phase7 **28/28**, phase8 **30/30**, phase9 **16/16** — all suites green (238 total assertions)
Runtime: PHP 8.2.12 dev server → MariaDB @ 3307, DB `bloodmatch_dev`; zero PHP warnings/errors; React production build clean

## Schema & Migration

- Migration 013: `notifications` table created with columns `id`, `user_id`, `type`, `title`, `body`, `related_type`, `related_id`, `dedup_key`, `generation`, `emailed_at`, `read_at`, `created_at`.
- Constraints: `UNIQUE KEY uq_notifications_dedup (dedup_key, generation)`, `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`.
- Indexes: `(user_id, read_at)`, `(type)`.

## Deduplication Strategy Verified

1. **Match-generation notifications:** Keyed by `dedup_key = match:{requestId}:{donorId}` with match `generation = N`. Re-matching without generation bump avoids duplicate notifications via `INSERT IGNORE`. Material change bumps generation and permits re-notification.
2. **Non-match notifications:** Keyed by event-specific patterns referencing `audit_log.id` or entity status (e.g., `verification:{userId}:{decision}:{auditId}`, `account:{userId}:{status}:{auditId}`, `request:{requestId}:cancelled`, `request:{requestId}:expired`, `donation:{reportId}:{decision}`). All non-match notifications store `generation = 0` (never `NULL`) to avoid MariaDB multi-NULL unique key bypass.
3. **AuditLogger extension:** `AuditLogger::log()` returns the inserted ID to provide unique event identities for lifecycle notifications.

## Endpoints Verified

| Endpoint | Access | Verified Behavior |
|---|---|---|
| `GET /api/notifications` | active user | paginated notification list; supports `?type=` and `?read=read/unread` filters |
| `GET /api/notifications/unread-count` | active user | returns live unread notification count |
| `POST /api/notifications/{id}/read` | active owner | marks notification as read (`read_at = now`); idempotent; cross-user access returns 404 |
| `POST /api/notifications/read-all` | active user | marks all unread notifications as read for actor |

## Event Wiring Verified

| Trigger Event | Notification Type | Recipient | Deduplication Key |
|---|---|---|---|
| Match Generation | `match.new` | Matched eligible donors | `match:{requestId}:{donorId}` @ generation N |
| Verification Decision | `verification.decision` | Target member | `verification:{targetId}:{decision}:{auditId}` |
| Account Deactivation/Reactivation | `account.status_changed` | Target user | `account:{targetId}:{status}:{auditId}` |
| Request Cancellation | `request.cancelled` | Request owner | `request:{requestId}:cancelled` |
| Request Expiry | `request.expired` | Request owner | `request:{requestId}:expired` |
| Donation Confirmation/Rejection | `donation.confirmed` / `donation.rejected` | Reporting donor | `donation:{reportId}:{decision}` |

## Email Configuration & Transport

- Synchronous best-effort delivery via `Mailer.php` wrapping PHPMailer.
- Reads SMTP configuration from environment: `MAIL_HOST`, `MAIL_PORT`, `MAIL_USER`, `MAIL_PASS`, `MAIL_FROM`.
- Rate limiting: max 5 emails/user/hour for normal priority; critical urgency bypasses rate limiting.
- Graceful degradation: if `MAIL_HOST` is unset, email transport is skipped without error and in-app notification persists.
- Plaintext password-reset tokens sent via email when configured; database stores only SHA-256 hashes.

## Bugs Found & Fixed During Verification

1. `Request::str()` and `Request::int()` previously only inspected JSON request body, ignoring `$_GET` query parameters for `GET` requests (`?type=`, `?read=`, `?page_size=`). Updated `Request.php` to fall back to `$_GET` when `$from` is not supplied.
2. `tests/phase8.ps1` assertion C4 expected `POTENTIAL` match status on parallel donors; with Phase 10 active, matches correctly transition to `NOTIFIED` upon request creation. Updated assertion to accept `NOTIFIED` or `POTENTIAL`.
3. Unicode em-dash characters in test files caused parser warnings in Windows PowerShell 5.1; sanitized to standard ASCII.
