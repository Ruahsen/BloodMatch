-- Migration 017: match lifecycle foundation for the authenticated Home / Blood Request Feed.
-- Adds ACCEPTED (requester-selected donor relationship) and WITHDRAWN (donor-explicit
-- terminal withdrawal) to matches.status. WITHDRAWN is terminal for its
-- (request_id, donor_id) pair and is never resurrected by matching regeneration.
-- Adds per-match bilateral contact-sharing consent flags (default false; emails stay
-- in users and are read live through the protected contact endpoint only).
-- Adds a request-scoped status index for capacity counting under row locking.
ALTER TABLE matches
    MODIFY COLUMN status ENUM('POTENTIAL', 'NOTIFIED', 'RESPONDED', 'ACCEPTED', 'COMPLETED', 'CLOSED', 'WITHDRAWN') NOT NULL DEFAULT 'POTENTIAL';

ALTER TABLE matches
    ADD COLUMN donor_share_consent TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN requester_share_consent TINYINT(1) NOT NULL DEFAULT 0;

CREATE INDEX idx_matches_request_status ON matches (request_id, status);
