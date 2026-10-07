-- Migration 020: claim tokens for the registration-time email OTP flow.
--
-- Why this table exists: registration creates NO session (the user must
-- still log in afterwards), yet the intended journey requires OTP
-- verification immediately after "Create Account". These single-purpose,
-- short-lived bearer tokens authorize ONLY the email-OTP claim endpoints
-- (send / verify / status-via-POST) for the just-registered account.
-- They grant no session, no capabilities, and no access to any other
-- endpoint. Only the SHA-256 hash is stored; the plaintext token is
-- returned once, inside the 201 registration response, to the registrant.
--
-- Lifecycle: minted once per registration; TTL ~30 minutes (parity with
-- password-reset tokens); consumed on successful verification; resends do
-- NOT mint new tokens (the token authorizes the account's verification,
-- independent of OTP rows). If the token lapses unused, the user simply
-- logs in and uses the existing authenticated OTP flow.
-- - No changes to sessions, login, capabilities, verification_status,
--   or the password-reset mechanism.
CREATE TABLE email_otp_claim_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_otp_claim_hash (token_hash),
    INDEX idx_email_otp_claim_user (user_id),
    INDEX idx_email_otp_claim_expires (expires_at),
    CONSTRAINT fk_email_otp_claim_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
