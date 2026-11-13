<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Database;

use LandingOps\Exception\LandingOpsException;

final class DatabaseConfig
{
    public function __construct(
        public readonly string $dsn,
        public readonly string $username,
        public readonly string $password,
    ) {
        if (!str_starts_with($dsn, 'mysql:')) {
            throw new LandingOpsException('Phase 1C 只支持 mysql: PDO DSN。');
        }
    }

    public static function fromEnvironment(): self
    {
        $dsn = getenv('LANDINGOPS_DB_DSN');
        $username = getenv('LANDINGOPS_DB_USER');
        $password = getenv('LANDINGOPS_DB_PASSWORD');
        if (!is_string($dsn) || $dsn === '' || !is_string($username) || $username === '' || !is_string($password)) {
            throw new LandingOpsException('缺少 LANDINGOPS_DB_DSN、LANDINGOPS_DB_USER 或 LANDINGOPS_DB_PASSWORD。');
        }
        return new self($dsn, $username, $password);
    }
}
