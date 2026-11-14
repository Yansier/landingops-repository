<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

final class RemoteInstallationPlanner
{
    public function __construct(private readonly RemoteEvidenceValidator $validator = new RemoteEvidenceValidator())
    {
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function plan(DeploymentConfig $config, array $evidence, string $signingKey, ?\DateTimeImmutable $now = null): array
    {
        $verified = $this->validator->validate($evidence, $config, $signingKey, $now);
        $facts = $verified['facts'];
        $confirmed = [
            'SSH 主机公钥与配置指纹匹配，且证据绑定本次只读会话。',
            '操作系统、架构、运行时、磁盘和目标路径元数据已由固定只读探针采集。',
            '已从宝塔 Nginx vhost 配置提取脱敏站点根路径并检查安装目录重叠。',
            '采集过程未执行服务器写入。',
        ];
        $manual = [
            '创建或确认非 root 专用部署账号及最小目录权限。',
            '准备独立生产数据库与最小授权；不得使用 landingops_test。',
            '准备管理域名 TLS 证书和独立 Nginx vhost 审批。',
            '制定数据库、模板私有 ZIP、密钥环和 shared 资产备份恢复方案。',
        ];
        $pending = $verified['capabilities']['pending'] ?? [];
        $blockers = $verified['blockers'] ?? [];
        return [
            'schema_version' => 'landingops-install-plan/1.0',
            'classification' => $blockers === [] ? 'readonly_ready_for_manual_preparation' : 'blocked',
            'deployment_scope' => 'landingops_platform',
            'evidence_session_id' => $verified['target']['session_id'],
            'evidence_expires_at' => $verified['expires_at'],
            'confirmed_read_only_prerequisites' => $confirmed,
            'manual_preparation_required' => $manual,
            'missing_or_write_required_evidence' => array_values($pending),
            'blockers' => array_values($blockers),
            'phase_1m_b_only_operations' => [
                'create_directories_and_permissions', 'create_production_database', 'upload_release',
                'install_nginx_vhost_and_tls', 'run_migrations', 'bootstrap_super_admin', 'switch_current',
            ],
            'observed_summary' => [
                'user' => $facts['identity']['observed_user'] ?? null,
                'os' => $facts['system']['os_release']['pretty_name'] ?? null,
                'architecture' => $facts['system']['architecture'] ?? null,
                'php' => $facts['runtime']['php_version'] ?? null,
                'nginx' => $facts['runtime']['nginx_version'] ?? null,
                'target_state' => $facts['filesystem']['target_state'] ?? null,
                'existing_site_root_count' => $facts['filesystem']['site_root_count'] ?? null,
            ],
            'all_steps_execute' => false,
            'real_execution_eligible' => false,
            'phase_1m_b_authorization_required' => true,
            'real_execution_enabled' => false,
            'write_operations_performed' => false,
        ];
    }
}
