<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\ControlPlane\Audit\AuditLogger;
use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Repository\ControlPlaneRepository;
use LandingOps\ControlPlane\Security\AeadSecretCipher;
use LandingOps\ControlPlane\Support\IdGenerator;
use LandingOps\ControlPlane\Support\UtcClock;
use Throwable;

final class CloudflareConnectionService
{
    private readonly AuditLogger $audit;

    public function __construct(
        private readonly ControlPlaneRepository $repository,
        private readonly ?AeadSecretCipher $cipher,
        private readonly AuthorizationService $authorization = new AuthorizationService(),
        private readonly IdGenerator $ids = new IdGenerator(),
        private readonly UtcClock $clock = new UtcClock(),
        ?AuditLogger $audit = null,
    ) {
        $this->audit = $audit ?? new AuditLogger($repository, $ids, $clock);
    }

    /** @return list<array<string,mixed>> */
    public function list(Principal $actor): array
    {
        $this->authorization->assertSuperAdmin($actor);
        return array_map(self::safe(...), $this->repository->listCloudflareConnections());
    }

    /** @return array<string,mixed> */
    public function create(Principal $actor, array $input, string $requestId): array
    {
        $this->authorization->assertSuperAdmin($actor);
        $cipher = $this->requireCipher();
        $label = self::label($input['label'] ?? null);
        $accountId = self::nullableIdentifier($input['account_external_id'] ?? null, 'account_external_id');
        $token = self::token($input['api_token'] ?? null);
        $id = $this->ids->generate();
        $encrypted = $cipher->encrypt($token, self::aad($id));
        $now = $this->clock->sql();
        $row = [
            'id' => $id,
            'label' => $label,
            'account_external_id' => $accountId,
            'token_ciphertext' => $encrypted['ciphertext'],
            'secret_key_version' => $encrypted['key_version'],
            'token_hint' => self::hint($token),
            'status' => 'unverified',
            'admin_state' => 'enabled',
            'verification_status' => 'unverified',
            'credential_context_version' => 1,
            'last_verified_at' => null,
            'last_verification_attempt_at' => null,
            'last_verification_result_json' => null,
            'version' => 1,
            'created_by_user_id' => $actor->id,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        try {
            $this->repository->transaction(function () use ($row, $actor, $requestId): void {
                $this->repository->insertCloudflareConnection($row);
                $this->audit->record($actor, 'cloudflare.connection_create', 'cloudflare_connection', (string) $row['id'], $requestId, [
                    'label' => $row['label'], 'status' => 'unverified',
                ]);
            });
        } catch (Throwable $exception) {
            if ($this->repository->findCloudflareConnectionByLabel($label) !== null) {
                throw new ApiException(409, 'cloudflare_connection_conflict', '连接名称已经存在。');
            }
            throw $exception;
        }
        return self::safe($row);
    }

    /** @return array<string,mixed> */
    public function update(Principal $actor, string $id, array $input, string $requestId): array
    {
        $this->authorization->assertSuperAdmin($actor);
        $current = $this->repository->findCloudflareConnection($id);
        if ($current === null) {
            throw new ApiException(404, 'cloudflare_connection_not_found', 'Cloudflare 连接不存在。');
        }
        $expectedVersion = self::expectedVersion($input);
        $changes = [];
        $trustContextChanged = false;
        if (array_key_exists('label', $input)) {
            $changes['label'] = self::label($input['label']);
        }
        if (array_key_exists('account_external_id', $input)) {
            $changes['account_external_id'] = self::nullableIdentifier($input['account_external_id'], 'account_external_id');
            $trustContextChanged = $changes['account_external_id'] !== ($current['account_external_id'] ?? null);
        }
        if (array_key_exists('admin_state', $input)) {
            if (!in_array($input['admin_state'], ['enabled', 'disabled'], true)) {
                throw new ApiException(422, 'cloudflare_admin_state_invalid', '连接管理员状态必须是 enabled 或 disabled。');
            }
            $changes['admin_state'] = $input['admin_state'];
        }
        if (array_key_exists('api_token', $input)) {
            $token = self::token($input['api_token']);
            $encrypted = $this->requireCipher()->encrypt($token, self::aad($id));
            $changes['token_ciphertext'] = $encrypted['ciphertext'];
            $changes['secret_key_version'] = $encrypted['key_version'];
            $changes['token_hint'] = self::hint($token);
            $changes['last_verified_at'] = null;
            $changes['verification_status'] = 'unverified';
            $changes['last_verification_result_json'] = null;
            $trustContextChanged = true;
        }
        if ($changes === []) {
            throw new ApiException(422, 'input_invalid', '没有可更新的连接字段。');
        }
        if ($trustContextChanged) {
            $changes['credential_context_version'] = (int) ($current['credential_context_version'] ?? 1) + 1;
            $changes['verification_status'] = 'unverified';
            $changes['last_verified_at'] = null;
            $changes['last_verification_attempt_at'] = null;
            $changes['last_verification_result_json'] = null;
        }
        $newAdminState = (string) ($changes['admin_state'] ?? $current['admin_state']);
        $newVerification = (string) ($changes['verification_status'] ?? $current['verification_status']);
        $changes['status'] = self::legacyStatus($newAdminState, $newVerification);
        $changes['updated_at'] = $this->clock->sql();
        try {
            $this->repository->transaction(function () use ($id, $changes, $expectedVersion, $current, $trustContextChanged, $actor, $requestId): void {
                if (!$this->repository->updateCloudflareConnection($id, $changes, $expectedVersion)) {
                    throw new ApiException(409, 'cloudflare_connection_version_conflict', '连接已被其他管理员更新，请刷新后重新确认。', [
                        'current_version' => (int) (($this->repository->findCloudflareConnection($id)['version'] ?? $expectedVersion)),
                    ]);
                }
                $invalidatedZones = 0;
                if ($trustContextChanged) {
                    $invalidatedZones = $this->repository->invalidateCloudflareZonesForConnection(
                        $id,
                        json_encode([
                            'status' => 'invalidated',
                            'code' => 'credential_context_changed',
                            'credential_context_version' => $changes['credential_context_version'],
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                        (string) $changes['updated_at'],
                    );
                }
                $action = 'cloudflare.connection_update';
                if (($changes['admin_state'] ?? null) === 'disabled' && ($current['admin_state'] ?? null) !== 'disabled') {
                    $action = 'cloudflare.connection_disable';
                } elseif (($changes['admin_state'] ?? null) === 'enabled' && ($current['admin_state'] ?? null) !== 'enabled') {
                    $action = 'cloudflare.connection_enable';
                }
                $this->audit->record($actor, $action, 'cloudflare_connection', $id, $requestId, [
                    'rotated_token' => isset($changes['token_ciphertext']),
                    'admin_state' => $changes['admin_state'] ?? null,
                    'verification_status' => $changes['verification_status'] ?? null,
                    'credential_context_changed' => $trustContextChanged,
                    'invalidated_zone_count' => $invalidatedZones,
                ]);
            });
        } catch (Throwable $exception) {
            $conflict = isset($changes['label'])
                ? $this->repository->findCloudflareConnectionByLabel((string) $changes['label']) : null;
            if ($conflict !== null && ($conflict['id'] ?? null) !== $id) {
                throw new ApiException(409, 'cloudflare_connection_conflict', '连接名称已经存在。');
            }
            throw $exception;
        }
        return self::safe($this->repository->findCloudflareConnection($id) ?? []);
    }

    private function requireCipher(): AeadSecretCipher
    {
        if ($this->cipher === null) {
            throw new ApiException(503, 'secret_key_unavailable', '控制面主密钥尚未配置，不能保存或轮换 Token。');
        }
        return $this->cipher;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function safe(array $row): array
    {
        return [
            'id' => $row['id'], 'label' => $row['label'], 'account_external_id' => $row['account_external_id'],
            'token_hint' => $row['token_hint'], 'status' => $row['status'],
            'admin_state' => $row['admin_state'], 'verification_status' => $row['verification_status'],
            'credential_context_version' => (int) $row['credential_context_version'],
            'last_verified_at' => $row['last_verified_at'],
            'last_verification_attempt_at' => $row['last_verification_attempt_at'],
            'last_verification_result' => self::decodeResult($row['last_verification_result_json'] ?? null),
            'version' => (int) $row['version'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
        ];
    }

    /** @param array<string,mixed> $input */
    private static function expectedVersion(array $input): int
    {
        $value = $input['expected_version'] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new ApiException(422, 'expected_version_invalid', '必须提交有效的 expected_version。');
        }
        return $value;
    }

    private static function legacyStatus(string $adminState, string $verificationStatus): string
    {
        if ($adminState === 'disabled') {
            return 'disabled';
        }
        return $verificationStatus === 'valid' ? 'active' : 'unverified';
    }

    /** @return array<string,mixed>|null */
    private static function decodeResult(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function label(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || strlen($value) > 120) {
            throw new ApiException(422, 'cloudflare_label_invalid', '连接名称长度必须为 1–120 个字符。');
        }
        return $value;
    }

    private static function nullableIdentifier(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 128 || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new ApiException(422, 'cloudflare_identifier_invalid', 'Cloudflare 外部标识格式无效。', ['field' => $field]);
        }
        return $value;
    }

    private static function token(mixed $value): string
    {
        if (!is_string($value) || strlen($value) < 8 || strlen($value) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new ApiException(422, 'cloudflare_token_invalid', 'Cloudflare API Token 格式无效。');
        }
        return $value;
    }

    private static function hint(string $token): string
    {
        return '…' . substr($token, -4);
    }

    private static function aad(string $id): string
    {
        return 'landingops:cloudflare-token:' . $id;
    }
}
