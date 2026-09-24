Schema migrations are applied once by `php run_migrations.php` and tracked in `schema_migrations`.

Rules:
- One numbered file per change: `001_create_users.sql`, `002_...`
- Use only statements supported inside a transaction (InnoDB DDL is fine on MySQL 8; avoid mixing engines)
- Never edit an already-applied migration; add a new one
- No credentials or real personal data in any migration

Phase note: no migrations exist yet — table design lands in Phase 2 per ROADMAP.md.
