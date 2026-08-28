# Phase 4 Verification Log — RBAC & Chapter Scoping

Executed: 2026-08-26 · Suite: `tests/phase4.ps1` (23 assertions) · **Result: 23 passed, 0 failed**
Regression: `tests/phase3.ps1` re-run after changes → **23 passed, 0 failed** (unchanged)
Environment: PHP 8.2.12 dev server → MariaDB 10.4 @ port 3307, DB `bloodmatch_dev`; zero PHP warnings/notices

## Endpoints introduced (administrative plumbing only)

| Endpoint | Access | Behavior |
|---|---|---|
| `GET /api/admin/users` | admin | system-wide list; filters role/status/chapter/q; pagination |
| `POST /api/admin/users/{id}/role` | admin | assign member/officer/admin; officer requires exactly one chapter; self-change forbidden |
| `POST /api/admin/users/{id}/chapter` | admin | assign/clear chapter; clearing an officer's chapter → 422 |
| `POST /api/admin/users/{id}/deactivate` | admin | soft-deactivation (`account_status='deactivated'`, `deactivated_at=UTC now`) |
| `POST /api/admin/users/{id}/reactivate` | admin | restores active + clears timestamp |
| `GET /api/officer/users` | officer | own-chapter users only; explicit `?chapter_id=<other>` → 403 |

## Matrix verified

| Test | Result |
|---|---|
| Unauthenticated → admin endpoint | 401 (+audit) |
| Member → admin endpoint | 403 (+audit) |
| Officer → admin-only endpoints (list/role/chapter on any target incl. self) | 403 (+audit) |
| Officer → own chapter list | 200, zero foreign rows |
| Officer → different chapter (explicit param) | 403 (+audit) |
| Officer with NULL chapter (bad row) | blocked from scoped access |
| Admin → cross-chapter reassignment | allowed (scope-exempt), persisted |
| Invalid role value / officer-without-chapter / clearing officer chapter | 400 / 400 / 422 |
| Deactivation lifecycle | status+timestamp set; verification_status untouched; reactivate clears both |
| Deactivated live sessions (member & admin) | denied protected access (fresh DB check per request) |
| Self-role/self-chapter/self-status changes | forbidden even for admins (lockout protection) |
| URL-guessing unknown admin path | 404 JSON, no exposure |
| Audit trail | `authz.denied` written per denial (+10 during run); admin actions audited (`admin.user.role_changed`, `.chapter_assigned`, `.deactivated`, `.reactivated`) |

## Design notes

- Deny-by-default: `requireAuth → requireActiveUser → requireRoles([…], endpoint) → requireChapterScope(when applicable)`; chapter scope applied ONLY where the resource is chapter-bound.
- Fresh per-request account-status check means deactivation takes effect immediately on live sessions (no stale-session privilege).
- Officers cannot be created via registration; only admins assign `role=officer` bound to exactly one `chapter_id`.
- No P5+ features touched: no verification decisions, documents, donor workflows, requests, matching, notifications, dashboards.

## Known gaps / deferred

- Officer-scoped surface is intentionally minimal (one read-only list) until verification module (P5) and dashboard queues (P12) arrive.
- Pagination metadata beyond `total` (page count) not yet needed by any UI.
