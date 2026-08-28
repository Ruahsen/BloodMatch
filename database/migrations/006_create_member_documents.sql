CREATE TABLE member_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    doc_type ENUM('national_id', 'donor_card', 'parental_consent') NOT NULL,
    stored_name CHAR(64) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    original_ext VARCHAR(10) NOT NULL DEFAULT '',
    size_bytes INT UNSIGNED NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_member_documents_stored_name (stored_name),
    INDEX idx_member_documents_user (user_id),
    INDEX idx_member_documents_user_type (user_id, doc_type),
    CONSTRAINT fk_member_documents_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
