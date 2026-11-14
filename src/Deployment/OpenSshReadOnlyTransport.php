<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

final class OpenSshReadOnlyTransport implements SshReadOnlyTransport
{
    public const POLICY_ID = 'landingops-linux-readonly-v2';
    public const PROTOCOL_VERSION = 'landingops-ssh-readonly/2';
    public const REMOTE_COMMAND = 'landingops-readonly collect v2';
    public const PROBE_SCOPE_VERSION = 'landingops-install-scope/1';
    public const FIXED_INSTALL_ROOT = '/srv/landingops';
    private const MAX_OUTPUT_BYTES = 1048576;

    public function __construct(
        private readonly string $sshBinary = 'ssh',
        private readonly string $sshKeyscanBinary = 'ssh-keyscan',
        private readonly string $sshKeygenBinary = 'ssh-keygen',
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function collect(DeploymentConfig $config): array
    {
        self::assertSupportedInstallRoot($config);
        $target = $config->all()['target'];
        $host = (string) $target['ssh_host'];
        $port = (int) $target['ssh_port'];
        $expectedFingerprint = (string) $target['ssh_host_fingerprint'];
        $scan = $this->run([$this->sshKeyscanBinary, '-T', '7', '-p', (string) $port, $host], null, 10);
        if ($scan['timed_out'] || $scan['exit_code'] !== 0 || trim($scan['stdout']) === '') {
            throw new DeploymentException('ssh_host_key_scan_failed', '无法读取 SSH 主机公钥；未建立远端会话。');
        }
        $matched = $this->matchingHostKey($scan['stdout'], $expectedFingerprint);
        if ($matched === null) {
            throw new DeploymentException('ssh_host_key_mismatch', 'SSH 主机公钥指纹与配置不一致；未建立远端会话。');
        }
        $knownHosts = tempnam(sys_get_temp_dir(), 'landingops-known-host-');
        if (!is_string($knownHosts)) {
            throw new DeploymentException('ssh_known_hosts_failed', '无法建立临时 SSH 主机公钥文件。');
        }
        try {
            file_put_contents($knownHosts, $matched['line'] . "\n", LOCK_EX);
            @chmod($knownHosts, 0600);
            $nullConfig = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
            $command = [
                $this->sshBinary, '-T', '-F', $nullConfig, '-p', (string) $port,
                '-o', 'StrictHostKeyChecking=yes',
                '-o', 'UserKnownHostsFile=' . $knownHosts,
                '-o', 'GlobalKnownHostsFile=' . $nullConfig,
                '-o', 'UpdateHostKeys=no',
                '-o', 'VerifyHostKeyDNS=no',
                '-o', 'ProxyCommand=none',
                '-o', 'ProxyJump=none',
                '-o', 'ClearAllForwardings=yes',
                '-o', 'ForwardAgent=no',
                '-o', 'RequestTTY=no',
                '-o', 'PermitLocalCommand=no',
                '-o', 'ControlMaster=no',
                '-o', 'PasswordAuthentication=no',
                '-o', 'KbdInteractiveAuthentication=no',
                '-o', 'PreferredAuthentications=publickey',
                '-o', 'ConnectTimeout=10',
                '-o', 'ConnectionAttempts=1',
                '-o', 'ServerAliveInterval=5',
                '-o', 'ServerAliveCountMax=2',
                '-o', 'NumberOfPasswordPrompts=1',
                '-o', 'LogLevel=ERROR',
                '-o', 'HostKeyAlgorithms=' . $matched['type'],
                (string) $target['ssh_user'] . '@' . $host,
                self::REMOTE_COMMAND,
            ];
            $result = $this->run($command, null, $this->timeoutSeconds);
            if ($result['timed_out']) {
                throw new DeploymentException('ssh_readonly_timeout', 'SSH 只读诊断超时，远端错误内容未记录。');
            }
            if ($result['exit_code'] !== 0) {
                throw new DeploymentException('ssh_readonly_failed', 'SSH 只读诊断失败，请检查账号、白名单和只读命令可用性。', [
                    'exit_code' => $result['exit_code'],
                ]);
            }
            $probes = $this->parseProtocol($result['stdout']);
            $this->validateHandshake($probes);
            return [
                'host_key_fingerprint' => $expectedFingerprint,
                'session_id' => bin2hex(random_bytes(16)),
                'probes' => $probes,
            ];
        } finally {
            @unlink($knownHosts);
        }
    }

    public static function assertSupportedInstallRoot(DeploymentConfig $config): void
    {
        if (!hash_equals(self::FIXED_INSTALL_ROOT, $config->installRoot())) {
            throw new DeploymentException('ssh_probe_scope_unsupported', 'v2 固定只读探针仅支持 /srv/landingops 安装根；未建立 SSH 会话。', [
                'supported_install_root' => self::FIXED_INSTALL_ROOT,
            ]);
        }
    }

    /** @return array{line:string,type:string}|null */
    private function matchingHostKey(string $scan, string $expected): ?array
    {
        foreach (preg_split('/\R/', trim($scan)) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (!is_array($parts) || count($parts) < 3 || !str_starts_with((string) $parts[1], 'ssh-')) {
                continue;
            }
            $path = tempnam(sys_get_temp_dir(), 'landingops-host-key-');
            if (!is_string($path)) { continue; }
            try {
                file_put_contents($path, $line . "\n", LOCK_EX);
                $fingerprint = $this->run([$this->sshKeygenBinary, '-lf', $path, '-E', 'sha256'], null, 5);
                if (!$fingerprint['timed_out'] && preg_match('/\b(SHA256:[A-Za-z0-9+\/=]+)\b/', $fingerprint['stdout'], $match) === 1
                    && hash_equals($expected, $match[1])) {
                    return ['line' => $line, 'type' => (string) $parts[1]];
                }
            } finally {
                @unlink($path);
            }
        }
        return null;
    }

    /** @return array<string,array{exit_code:int,stdout:string,timed_out:bool}> */
    private function parseProtocol(string $stdout): array
    {
        $probes = [];
        foreach (preg_split('/\R/', trim($stdout)) ?: [] as $line) {
            $parts = explode("\t", $line, 4);
            if (count($parts) !== 4 || $parts[0] !== 'LO1' || preg_match('/^[a-z0-9_]{1,64}$/D', $parts[1]) !== 1
                || preg_match('/^\d{1,3}$/D', $parts[2]) !== 1 || isset($probes[$parts[1]])) {
                throw new DeploymentException('ssh_probe_protocol_invalid', 'SSH 只读响应不符合固定探针协议。');
            }
            $decoded = base64_decode($parts[3], true);
            if (!is_string($decoded) || strlen($decoded) > 65536) {
                throw new DeploymentException('ssh_probe_protocol_invalid', 'SSH 只读探针响应无效或过大。');
            }
            $probes[$parts[1]] = ['exit_code' => (int) $parts[2], 'stdout' => $decoded, 'timed_out' => false];
        }
        return $probes;
    }

    /** @param array<string,array{exit_code:int,stdout:string,timed_out:bool}> $probes */
    private function validateHandshake(array $probes): void
    {
        $required = [
            'protocol_version' => self::PROTOCOL_VERSION,
            'command_policy_id' => self::POLICY_ID,
            'probe_scope_version' => self::PROBE_SCOPE_VERSION,
            'probe_install_root' => self::FIXED_INSTALL_ROOT,
            'write_operations_performed' => 'false',
        ];
        foreach ($required as $probe => $expected) {
            $result = $probes[$probe] ?? null;
            if (!is_array($result) || $result['exit_code'] !== 0 || $result['timed_out']
                || !hash_equals($expected, trim($result['stdout']))) {
                throw new DeploymentException('ssh_probe_handshake_mismatch', '服务端只读探针协议或策略版本不匹配。', [
                    'probe' => $probe,
                ]);
            }
        }
    }

    /** @param list<string> $command @return array{exit_code:int,stdout:string,timed_out:bool} */
    private function run(array $command, ?string $stdin, int $timeout): array
    {
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new DeploymentException('ssh_process_unavailable', '无法启动系统 OpenSSH 工具。');
        }
        if ($stdin !== null && $stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $started = microtime(true);
        $timedOut = false;
        $tooLarge = false;
        $exitCode = -1;
        while (true) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            stream_get_contents($pipes[2]); // Deliberately discard possibly sensitive remote/authentication details.
            if (strlen($stdout) > self::MAX_OUTPUT_BYTES) {
                proc_terminate($process);
                $tooLarge = true;
                break;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if ((microtime(true) - $started) >= $timeout) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(20000);
        }
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        if ($tooLarge) {
            throw new DeploymentException('ssh_output_too_large', 'SSH 只读响应超过安全上限。');
        }
        if ($exitCode < 0 && $closed >= 0) { $exitCode = $closed; }
        return ['exit_code' => $timedOut ? 124 : $exitCode, 'stdout' => $stdout, 'timed_out' => $timedOut];
    }
}
