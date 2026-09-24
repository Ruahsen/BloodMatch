CREATE TABLE notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(60) NOT NULL,
    title VARCHAR(150) NOT NULL,
    body VARCHAR(500) NOT NULL,
    related_type VARCHAR(40) NULL,
    related_id BIGINT UNSIGNED NULL,
    dedup_key VARCHAR(120) NULL,
    generation INT UNSIGNED NULL,
    emailed_at DATETIME NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_notifications_dedup (dedup_key, generation),
    INDEX idx_notifications_user_read (user_id, read_at),
    INDEX idx_notifications_type (type),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
