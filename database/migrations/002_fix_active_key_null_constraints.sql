DROP TEMPORARY TABLE IF EXISTS landingops_002_active_key_guard;

CREATE TEMPORARY TABLE landingops_002_active_key_guard (
    invalid_row_count BIGINT UNSIGNED NOT NULL,
    CONSTRAINT chk_landingops_002_no_invalid_rows CHECK (invalid_row_count = 0)
) ENGINE=InnoDB;

INSERT INTO landingops_002_active_key_guard(invalid_row_count)
SELECT
    (
        SELECT COUNT(*)
        FROM site_releases
        WHERE
            (status = 'active' AND (active_instance_id IS NULL OR active_instance_id <> instance_id))
            OR (status <> 'active' AND active_instance_id IS NOT NULL)
    )
    +
    (
        SELECT COUNT(*)
        FROM domain_reservations
        WHERE
            (
                status IN ('held', 'committed')
                AND (active_fqdn IS NULL OR active_fqdn <> normalized_fqdn)
            )
            OR (status IN ('released', 'expired') AND active_fqdn IS NOT NULL)
    );

DROP TEMPORARY TABLE landingops_002_active_key_guard;

ALTER TABLE site_releases
    ADD CONSTRAINT chk_site_releases_active_key_not_null CHECK (
        (
            status = 'active'
            AND active_instance_id IS NOT NULL
            AND active_instance_id = instance_id
        )
        OR (status <> 'active' AND active_instance_id IS NULL)
    );

ALTER TABLE domain_reservations
    ADD CONSTRAINT chk_domain_reservations_active_key_not_null CHECK (
        (
            status IN ('held', 'committed')
            AND active_fqdn IS NOT NULL
            AND active_fqdn = normalized_fqdn
        )
        OR (status IN ('released', 'expired') AND active_fqdn IS NULL)
    );
