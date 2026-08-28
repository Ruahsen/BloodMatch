CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NULL,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    role ENUM('member', 'officer', 'admin') NOT NULL DEFAULT 'member',
    chapter_id TINYINT UNSIGNED NULL,
    verification_status ENUM('unverified', 'pending', 'verified', 'rejected') NOT NULL DEFAULT 'unverified',
    date_of_birth DATE NULL,
    blood_type ENUM('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-') NULL,
    blood_type_source ENUM('self_reported', 'donor_card') NULL,
    blood_type_verified TINYINT(1) NOT NULL DEFAULT 0,
    donor_enrolled_at DATETIME NULL,
    donor_availability ENUM('available', 'unavailable', 'standby') NULL DEFAULT NULL,
    last_verified_donation_at DATETIME NULL,
    latitude DECIMAL(9, 6) NULL,
    longitude DECIMAL(9, 6) NULL,
    account_status ENUM('active', 'deactivated') NOT NULL DEFAULT 'active',
    deactivated_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    INDEX idx_users_chapter (chapter_id),
    INDEX idx_users_role (role),
    INDEX idx_users_verification (verification_status),
    INDEX idx_users_account_status (account_status),
    INDEX idx_users_availability (donor_availability),
    CONSTRAINT fk_users_chapter FOREIGN KEY (chapter_id) REFERENCES chapters (id) ON UPDATE CASCADE,
    CONSTRAINT chk_users_blood_type CHECK (
        blood_type IS NULL
        OR blood_type IN ('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-')
    ),
    CONSTRAINT chk_users_blood_source CHECK (
        (blood_type IS NULL AND blood_type_source IS NULL)
        OR (blood_type IS NOT NULL AND blood_type_source IS NOT NULL)
    ),
    CONSTRAINT chk_users_blood_verified CHECK (
        blood_type_verified = 0
        OR (blood_type_verified = 1 AND blood_type IS NOT NULL)
    ),
    CONSTRAINT chk_users_geo CHECK (
        (latitude IS NULL AND longitude IS NULL)
        OR (latitude IS NOT NULL AND longitude IS NOT NULL)
    ),
    CONSTRAINT chk_users_deactivation CHECK (
        (account_status = 'active' AND deactivated_at IS NULL)
        OR (account_status = 'deactivated' AND deactivated_at IS NOT NULL)
    )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
