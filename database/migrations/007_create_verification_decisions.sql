CREATE TABLE verification_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_user_id BIGINT UNSIGNED NOT NULL,
    officer_id BIGINT UNSIGNED NULL,
    decision ENUM('verified', 'rejected', 're_review_requested', 'resubmitted') NOT NULL,
    reason VARCHAR(500) NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    INDEX idx_verdec_target (target_user_id),
    INDEX idx_verdec_officer (officer_id),
    CONSTRAINT fk_verdec_target FOREIGN KEY (target_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_verdec_officer FOREIGN KEY (officer_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
