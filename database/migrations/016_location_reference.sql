-- 016: canonical Bataan location reference for user-friendly proximity ranking.
-- Municipalities/cities + barangays from official PSGC data (province 030800000).
-- Municipality rows carry verified poblacion reference coordinates (Wikidata P625,
-- cross-checked with Wikipedia town coordinates); barangay rows carry their
-- municipality's reference point until finer survey data is adopted via update.
-- users/blood_requests keep latitude/longitude as backend-resolved reference
-- coordinates consumed by the unchanged matching engine; location_id records
-- which canonical location they were derived from (NULL = legacy/manual record).
CREATE TABLE IF NOT EXISTS bataan_locations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    psgc_code VARCHAR(9) NOT NULL,
    name VARCHAR(120) NOT NULL,
    level ENUM('municipality', 'barangay') NOT NULL,
    municipality_code VARCHAR(9) NOT NULL,
    municipality_name VARCHAR(120) NOT NULL,
    latitude DECIMAL(9, 6) NULL,
    longitude DECIMAL(9, 6) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_location_psgc (psgc_code),
    INDEX idx_location_municipality (municipality_code, level),
    INDEX idx_location_active (is_active)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE users
    ADD COLUMN location_id INT UNSIGNED NULL,
    ADD CONSTRAINT fk_users_location FOREIGN KEY (location_id)
        REFERENCES bataan_locations (id) ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE blood_requests
    ADD COLUMN location_id INT UNSIGNED NULL,
    ADD CONSTRAINT fk_requests_location FOREIGN KEY (location_id)
        REFERENCES bataan_locations (id) ON UPDATE CASCADE ON DELETE SET NULL;
