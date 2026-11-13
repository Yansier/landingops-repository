<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\ControlPlane\Audit\AuditLogger;
use LandingOps\ControlPlane\Auth\PasswordHasher;
use LandingOps\ControlPlane\Auth\Role;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Repository\ControlPlaneRepository;
use LandingOps\ControlPlane\Support\IdGenerator;
use LandingOps\ControlPlane\Support\UtcClock;

final class BootstrapAdminService
{
    public function __construct(
        private readonly ControlPlaneRepository $repository,
        private readonly PasswordHasher $passwords = new PasswordHasher(),
        private readonly IdGenerator $ids = new IdGenerator(),
        private readonly UtcClock $clock = new UtcClock(),
        ?AuditLogger $audit = null,
    ) {
        $this->audit = $audit ?? new AuditLogger($repository);
    }

    private readonly AuditLogger $audit;

    /** @return array<string,mixed> */
    public function createFirst(string $email, string $displayName, string $password): array
    {
        $normalized = strtolower(trim($email));
        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false || trim($displayName) === '') {
            throw new ApiException(422, 'bootstrap_input_invalid', '管理员邮箱或显示名称无效。');
        }
        $hash = $this->passwords->hash($password);
        return $this->repository->transaction(function () use ($email, $normalized, $displayName, $hash): array {
            if ($this->repository->countUsersByRole(Role::SUPER_ADMIN) !== 0) {
                throw new ApiException(409, 'super_admin_exists', '总管理员已经存在。');
            }
            $now = $this->clock->sql();
            $user = [
                'id' => $this->ids->generate(),
                'team_id' => null,
                'email' => trim($email),
                'normalized_email' => $normalized,
                'display_name' => trim($displayName),
                'password_hash' => $hash,
                'role' => Role::SUPER_ADMIN,
                'status' => 'active',
                'version' => 1,
                'password_changed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $this->repository->insertUser($user);
            $this->audit->record(null, 'bootstrap.super_admin', 'user', $user['id'], 'cli-bootstrap');
            return AccountService::safeUser($user);
        });
    }
}
