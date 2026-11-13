<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Baota;

use LandingOps\ControlPlane\Exception\ApiException;

final class BaotaEndpointPolicy
{
    public static function normalize(string $value): string
    {
        $value = trim($value);
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && !in_array($parts['path'], ['', '/'], true))
            || (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535))) {
            throw new ApiException(422, 'baota_base_url_invalid', '宝塔面板地址必须是无路径、查询或用户信息的 HTTPS origin。');
        }
        $host = strtolower($parts['host']);
        $hostPart = str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host;
        return 'https://' . $hostPart . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    public static function assertAllowed(string $baseUrl): void
    {
        $configured = getenv('LANDINGOPS_BAOTA_ALLOWED_BASE_URLS');
        $allowed = [];
        if (is_string($configured) && trim($configured) !== '') {
            foreach (explode(',', $configured) as $candidate) {
                if (trim($candidate) !== '') {
                    $allowed[] = self::normalize($candidate);
                }
            }
        }
        if (!in_array(self::normalize($baseUrl), $allowed, true)) {
            throw new ApiException(503, 'baota_target_not_allowed', '面板地址未被运行环境白名单明确授权。');
        }
    }

    public static function hint(string $baseUrl): string
    {
        $normalized = self::normalize($baseUrl);
        $parts = parse_url($normalized);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $octets = explode('.', $host);
            $masked = $octets[0] . '.***.***.' . $octets[3];
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $masked = substr($host, 0, 4) . ':…:' . substr($host, -4);
        } else {
            $labels = explode('.', $host);
            $labels[0] = substr($labels[0], 0, 1) . '***';
            $masked = implode('.', $labels);
        }
        return 'https://' . (str_contains($masked, ':') ? '[' . $masked . ']' : $masked)
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }
}
