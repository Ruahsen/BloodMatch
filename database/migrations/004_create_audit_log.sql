CREATE TABLE audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    target_type VARCHAR(60) NULL,
    target_id VARCHAR(64) NULL,
    context JSON NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_actor (actor_id),
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_target (target_type, target_id),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS audit_log_block_update;
CREATE TRIGGER audit_log_block_update
BEFORE UPDATE ON audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'audit_log is append-only: UPDATE denied';

DROP TRIGGER IF EXISTS audit_log_block_delete;
CREATE TRIGGER audit_log_block_delete
BEFORE DELETE ON audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'audit_log is append-only: DELETE denied';
