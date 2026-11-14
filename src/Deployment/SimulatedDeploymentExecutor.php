<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

final class SimulatedDeploymentExecutor
{
    private const MARKER = '.landingops-installation.json';
    private const POINTER = 'current-release.json';

    public function __construct(private readonly ReleaseManifest $manifest = new ReleaseManifest())
    {
    }

    /**
     * @param array<string,bool> $faults Test-only fault injection: migration_fail, switch_fail, health_fail.
     * @return array<string,mixed>
     */
    public function execute(
        DeploymentConfig $config,
        string $operation,
        string $version,
        ?string $releaseDirectory,
        string $sandboxRoot,
        array $faults = [],
    ): array {
        if (!in_array($operation, ['init', 'update', 'rollback'], true)) {
            throw new DeploymentException('deployment_operation_invalid', '模拟执行器只支持 init、update、rollback。');
        }
        $sandbox = realpath($sandboxRoot);
        if (!is_string($sandbox) || !is_dir($sandbox)) {
            throw new DeploymentException('simulation_root_invalid', '模拟根目录必须预先存在。');
        }
        $target = $this->mapPath($sandbox, $config->installRoot());
        $this->assertNoSymlinkAncestors($sandbox, $target);
        $markerPath = $target . DIRECTORY_SEPARATOR . self::MARKER;
        $newInstallation = $operation === 'init';
        if ($operation === 'init') {
            if (is_dir($target)) {
                $entries = array_values(array_diff(scandir($target) ?: [], ['.', '..']));
                if ($entries !== []) {
                    throw new DeploymentException('target_directory_unknown_content', '目标目录已包含未知内容，拒绝首次安装。');
                }
            } else {
                if (!mkdir($target, 0700, true)) {
                    throw new DeploymentException('simulation_write_failed', '无法创建模拟安装目录。');
                }
            }
        } else {
            $this->assertOwned($config, $markerPath);
        }
        $paths = $config->all()['paths'];
        $releases = $this->mapPath($sandbox, (string) $paths['releases_dir']);
        $staging = $this->mapPath($sandbox, (string) $paths['staging_dir']);
        $shared = $this->mapPath($sandbox, (string) $paths['shared_dir']);
        $pointer = $target . DIRECTORY_SEPARATOR . self::POINTER;
        foreach ([$releases, $staging, $shared] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new DeploymentException('simulation_write_failed', '无法创建模拟部署目录。');
            }
        }
        if ($operation === 'init') {
            file_put_contents($markerPath, json_encode([
                'schema_version' => 'landingops-installation/1.0',
                'installation_id' => $config->installationId(),
                'deployment_scope' => 'landingops_platform',
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n", LOCK_EX);
        }
        $before = $this->currentVersion($pointer);
        $stage = null;
        try {
            if ($operation === 'rollback') {
                $releaseTarget = $releases . DIRECTORY_SEPARATOR . $version;
                $manifest = $this->manifest->verify($releaseTarget);
                if (($manifest['version'] ?? null) !== $version) {
                    throw new DeploymentException('release_version_mismatch', '回滚目标版本与 manifest 不一致。');
                }
            } else {
                if ($releaseDirectory === null) {
                    throw new DeploymentException('release_missing', 'init/update 必须提供已构建 release 目录。');
                }
                $manifest = $this->manifest->verify($releaseDirectory);
                if (($manifest['version'] ?? null) !== $version) {
                    throw new DeploymentException('release_version_mismatch', '请求版本与 manifest 不一致。');
                }
                $releaseTarget = $releases . DIRECTORY_SEPARATOR . $version;
                if (file_exists($releaseTarget)) {
                    throw new DeploymentException('release_exists', '目标 release 已存在，禁止覆盖。');
                }
                $stage = $staging . DIRECTORY_SEPARATOR . $version . '-' . bin2hex(random_bytes(4));
                $this->copyTree($releaseDirectory, $stage);
                $this->manifest->verify($stage);
                if (($faults['migration_fail'] ?? false) === true) {
                    throw new DeploymentException('database_migration_failed', '模拟数据库迁移失败；current 未切换。');
                }
                if (!rename($stage, $releaseTarget)) {
                    throw new DeploymentException('release_commit_failed', '无法原子提交模拟 release。');
                }
                $stage = null;
            }
            if (($faults['switch_fail'] ?? false) === true) {
                throw new DeploymentException('release_switch_failed', '模拟 current 指针切换失败。');
            }
            $this->writePointer($pointer, $version);
            if (($faults['health_fail'] ?? false) === true) {
                if ($before === null) {
                    @unlink($pointer);
                } else {
                    $this->writePointer($pointer, $before);
                }
                return [
                    'status' => 'rolled_back_after_health_failure', 'operation' => $operation,
                    'attempted_version' => $version, 'active_version' => $before,
                    'database_rollback_performed' => false, 'persistent_data_preserved' => true,
                    'write_scope' => 'simulation_sandbox_only', 'real_server_writes' => false,
                ];
            }
            return [
                'status' => 'simulated_success', 'operation' => $operation, 'active_version' => $version,
                'previous_version' => $before, 'database_rollback_performed' => false,
                'persistent_data_preserved' => true, 'write_scope' => 'simulation_sandbox_only',
                'real_server_writes' => false,
            ];
        } catch (\Throwable $exception) {
            if (is_string($stage) && file_exists($stage)) {
                $this->removeTree($stage);
            }
            if ($newInstallation && $this->currentVersion($pointer) === null) {
                $this->removeOwnedEmptyInstall($target, $config->installationId());
            }
            throw $exception;
        }
    }

    private function assertOwned(DeploymentConfig $config, string $markerPath): void
    {
        try {
            $marker = json_decode((string) file_get_contents($markerPath), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new DeploymentException('installation_marker_missing', '目标不是已登记的 LandingOps 安装。');
        }
        if (!is_array($marker) || ($marker['installation_id'] ?? null) !== $config->installationId()
            || ($marker['deployment_scope'] ?? null) !== 'landingops_platform') {
            throw new DeploymentException('installation_marker_mismatch', '安装所有权标记不匹配。');
        }
    }

    private function mapPath(string $sandbox, string $logicalPath): string
    {
        $relative = str_replace('/', DIRECTORY_SEPARATOR, ltrim($logicalPath, '/'));
        $mapped = $sandbox . DIRECTORY_SEPARATOR . $relative;
        if (!str_starts_with(strtolower($mapped . DIRECTORY_SEPARATOR), strtolower($sandbox . DIRECTORY_SEPARATOR))) {
            throw new DeploymentException('simulation_path_escape', '模拟路径越界。');
        }
        return $mapped;
    }

    private function assertNoSymlinkAncestors(string $sandbox, string $target): void
    {
        $relative = substr($target, strlen($sandbox) + 1);
        $current = $sandbox;
        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $part) {
            $current .= DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) {
                throw new DeploymentException('deployment_path_symlink', '目标路径祖先包含符号链接。');
            }
        }
    }

    private function currentVersion(string $pointer): ?string
    {
        if (!is_file($pointer)) {
            return null;
        }
        try {
            $value = json_decode((string) file_get_contents($pointer), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new DeploymentException('release_pointer_invalid', 'current 指针无效。');
        }
        return is_array($value) && is_string($value['version'] ?? null) ? $value['version'] : null;
    }

    private function writePointer(string $pointer, string $version): void
    {
        $temporary = $pointer . '.tmp-' . bin2hex(random_bytes(4));
        file_put_contents($temporary, json_encode(['version' => $version], JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
        if (!rename($temporary, $pointer)) {
            @unlink($temporary);
            throw new DeploymentException('release_switch_failed', '无法原子替换模拟 current 指针。');
        }
    }

    private function copyTree(string $source, string $destination): void
    {
        if (is_link($source)) {
            throw new DeploymentException('release_symlink_forbidden', 'release 包含符号链接。');
        }
        if (!is_dir($destination) && !mkdir($destination, 0700, true) && !is_dir($destination)) {
            throw new DeploymentException('simulation_write_failed', '无法创建 staging。');
        }
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $source . DIRECTORY_SEPARATOR . $entry;
            $to = $destination . DIRECTORY_SEPARATOR . $entry;
            if (is_link($from)) {
                throw new DeploymentException('release_symlink_forbidden', 'release 包含符号链接。');
            }
            if (is_dir($from)) {
                $this->copyTree($from, $to);
            } elseif (!copy($from, $to)) {
                throw new DeploymentException('simulation_write_failed', '无法复制 release 到 staging。');
            }
        }
    }

    private function removeOwnedEmptyInstall(string $target, string $installationId): void
    {
        $marker = $target . DIRECTORY_SEPARATOR . self::MARKER;
        if (!is_file($marker) || !str_contains((string) file_get_contents($marker), $installationId)) {
            return;
        }
        $this->removeTree($target);
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    }
}
