<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\ControlPlane\Audit\AuditLogger;
use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Baota\BaotaEndpointPolicy;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Repository\ControlPlaneRepository;
use LandingOps\ControlPlane\Security\AeadSecretCipher;
use LandingOps\ControlPlane\Support\IdGenerator;
use LandingOps\ControlPlane\Support\UtcClock;
use Throwable;

final class BaotaConfigService
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

    /** @return array<string,mixed>|null */
    public function get(Principal $actor): ?array
    {
        $this->authorization->assertSuperAdmin($actor);
        $row = $this->repository->findBaotaConfig();
        return $row === null ? null : self::safe($row);
    }

    /** @return array<string,mixed> */
    public function save(Principal $actor, array $input, string $requestId): array
    {
        $this->authorization->assertSuperAdmin($actor);
        $current = $this->repository->findBaotaConfig();
        if ($current === null) {
            return $this->create($actor, $input, $requestId);
        }
        $expectedVersion = self::expectedVersion($input);
        $changes = [];
        $credentialsChanged = false;
        if (array_key_exists('base_url', $input)) {
            $changes['base_url'] = BaotaEndpointPolicy::normalize(self::string($input['base_url'], 'baota_base_url_invalid'));
            $credentialsChanged = $changes['base_url'] !== $current['base_url'];
        }
        if (array_key_exists('api_key', $input) && $input['api_key'] !== '') {
            $key = self::apiKey($input['api_key']);
            $encrypted = $this->requireCipher()->encrypt($key, self::aad((string) $current['id']));
            $changes['api_key_ciphertext'] = $encrypted['ciphertext'];
            $changes['api_key_hint'] = self::hint($key);
            $changes['secret_key_version'] = $encrypted['key_version'];
            $credentialsChanged = true;
        }
        if (array_key_exists('admin_state', $input)) {
            if (!in_array($input['admin_state'], ['enabled', 'disabled'], true)) {
                throw new ApiException(422, 'baota_admin_state_invalid', '管理员状态必须是 enabled 或 disabled。');
            }
            $changes['admin_state'] = $input['admin_state'];
        }
        if ($changes === []) {
            throw new ApiException(422, 'input_invalid', '没有可更新的宝塔连接字段。');
        }
        if ($credentialsChanged) {
            $changes += [
                'verification_status' => 'unverified', 'last_verified_at' => null,
                'last_verification_attempt_at' => null, 'last_verification_result_json' => null,
                'panel_version' => null, 'capabilities_json' => null,
            ];
        }
        $adminState = (string) ($changes['admin_state'] ?? $current['admin_state']);
        $verificationStatus = (string) ($changes['verification_status'] ?? $current['verification_status']);
        $changes['status'] = self::legacyStatus($adminState, $verificationStatus);
        $changes['updated_by_user_id'] = $actor->id;
        $changes['updated_at'] = $this->clock->sql();
        $this->repository->transaction(function () use ($actor, $current, $changes, $credentialsChanged, $expectedVersion, $requestId): void {
            if (!$this->repository->updateBaotaConfig((string) $current['id'], $changes, $expectedVersion)) {
                throw new ApiException(409, 'baota_config_version_conflict', '宝塔连接已被其他管理员更新，请刷新后重试。');
            }
            $action = 'baota.config_update';
            if (($changes['admin_state'] ?? null) === 'disabled' && ($current['admin_state'] ?? null) !== 'disabled') {
                $action = 'baota.config_disable';
            } elseif (($changes['admin_state'] ?? null) === 'enabled' && ($current['admin_state'] ?? null) !== 'enabled') {
                $action = 'baota.config_enable';
            }
            $this->audit->record($actor, $action, 'baota_config', (string) $current['id'], $requestId, [
                'credentials_changed' => $credentialsChanged,
                'admin_state' => $changes['admin_state'] ?? null,
            ]);
        });
        return self::safe($this->repository->findBaotaConfig() ?? []);
    }

    /** @return array<string,mixed> */
    public function requireEnabled(Principal $actor): array
    {
        $this->authorization->assertSuperAdmin($actor);
        $row = $this->repository->findBaotaConfig();
        if ($row === null) {
            throw new ApiException(404, 'baota_config_not_found', '尚未登记宝塔连接。');
        }
        if (($row['admin_state'] ?? null) !== 'enabled') {
            throw new ApiException(409, 'baota_config_disabled', '宝塔连接已被管理员停用。');
        }
        return $row;
    }

    public function decryptApiKey(array $row): string
    {
        $cipher = $this->requireCipher();
        try {
            return $cipher->decrypt(
                (string) $row['api_key_ciphertext'],
                (string) $row['secret_key_version'],
                self::aad((string) $row['id']),
            );
        } catch (Throwable) {
            throw new ApiException(503, 'baota_secret_unavailable', '宝塔 API Key 无法使用当前运行环境密钥解密。');
        }
    }

    /** @return array<string,mixed> */
    private function create(Principal $actor, array $input, string $requestId): array
    {
        $baseUrl = BaotaEndpointPolicy::normalize(self::string($input['base_url'] ?? null, 'baota_base_url_invalid'));
        $key = self::apiKey($input['api_key'] ?? null);
        $id = $this->ids->generate();
        $encrypted = $this->requireCipher()->encrypt($key, self::aad($id));
        $now = $this->clock->sql();
        $row = [
            'id' => $id, 'base_url' => $baseUrl, 'api_key_ciphertext' => $encrypted['ciphertext'],
            'api_key_hint' => self::hint($key), 'secret_key_version' => $encrypted['key_version'],
            'expected_server_fingerprint' => null, 'status' => 'unverified', 'admin_state' => 'enabled',
            'verification_status' => 'unverified', 'last_verified_at' => null,
            'last_verification_attempt_at' => null, 'last_verification_result_json' => null,
            'panel_version' => null, 'capabilities_json' => null, 'version' => 1,
            'created_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id,
            'created_at' => $now, 'updated_at' => $now,
        ];
        try {
            $this->repository->transaction(function () use ($row, $actor, $requestId): void {
                $this->repository->insertBaotaConfig($row);
                $this->audit->record($actor, 'baota.config_create', 'baota_config', (string) $row['id'], $requestId, [
                    'base_url_hint' => BaotaEndpointPolicy::hint((string) $row['base_url']), 'status' => 'unverified',
                ]);
            });
        } catch (Throwable $exception) {
            if ($this->repository->findBaotaConfig() !== null) {
                throw new ApiException(409, 'baota_config_conflict', '全局宝塔连接已经存在，不能登记第二台服务器。');
            }
            throw $exception;
        }
        return self::safe($row);
    }

    private function requireCipher(): AeadSecretCipher
    {
        if ($this->cipher === null) {
            throw new ApiException(503, 'secret_key_unavailable', '控制面主密钥尚未配置，不能保存或读取宝塔 API Key。');
        }
        return $this->cipher;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function safe(array $row): array
    {
        return [
            'id' => $row['id'], 'base_url_hint' => BaotaEndpointPolicy::hint((string) $row['base_url']),
            'api_key_hint' => $row['api_key_hint'],
            'status' => $row['status'], 'admin_state' => $row['admin_state'],
            'verification_status' => $row['verification_status'], 'panel_version' => $row['panel_version'],
            'capabilities' => self::decode($row['capabilities_json'] ?? null),
            'last_verified_at' => $row['last_verified_at'],
            'last_verification_attempt_at' => $row['last_verification_attempt_at'],
            'last_verification_result' => self::decode($row['last_verification_result_json'] ?? null),
            'version' => (int) $row['version'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
        ];
    }

    private static function apiKey(mixed $value): string
    {
        if (!is_string($value) || strlen($value) < 8 || strlen($value) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new ApiException(422, 'baota_api_key_invalid', '宝塔 API Key 格式无效。');
        }
        return $value;
    }

    private static function string(mixed $value, string $code): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new ApiException(422, $code, '必须提供有效字符串。');
        }
        return trim($value);
    }

    private static function expectedVersion(array $input): int
    {
        $value = $input['expected_version'] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new ApiException(422, 'expected_version_invalid', '必须提交有效的 expected_version。');
        }
        return $value;
    }

    private static function hint(string $key): string
    {
        return '…' . substr($key, -4);
    }

    private static function aad(string $id): string
    {
        return 'landingops:baota-api-key:' . $id;
    }

    private static function legacyStatus(string $adminState, string $verificationStatus): string
    {
        return $adminState === 'disabled' ? 'disabled' : ($verificationStatus === 'valid' ? 'active' : 'unverified');
    }

    /** @return array<string,mixed>|null */
    private static function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : null;
    }
}
