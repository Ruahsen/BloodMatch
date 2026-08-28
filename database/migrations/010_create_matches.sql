CREATE TABLE matches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id BIGINT UNSIGNED NOT NULL,
    donor_id BIGINT UNSIGNED NOT NULL,
    generation INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('POTENTIAL', 'NOTIFIED', 'RESPONDED', 'COMPLETED', 'CLOSED') NOT NULL DEFAULT 'POTENTIAL',
    distance_km DECIMAL(8, 2) NULL,
    rank_score DECIMAL(10, 4) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_matches_request_donor (request_id, donor_id),
    INDEX idx_matches_request_gen (request_id, generation),
    INDEX idx_matches_status (status),
    INDEX idx_matches_donor (donor_id),
    CONSTRAINT fk_matches_request FOREIGN KEY (request_id) REFERENCES blood_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_matches_donor FOREIGN KEY (donor_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
