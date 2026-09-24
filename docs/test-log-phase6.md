# Phase 6 Verification Log — Blood Request Lifecycle

Executed: 2026-08-26 · Suite: `tests/phase6.ps1` (35 assertions) · **Result: 35 passed, 0 failed**
Regressions after changes: phase3 **23/23**, phase4 **23/23**, phase5 **40/40** (all unchanged)
Clean-database drill: DROP/recreate `bloodmatch_dev` → migrations 001–008 applied → seeds → **all four suites green against the rebuilt DB** (121 total assertions)
Runtime: PHP 8.2.12 dev server → MariaDB @ 3307; zero PHP warnings/errors in fresh log; React production build clean

## Endpoints introduced

| Endpoint | Access | Behavior |
|---|---|---|
| `POST /api/requests` | capability-gated (`create_request`: pending/verified members) | validation battery; derives immutable `request_chapter_id`; sets `review_status='pending_review'` for pending creators |
| `GET /api/my/requests` | owner | own list, safe fields |
| `GET /api/requests/{id}` | owner / same-request-chapter officer / admin | others → 403 (+audit); anonymous → 401 |
| `PUT /api/requests/{id}` | owner, OPEN only | partial whitelist; material-group diffing emits `request.material_change` (no-op edits emit plain `request.updated`); past needed_datetime rejected |
| `POST /api/requests/{id}/cancel` | owner / same-chapter officer / admin, OPEN only | double-cancel → 409; cross-chapter officer → 403 (+audit) |

CLI: `database/run_expiry.php` — flips due OPEN→EXPIRED (batch 500), sets expired_at once, audited as single batch event, idempotent.

## Verified highlights

- Capability gates reuse `CapabilityMatrix` (no second gating system): unverified/rejected creators → 403 with audit; pending ✓ (`pending_review` provenance snapshot); verified ✓ (`not_required`)
- Full validation battery: 8-type enum, units 1–10, facility length, urgency enum, future UTC datetime, coordinate pair + ranges
- Chapter snapshot persisted at creation (T06); officers scoped to `request_chapter_id`, admin exempt
- Material-change auditing: blood-type + datetime change emitted exactly one event; identical resubmission emitted none
- Expiry: due row flipped w/ timestamp; rerun reported 0; CANCELLED-past rows untouched; batch audit written
- Privacy: responses contain no credentials/security metadata; unrelated members denied visibility (no public directory)

## Design decisions recorded

- `review_status` is an immutable creation-time provenance flag (pending-user requests), NOT a second lifecycle status; current account state is always read live from `users`.
- `request_chapter_id` is an immutable FK snapshot for scoping/history; documented in `docs/erd.md`.
- Anonymous POSTs without session hit the CSRF shield first (403 by design since P3); unauthenticated GET access denials are 401 — both behaviors asserted.

## Known gaps / deferred

- FULFILLED transition arrives with Phase 8 donation confirmation (enum ready, no endpoint).
- Matching re-evaluation will subscribe to `request.material_change` in Phase 7 (events already emitted).
- Officer "request correction" nuance on requests (beyond cancel) intentionally deferred to dashboard queues (P12).
- No public/chapter-wide request browsing beyond authorized views (per scope decision #8).
