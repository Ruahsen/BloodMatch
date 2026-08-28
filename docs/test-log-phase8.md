# Phase 8 Verification Log — Donor Availability & Donation Lifecycle

Executed: 2026-08-26 · Suite: `tests/phase8.ps1` (30 assertions) · **Result: 30 passed, 0 failed**
Regressions: phase3 **23/23**, phase4 **23/23**, phase5 **40/40**, phase6 **35/35**, phase7 **28/28** — all unchanged
Runtime: PHP 8.2.12 dev server → MariaDB @ 3307, `bloodmatch_dev`; zero PHP warnings; React build clean

## Schema (migration 011) + canonical relationship decision

`donation_reports`: `match_id FK → matches.id ON DELETE CASCADE` is the **canonical** relationship;
the request is always derived through `matches.request_id`. No duplicate request_id column.
Fields: donor_id FK, report_note ≤500, status ENUM(PENDING/CONFIRMED/REJECTED), reported_at UTC,
confirmed_by FK SET NULL / confirmed_at (also used as reviewer metadata on rejection).
Duplicate PENDING reports per match are blocked at service level.

## DonorEligibilityService extension point

`assertAvailabilityChangeAllowed(user)` currently enforces enrollment + active account.
Phase 9 will extend this single method with 42-hour standby + inter-donation cooldown windows —
no other call sites change.

## Endpoints verified

| Endpoint | Verified behavior |
|---|---|
| `POST /api/profile/donor-availability` | enrolled-only (non-enrolled 409); values available/unavailable only (else 400); deactivated live sessions 403 via middleware; persisted both directions |
| `POST /api/matches/{matchId}/respond` | owner-only (403 others); POTENTIAL/NOTIFIED→RESPONDED; idempotent duplicate → 200; parallel donors untouched; non-OPEN request → 409 |
| `POST /api/donation-reports` | match owner only; RESPONDED required; OPEN request required; no duplicate PENDING; UTC reported_at |
| `GET /api/officer/donation-reports` | officer sees own-chapter PENDING queue; admin system-wide |
| `POST .../confirm` · `.../reject` | chapter-scoped officer/admin; single locked transaction |

## Confirmation transaction (verified atomically)

Inside one DB transaction with `FOR UPDATE` locks:
report PENDING check → CONFIRMED + confirmed_by/at → donor.`last_verified_donation_at` = UTC now →
match COMPLETED → fulfillment recalculation.

**Fulfillment rule (AUTHORITATIVE, implemented):** request becomes FULFILLED when
`COUNT(matches.status='COMPLETED') >= quantity_units`. Verified: qty=1 fulfills on first
confirmation; qty=2 stays OPEN after unit #1 and fulfills on unit #2. On fulfillment the same
transaction closes remaining unresolved matches (POTENTIAL/NOTIFIED/RESPONDED → CLOSED) and writes
`request.fulfilled`. Responses/reports/re-matching against a FULFILLED/non-OPEN request → 409.

Rollback proof: a BEFORE UPDATE trigger forcing SIGNAL during confirmation produced HTTP 500 with
report still PENDING, match still RESPONDED, donor timestamp NULL, request still OPEN — full atomic rollback.

## Access-model refinement

Matched donors may call `GET /api/requests/{id}/matches` but receive **only their own entry**
(privacy §9.8). Owner/same-chapter-officer/admin retain the full ranked list. Asserted B0.

## Audit events observed

`donor.availability_changed`, `match.responded`, `donation.reported`, `donation.confirmed`,
`donation.rejected`, `request.fulfilled`, `authz.denied` (7 distinct).

## Bugs found & fixed during verification

1. `DonationService` called a non-existent bridge method (`AuthBridge::requireRoles`) → switched to `AuthMiddleware::requireRoles`.
2. Donor match visibility: donors previously 403'd on the matches endpoint — added scoped own-entry view (documented above).

## Known gaps / deferred

- NOTIFIED state remains unused until P10 notifications; RESPONDED→COMPLETED only via officer confirmation.
- Standby/cooldown enforcement intentionally absent (P9 owns `DonorEligibilityService` extension).
- Officer confirmation UI is minimal by design; consolidated queues arrive in P12 dashboard.
