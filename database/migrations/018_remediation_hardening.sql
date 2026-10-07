-- Migration 018: remediation hardening (audit follow-up C/H/M fixes).
-- 1. users.session_version: server-side session revocation counter. Bumped on
--    password reset, deactivation/reactivation, and role/chapter changes so
--    stale cookies stop passing requireActiveUser even though PHP file
--    sessions cannot delete a peer session directly.
-- 2. notifications dedup hardening: backfill NULL dedup_key/generation rows
--    (MySQL treats NULLs as distinct, silently opting rows out of the
--    UNIQUE(dedup_key, generation) contract), then enforce NOT NULL.
-- 3. donation_reports(match_id, status) index for the atomic
--    insert-if-no-pending guard and the pending-exists check.
-- 4. matches(donor_id, status) index for per-donor feed/match-tab lookups
--    (017 added only (request_id, status)).
ALTER TABLE users
    ADD COLUMN session_version INT NOT NULL DEFAULT 1;

UPDATE notifications SET dedup_key = CONCAT('legacy-null:', id) WHERE dedup_key IS NULL;

UPDATE notifications SET generation = 0 WHERE generation IS NULL;

ALTER TABLE notifications
    MODIFY COLUMN dedup_key VARCHAR(120) NOT NULL,
    MODIFY COLUMN generation INT UNSIGNED NOT NULL DEFAULT 0;

CREATE INDEX idx_drep_match_status ON donation_reports (match_id, status);

CREATE INDEX idx_matches_donor_status ON matches (donor_id, status);
