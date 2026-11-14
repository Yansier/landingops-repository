<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

final class RemoteEvidenceValidator
{
    public function __construct(private readonly RemoteEvidenceSigner $signer = new RemoteEvidenceSigner())
    {
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function validate(array $evidence, DeploymentConfig $config, string $key, ?\DateTimeImmutable $now = null): array
    {
        OpenSshReadOnlyTransport::assertSupportedInstallRoot($config);
        if (($evidence['schema_version'] ?? null) !== 'landingops-remote-evidence/1.0'
            || ($evidence['source'] ?? null) !== 'trusted_ssh_readonly_collector'
            || ($evidence['trust_level'] ?? null) !== 'verified_read_only_session'
            || ($evidence['collector_version'] ?? null) !== SshEnvironmentCollector::VERSION
            || !in_array($evidence['classification'] ?? null, ['readonly_verified_with_pending_writes', 'readonly_blocked'], true)
            || ($evidence['real_execution_enabled'] ?? null) !== false
            || ($evidence['real_execution_eligible'] ?? null) !== false
            || ($evidence['phase_1m_b_authorization_required'] ?? null) !== true
            || ($evidence['write_operations_performed'] ?? null) !== false
            || !is_array($evidence['target'] ?? null)
            || !is_array($evidence['facts'] ?? null)
            || !is_array($evidence['capabilities'] ?? null)
            || !is_array($evidence['errors'] ?? null)
            || ($evidence['command_policy']['id'] ?? null) !== OpenSshReadOnlyTransport::POLICY_ID
            || ($evidence['command_policy']['protocol_version'] ?? null) !== OpenSshReadOnlyTransport::PROTOCOL_VERSION
            || ($evidence['command_policy']['probe_scope_version'] ?? null) !== OpenSshReadOnlyTransport::PROBE_SCOPE_VERSION
            || ($evidence['command_policy']['checked_install_root'] ?? null) !== OpenSshReadOnlyTransport::FIXED_INSTALL_ROOT
            || ($evidence['command_policy']['arbitrary_commands_allowed'] ?? null) !== false) {
            throw new DeploymentException('remote_evidence_contract_invalid', '远端只读证据契约无效。');
        }
        if (!$this->signer->verify($evidence, $key)) {
            throw new DeploymentException('remote_evidence_signature_invalid', '远端只读证据签名无效或已被修改。');
        }
        $target = $evidence['target'];
        $configured = $config->all()['target'];
        if (($target['host'] ?? null) !== $configured['ssh_host']
            || ($target['port'] ?? null) !== $configured['ssh_port']
            || ($target['configured_user'] ?? null) !== $configured['ssh_user']
            || ($target['host_key_fingerprint'] ?? null) !== $configured['ssh_host_fingerprint']
            || ($target['host_key_verified'] ?? null) !== true
            || !is_string($target['session_id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $target['session_id']) !== 1) {
            throw new DeploymentException('remote_evidence_target_mismatch', '远端证据未绑定当前 SSH 目标身份。');
        }
        if (($evidence['facts']['database']['configured_name'] ?? null) !== $config->all()['database']['name']) {
            throw new DeploymentException('remote_evidence_database_mismatch', '远端证据中的数据库规划身份与当前配置不一致。');
        }
        if (($evidence['facts']['filesystem']['target_path'] ?? null) !== $config->installRoot()
            || ($evidence['facts']['filesystem']['checked_install_root'] ?? null) !== $config->installRoot()
            || ($evidence['facts']['filesystem']['probe_scope_version'] ?? null) !== OpenSshReadOnlyTransport::PROBE_SCOPE_VERSION) {
            throw new DeploymentException('remote_evidence_probe_scope_mismatch', '远端证据检查路径或固定探针范围与当前配置不一致。');
        }
        try {
            $observedAt = new \DateTimeImmutable((string) ($evidence['observed_at'] ?? ''));
            $expiresAt = new \DateTimeImmutable((string) ($evidence['expires_at'] ?? ''));
        } catch (\Throwable) {
            throw new DeploymentException('remote_evidence_time_invalid', '远端证据时间无效。');
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($expiresAt <= $observedAt || $expiresAt->getTimestamp() - $observedAt->getTimestamp() > 1800
            || $expiresAt <= $now || $observedAt > $now->modify('+5 minutes')) {
            throw new DeploymentException('remote_evidence_expired', '远端只读证据已过期或时间窗口无效。');
        }
        return $evidence;
    }
}
