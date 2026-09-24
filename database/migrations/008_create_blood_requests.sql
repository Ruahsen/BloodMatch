CREATE TABLE blood_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    requester_id BIGINT UNSIGNED NOT NULL,
    request_chapter_id TINYINT UNSIGNED NULL,
    required_blood_type ENUM('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-') NOT NULL,
    quantity_units TINYINT UNSIGNED NOT NULL DEFAULT 1,
    facility_name VARCHAR(150) NOT NULL,
    latitude DECIMAL(9, 6) NULL,
    longitude DECIMAL(9, 6) NULL,
    urgency ENUM('routine', 'urgent', 'critical') NOT NULL DEFAULT 'routine',
    needed_datetime DATETIME NOT NULL,
    status ENUM('OPEN', 'FULFILLED', 'CANCELLED', 'EXPIRED') NOT NULL DEFAULT 'OPEN',
    review_status ENUM('not_required', 'pending_review') NOT NULL DEFAULT 'not_required',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expired_at DATETIME NULL,
    PRIMARY KEY (id),
    INDEX idx_breq_requester (requester_id),
    INDEX idx_breq_chapter (request_chapter_id),
    INDEX idx_breq_status (status),
    INDEX idx_breq_expiry (status, needed_datetime),
    INDEX idx_breq_urgency (urgency),
    CONSTRAINT fk_breq_requester FOREIGN KEY (requester_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_breq_chapter FOREIGN KEY (request_chapter_id) REFERENCES chapters (id) ON UPDATE CASCADE,
    CONSTRAINT chk_breq_geo CHECK (
        (latitude IS NULL AND longitude IS NULL)
        OR (latitude IS NOT NULL AND longitude IS NOT NULL)
    )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
