<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

final class DeploymentPlanner
{
    /** @param array<string,mixed> $doctorReport @return array<string,mixed> */
    public function plan(DeploymentConfig $config, string $operation, string $version, array $doctorReport): array
    {
        if (!in_array($operation, ['init', 'update', 'rollback'], true)) {
            throw new DeploymentException('deployment_operation_invalid', '只支持 init、update 或 rollback 计划。');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/D', $version) !== 1) {
            throw new DeploymentException('release_version_invalid', 'release 版本标识无效。');
        }
        $blocked = ($doctorReport['classification'] ?? null) !== 'ready_for_simulation';
        $common = [
            $this->step(1, 'verify', '重新执行目标身份、路径、运行时、数据库、TLS 和凭据检查'),
            $this->step(2, 'manifest', '上传到私有 staging，并验证 SHA-256 manifest 与权限'),
        ];
        $specific = match ($operation) {
            'init' => [
                $this->step(3, 'directories', '由经审批的高权限步骤创建专属目录并移交给非 root 部署账号', true),
                $this->step(4, 'database', '确认空库后执行 001–006，并通过隐藏输入初始化首个总管理员', true),
                $this->step(5, 'nginx_tls', '安装仅指向 release/public 的独立 Nginx vhost 和预置 TLS', true),
                $this->step(6, 'switch', '同文件系统原子设置 current 指针'),
                $this->step(7, 'health', '验证 /healthz 与 /api/v1 路由后标记 known-good'),
            ],
            'update' => [
                $this->step(3, 'backup', '备份数据库、模板私有库存储与密钥环，并验证恢复能力', true),
                $this->step(4, 'migration', '执行向前数据库迁移；不承诺自动数据库回滚', true),
                $this->step(5, 'switch', '同文件系统原子切换 current 指针'),
                $this->step(6, 'health', '健康检查失败时只恢复旧代码指针'),
                $this->step(7, 'retention', '仅清理本安装创建且超出保留策略的旧 release'),
            ],
            'rollback' => [
                $this->step(3, 'compatibility', '确认目标旧代码兼容当前数据库 schema', true),
                $this->step(4, 'switch', '原子切换到已验证的 known-good release'),
                $this->step(5, 'health', '验证健康状态；不执行数据库降级'),
            ],
        };
        $steps = [...$common, ...$specific];
        return [
            'classification' => $blocked ? 'blocked' : 'simulation_ready',
            'operation' => $operation,
            'version' => $version,
            'deployment_scope' => 'landingops_platform',
            'customer_site_operations' => 0,
            'target' => [
                'ssh_host' => $config->all()['target']['ssh_host'],
                'install_root' => $config->installRoot(),
                'application_domain' => $config->all()['application']['domain'],
            ],
            'steps' => $steps,
            'manual_approval_required' => array_values(array_filter($steps, static fn (array $step): bool => $step['manual_approval_required'])),
            'database' => [
                'migrations' => '001-006',
                'backup_required' => true,
                'automatic_rollback' => false,
                'code_rollback_is_database_rollback' => false,
            ],
            'facts_trust_level' => $doctorReport['facts_trust_level'] ?? 'simulation_only',
            'real_execution_eligible' => false,
            'phase_1m_reverification_required' => true,
            'real_execution_enabled' => false,
            'write_operations_performed' => false,
            'blockers' => $blocked ? ['doctor_not_ready'] : [],
        ];
    }

    /** @return array<string,mixed> */
    private function step(int $order, string $kind, string $action, bool $approval = false): array
    {
        return [
            'order' => $order, 'kind' => $kind, 'action' => $action,
            'execute' => false, 'manual_approval_required' => $approval,
        ];
    }
}
