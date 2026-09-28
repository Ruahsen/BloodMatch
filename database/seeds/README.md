Seed data is re-applied on every `php run_seeds.php` run and must therefore be idempotent (`INSERT ... ON DUPLICATE KEY UPDATE`, `INSERT IGNORE`, or guarded lookups).

Current seeds:

- `001_chapters.sql`: Mt. Samat Chapter (Orani), Mt. Tarak Chapter (Mariveles), Meridian Heights Chapter (Balanga City) — fixed reference data per CONTEXT.md §9.13 (`ON DUPLICATE KEY UPDATE`, 3 rows)
- `002_compatibility_matrix.sql`: red-cell ABO/Rh matrix per CONTEXT.md §9.3 (8 recipient types, CSV `allowed_donor_types`, sole consumer `BloodCompatibilityService`)
- `003_system_settings.sql`: `standby_hours=42`, `cooldown_days=90` (`INSERT IGNORE`, preserves overrides)
- `004_bataan_locations.sql`: canonical Bataan location reference (PSGC province 030800000) — 12 municipalities/cities + 237 barangays = 249 rows; municipality poblacion points (Wikidata P625, cross-checked); barangays inherit their municipality point until finer survey data is adopted; idempotent via `ON DUPLICATE KEY UPDATE`. Backs `LocationService`, `LocationSelector.jsx`, and `location_id` resolution for `users`/`blood_requests`.

Do not seed real member data, passwords, or secrets.
