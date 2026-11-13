<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Repository;

use PDO;
use Throwable;

final class PdoControlPlaneRepository implements ControlPlaneRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function countUsersByRole(string $role): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE role = ?');
        $statement->execute([$role]);
        return (int) $statement->fetchColumn();
    }

    public function findUserByEmail(string $normalizedEmail): ?array
    {
        return $this->one('SELECT * FROM users WHERE normalized_email = ?', [$normalizedEmail]);
    }

    public function findUserById(string $id): ?array
    {
        return $this->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function insertUser(array $user): void
    {
        $this->execute(
            'INSERT INTO users(id, team_id, email, normalized_email, display_name, password_hash, role, status, '
            . 'version, password_changed_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $user['id'], $user['team_id'], $user['email'], $user['normalized_email'], $user['display_name'],
                $user['password_hash'], $user['role'], $user['status'], $user['version'],
                $user['password_changed_at'], $user['created_at'], $user['updated_at'],
            ],
        );
    }

    public function insertTeam(array $team): void
    {
        $this->execute(
            'INSERT INTO teams(id, name, slug, status, version, created_at, updated_at) VALUES (?,?,?,?,?,?,?)',
            [$team['id'], $team['name'], $team['slug'], $team['status'], $team['version'], $team['created_at'], $team['updated_at']],
        );
    }

    public function findTeamById(string $id): ?array
    {
        return $this->one('SELECT id, name, slug, status, version, created_at, updated_at FROM teams WHERE id = ?', [$id]);
    }

    public function listTeams(): array
    {
        return $this->all('SELECT id, name, slug, status, version, created_at, updated_at FROM teams ORDER BY created_at, id');
    }

    public function listUsers(?string $teamId): array
    {
        if ($teamId === null) {
            return $this->all('SELECT * FROM users ORDER BY created_at, id');
        }
        return $this->all('SELECT * FROM users WHERE team_id = ? ORDER BY created_at, id', [$teamId]);
    }

    public function updateLastLogin(string $userId, string $at): void
    {
        $this->execute('UPDATE users SET last_login_at = ?, updated_at = ? WHERE id = ?', [$at, $at, $userId]);
    }

    public function updateUserProfile(string $userId, string $displayName, string $at): void
    {
        $this->execute(
            'UPDATE users SET display_name = ?, version = version + 1, updated_at = ? WHERE id = ?',
            [$displayName, $at, $userId],
        );
    }

    public function updatePassword(string $userId, string $hash, string $at): void
    {
        $this->execute(
            'UPDATE users SET password_hash = ?, password_changed_at = ?, version = version + 1, updated_at = ? WHERE id = ?',
            [$hash, $at, $at, $userId],
        );
    }

    public function disableUser(string $userId, string $at): void
    {
        $this->execute(
            "UPDATE users SET status = 'disabled', version = version + 1, updated_at = ? WHERE id = ?",
            [$at, $userId],
        );
    }

    public function insertSession(array $session): void
    {
        $this->execute(
            'INSERT INTO auth_sessions(id, user_id, token_hash, csrf_hash, user_agent_hash, ip_hash, '
            . 'created_at, last_seen_at, expires_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [
                $session['id'], $session['user_id'], $session['token_hash'], $session['csrf_hash'],
                $session['user_agent_hash'], $session['ip_hash'], $session['created_at'],
                $session['last_seen_at'], $session['expires_at'],
            ],
        );
    }

    public function findSessionByHash(string $tokenHash): ?array
    {
        return $this->one(
            'SELECT u.*, s.id AS session_id, s.csrf_hash, s.created_at AS session_created_at, '
            . 's.expires_at, s.revoked_at FROM auth_sessions s JOIN users u ON u.id = s.user_id '
            . 'WHERE s.token_hash = ? AND s.revoked_at IS NULL',
            [$tokenHash],
        );
    }

    public function updateSessionCsrf(string $sessionId, string $csrfHash, string $at): void
    {
        $this->execute(
            'UPDATE auth_sessions SET csrf_hash = ?, last_seen_at = ? WHERE id = ? AND revoked_at IS NULL',
            [$csrfHash, $at, $sessionId],
        );
    }

    public function revokeSession(string $sessionId, string $reason, string $at): void
    {
        $this->execute(
            'UPDATE auth_sessions SET revoked_at = COALESCE(revoked_at, ?), revoke_reason = COALESCE(revoke_reason, ?) WHERE id = ?',
            [$at, $reason, $sessionId],
        );
    }

    public function revokeUserSessions(string $userId, string $reason, string $at): void
    {
        $this->execute(
            'UPDATE auth_sessions SET revoked_at = ?, revoke_reason = ? WHERE user_id = ? AND revoked_at IS NULL',
            [$at, $reason, $userId],
        );
    }

    public function listInstances(?string $teamId, ?string $ownerUserId): array
    {
        $sql = 'SELECT id, team_id, template_version_id, primary_domain_id, fqdn, status, version, '
            . 'created_by_user_id, current_owner_user_id, native_admin_enabled, active_config_revision, '
            . 'active_release_id, normalized_fqdn, site_path_key, baota_site_external_id, '
            . 'baota_site_root_path, ownership_marker_hash, created_at, updated_at FROM instances';
        $where = [];
        $parameters = [];
        if ($teamId !== null) {
            $where[] = 'team_id = ?';
            $parameters[] = $teamId;
        }
        if ($ownerUserId !== null) {
            $where[] = 'current_owner_user_id = ?';
            $parameters[] = $ownerUserId;
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        return $this->all($sql . ' ORDER BY created_at, id', $parameters);
    }

    public function findInstance(string $id, bool $forUpdate = false): ?array
    {
        return $this->one('SELECT * FROM instances WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$id]);
    }

    public function findInstanceByNormalizedFqdn(string $normalizedFqdn): ?array
    {
        return $this->one('SELECT * FROM instances WHERE normalized_fqdn = ?', [$normalizedFqdn]);
    }

    public function findInstanceBySitePathKey(string $sitePathKey): ?array
    {
        return $this->one('SELECT * FROM instances WHERE site_path_key = ?', [$sitePathKey]);
    }

    public function hasActiveDomainReservation(string $normalizedFqdn): bool
    {
        return $this->one(
            "SELECT id FROM domain_reservations WHERE normalized_fqdn = ? AND status IN ('held','committed') LIMIT 1",
            [$normalizedFqdn],
        ) !== null;
    }

    public function updateInstanceOwner(string $instanceId, string $ownerId, int $expectedVersion, string $at): bool
    {
        $statement = $this->execute(
            'UPDATE instances SET current_owner_user_id = ?, version = version + 1, updated_at = ? '
            . 'WHERE id = ? AND version = ?',
            [$ownerId, $at, $instanceId, $expectedVersion],
        );
        return $statement->rowCount() === 1;
    }

    public function insertOwnerHistory(array $history): void
    {
        $this->execute(
            'INSERT INTO instance_owner_history(id, instance_id, from_user_id, to_user_id, changed_by_user_id, reason, created_at) '
            . 'VALUES (?,?,?,?,?,?,?)',
            [
                $history['id'], $history['instance_id'], $history['from_user_id'], $history['to_user_id'],
                $history['changed_by_user_id'], $history['reason'], $history['created_at'],
            ],
        );
    }

    public function findTemplateVersionAuthorization(string $templateVersionId): ?array
    {
        return $this->one(
            'SELECT tv.id, tv.template_id, tv.uploaded_by_user_id, tv.validation_status, '
            . 't.owner_team_id, t.status AS template_status '
            . 'FROM template_versions tv JOIN templates t ON t.id = tv.template_id WHERE tv.id = ?',
            [$templateVersionId],
        );
    }

    public function findTemplateBySlug(string $slug): ?array
    {
        return $this->one('SELECT * FROM templates WHERE slug = ?', [$slug]);
    }

    public function findTemplateVersionByHash(string $sha256): ?array
    {
        return $this->one('SELECT * FROM template_versions WHERE zip_sha256 = ?', [$sha256]);
    }

    public function findTemplateVersionByTemplateAndVersion(string $templateId, string $version): ?array
    {
        return $this->one(
            'SELECT * FROM template_versions WHERE template_id = ? AND version = ?',
            [$templateId, $version],
        );
    }

    public function insertTemplate(array $template): void
    {
        $this->execute(
            'INSERT INTO templates(id, slug, name, description, owner_team_id, created_by_user_id, status, created_at, updated_at) '
            . 'VALUES (?,?,?,?,?,?,?,?,?)',
            [
                $template['id'], $template['slug'], $template['name'], $template['description'],
                $template['owner_team_id'], $template['created_by_user_id'], $template['status'],
                $template['created_at'], $template['updated_at'],
            ],
        );
    }

    public function insertTemplateVersion(array $version): void
    {
        $this->execute(
            'INSERT INTO template_versions(id, template_id, version, standard_version, zip_storage_key, zip_sha256, '
            . 'manifest_json, config_schema_json, capability_tags_json, validation_status, validation_report_json, '
            . 'uploaded_by_user_id, published_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $version['id'], $version['template_id'], $version['version'], $version['standard_version'],
                $version['zip_storage_key'], $version['zip_sha256'], $version['manifest_json'],
                $version['config_schema_json'], $version['capability_tags_json'], $version['validation_status'],
                $version['validation_report_json'], $version['uploaded_by_user_id'], $version['published_at'],
                $version['created_at'],
            ],
        );
    }

    public function listTemplateVersions(?string $teamId): array
    {
        $sql = 'SELECT t.id AS template_id, t.slug, t.name AS template_name, t.description, '
            . 't.owner_team_id, ot.name AS owner_team_name, t.created_by_user_id, creator.display_name AS creator_name, '
            . 't.status AS template_status, t.created_at AS template_created_at, '
            . 'tv.id AS version_id, tv.version, tv.standard_version, tv.zip_sha256, tv.validation_status, '
            . 'tv.validation_report_json, tv.uploaded_by_user_id, uploader.display_name AS uploader_name, '
            . 'tv.published_at, tv.created_at AS version_created_at '
            . 'FROM template_versions tv JOIN templates t ON t.id = tv.template_id '
            . 'JOIN users creator ON creator.id = t.created_by_user_id '
            . 'JOIN users uploader ON uploader.id = tv.uploaded_by_user_id '
            . 'LEFT JOIN teams ot ON ot.id = t.owner_team_id '
            . "WHERE t.status = 'active' AND tv.validation_status = 'valid'";
        $parameters = [];
        if ($teamId !== null) {
            $sql .= " AND EXISTS (SELECT 1 FROM template_grants tg WHERE tg.template_version_id = tv.id "
                . "AND tg.team_id = ? AND tg.status = 'active')";
            $parameters[] = $teamId;
        }
        return $this->all($sql . ' ORDER BY t.created_at DESC, tv.created_at DESC, tv.id DESC', $parameters);
    }

    public function findTemplateVersionDetail(string $templateVersionId, ?string $teamId): ?array
    {
        $sql = 'SELECT tv.id, tv.validation_status, tv.validation_report_json FROM template_versions tv '
            . "JOIN templates t ON t.id = tv.template_id WHERE tv.id = ? AND t.status = 'active'";
        $parameters = [$templateVersionId];
        if ($teamId !== null) {
            $sql .= " AND EXISTS (SELECT 1 FROM template_grants tg WHERE tg.template_version_id = tv.id "
                . "AND tg.team_id = ? AND tg.status = 'active')";
            $parameters[] = $teamId;
        }
        return $this->one($sql, $parameters);
    }

    public function listTemplateGrants(string $templateVersionId): array
    {
        return $this->all(
            'SELECT tg.team_id, team.name AS team_name, tg.status, tg.granted_at, tg.revoked_at '
            . 'FROM template_grants tg JOIN teams team ON team.id = tg.team_id '
            . 'WHERE tg.template_version_id = ? ORDER BY team.name, team.id',
            [$templateVersionId],
        );
    }

    public function hasTemplateGrant(string $templateVersionId, string $teamId): bool
    {
        return $this->exists(
            "SELECT 1 FROM template_grants WHERE template_version_id = ? AND team_id = ? AND status = 'active'",
            [$templateVersionId, $teamId],
        );
    }

    public function createTemplateGrant(array $grant): bool
    {
        $statement = $this->execute(
            'INSERT INTO template_grants(id, template_version_id, team_id, status, granted_by_user_id, granted_at, '
            . 'revoked_by_user_id, revoked_at) VALUES (?,?,?,\'active\',?,?,NULL,NULL) '
            . 'ON DUPLICATE KEY UPDATE id = id',
            [
                $grant['id'], $grant['template_version_id'], $grant['team_id'],
                $grant['granted_by_user_id'], $grant['granted_at'],
            ],
        );
        return $statement->rowCount() === 1;
    }

    public function grantTemplateVersion(array $grant): void
    {
        $this->execute(
            'INSERT INTO template_grants(id, template_version_id, team_id, status, granted_by_user_id, granted_at, '
            . 'revoked_by_user_id, revoked_at) VALUES (?,?,?,\'active\',?,?,NULL,NULL) '
            . 'ON DUPLICATE KEY UPDATE status = \'active\', granted_by_user_id = VALUES(granted_by_user_id), '
            . 'granted_at = VALUES(granted_at), revoked_by_user_id = NULL, revoked_at = NULL',
            [
                $grant['id'], $grant['template_version_id'], $grant['team_id'],
                $grant['granted_by_user_id'], $grant['granted_at'],
            ],
        );
    }

    public function revokeTemplateVersionGrant(
        string $templateVersionId,
        string $teamId,
        string $actorUserId,
        string $at,
    ): bool {
        $statement = $this->execute(
            "UPDATE template_grants SET status = 'revoked', revoked_by_user_id = ?, revoked_at = ? "
            . "WHERE template_version_id = ? AND team_id = ? AND status = 'active'",
            [$actorUserId, $at, $templateVersionId, $teamId],
        );
        return $statement->rowCount() === 1;
    }

    public function listCloudflareConnections(): array
    {
        return $this->all(
            'SELECT id,label,account_external_id,token_hint,status,admin_state,verification_status,credential_context_version,last_verified_at,'
            . 'last_verification_attempt_at,last_verification_result_json,version,created_at,updated_at '
            . 'FROM cloudflare_connections ORDER BY created_at DESC,id DESC',
        );
    }

    public function findCloudflareConnection(string $id): ?array
    {
        return $this->one('SELECT * FROM cloudflare_connections WHERE id = ?', [$id]);
    }

    public function findCloudflareConnectionByLabel(string $label): ?array
    {
        return $this->one('SELECT * FROM cloudflare_connections WHERE label = ?', [$label]);
    }

    public function insertCloudflareConnection(array $connection): void
    {
        $this->execute(
            'INSERT INTO cloudflare_connections(id,label,account_external_id,token_ciphertext,secret_key_version,'
            . 'token_hint,status,admin_state,verification_status,credential_context_version,last_verified_at,last_verification_attempt_at,'
            . 'last_verification_result_json,version,created_by_user_id,created_at,updated_at) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $connection['id'], $connection['label'], $connection['account_external_id'], $connection['token_ciphertext'],
                $connection['secret_key_version'], $connection['token_hint'], $connection['status'],
                $connection['admin_state'], $connection['verification_status'], $connection['credential_context_version'], $connection['last_verified_at'],
                $connection['last_verification_attempt_at'], $connection['last_verification_result_json'], $connection['version'],
                $connection['created_by_user_id'], $connection['created_at'], $connection['updated_at'],
            ],
        );
    }

    public function updateCloudflareConnection(string $id, array $changes, int $expectedVersion): bool
    {
        $allowed = [
            'label', 'account_external_id', 'token_ciphertext', 'secret_key_version', 'token_hint', 'status',
            'admin_state', 'verification_status', 'credential_context_version', 'last_verified_at', 'last_verification_attempt_at',
            'last_verification_result_json', 'updated_at',
        ];
        $set = [];
        $values = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $changes)) {
                $set[] = $column . ' = ?';
                $values[] = $changes[$column];
            }
        }
        if ($set === []) {
            return false;
        }
        $set[] = 'version = version + 1';
        $values[] = $id;
        $values[] = $expectedVersion;
        return $this->execute(
            'UPDATE cloudflare_connections SET ' . implode(', ', $set) . ' WHERE id = ? AND version = ?',
            $values,
        )->rowCount() === 1;
    }

    public function invalidateCloudflareZonesForConnection(string $connectionId, string $resultJson, string $at): int
    {
        return $this->execute(
            "UPDATE cloudflare_zones SET verification_status='unverified',verified_credential_context_version=NULL,"
            . "cloudflare_status=NULL,status=CASE WHEN admin_state='disabled' THEN 'disabled' ELSE 'unverified' END,"
            . 'last_synced_at=NULL,last_verification_result_json=?,version=version+1,updated_at=? WHERE connection_id=?',
            [$resultJson, $at, $connectionId],
        )->rowCount();
    }

    public function listCloudflareZones(): array
    {
        return $this->all(
            'SELECT z.id,z.connection_id,z.external_zone_id,z.zone_name,z.normalized_zone_name,z.status,'
            . 'z.admin_state,z.verification_status,z.verified_credential_context_version,z.cloudflare_status,z.last_synced_at,z.last_verification_result_json,z.version,'
            . 'z.created_at,z.updated_at,c.label AS connection_label,c.status AS connection_status,'
            . 'c.admin_state AS connection_admin_state,c.verification_status AS connection_verification_status,'
            . 'c.credential_context_version AS connection_credential_context_version '
            . 'FROM cloudflare_zones z JOIN cloudflare_connections c ON c.id=z.connection_id '
            . 'ORDER BY z.created_at DESC,z.id DESC',
        );
    }

    public function findCloudflareZone(string $id): ?array
    {
        return $this->one(
            'SELECT z.*,c.label AS connection_label,c.status AS connection_status,'
            . 'c.admin_state AS connection_admin_state,c.verification_status AS connection_verification_status,'
            . 'c.credential_context_version AS connection_credential_context_version '
            . 'FROM cloudflare_zones z '
            . 'JOIN cloudflare_connections c ON c.id=z.connection_id WHERE z.id=?',
            [$id],
        );
    }

    public function findCloudflareZoneByName(string $normalizedName): ?array
    {
        return $this->one('SELECT * FROM cloudflare_zones WHERE normalized_zone_name = ?', [$normalizedName]);
    }

    public function findCloudflareZoneByExternal(string $connectionId, string $externalZoneId): ?array
    {
        return $this->one(
            'SELECT * FROM cloudflare_zones WHERE connection_id = ? AND external_zone_id = ?',
            [$connectionId, $externalZoneId],
        );
    }

    public function insertCloudflareZone(array $zone): void
    {
        $this->execute(
            'INSERT INTO cloudflare_zones(id,connection_id,external_zone_id,zone_name,normalized_zone_name,status,'
            . 'admin_state,verification_status,verified_credential_context_version,cloudflare_status,last_synced_at,last_verification_result_json,version,created_at,updated_at) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $zone['id'], $zone['connection_id'], $zone['external_zone_id'], $zone['zone_name'],
                $zone['normalized_zone_name'], $zone['status'], $zone['admin_state'], $zone['verification_status'], $zone['verified_credential_context_version'], $zone['cloudflare_status'],
                $zone['last_synced_at'], $zone['last_verification_result_json'], $zone['version'],
                $zone['created_at'], $zone['updated_at'],
            ],
        );
    }

    public function updateCloudflareZone(string $id, array $changes, int $expectedVersion): bool
    {
        $allowed = [
            'status', 'admin_state', 'verification_status', 'verified_credential_context_version', 'cloudflare_status', 'last_synced_at',
            'last_verification_result_json', 'updated_at',
        ];
        $set = [];
        $values = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $changes)) {
                $set[] = $column . ' = ?';
                $values[] = $changes[$column];
            }
        }
        if ($set === []) {
            return false;
        }
        $set[] = 'version = version + 1';
        $values[] = $id;
        $values[] = $expectedVersion;
        return $this->execute(
            'UPDATE cloudflare_zones SET ' . implode(', ', $set) . ' WHERE id=? AND version=?',
            $values,
        )->rowCount() === 1;
    }

    public function listPrimaryDomains(?string $teamId, bool $usableOnly): array
    {
        $sql = 'SELECT d.id,d.zone_id,d.domain_name,d.normalized_domain_name,d.status AS domain_status,d.created_at,d.updated_at,'
            . 'z.zone_name,z.normalized_zone_name,z.status AS zone_status,z.admin_state AS zone_admin_state,'
            . 'z.verification_status AS zone_verification_status,z.verified_credential_context_version AS zone_verified_credential_context_version,z.last_verification_result_json AS zone_verification_result_json,'
            . 'z.cloudflare_status AS zone_cloudflare_status,'
            . 'z.connection_id,'
            . 'c.label AS connection_label,c.status AS connection_status,c.admin_state AS connection_admin_state,'
            . 'c.verification_status AS connection_verification_status,c.credential_context_version AS connection_credential_context_version '
            . 'FROM primary_domains d JOIN cloudflare_zones z ON z.id=d.zone_id '
            . 'JOIN cloudflare_connections c ON c.id=z.connection_id';
        $where = [];
        $parameters = [];
        if ($teamId !== null) {
            $where[] = "EXISTS (SELECT 1 FROM domain_grants g WHERE g.primary_domain_id=d.id AND g.team_id=? AND g.status='active')";
            $parameters[] = $teamId;
        }
        if ($usableOnly) {
            $where[] = "d.status='active' AND z.admin_state='enabled' AND z.verification_status='valid' AND z.cloudflare_status='active' "
                . "AND z.verified_credential_context_version=c.credential_context_version "
                . "AND c.admin_state='enabled' AND c.verification_status='valid'";
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        return $this->all($sql . ' ORDER BY d.created_at DESC,d.id DESC', $parameters);
    }

    public function findPrimaryDomain(string $id): ?array
    {
        return $this->one(
            'SELECT d.*,z.status AS zone_status,z.admin_state AS zone_admin_state,'
            . 'z.verification_status AS zone_verification_status,z.verified_credential_context_version AS zone_verified_credential_context_version,z.cloudflare_status AS zone_cloudflare_status,'
            . 'z.normalized_zone_name,z.connection_id,'
            . 'c.status AS connection_status,c.admin_state AS connection_admin_state,'
            . 'c.verification_status AS connection_verification_status,c.credential_context_version AS connection_credential_context_version '
            . 'FROM primary_domains d JOIN cloudflare_zones z ON z.id=d.zone_id '
            . 'JOIN cloudflare_connections c ON c.id=z.connection_id WHERE d.id=?',
            [$id],
        );
    }

    public function findPrimaryDomainByName(string $normalizedName): ?array
    {
        return $this->one('SELECT * FROM primary_domains WHERE normalized_domain_name = ?', [$normalizedName]);
    }

    public function insertPrimaryDomain(array $domain): void
    {
        $this->execute(
            'INSERT INTO primary_domains(id,zone_id,domain_name,normalized_domain_name,status,dns_policy_json,'
            . 'created_by_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [
                $domain['id'], $domain['zone_id'], $domain['domain_name'], $domain['normalized_domain_name'],
                $domain['status'], $domain['dns_policy_json'], $domain['created_by_user_id'], $domain['created_at'], $domain['updated_at'],
            ],
        );
    }

    public function updatePrimaryDomainStatus(string $id, string $status, string $at): void
    {
        $this->execute('UPDATE primary_domains SET status=?,updated_at=? WHERE id=?', [$status, $at, $id]);
    }

    public function listDomainGrants(string $primaryDomainId): array
    {
        return $this->all(
            'SELECT g.team_id,t.name AS team_name,g.status,g.granted_at,g.revoked_at FROM domain_grants g '
            . 'JOIN teams t ON t.id=g.team_id WHERE g.primary_domain_id=? ORDER BY t.name,t.id',
            [$primaryDomainId],
        );
    }

    public function hasDomainGrant(string $primaryDomainId, string $teamId): bool
    {
        return $this->exists(
            "SELECT 1 FROM domain_grants WHERE primary_domain_id = ? AND team_id = ? AND status = 'active'",
            [$primaryDomainId, $teamId],
        );
    }

    public function grantPrimaryDomain(array $grant): void
    {
        $this->execute(
            "INSERT INTO domain_grants(id,primary_domain_id,team_id,status,granted_by_user_id,granted_at,revoked_at) "
            . "VALUES (?,?,?,'active',?,?,NULL) ON DUPLICATE KEY UPDATE status='active',"
            . 'granted_by_user_id=VALUES(granted_by_user_id),granted_at=VALUES(granted_at),revoked_at=NULL',
            [
                $grant['id'], $grant['primary_domain_id'], $grant['team_id'],
                $grant['granted_by_user_id'], $grant['granted_at'],
            ],
        );
    }

    public function revokePrimaryDomainGrant(string $primaryDomainId, string $teamId, string $at): bool
    {
        $statement = $this->execute(
            "UPDATE domain_grants SET status='revoked',revoked_at=? WHERE primary_domain_id=? AND team_id=? AND status='active'",
            [$at, $primaryDomainId, $teamId],
        );
        return $statement->rowCount() === 1;
    }

    public function findBaotaConfig(): ?array
    {
        return $this->one('SELECT * FROM baota_config WHERE singleton_key = 1');
    }

    public function insertBaotaConfig(array $config): void
    {
        $this->execute(
            'INSERT INTO baota_config(id,singleton_key,base_url,api_key_ciphertext,api_key_hint,secret_key_version,'
            . 'expected_server_fingerprint,status,admin_state,verification_status,last_verified_at,'
            . 'last_verification_attempt_at,last_verification_result_json,panel_version,capabilities_json,version,'
            . 'created_by_user_id,updated_by_user_id,created_at,updated_at) '
            . 'VALUES (?,1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $config['id'], $config['base_url'], $config['api_key_ciphertext'], $config['api_key_hint'],
                $config['secret_key_version'], $config['expected_server_fingerprint'], $config['status'],
                $config['admin_state'], $config['verification_status'], $config['last_verified_at'],
                $config['last_verification_attempt_at'], $config['last_verification_result_json'],
                $config['panel_version'], $config['capabilities_json'], $config['version'],
                $config['created_by_user_id'], $config['updated_by_user_id'], $config['created_at'], $config['updated_at'],
            ],
        );
    }

    public function updateBaotaConfig(string $id, array $changes, int $expectedVersion): bool
    {
        $allowed = [
            'base_url', 'api_key_ciphertext', 'api_key_hint', 'secret_key_version', 'expected_server_fingerprint',
            'status', 'admin_state', 'verification_status', 'last_verified_at', 'last_verification_attempt_at',
            'last_verification_result_json', 'panel_version', 'capabilities_json', 'updated_by_user_id', 'updated_at',
        ];
        $set = [];
        $parameters = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $changes)) {
                $set[] = $column . ' = ?';
                $parameters[] = $changes[$column];
            }
        }
        if ($set === []) {
            return false;
        }
        $parameters[] = $id;
        $parameters[] = $expectedVersion;
        return $this->execute(
            'UPDATE baota_config SET ' . implode(',', $set) . ',version=version+1 WHERE id=? AND version=?',
            $parameters,
        )->rowCount() === 1;
    }

    public function insertAudit(array $audit): void
    {
        $metadata = json_encode($audit['metadata_json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->execute(
            'INSERT INTO audit_logs(id, actor_user_id, actor_type, team_id, action, resource_type, resource_id, '
            . 'request_id, before_digest, after_digest, metadata_json, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $audit['id'], $audit['actor_user_id'], $audit['actor_type'], $audit['team_id'], $audit['action'],
                $audit['resource_type'], $audit['resource_id'], $audit['request_id'], $audit['before_digest'],
                $audit['after_digest'], $metadata, $audit['created_at'],
            ],
        );
    }

    /** @param list<mixed> $parameters */
    private function execute(string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    /** @param list<mixed> $parameters @return array<string,mixed>|null */
    private function one(string $sql, array $parameters = []): ?array
    {
        $row = $this->execute($sql, $parameters)->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param list<mixed> $parameters @return list<array<string,mixed>> */
    private function all(string $sql, array $parameters = []): array
    {
        return $this->execute($sql, $parameters)->fetchAll();
    }

    /** @param list<mixed> $parameters */
    private function exists(string $sql, array $parameters): bool
    {
        return $this->execute($sql, $parameters)->fetchColumn() !== false;
    }
}
