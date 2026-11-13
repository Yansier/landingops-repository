<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/autoload.php';

use LandingOps\Instance\InstanceLock;

[$script, $mode, $instanceRoot, $readyPath, $milliseconds] = array_pad($argv, 5, null);
if (!is_string($mode)
    || !in_array($mode, ['hold', 'crash'], true)
    || !is_string($instanceRoot)
    || !is_string($readyPath)
    || !is_string($milliseconds)
) {
    fwrite(STDERR, "invalid worker arguments\n");
    exit(2);
}

$lock = new InstanceLock($instanceRoot);
$lock->synchronized(static function () use ($mode, $readyPath, $milliseconds): void {
    if (file_put_contents($readyPath, "locked\n", LOCK_EX) === false) {
        exit(3);
    }
    if ($mode === 'crash') {
        exit(23);
    }
    usleep(max(0, (int) $milliseconds) * 1000);
});
