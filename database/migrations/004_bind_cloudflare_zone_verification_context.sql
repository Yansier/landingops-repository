ALTER TABLE cloudflare_connections
    ADD COLUMN credential_context_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER verification_status;

ALTER TABLE cloudflare_zones
    ADD COLUMN verified_credential_context_version BIGINT UNSIGNED NULL AFTER verification_status;

-- 003 曾从旧 status=active 推断 valid；旧状态没有可验证的 Token/Zone 证据，004 保守失效全部旧信任。
UPDATE cloudflare_connections
SET
    verification_status = 'unverified',
    status = CASE WHEN admin_state = 'disabled' THEN 'disabled' ELSE 'unverified' END,
    last_verified_at = NULL,
    last_verification_attempt_at = NULL,
    last_verification_result_json = JSON_OBJECT('status', 'invalidated', 'code', 'credential_context_binding_required')
WHERE verification_status = 'valid' OR status = 'active';

UPDATE cloudflare_zones
SET
    verification_status = 'unverified',
    verified_credential_context_version = NULL,
    cloudflare_status = NULL,
    status = CASE WHEN admin_state = 'disabled' THEN 'disabled' ELSE 'unverified' END,
    last_synced_at = NULL,
    last_verification_result_json = JSON_OBJECT('status', 'invalidated', 'code', 'credential_context_binding_required')
WHERE verification_status = 'valid' OR status = 'active';

ALTER TABLE cloudflare_connections
    ADD CONSTRAINT chk_cloudflare_connections_credential_context_version
        CHECK (credential_context_version >= 1);

ALTER TABLE cloudflare_zones
    ADD CONSTRAINT chk_cloudflare_zones_verified_credential_context
        CHECK (
            (verification_status = 'valid' AND verified_credential_context_version IS NOT NULL)
            OR (verification_status <> 'valid' AND verified_credential_context_version IS NULL)
        );
