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
            . 'active_release_id, created_at, updated_at FROM instances';
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

    public function hasDomainGrant(string $primaryDomainId, string $teamId): bool
    {
        return $this->exists(
            "SELECT 1 FROM domain_grants WHERE primary_domain_id = ? AND team_id = ? AND status = 'active'",
            [$primaryDomainId, $teamId],
        );
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
