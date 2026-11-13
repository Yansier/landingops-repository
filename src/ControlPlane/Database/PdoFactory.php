<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Database;

use LandingOps\Exception\LandingOpsException;
use PDO;

final class PdoFactory
{
    public function connect(DatabaseConfig $config): PDO
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new LandingOpsException('当前 PHP 未启用 pdo_mysql；不能执行 MySQL 操作。');
        }
        $pdo = new PDO($config->dsn, $config->username, $config->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }
}
