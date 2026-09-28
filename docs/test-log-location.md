# Location Verification Log — Bataan Municipality/Barangay Selector & Match Consistency

Executed: 2026-09-24 · Suite: `tests/location.ps1` (20 assertions) · **Result: 20 passed, 0 failed**
Environment: PHP 8.2.12 dev server (127.0.0.1:8000) → MariaDB 10.4 @ 127.0.0.1:**3307**, DB `bloodmatch_dev`
Regression: `tests/run_all.ps1` **12/12 suites green** (incl. updated phase5/6/7/8/9/10/11/12/16); Vite build clean

## Schema additions (migration 016 + seed 004)

- `bataan_locations`: `id INT UNSIGNED AI PK`, `psgc_code VARCHAR(9) UNIQUE`, `name`, `level ENUM(municipality,barangay)`, `municipality_code/name` (barangay parent), `latitude/longitude DECIMAL(9,6)` reference point, `is_active`. 249 rows: 12 municipalities + 237 barangays (PSGC province 030800000), seeded idempotently via `ON DUPLICATE KEY UPDATE`.
- `users.location_id`, `blood_requests.location_id` FK → `bataan_locations.id` (`ON UPDATE CASCADE`, `ON DELETE SET NULL`). `latitude/longitude` retained as backend-resolved reference coordinates for the unchanged engine.

## Reference data methodology (no invented coordinates)

- Hierarchy/names/codes: PSA PSGC via `psgc.gitlab.io` snapshots (12 LGUs, 237 barangays — matches published 237 total). Audited code-by-code and name-by-name against fresh snapshots: 0 missing, 0 extras, 0 mismatches.
- Municipal points: Wikidata P625 poblacion coordinates, cross-checked with Wikipedia town coordinates and existing chapter seeds.
- Barangay points: municipal reference point (PSGC publishes no barangay coordinates; GeoNames ADM4 evaluated and rejected — no parent linkage, duplicate names across towns, mixed subdivisions). Documented in seed header and UI copy; architecture supports finer points via reference-data update.

## API behavior verified (L01–L12)

| Area | Evidence |
|---|---|
| Reference lists | 12 municipalities (L01); 29 Orani barangays incl. Tugatog 030809023 (L02); invalid/missing municipality → 400 (L03/L04); selector APIs expose no coordinates (L16) |
| Profile | municipality resolves to Orani point 14.8/120.533333 (L05); barangay saves names (L06); invalid id → 400 (L07); raw coords rejected (L08) |
| Requests | municipality resolves + names returned (L09); location stays optional with null coords (L10); raw coords rejected (L11); location edit is material (coords re-resolved + `request.material_change` + regeneration) (L12) |
| Legacy | coords-only donor (no `location_id`) still matched with distance (L14); profile PUT without `location_id` leaves location unchanged |

## Match consistency fix verified (L17–L20 + live repro)

- Reproduced staleness pre-fix: donor Samal→Mariveles left `distance_km=3.73` (Samal-era) on the match row indefinitely.
- Fix: `MatchService::refreshMatchesForDonor()` re-runs generation **without bump** for the donor's OPEN live matches on profile location change; dedup keys hold so no duplicate notifications; COMPLETED/CLOSED history untouched.
- Live proof: reorder scenario (Samal 3.7 km vs Abucay 8.6 km) flips correctly after move to Mariveles (~41.1 km); generation unchanged; notification count unchanged; `matches_refreshed` returned.

## Privacy

Match results still expose only `approximate_distance_km` (L13); audit contexts (`profile.updated` field names, `match.generation` trigger/stats) contain no coordinates.

## Known limitations

- Barangay selection is an administrative label until finer survey coordinates are seeded.
- Donor refresh runs synchronously per affected OPEN request (correct at current scale).
- No Playwright/e2e tests exist in the repo; UI verified by code inspection + build.
