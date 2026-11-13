<?php

declare(strict_types=1);

namespace LandingOps\Contract;

use JsonException;
use LandingOps\Exception\LandingOpsException;
use stdClass;

final class Json
{
    public static function decode(string $json, string $label): mixed
    {
        try {
            return json_decode($json, false, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LandingOpsException(
                sprintf('%s 不是有效 JSON：%s', $label, $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    public static function encode(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        try {
            return json_encode(self::sort($value), $flags) . ($pretty ? "\n" : '');
        } catch (JsonException $exception) {
            throw new LandingOpsException('JSON 编码失败：' . $exception->getMessage(), previous: $exception);
        }
    }

    public static function canonical(mixed $value): string
    {
        return self::encode($value, false);
    }

    public static function hash(mixed $value): string
    {
        return 'sha256:' . hash('sha256', self::canonical($value));
    }

    private static function sort(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $sorted = new stdClass();
            foreach ($properties as $key => $item) {
                $sorted->{$key} = self::sort($item);
            }

            return $sorted;
        }

        if (is_array($value)) {
            return array_map(self::sort(...), $value);
        }

        return $value;
    }
}
