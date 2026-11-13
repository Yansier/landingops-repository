<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Auth;

final class Role
{
    public const SUPER_ADMIN = 'super_admin';
    public const TEAM_ADMIN = 'team_admin';
    public const STAFF = 'staff';

    public static function valid(string $role): bool
    {
        return in_array($role, [self::SUPER_ADMIN, self::TEAM_ADMIN, self::STAFF], true);
    }
}
