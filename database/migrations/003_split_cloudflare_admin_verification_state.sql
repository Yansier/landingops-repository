ALTER TABLE cloudflare_connections
    ADD COLUMN admin_state VARCHAR(16) NOT NULL DEFAULT 'enabled' AFTER status,
    ADD COLUMN verification_status VARCHAR(16) NOT NULL DEFAULT 'unverified' AFTER admin_state,
    ADD COLUMN last_verification_attempt_at DATETIME(6) NULL AFTER last_verified_at,
    ADD COLUMN last_verification_result_json JSON NULL AFTER last_verification_attempt_at,
    ADD COLUMN version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER last_verification_result_json;

UPDATE cloudflare_connections
SET
    admin_state = CASE WHEN status = 'disabled' THEN 'disabled' ELSE 'enabled' END,
    verification_status = CASE WHEN status = 'active' THEN 'valid' ELSE 'unverified' END;

ALTER TABLE cloudflare_connections
    ADD CONSTRAINT chk_cloudflare_connections_admin_state CHECK (admin_state IN ('enabled', 'disabled')),
    ADD CONSTRAINT chk_cloudflare_connections_verification CHECK (verification_status IN ('unverified', 'valid', 'invalid')),
    ADD CONSTRAINT chk_cloudflare_connections_version CHECK (version >= 1);

ALTER TABLE cloudflare_zones
    ADD COLUMN admin_state VARCHAR(16) NOT NULL DEFAULT 'enabled' AFTER status,
    ADD COLUMN verification_status VARCHAR(16) NOT NULL DEFAULT 'unverified' AFTER admin_state,
    ADD COLUMN cloudflare_status VARCHAR(32) NULL AFTER verification_status,
    ADD COLUMN last_verification_result_json JSON NULL AFTER last_synced_at,
    ADD COLUMN version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER last_verification_result_json;

UPDATE cloudflare_zones
SET
    admin_state = CASE WHEN status = 'disabled' THEN 'disabled' ELSE 'enabled' END,
    verification_status = CASE WHEN status = 'active' THEN 'valid' ELSE 'unverified' END,
    cloudflare_status = CASE WHEN status = 'active' THEN 'active' ELSE NULL END;

ALTER TABLE cloudflare_zones
    ADD CONSTRAINT chk_cloudflare_zones_admin_state CHECK (admin_state IN ('enabled', 'disabled')),
    ADD CONSTRAINT chk_cloudflare_zones_verification CHECK (verification_status IN ('unverified', 'valid', 'invalid')),
    ADD CONSTRAINT chk_cloudflare_zones_version CHECK (version >= 1);
