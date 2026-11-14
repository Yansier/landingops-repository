<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

final class SshAuthenticationBoundaryAuditor
{
    public const ACCOUNT = 'landingops-audit';
    public const WRAPPER = '/usr/local/libexec/landingops-readonly-wrapper';

    /** @param array<string,mixed> $facts @return array{safe:bool,violations:list<string>} */
    public function audit(array $facts): array
    {
        $violations = [];
        if (($facts['user'] ?? null) !== self::ACCOUNT || (int) ($facts['uid'] ?? 0) === 0 || (int) ($facts['gid'] ?? 0) === 0) {
            $violations[] = 'dedicated_non_root_identity_required';
        }
        $groups = $facts['groups'] ?? [];
        if (!is_array($groups) || $groups !== [self::ACCOUNT]) {
            $violations[] = 'supplemental_groups_forbidden';
        }
        if (($facts['password_locked'] ?? null) !== true) {
            $violations[] = 'password_must_be_locked';
        }
        if (($facts['shell'] ?? null) !== self::WRAPPER) {
            $violations[] = 'forced_login_shell_required';
        }
        $expectedModes = ['home' => '755', 'ssh_dir' => '755', 'authorized_keys' => '644', 'wrapper' => '755', 'probe' => '755'];
        foreach ($expectedModes as $name => $expectedMode) {
            $entry = $facts[$name] ?? null;
            if (!is_array($entry) || ($entry['owner'] ?? null) !== 'root' || ($entry['group'] ?? null) !== 'root'
                || !is_string($entry['mode'] ?? null)) {
                $violations[] = $name . '_ownership_unverified';
                continue;
            }
            $mode = (string) $entry['mode'];
            if (!hash_equals($expectedMode, $mode)) {
                $violations[] = $name . '_mode_invalid';
            }
        }
        $line = $facts['authorized_key_line'] ?? null;
        if (!is_string($line) || !$this->validAuthorizedKeyLine($line)) {
            $violations[] = 'authorized_key_restrictions_invalid';
        }
        return ['safe' => $violations === [], 'violations' => array_values(array_unique($violations))];
    }

    public function validAuthorizedKeyLine(string $line): bool
    {
        $prefix = 'restrict,command="' . self::WRAPPER . '" ';
        if (!str_starts_with($line, $prefix)) {
            return false;
        }
        $key = substr($line, strlen($prefix));
        return preg_match('/^ssh-ed25519 [A-Za-z0-9+\/=]{32,} landingops-audit(?:@[A-Za-z0-9._-]+)?$/D', $key) === 1;
    }
}
