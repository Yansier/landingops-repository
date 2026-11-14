CREATE TABLE IF NOT EXISTS baota_inventory_snapshots (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    baota_config_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    config_version BIGINT UNSIGNED NOT NULL,
    inventory_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    site_ids_json JSON NOT NULL,
    observations_json JSON NOT NULL,
    status VARCHAR(16) NOT NULL,
    next_offset INT UNSIGNED NULL,
    failure_code VARCHAR(64) NULL,
    observed_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_baota_inventory_snapshots_config
        FOREIGN KEY (baota_config_id) REFERENCES baota_config(id) ON DELETE CASCADE,
    CONSTRAINT chk_baota_inventory_snapshots_status
        CHECK (status IN ('collecting', 'complete', 'failed')),
    CONSTRAINT chk_baota_inventory_snapshots_version CHECK (version >= 1),
    CONSTRAINT chk_baota_inventory_snapshots_offset CHECK (
        (status = 'collecting' AND next_offset IS NOT NULL AND failure_code IS NULL)
        OR (status = 'complete' AND next_offset IS NULL AND failure_code IS NULL)
        OR (status = 'failed' AND next_offset IS NULL AND failure_code IS NOT NULL)
    ),
    INDEX idx_baota_inventory_snapshots_lookup
        (baota_config_id, config_version, status, expires_at, observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
