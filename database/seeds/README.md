Seed data is re-applied on every `php run_seeds.php` run and must therefore be idempotent (`INSERT ... ON DUPLICATE KEY UPDATE`, `INSERT IGNORE`, or guarded lookups).

Planned seeds (Phase 2+):
- chapters: Mt. Samat Chapter (Orani), Mt. Tarak Chapter (Mariveles), Meridian Heights Chapter (Balanga City) — fixed reference data per CONTEXT.md §9.13
- compatibility_matrix: red-cell ABO/Rh matrix per CONTEXT.md §9.3

Do not seed real member data, passwords, or secrets.
