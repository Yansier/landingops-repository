<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

final class OpenSshReadOnlyTransport implements SshReadOnlyTransport
{
    public const POLICY_ID = 'landingops-linux-readonly-v1';
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
                '-o', 'ConnectTimeout=10',
                '-o', 'ConnectionAttempts=1',
                '-o', 'ServerAliveInterval=5',
                '-o', 'ServerAliveCountMax=2',
                '-o', 'NumberOfPasswordPrompts=1',
                '-o', 'LogLevel=ERROR',
                '-o', 'HostKeyAlgorithms=' . $matched['type'],
                (string) $target['ssh_user'] . '@' . $host,
                'sh', '-s', '--',
            ];
            $result = $this->run($command, $this->probeScript($config), $this->timeoutSeconds);
            if ($result['timed_out']) {
                throw new DeploymentException('ssh_readonly_timeout', 'SSH 只读诊断超时，远端错误内容未记录。');
            }
            if ($result['exit_code'] !== 0) {
                throw new DeploymentException('ssh_readonly_failed', 'SSH 只读诊断失败，请检查账号、白名单和只读命令可用性。', [
                    'exit_code' => $result['exit_code'],
                ]);
            }
            return [
                'host_key_fingerprint' => $expectedFingerprint,
                'session_id' => bin2hex(random_bytes(16)),
                'probes' => $this->parseProtocol($result['stdout']),
            ];
        } finally {
            @unlink($knownHosts);
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

    private function probeScript(DeploymentConfig $config): string
    {
        $install = $this->shellQuote($config->installRoot());
        $parent = $this->shellQuote(dirname($config->installRoot()));
        $phpExtensions = "if command -v php >/dev/null 2>&1; then php -r 'foreach(get_loaded_extensions() as \$e){echo strtolower(\$e),PHP_EOL;}'; else exit 127; fi";
        $commands = [
            'identity_user' => 'id -un', 'identity_uid' => 'id -u', 'identity_gid' => 'id -g', 'identity_group' => 'id -gn',
            'os_release' => 'cat /etc/os-release', 'architecture' => 'uname -m',
            'php_version' => 'if command -v php >/dev/null 2>&1; then php -v | head -n 1; else exit 127; fi',
            'php_extensions' => $phpExtensions,
            'php_fpm_versions' => 'found=0; for p in /usr/sbin/php-fpm* /www/server/php/*/sbin/php-fpm; do [ -x "$p" ] || continue; found=1; printf "%s|" "$p"; "$p" -v 2>&1 | head -n 1; done; [ "$found" -eq 1 ]',
            'php_runtime_matrix' => "found=0; for p in /usr/bin/php /www/server/php/*/bin/php; do [ -x \"\$p\" ] || continue; found=1; printf \"%s|\" \"\$p\"; \"\$p\" -r 'echo PHP_VERSION,\"|\",implode(\",\",array_map(\"strtolower\",get_loaded_extensions())),PHP_EOL;'; done; [ \"\$found\" -eq 1 ]",
            'nginx_version' => 'if command -v nginx >/dev/null 2>&1; then nginx -v 2>&1; elif [ -x /www/server/nginx/sbin/nginx ]; then /www/server/nginx/sbin/nginx -v 2>&1; else exit 127; fi',
            'nginx_vhost_capability' => 'if [ -d /www/server/panel/vhost/nginx ]; then echo baota-vhost-directory-present; elif [ -d /etc/nginx/sites-available ]; then echo nginx-sites-available-present; else exit 2; fi',
            'disk' => 'df -Pk -- ' . $parent . " 2>/dev/null | awk 'NR==2 {print \$2 \" \" \$4}'",
            'filesystem' => 'if command -v findmnt >/dev/null 2>&1; then findmnt -T ' . $parent . ' -n -o FSTYPE,SOURCE,TARGET,OPTIONS; else stat -f -c "%T" -- ' . $parent . '; fi',
            'target_state' => 'p=' . $install . '; if [ -L "$p" ]; then echo symlink; elif [ ! -e "$p" ]; then echo absent; elif [ -d "$p" ]; then if find "$p" -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then echo nonempty; else echo empty; fi; else echo other; fi',
            'installation_marker' => 'p=' . $install . '/.landingops-installation.json; if [ -r "$p" ] && [ ! -L "$p" ]; then head -c 4096 "$p"; else echo absent; fi',
            'parent_stat' => 'stat -Lc "%F|%U|%G|%a|%d" -- ' . $parent,
            'ancestor_symlinks' => 'p=' . $install . '; while [ "$p" != / ] && [ -n "$p" ]; do [ -L "$p" ] && printf "%s\\n" "$p"; p=$(dirname -- "$p"); done',
            'acl_summary' => 'if command -v getfacl >/dev/null 2>&1; then getfacl -cp -- ' . $parent . " 2>/dev/null | sed -n '/^(user|group|mask|other):/p' | head -n 50; else echo getfacl-unavailable; fi",
            'site_roots' => "find /www/server/panel/vhost/nginx -type f -name '*.conf' -readable -exec awk '\$1==\"root\" {gsub(/;/,\"\",\$2); if (\$2 ~ /^\\//) print \$2}' {} + 2>/dev/null | sort -u",
            'site_config_count' => "find /www/server/panel/vhost/nginx -type f -name '*.conf' 2>/dev/null | wc -l | tr -d ' '",
            'site_root_declaration_count' => "find /www/server/panel/vhost/nginx -type f -name '*.conf' -readable -exec awk '\$1==\"root\" {count++} END {print count+0}' {} + 2>/dev/null | awk '{sum+=\$1} END {print sum+0}'",
            'site_non_absolute_root_count' => "find /www/server/panel/vhost/nginx -type f -name '*.conf' -readable -exec awk '\$1==\"root\" {gsub(/;/,\"\",\$2); if (\$2 !~ /^\\//) count++} END {print count+0}' {} + 2>/dev/null | awk '{sum+=\$1} END {print sum+0}'",
            'site_unreadable_count' => "find /www/server/panel/vhost/nginx -type f -name '*.conf' ! -readable 2>/dev/null | wc -l | tr -d ' '",
            'site_symlink_count' => "find /www/server/panel/vhost/nginx -type l -name '*.conf' 2>/dev/null | wc -l | tr -d ' '",
            'database_client' => 'if command -v mariadb >/dev/null 2>&1; then mariadb --version; elif command -v mysql >/dev/null 2>&1; then mysql --version; else exit 127; fi',
            'database_server_binary' => 'if command -v mariadbd >/dev/null 2>&1; then mariadbd --version; elif command -v mysqld >/dev/null 2>&1; then mysqld --version; elif [ -x /www/server/mysql/bin/mysqld ]; then /www/server/mysql/bin/mysqld --version; else exit 127; fi',
            'database_runtime_binary' => 'pid=$(pgrep -xo mariadbd 2>/dev/null || pgrep -xo mysqld 2>/dev/null || true); [ -n "$pid" ] || exit 3; exe=$(readlink -f "/proc/$pid/exe") || exit 4; [ -x "$exe" ] || exit 5; "$exe" --version',
            'baota_version' => 'if [ -r /www/server/panel/data/version.pl ]; then head -n 1 /www/server/panel/data/version.pl; else exit 2; fi',
        ];
        $lines = [
            '#!/bin/sh', 'export LC_ALL=C', 'export LANG=C', 'umask 077',
            'emit_probe() {',
            '  probe="$1"',
            '  command_text="$2"',
            '  output=$(sh -c "$command_text" 2>&1)',
            '  code=$?',
            '  encoded=$(printf "%s" "$output" | base64 | tr -d "\\r\\n")',
            '  printf "LO1\\t%s\\t%s\\t%s\\n" "$probe" "$code" "$encoded"',
            '}',
            'command -v base64 >/dev/null 2>&1 || exit 126',
        ];
        foreach ($commands as $name => $command) {
            $lines[] = 'emit_probe ' . $this->shellQuote($name) . ' ' . $this->shellQuote($command);
        }
        return implode("\n", $lines) . "\n";
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

    private function shellQuote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
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
        fwrite($pipes[0], $stdin ?? '');
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
