<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Domain;

use LandingOps\ControlPlane\Exception\ApiException;

final class DomainName
{
    public static function normalize(string $value, string $field = 'domain_name'): string
    {
        $normalized = strtolower(rtrim(trim($value), '.'));
        if ($normalized === '' || strlen($normalized) > 253 || !preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))+$/D',
            $normalized,
        )) {
            throw new ApiException(422, 'domain_name_invalid', '域名格式无效，仅接受规范的 ASCII 域名。', ['field' => $field]);
        }
        return $normalized;
    }

    public static function belongsToZone(string $domain, string $zone): bool
    {
        return $domain === $zone || str_ends_with($domain, '.' . $zone);
    }
}
