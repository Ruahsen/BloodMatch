CREATE TABLE compatibility_matrix (
    recipient_type VARCHAR(3) NOT NULL,
    allowed_donor_types TEXT NOT NULL,
    PRIMARY KEY (recipient_type),
    CONSTRAINT chk_cmatrix_type CHECK (
        recipient_type IN ('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-')
    )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
