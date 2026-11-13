ALTER TABLE baota_config
    ADD COLUMN api_key_hint VARCHAR(16) NOT NULL DEFAULT 'configured' AFTER api_key_ciphertext,
    ADD COLUMN admin_state VARCHAR(16) NOT NULL DEFAULT 'enabled' AFTER status,
    ADD COLUMN verification_status VARCHAR(16) NOT NULL DEFAULT 'unverified' AFTER admin_state,
    ADD COLUMN last_verification_attempt_at DATETIME(6) NULL AFTER last_verified_at,
    ADD COLUMN last_verification_result_json JSON NULL AFTER last_verification_attempt_at,
    ADD COLUMN panel_version VARCHAR(64) NULL AFTER last_verification_result_json,
    ADD COLUMN capabilities_json JSON NULL AFTER panel_version,
    ADD COLUMN version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER capabilities_json;

UPDATE baota_config
SET
    admin_state = CASE WHEN status = 'disabled' THEN 'disabled' ELSE 'enabled' END,
    verification_status = 'unverified',
    status = CASE WHEN status = 'disabled' THEN 'disabled' ELSE 'unverified' END,
    last_verified_at = NULL,
    last_verification_attempt_at = NULL,
    last_verification_result_json = JSON_OBJECT('status', 'invalidated', 'code', 'readonly_verification_required'),
    panel_version = NULL,
    capabilities_json = NULL;

ALTER TABLE baota_config
    ADD CONSTRAINT chk_baota_config_admin_state CHECK (admin_state IN ('enabled', 'disabled')),
    ADD CONSTRAINT chk_baota_config_verification_status CHECK (verification_status IN ('unverified', 'valid', 'invalid')),
    ADD CONSTRAINT chk_baota_config_version CHECK (version >= 1);

ALTER TABLE instances
    ADD COLUMN baota_site_external_id VARCHAR(64) NULL AFTER active_release_id,
    ADD COLUMN baota_site_root_path VARCHAR(1024) NULL AFTER baota_site_external_id,
    ADD COLUMN ownership_marker_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER baota_site_root_path,
    ADD CONSTRAINT uq_instances_baota_site_external UNIQUE (baota_site_external_id),
    ADD CONSTRAINT chk_instances_baota_ownership_evidence CHECK (
        (baota_site_external_id IS NULL AND baota_site_root_path IS NULL AND ownership_marker_hash IS NULL)
        OR
        (baota_site_external_id IS NOT NULL AND baota_site_root_path IS NOT NULL AND ownership_marker_hash IS NOT NULL)
    );
