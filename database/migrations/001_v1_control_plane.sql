CREATE TABLE IF NOT EXISTS teams (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT uq_teams_slug UNIQUE (slug),
    CONSTRAINT chk_teams_status CHECK (status IN ('active', 'disabled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    team_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    email VARCHAR(254) NOT NULL,
    normalized_email VARCHAR(254) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(24) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    password_changed_at DATETIME(6) NOT NULL,
    last_login_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT uq_users_normalized_email UNIQUE (normalized_email),
    CONSTRAINT fk_users_team FOREIGN KEY (team_id) REFERENCES teams(id),
    CONSTRAINT chk_users_role CHECK (role IN ('super_admin', 'team_admin', 'staff')),
    CONSTRAINT chk_users_status CHECK (status IN ('active', 'disabled')),
    CONSTRAINT chk_users_team_role CHECK (
        (role = 'super_admin' AND team_id IS NULL) OR
        (role IN ('team_admin', 'staff') AND team_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_sessions (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_hash BINARY(32) NOT NULL,
    csrf_hash BINARY(32) NOT NULL,
    user_agent_hash BINARY(32) NULL,
    ip_hash BINARY(32) NULL,
    created_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    revoke_reason VARCHAR(64) NULL,
    CONSTRAINT uq_auth_sessions_token UNIQUE (token_hash),
    CONSTRAINT fk_auth_sessions_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_auth_sessions_user_active (user_id, revoked_at, expires_at),
    INDEX idx_auth_sessions_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS templates (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    slug VARCHAR(100) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    owner_team_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_by_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(20) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT uq_templates_slug UNIQUE (slug),
    CONSTRAINT fk_templates_team FOREIGN KEY (owner_team_id) REFERENCES teams(id),
    CONSTRAINT fk_templates_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    CONSTRAINT chk_templates_status CHECK (status IN ('draft', 'active', 'disabled', 'archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS template_versions (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    template_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version VARCHAR(64) NOT NULL,
    standard_version VARCHAR(16) NOT NULL,
    zip_storage_key VARCHAR(512) NOT NULL,
    zip_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    manifest_json JSON NOT NULL,
    config_schema_json JSON NULL,
    capability_tags_json JSON NOT NULL,
    validation_status VARCHAR(20) NOT NULL,
    validation_report_json JSON NOT NULL,
    uploaded_by_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    published_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    CONSTRAINT uq_template_versions_version UNIQUE (template_id, version),
    CONSTRAINT uq_template_versions_hash UNIQUE (zip_sha256),
    CONSTRAINT fk_template_versions_template FOREIGN KEY (template_id) REFERENCES templates(id),
    CONSTRAINT fk_template_versions_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id),
    CONSTRAINT chk_template_versions_validation CHECK (validation_status IN ('pending', 'valid', 'invalid'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS template_grants (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    template_version_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    team_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) NOT NULL,
    granted_by_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    granted_at DATETIME(6) NOT NULL,
    revoked_by_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    revoked_at DATETIME(6) NULL,
    CONSTRAINT uq_template_grants_version_team UNIQUE (template_version_id, team_id),
    CONSTRAINT fk_template_grants_version FOREIGN KEY (template_version_id) REFERENCES template_versions(id),
    CONSTRAINT fk_template_grants_team FOREIGN KEY (team_id) REFERENCES teams(id),
    CONSTRAINT fk_template_grants_granter FOREIGN KEY (granted_by_user_id) REFERENCES users(id),
    CONSTRAINT fk_template_grants_revoker FOREIGN KEY (revoked_by_user_id) REFERENCES users(id),
    CONSTRAINT chk_template_grants_status CHECK (status IN ('active', 'revoked'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS baota_config (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    singleton_key TINYINT UNSIGNED NOT NULL DEFAULT 1,
    base_url VARCHAR(512) NOT NULL,
    api_key_ciphertext VARBINARY(4096) NOT NULL,
    secret_key_version VARCHAR(40) NOT NULL,
    expected_server_fingerprint VARCHAR(255) NULL,
    status VARCHAR(16) NOT NULL,
    last_verified_at DATETIME(6) NULL,
    created_by_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_by_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT uq_baota_config_singleton UNIQUE (singleton_key),
    CONSTRAINT fk_baota_config_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    CONSTRAINT fk_baota_config_updater FOREIGN KEY (updated_by_user_id) REFERENCES users(id),
    CONSTRAINT chk_baota_config_singleton CHECK (singleton_key = 1),
    CONSTRAINT chk_baota_config_status CHECK (status IN ('active', 'disabled', 'unverified'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cloudflare_connections (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    label VARCHAR(120) NOT NULL,
    account_external_id VARCHAR(128) NULL,
    token_ciphertext VARBINARY(4096) NOT NULL,
    secret_key_version VARCHAR(40) NOT NULL,
    token_hint VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL,
    last_verified_at DATETIME(6) NULL,
    created_by_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT uq_cloudflare_connections_label UNIQUE (label),
    CONSTRAINT fk_cloudflare_connections_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    CONSTRAINT chk_cloudflare_connections_status CHECK (status IN ('active', 'disabled', 'unverified'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cloudflare_zones (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    connection_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_zone_id VARCHAR(128) NOT NULL,
    zone_name VARCHAR(253) NOT NULL,
    normalized_zone_name VARCHAR(253) NOT NULL,
    status VARCHAR(16) NOT NULL,
    last_synced_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT uq_cloudflare_zones_external UNIQUE (connection_id, external_zone_id),
    CONSTRAINT uq_cloudflare_zones_name UNIQUE (normalized_zone_name),
    CONSTRAINT fk_cloudflare_zones_connection FOREIGN KEY (connection_id) REFERENCES cloudflare_connections(id),
    CONSTRAINT chk_cloudflare_zones_status CHECK (status IN ('active', 'disabled', 'unverified'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS primary_domains (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    zone_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    domain_name VARCHAR(253) NOT NULL,
    normalized_domain_name VARCHAR(253) NOT NULL,
    status VARCHAR(16) NOT NULL,
    dns_policy_json JSON NOT NULL,
    created_by_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT uq_primary_domains_name UNIQUE (normalized_domain_name),
    CONSTRAINT fk_primary_domains_zone FOREIGN KEY (zone_id) REFERENCES cloudflare_zones(id),
    CONSTRAINT fk_primary_domains_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    CONSTRAINT chk_primary_domains_status CHECK (status IN ('active', 'disabled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS domain_grants (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    primary_domain_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    team_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) NOT NULL,
    granted_by_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    granted_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    CONSTRAINT uq_domain_grants_domain_team UNIQUE (primary_domain_id, team_id),
    CONSTRAINT fk_domain_grants_domain FOREIGN KEY (primary_domain_id) REFERENCES primary_domains(id),
    CONSTRAINT fk_domain_grants_team FOREIGN KEY (team_id) REFERENCES teams(id),
    CONSTRAINT fk_domain_grants_granter FOREIGN KEY (granted_by_user_id) REFERENCES users(id),
    CONSTRAINT chk_domain_grants_status CHECK (status IN ('active', 'revoked'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_page_assets (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    version VARCHAR(64) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    storage_key VARCHAR(512) NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL,
    uploaded_by_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    CONSTRAINT uq_backup_page_assets_version UNIQUE (version),
    CONSTRAINT uq_backup_page_assets_hash UNIQUE (sha256),
    CONSTRAINT fk_backup_page_assets_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id),
    CONSTRAINT chk_backup_page_assets_status CHECK (status IN ('active', 'disabled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendor_component_versions (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    version VARCHAR(64) NOT NULL,
    storage_key VARCHAR(512) NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    php_constraint VARCHAR(100) NULL,
    compatibility_json JSON NOT NULL,
    status VARCHAR(16) NOT NULL,
    uploaded_by_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    CONSTRAINT uq_vendor_component_name_version UNIQUE (name, version),
    CONSTRAINT uq_vendor_component_hash UNIQUE (sha256),
    CONSTRAINT fk_vendor_component_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id),
    CONSTRAINT chk_vendor_component_status CHECK (status IN ('active', 'disabled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS instances (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    team_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    template_version_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    primary_domain_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    fqdn VARCHAR(253) NOT NULL,
    normalized_fqdn VARCHAR(253) NOT NULL,
    site_path_key VARCHAR(160) NOT NULL,
    status VARCHAR(20) NOT NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_by_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    current_owner_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    native_admin_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    backup_page_asset_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    vendor_component_version_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    active_config_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    active_release_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    archived_at DATETIME(6) NULL,
    CONSTRAINT uq_instances_fqdn UNIQUE (normalized_fqdn),
    CONSTRAINT uq_instances_site_path UNIQUE (site_path_key),
    CONSTRAINT fk_instances_team FOREIGN KEY (team_id) REFERENCES teams(id),
    CONSTRAINT fk_instances_template_version FOREIGN KEY (template_version_id) REFERENCES template_versions(id),
    CONSTRAINT fk_instances_primary_domain FOREIGN KEY (primary_domain_id) REFERENCES primary_domains(id),
    CONSTRAINT fk_instances_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    CONSTRAINT fk_instances_owner FOREIGN KEY (current_owner_user_id) REFERENCES users(id),
    CONSTRAINT fk_instances_backup FOREIGN KEY (backup_page_asset_id) REFERENCES backup_page_assets(id),
    CONSTRAINT fk_instances_vendor FOREIGN KEY (vendor_component_version_id) REFERENCES vendor_component_versions(id),
    CONSTRAINT chk_instances_status CHECK (status IN ('draft', 'reserved', 'deploying', 'active', 'degraded', 'failed', 'disabled', 'archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS instance_owner_history (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    instance_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    from_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    to_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    changed_by_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_instance_owner_history_instance FOREIGN KEY (instance_id) REFERENCES instances(id),
    CONSTRAINT fk_instance_owner_history_from FOREIGN KEY (from_user_id) REFERENCES users(id),
    CONSTRAINT fk_instance_owner_history_to FOREIGN KEY (to_user_id) REFERENCES users(id),
    CONSTRAINT fk_instance_owner_history_actor FOREIGN KEY (changed_by_user_id) REFERENCES users(id),
    INDEX idx_instance_owner_history_instance (instance_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS config_versions (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    instance_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision BIGINT UNSIGNED NOT NULL,
    schema_version VARCHAR(64) NOT NULL,
    content_hash CHAR(71) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    public_hash CHAR(71) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    config_json JSON NOT NULL,
    source VARCHAR(24) NOT NULL,
    status VARCHAR(20) NOT NULL,
    base_revision BIGINT UNSIGNED NOT NULL,
    restored_from_config_version_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_by_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    applied_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    CONSTRAINT uq_config_versions_revision UNIQUE (instance_id, revision),
    CONSTRAINT fk_config_versions_instance FOREIGN KEY (instance_id) REFERENCES instances(id),
    CONSTRAINT fk_config_versions_restored FOREIGN KEY (restored_from_config_version_id) REFERENCES config_versions(id),
    CONSTRAINT fk_config_versions_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    CONSTRAINT chk_config_versions_source CHECK (source IN ('landingops', 'native_admin', 'reconciled', 'restore')),
    CONSTRAINT chk_config_versions_status CHECK (status IN ('pending', 'applied', 'conflict', 'failed')),
    INDEX idx_config_versions_content_hash (instance_id, content_hash),
    INDEX idx_config_versions_public_hash (instance_id, public_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_releases (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    instance_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    config_version_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    release_no BIGINT UNSIGNED NOT NULL,
    release_path_key VARCHAR(255) NOT NULL,
    manifest_hash CHAR(71) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    public_config_asset VARCHAR(512) NOT NULL,
    public_hash CHAR(71) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(20) NOT NULL,
    previous_release_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    activated_at DATETIME(6) NULL,
    health_checked_at DATETIME(6) NULL,
    failure_summary VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL,
    active_instance_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    CONSTRAINT uq_site_releases_no UNIQUE (instance_id, release_no),
    CONSTRAINT uq_site_releases_path UNIQUE (instance_id, release_path_key),
    CONSTRAINT uq_site_releases_one_active UNIQUE (active_instance_id),
    CONSTRAINT fk_site_releases_instance FOREIGN KEY (instance_id) REFERENCES instances(id),
    CONSTRAINT fk_site_releases_config FOREIGN KEY (config_version_id) REFERENCES config_versions(id),
    CONSTRAINT fk_site_releases_previous FOREIGN KEY (previous_release_id) REFERENCES site_releases(id),
    CONSTRAINT chk_site_releases_status CHECK (status IN ('staged', 'active', 'known_good', 'failed', 'retained', 'purged')),
    CONSTRAINT chk_site_releases_active_key CHECK (
        (status = 'active' AND active_instance_id = instance_id) OR
        (status <> 'active' AND active_instance_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deployment_batches (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    team_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(20) NOT NULL,
    total_count INT UNSIGNED NOT NULL,
    succeeded_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    CONSTRAINT fk_deployment_batches_team FOREIGN KEY (team_id) REFERENCES teams(id),
    CONSTRAINT fk_deployment_batches_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    CONSTRAINT chk_deployment_batches_status CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'partial', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deployment_jobs (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    batch_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    instance_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    baota_config_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation VARCHAR(24) NOT NULL,
    status VARCHAR(24) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    priority INT NOT NULL DEFAULT 0,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
    lease_owner VARCHAR(191) NULL,
    lease_expires_at DATETIME(6) NULL,
    next_run_at DATETIME(6) NOT NULL,
    requested_by_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_error_code VARCHAR(100) NULL,
    last_error_summary VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL,
    started_at DATETIME(6) NULL,
    finished_at DATETIME(6) NULL,
    CONSTRAINT uq_deployment_jobs_idempotency UNIQUE (idempotency_key),
    CONSTRAINT fk_deployment_jobs_batch FOREIGN KEY (batch_id) REFERENCES deployment_batches(id),
    CONSTRAINT fk_deployment_jobs_instance FOREIGN KEY (instance_id) REFERENCES instances(id),
    CONSTRAINT fk_deployment_jobs_baota FOREIGN KEY (baota_config_id) REFERENCES baota_config(id),
    CONSTRAINT fk_deployment_jobs_requester FOREIGN KEY (requested_by_user_id) REFERENCES users(id),
    CONSTRAINT chk_deployment_jobs_operation CHECK (operation IN ('deploy', 'config_publish', 'verify', 'repair')),
    CONSTRAINT chk_deployment_jobs_status CHECK (status IN ('queued', 'running', 'retry_wait', 'needs_attention', 'compensating', 'cancelled', 'partial', 'succeeded', 'failed')),
    INDEX idx_deployment_jobs_claim (status, next_run_at, priority),
    INDEX idx_deployment_jobs_lease (lease_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_steps (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    job_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    step_key VARCHAR(80) NOT NULL,
    sequence_no INT UNSIGNED NOT NULL,
    status VARCHAR(24) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    input_digest CHAR(71) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_resource_type VARCHAR(80) NULL,
    external_resource_id VARCHAR(255) NULL,
    created_by_this_step BOOLEAN NOT NULL DEFAULT FALSE,
    result_json JSON NULL,
    error_code VARCHAR(100) NULL,
    error_summary VARCHAR(1000) NULL,
    started_at DATETIME(6) NULL,
    finished_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT uq_job_steps_key UNIQUE (job_id, step_key),
    CONSTRAINT uq_job_steps_idempotency UNIQUE (idempotency_key),
    CONSTRAINT fk_job_steps_job FOREIGN KEY (job_id) REFERENCES deployment_jobs(id),
    CONSTRAINT chk_job_steps_status CHECK (status IN ('pending', 'running', 'succeeded', 'retry_wait', 'needs_attention', 'compensating', 'compensated', 'failed', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS domain_reservations (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    normalized_fqdn VARCHAR(253) NOT NULL,
    instance_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    job_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status VARCHAR(16) NOT NULL,
    lease_expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    active_fqdn VARCHAR(253) NULL,
    CONSTRAINT uq_domain_reservations_active UNIQUE (active_fqdn),
    CONSTRAINT fk_domain_reservations_instance FOREIGN KEY (instance_id) REFERENCES instances(id),
    CONSTRAINT fk_domain_reservations_job FOREIGN KEY (job_id) REFERENCES deployment_jobs(id),
    CONSTRAINT chk_domain_reservations_status CHECK (status IN ('held', 'committed', 'released', 'expired')),
    CONSTRAINT chk_domain_reservations_active_key CHECK (
        (status IN ('held', 'committed') AND active_fqdn = normalized_fqdn) OR
        (status IN ('released', 'expired') AND active_fqdn IS NULL)
    ),
    INDEX idx_domain_reservations_lease (status, lease_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    actor_user_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    actor_type VARCHAR(24) NOT NULL,
    team_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    action VARCHAR(120) NOT NULL,
    resource_type VARCHAR(80) NOT NULL,
    resource_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id VARCHAR(64) NOT NULL,
    job_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    before_digest CHAR(71) CHARACTER SET ascii COLLATE ascii_bin NULL,
    after_digest CHAR(71) CHARACTER SET ascii COLLATE ascii_bin NULL,
    metadata_json JSON NOT NULL,
    ip_hash BINARY(32) NULL,
    created_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users(id),
    CONSTRAINT fk_audit_logs_team FOREIGN KEY (team_id) REFERENCES teams(id),
    CONSTRAINT fk_audit_logs_job FOREIGN KEY (job_id) REFERENCES deployment_jobs(id),
    CONSTRAINT chk_audit_logs_actor_type CHECK (actor_type IN ('user', 'worker', 'native_admin', 'system')),
    INDEX idx_audit_logs_resource (resource_type, resource_id, created_at),
    INDEX idx_audit_logs_actor (actor_user_id, created_at),
    INDEX idx_audit_logs_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
