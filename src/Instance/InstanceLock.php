<?php

declare(strict_types=1);

namespace LandingOps\Instance;

use LandingOps\Exception\LandingOpsException;
use LandingOps\Filesystem\LocalFilesystem;

final class InstanceLock
{
    /** @var array<string,array{handle:resource,depth:int}> */
    private static array $held = [];

    private readonly string $key;
    private readonly string $lockPath;

    public function __construct(
        string $instanceRoot,
        LocalFilesystem $filesystem = new LocalFilesystem(),
    ) {
        $trimmed = rtrim($instanceRoot, '\\/');
        if ($trimmed === '' || in_array(basename($trimmed), ['', '.', '..'], true)) {
            throw new LandingOpsException('实例目录不能用于创建发布锁。');
        }

        if (is_dir($trimmed)) {
            $resolved = realpath($trimmed);
            if (!is_string($resolved)) {
                throw new LandingOpsException('无法解析实例目录发布锁路径。');
            }
            $canonical = $resolved;
            $lockDirectory = dirname($resolved);
        } else {
            $parent = dirname($trimmed);
            $filesystem->ensureDirectory($parent);
            $resolvedParent = realpath($parent);
            if (!is_string($resolvedParent)) {
                throw new LandingOpsException('无法解析实例父目录发布锁路径。');
            }
            $canonical = $resolvedParent . DIRECTORY_SEPARATOR . basename($trimmed);
            $lockDirectory = $resolvedParent;
        }

        $canonical = str_replace('\\', '/', $canonical);
        if (PHP_OS_FAMILY === 'Windows') {
            $canonical = strtolower($canonical);
        }
        $this->key = hash('sha256', $canonical);
        $this->lockPath = $lockDirectory . DIRECTORY_SEPARATOR
            . '.landingops-instance-' . substr($this->key, 0, 24) . '.lock';
    }

    /** @template T @param callable():T $callback @return T */
    public function synchronized(callable $callback): mixed
    {
        if (isset(self::$held[$this->key])) {
            self::$held[$this->key]['depth']++;
            try {
                return $callback();
            } finally {
                self::$held[$this->key]['depth']--;
            }
        }

        $handle = fopen($this->lockPath, 'c+b');
        if (!is_resource($handle)) {
            throw new LandingOpsException('无法打开实例发布锁。');
        }
        @chmod($this->lockPath, 0600);
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new LandingOpsException('无法取得实例发布锁。');
        }

        self::$held[$this->key] = ['handle' => $handle, 'depth' => 1];
        try {
            return $callback();
        } finally {
            unset(self::$held[$this->key]);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function assertHeld(): void
    {
        if (!isset(self::$held[$this->key]) || self::$held[$this->key]['depth'] < 1) {
            throw new LandingOpsException('实例发布操作必须持有实例级锁。');
        }
    }
}
