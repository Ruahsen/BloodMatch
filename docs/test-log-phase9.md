# Phase 9 Verification Log — 42-Hour Standby & Inter-Donation Cooldown

Executed: 2026-08-26 · Suite: `tests/phase9.ps1` (16 assertions) · **Result: 16 passed, 0 failed**
Regressions: phase3 **23/23**, phase4 **23/23**, phase5 **40/40**, phase6 **35/35**, phase7 **28/28**, phase8 **30/30**
Full sweep on fresh log: zero unexpected PHP warnings/errors; smoke PASS; React build clean

## Schema & configuration

- Migration 012: `system_settings(setting_key PK, value, updated_at)`
- Seed 003 (`INSERT IGNORE` → preserves operator overrides): `standby_hours=42`, `cooldown_days=90`
- `SystemSettingsService` validates presence/type/range (standby 1–720h, cooldown 1–1825d) and throws on missing/invalid values — no silent fallbacks. Values are BloodMatch administrative intervals; UI text states actual eligibility is decided by the donating facility.

## Window semantics implemented

Anchor for both windows: `last_verified_donation_at`.
- standby ends at anchor + `standby_hours`; cooldown ends at anchor + `cooldown_days`
- blocked priority: standby active → which=standby; else cooldown active → which=cooldown; else unblocked
- API returns exact authoritative `ends_at_utc` (+ remaining_seconds)
- Clock injection: every evaluation accepts optional `$nowUtc`; tests simulate time travel by shifting the DB anchor (no sleep())

## Read-model decision (documented in docs/erd.md)

`donor_availability` stays a persisted preference/state — **never lazily mutated** by window passage.
Computed matchability rules in `MatchService`:
- stored `available` → matchable iff both windows expired
- stored `standby` → matchable iff windows expired AND donation history exists (lvd NOT NULL); a stored standby with no history never matches
- stored `unavailable`/NULL → never
Verified: donor kept stored `standby` across full window expiry re-entered the pool without any row mutation (T11/T12).

## Verified behaviors

| Area | Evidence |
|---|---|
| Standby writer | Officer confirmation transaction sets `last_verified_donation_at` + `donor_availability='standby'` together (T1); no other code path writes standby |
| Immediate block | profile shows blocked/standby right after confirmation (T2) |
| Toggle guard | availability change while blocked → 409 with blocking-window payload; DB row unchanged (T3/T8) |
| Boundary | 41h59m still standby-blocked (T6); 43h → cooldown blocking with ~87d remaining (T7) |
| Configurability | `standby_hours` 24 vs 48 flips a 30h-post-donation donor between cooldown-blocked and standby-blocked (T10a/b); restored to 42 |
| Matching pool | blocked donors excluded while blocked (T4/T9); re-enter after expiry via manual re-match (T12) |
| Non-response negative | donor who never responded keeps availability untouched (T5) |
| Post-expiry transitions | unavailable then available both succeed once windows end (T13/T14) |
| Audit | donation.confirmed / donor.availability_changed / match.manual_rematch / request.created all present |

## Regression note (cross-suite interaction found & fixed)

Phase 9's initial pool change allowed stored-standby donors without donation history to enter matches,
which surfaced as a Phase 7 regression during the sequential sweep. Fixed by requiring donation history
for the standby read-model path (see MatchService SQL comment). All suites re-run green afterwards.

## Bugs found & fixed during verification

1. `WindowBlockedException` declared under wrong namespace → class-not-found on toggle.
2. Test-harness session/token mismatches in rematch calls; age-fixture DOB handling carried from earlier suites.

## Known gaps / deferred

- Notification of window expiry belongs to P10.
- Settings currently seed-only; admin UI to edit `system_settings` would require an explicit new requirement.
