-- Migration 019: email OTP verification (account-email ownership proof).
--
-- Design notes (see CONTEXT.md separation rules):
-- - `users.email_verified_at` records WHEN the account's login email was
--   proven reachable. It is independent of `verification_status` (officer
--   identity workflow: unverified/pending/verified/rejected) and of
--   `account_status` (active/deactivated). A verified email NEVER implies a
--   verified donor, officer/admin approval, or medical eligibility.
-- - `email_verification_otps` holds single-use OTP challenges. Only the
--   SHA-256 hash is stored; the plaintext 6-digit code exists only long
--   enough to be handed to the shared Mailer transport. At most one usable
--   row per user exists at a time: issuing a new OTP supersedes (deletes)
--   prior unused rows for that user.
-- - No capability, lifecycle, or throttle-table changes: resend limiting
--   reuses the existing `auth_throttle` mechanism from application code.
ALTER TABLE users
    ADD COLUMN email_verified_at DATETIME NULL;

CREATE TABLE email_verification_otps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    otp_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_email_otp_user (user_id),
    INDEX idx_email_otp_expires (expires_at),
    CONSTRAINT fk_email_otp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
