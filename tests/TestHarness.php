<?php

declare(strict_types=1);

namespace LandingOps\Tests;

use RuntimeException;
use Throwable;

final class TestHarness
{
    /** @var list<string> */
    private static array $temporaryDirectories = [];

    public static function assertTrue(bool $condition, string $message = 'Expected true.'): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public static function assertFalse(bool $condition, string $message = 'Expected false.'): void
    {
        self::assertTrue(!$condition, $message);
    }

    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message !== '' ? $message : sprintf(
                "Expected %s, got %s.",
                var_export($expected, true),
                var_export($actual, true),
            ));
        }
    }

    public static function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        self::assertTrue(str_contains($haystack, $needle), $message !== '' ? $message : 'Missing expected text: ' . $needle);
    }

    public static function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        self::assertFalse(str_contains($haystack, $needle), $message !== '' ? $message : 'Unexpected text: ' . $needle);
    }

    public static function assertFileExists(string $path): void
    {
        self::assertTrue(is_file($path), 'Expected file: ' . $path);
    }

    /** @param class-string<Throwable> $class */
    public static function assertThrows(callable $callback, string $class): Throwable
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            if (!($exception instanceof $class)) {
                throw new RuntimeException(sprintf('Expected %s, got %s.', $class, $exception::class), previous: $exception);
            }
            return $exception;
        }
        throw new RuntimeException('Expected exception ' . $class . '.');
    }

    public static function tempDirectory(string $label): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'landingops-' . preg_replace('/[^a-z0-9-]/i', '-', $label)
            . '-' . bin2hex(random_bytes(6));
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create test temp directory.');
        }
        self::$temporaryDirectories[] = $path;
        return $path;
    }

    public static function cleanup(): void
    {
        foreach (array_reverse(self::$temporaryDirectories) as $directory) {
            self::remove($directory);
        }
        self::$temporaryDirectories = [];
    }

    private static function remove(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::remove($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }
}
