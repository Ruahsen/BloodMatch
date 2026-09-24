# Phase 7 Verification Log — Compatibility & Matching Engine

Executed: 2026-08-26 · Suite: `tests/phase7.ps1` (28 assertions) · **Result: 28 passed, 0 failed**
Regressions: phase3 **23/23**, phase4 **23/23**, phase5 **40/40**, phase6 **35/35** — all unchanged
Full sweep on fresh log: zero PHP warnings/errors; React build clean; smoke PASS

## Schema additions (migrations 009–010 + seed)

- `compatibility_matrix`: recipient_type PK over the 8 ABO/Rh types; `allowed_donor_types` CSV. Red-cell rules only — seeded idempotently.
- `matches`: UNIQUE(request_id, donor_id) — one persistent row per pair; `generation` = last generation in which the pair was eligible; status enum POTENTIAL/NOTIFIED/RESPONDED/COMPLETED/CLOSED; distance_km/rank_score snapshots.

## Donor enrollment decision (ratified)

Verified members are **NOT** implicitly donors. Matching pool requires ALL of:
`role='member'` ∧ `account_status='active'` ∧ `verification_status='verified'` ∧
`donor_enrolled_at IS NOT NULL` ∧ `donor_availability='available'`.

Minimum backend capability added: `POST /api/profile/enroll-donor` — requires verified status,
DOB-based age eligibility (§9.2: <16 no; 16–17 parental_consent doc), idempotent when already
enrolled; sets `donor_enrolled_at=UTC now`, `availability='available'`; audited `donor.enrolled`.
No donation completion / standby / cooldown / response logic implemented.

## Engine behavior verified

| Area | Evidence |
|---|---|
| Full 8-type red-cell matrix | M1–M8 exact-set comparisons via `GET /api/compatibility-matrix` (officer/admin) |
| Compatibility = hard filter | Nearby B+ donor absent from A+ request while distant compatible present (T3) |
| Pool exclusions | unverified/pending/rejected/deactivated/standby/unavailable/not-enrolled all excluded (T2) |
| Enrollment gate | unverified enroll → 409 (T8a); verified enroll → persisted `Y\|available` (T8b) |
| Ranking rule | located same-chapter → located cross-chapter → unlocated last (T4); score = located(10000) + same-chapter(100) + max(0, 5000−ceil(km)); compatibility never weighted |
| Haversine | plausible km between Balanga coords (T6); missing coords → NULL distance, still matched (T7) |
| Generation semantics | creation → gen 1 (T1); material change → gen bumped (T9); manual non-material re-match keeps generation (T10/T14); newly enrolled donor inserted at current generation (T13) |
| Dedup | single persistent row per pair after many runs (T11) |
| Closure history | unavailable donor's match → CLOSED, row retained (T15) |
| Auto-generation | request creation runs first generation synchronously (T1/T19) |
| Manual re-match authz | member 403, cross-chapter officer 403, same-chapter officer 200, admin system-wide (T16–T18) |
| Privacy serialization | raw responses contain no latitude/longitude/phone/email/password/document keys; only approximate distance (T5) |
| Audit | `match.generation`, `match.manual_rematch`, `donor.enrolled`, `request.material_change` recorded |

## Bugs found & fixed during verification

1. `UserRepository::findById()` missing `donor_enrolled_at` column → enrollment endpoint fatal.
2. Test-harness issues: fixtures without DOB (correctly rejected by age gate — system right, test wrong); T8a originally targeted the wrong fixture user.

## Known gaps / deferred

- Matches stay POTENTIAL: NOTIFIED arrives with P10 notifications, RESPONDED with P8 donor response, COMPLETED with donation confirmation.
- No UI beyond a read-only privacy-safe match panel per OPEN request (`/requests/{id}/matches`).
- Urgency stored but unused by ranking (per locked decision #8).
