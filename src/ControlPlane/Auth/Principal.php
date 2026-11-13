<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Auth;

use LandingOps\ControlPlane\Exception\ApiException;

final class Principal
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $teamId,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $role,
        public readonly string $status,
    ) {
        if (!Role::valid($role)) {
            throw new ApiException(500, 'identity_corrupt', '用户角色无效。');
        }
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['id'],
            isset($row['team_id']) ? (string) $row['team_id'] : null,
            (string) $row['email'],
            (string) ($row['display_name'] ?? ''),
            (string) $row['role'],
            (string) $row['status'],
        );
    }

    /** @return array<string,mixed> */
    public function safeArray(): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->teamId,
            'email' => $this->email,
            'display_name' => $this->displayName,
            'role' => $this->role,
            'status' => $this->status,
        ];
    }
}
