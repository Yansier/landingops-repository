<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Database;

use LandingOps\Exception\LandingOpsException;
use PDO;

final class MigrationRunner
{
    public function __construct(private readonly SqlStatementSplitter $splitter = new SqlStatementSplitter())
    {
    }

    /** @return list<string> */
    public function migrate(PDO $pdo, string $directory): array
    {
        $this->assertMysql($pdo);
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'version VARCHAR(191) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME(6) NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );
        $locked = (int) $pdo->query("SELECT GET_LOCK('landingops_schema_migrations', 30)")->fetchColumn();
        if ($locked !== 1) {
            throw new LandingOpsException('无法取得数据库迁移锁。');
        }

        $applied = [];
        try {
            foreach ($this->files($directory) as $file) {
                $version = basename($file, '.sql');
                $sql = file_get_contents($file);
                if (!is_string($sql)) {
                    throw new LandingOpsException('无法读取迁移：' . $version);
                }
                $checksum = hash('sha256', $sql);
                $statement = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE version = ?');
                $statement->execute([$version]);
                $existing = $statement->fetchColumn();
                if (is_string($existing)) {
                    if (!hash_equals($existing, $checksum)) {
                        throw new LandingOpsException('已应用迁移内容发生变化：' . $version);
                    }
                    continue;
                }
                foreach ($this->splitter->split($sql) as $migrationStatement) {
                    $pdo->exec($migrationStatement);
                }
                $insert = $pdo->prepare(
                    'INSERT INTO schema_migrations(version, checksum, applied_at) VALUES (?, ?, UTC_TIMESTAMP(6))',
                );
                $insert->execute([$version, $checksum]);
                $applied[] = $version;
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('landingops_schema_migrations')");
        }
        return $applied;
    }

    /** @return list<string> */
    public function files(string $directory): array
    {
        $files = glob(rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9]_*.sql') ?: [];
        sort($files, SORT_STRING);
        return array_values($files);
    }

    private function assertMysql(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new LandingOpsException('迁移只能在真实 MySQL PDO 连接上执行；禁止以 SQLite 冒充。');
        }
    }
}
