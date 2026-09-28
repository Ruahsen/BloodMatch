Schema migrations are applied once by `php run_migrations.php` and tracked in `schema_migrations`.

Rules:
- One numbered file per change: `001_create_users.sql`, `002_...`
- Use only statements supported inside a transaction (InnoDB DDL is fine on MySQL 8; avoid mixing engines)
- Never edit an already-applied migration; add a new one
- No credentials or real personal data in any migration

Current migration state: 001–016 applied (verified via `run_migrations.php`; see `docs/erd.md` and `docs/test-log-location.md`).

| Migration | Purpose |
|---|---|
| `001_create_chapters.sql` | Fixed 3-chapter reference (Mt. Samat/Orani, Mt. Tarak/Mariveles, Meridian Heights/Balanga City) with canonical centroids |
| `002_create_users.sql` | Accounts: identity, role, `verification_status` + `account_status`/`deactivated_at`, chapter FK, DOB, blood type + provenance (`blood_type`, `blood_type_source`, `blood_type_verified`), donor enrollment/availability, `last_verified_donation_at`, coordinates |
| `003_create_password_resets.sql` | Hashed single-use reset tokens (~30-min expiry, `used_at`) |
| `004_create_audit_log.sql` | Append-only `audit_log` + `UPDATE`/`DELETE`-blocking triggers |
| `005_create_auth_throttle.sql` | Login/reset throttling (`identifier`, `failed_count`, `locked_until`) |
| `006_create_member_documents.sql` | Verification evidence (`national_id`/`donor_card`/`parental_consent`, 64-hex `stored_name` outside webroot) |
| `007_create_verification_decisions.sql` | Verification history (`verified`/`rejected`/`re_review_requested`/`resubmitted`) |
| `008_create_blood_requests.sql` | Blood requests (requester, immutable `request_chapter_id`, blood type, quantity, facility, urgency, needed datetime, `OPEN`/`FULFILLED`/`CANCELLED`/`EXPIRED`, `review_status`, coordinates) |
| `009_create_compatibility_matrix.sql` | Central 8-type red-cell ABO/Rh lookup consumed only by `BloodCompatibilityService` |
| `010_create_matches.sql` | Persistent `(request_id, donor_id)` matches with `generation`, status, `distance_km`, `rank_score` |
| `011_create_donation_reports.sql` | Donation reports linked to `matches.id` + confirmer |
| `012_create_system_settings.sql` | Key-value settings (`standby_hours=42`, `cooldown_days=90`) |
| `013_create_notifications.sql` | In-app notifications with `UNIQUE(dedup_key, generation)` deduplication |
| `014_performance_indexes.sql` | Composite indexes `blood_requests(request_chapter_id, status, created_at)`, `audit_log(target_type, target_id, created_at)` |
| `015_create_profile_picture.sql` | `users.profile_picture VARCHAR(64) NULL` — 64-hex server-generated filename in `backend/storage/profile_pictures`, NULL when none |
| `016_location_reference.sql` | `bataan_locations` canonical reference (12 municipalities + 237 barangays, PSGC 030800000) + `users.location_id` / `blood_requests.location_id` FKs (`ON UPDATE CASCADE`, `ON DELETE SET NULL`); `latitude`/`longitude` retained as backend-resolved reference coordinates |
