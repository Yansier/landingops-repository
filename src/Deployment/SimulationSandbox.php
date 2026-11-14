<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

final class SimulationSandbox
{
    public const MARKER = '.landingops-simulation-root.json';

    public function authorize(string $path, DeploymentConfig $config, string $projectRoot): string
    {
        $root = $this->validateLocation($path, $config, $projectRoot);
        $entries = array_values(array_diff(scandir($root) ?: [], ['.', '..']));
        if ($entries !== []) {
            throw new DeploymentException('simulation_root_not_empty', '首次授权的模拟沙箱必须为空。');
        }
        $marker = [
            'schema_version' => 'landingops-simulation-root/1.0',
            'deployment_scope' => 'landingops_platform_simulation',
            'installation_id' => $config->installationId(),
            'sandbox_id' => bin2hex(random_bytes(16)),
            'authorized_path_sha256' => hash('sha256', $this->comparable($root)),
        ];
        $markerPath = $root . DIRECTORY_SEPARATOR . self::MARKER;
        if (file_put_contents($markerPath, json_encode($marker, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n", LOCK_EX) === false) {
            throw new DeploymentException('simulation_authorization_failed', '无法写入模拟沙箱授权标记。');
        }
        return $root;
    }

    public function verify(string $path, DeploymentConfig $config, string $projectRoot): string
    {
        $root = $this->validateLocation($path, $config, $projectRoot);
        $markerPath = $root . DIRECTORY_SEPARATOR . self::MARKER;
        if (!is_file($markerPath) || is_link($markerPath)) {
            throw new DeploymentException('simulation_marker_missing', '模拟根目录缺少独立授权标记。');
        }
        try {
            $marker = json_decode((string) file_get_contents($markerPath), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new DeploymentException('simulation_marker_invalid', '模拟沙箱授权标记无效。');
        }
        $expectedKeys = ['schema_version', 'deployment_scope', 'installation_id', 'sandbox_id', 'authorized_path_sha256'];
        if (!is_array($marker) || array_is_list($marker) || array_keys($marker) !== $expectedKeys
            || ($marker['schema_version'] ?? null) !== 'landingops-simulation-root/1.0'
            || ($marker['deployment_scope'] ?? null) !== 'landingops_platform_simulation'
            || ($marker['installation_id'] ?? null) !== $config->installationId()
            || !is_string($marker['sandbox_id'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $marker['sandbox_id']) !== 1
            || !is_string($marker['authorized_path_sha256'] ?? null)
            || !hash_equals(hash('sha256', $this->comparable($root)), $marker['authorized_path_sha256'])) {
            throw new DeploymentException('simulation_marker_mismatch', '模拟沙箱标记与路径或安装身份不匹配。');
        }
        $this->assertTreeHasNoLinks($root);
        return $root;
    }

    public function map(string $root, string $logicalPath): string
    {
        $relative = str_replace('/', DIRECTORY_SEPARATOR, ltrim($logicalPath, '/'));
        $mapped = $root . DIRECTORY_SEPARATOR . $relative;
        $this->assertWithin($root, $mapped);
        $this->assertExistingAncestorsSafe($root, $mapped);
        return $mapped;
    }

    public function assertManagedPath(string $root, string $path): void
    {
        $this->assertWithin($root, $path);
        $this->assertExistingAncestorsSafe($root, $path);
        if (file_exists($path)) {
            $real = realpath($path);
            if (!is_string($real) || !$this->isWithin($root, $real)) {
                throw new DeploymentException('simulation_path_escape', '模拟路径解析到沙箱之外。');
            }
        }
    }

    public function assertTreeHasNoLinks(string $path, ?string $authorizedRoot = null): void
    {
        $authorizedRoot ??= realpath($path) ?: $path;
        if (is_link($path)) {
            throw new DeploymentException('simulation_symlink_forbidden', '模拟沙箱不允许符号链接或目录联接。');
        }
        if (file_exists($path)) {
            $real = realpath($path);
            if (!is_string($real) || !$this->isWithin($authorizedRoot, $real)) {
                throw new DeploymentException('simulation_path_escape', '模拟子目录解析到授权边界之外。');
            }
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->assertTreeHasNoLinks($path . DIRECTORY_SEPARATOR . $entry, $authorizedRoot);
            }
        }
    }

    private function validateLocation(string $path, DeploymentConfig $config, string $projectRoot): string
    {
        if (!$this->isAbsolute($path) || str_contains($path, "\0") || preg_match('#(?:^|[\\/])\.\.?(?:[\\/]|$)#', $path) === 1
            || is_link($path)) {
            throw new DeploymentException('simulation_root_invalid', '模拟根必须是预先建立的规范化本地绝对目录。');
        }
        $root = realpath($path);
        $project = realpath($projectRoot);
        if (!is_string($root) || !is_dir($root) || !is_string($project)) {
            throw new DeploymentException('simulation_root_invalid', '模拟根目录必须预先存在。');
        }
        if ($this->comparable($this->lexical($path)) !== $this->comparable($root)
            || dirname($root) === $root
            || $this->pathsOverlap($root, $project)) {
            throw new DeploymentException('simulation_root_forbidden', '禁止使用文件系统根、项目目录或其子目录作为模拟沙箱。');
        }
        if (DIRECTORY_SEPARATOR === '/' && $this->pathsOverlap($root, $config->installRoot())) {
            throw new DeploymentException('simulation_root_forbidden', '模拟沙箱不得与生产安装目录重叠。');
        }
        $this->assertExistingAncestorsSafe(dirname($root), $root);
        return $root;
    }

    private function assertExistingAncestorsSafe(string $root, string $path): void
    {
        $this->assertWithin($root, $path);
        $relative = ltrim(substr($this->lexical($path), strlen(rtrim($this->lexical($root), '\\/'))), '\\/');
        $current = rtrim($root, '\\/');
        foreach ($relative === '' ? [] : preg_split('#[\\\\/]#', $relative) as $part) {
            $current .= DIRECTORY_SEPARATOR . $part;
            if (file_exists($current) || is_link($current)) {
                if (is_link($current)) {
                    throw new DeploymentException('simulation_symlink_forbidden', '模拟路径包含符号链接或目录联接。');
                }
                $real = realpath($current);
                if (!is_string($real) || !$this->isWithin($root, $real)) {
                    throw new DeploymentException('simulation_path_escape', '模拟路径解析到沙箱之外。');
                }
            }
        }
    }

    private function assertWithin(string $root, string $path): void
    {
        if (!$this->isWithin($root, $this->lexical($path))) {
            throw new DeploymentException('simulation_path_escape', '模拟路径越界。');
        }
    }

    private function isWithin(string $root, string $path): bool
    {
        $rootParts = $this->parts($root);
        $pathParts = $this->parts($path);
        return count($pathParts) >= count($rootParts)
            && array_slice($pathParts, 0, count($rootParts)) === $rootParts;
    }

    private function pathsOverlap(string $left, string $right): bool
    {
        return $this->isWithin($left, $right) || $this->isWithin($right, $left);
    }

    /** @return list<string> */
    private function parts(string $path): array
    {
        $parts = preg_split('#[\\\\/]+#', trim($this->comparable($path), '\\/')) ?: [];
        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function comparable(string $path): string
    {
        $path = str_replace('\\', '/', rtrim($path, '\\/'));
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }

    private function lexical(string $path): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), '\\/');
    }

    private function isAbsolute(string $path): bool
    {
        return DIRECTORY_SEPARATOR === '\\'
            ? preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1
            : str_starts_with($path, '/');
    }
}
