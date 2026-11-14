<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Cloudflare;

/** 固定 Cloudflare 官方 API origin；禁止重定向和调用方提供 URL。 */
final class StreamCloudflareHttpTransport implements CloudflareHttpTransport
{
    private const ORIGIN = 'https://api.cloudflare.com/client/v4';

    public function get(string $path, string $token, float $timeoutSeconds): CloudflareHttpResponse
    {
        if (!self::isAllowedPath($path)) {
            throw new CloudflareTransportException('path_rejected');
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\nUser-Agent: LandingOps/1.0\r\n",
                'timeout' => max(1.0, min(8.0, $timeoutSeconds)),
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true],
        ]);
        $body = @file_get_contents(self::ORIGIN . $path, false, $context);
        /** @var list<string> $http_response_header */
        $headers = $http_response_header ?? [];
        $status = 0;
        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches) === 1) {
            $status = (int) $matches[1];
        }
        if (!is_string($body) || $status === 0) {
            throw new CloudflareTransportException('timeout_or_network');
        }
        if (strlen($body) > 1024 * 1024) {
            throw new CloudflareTransportException('response_too_large');
        }
        return new CloudflareHttpResponse($status, $body);
    }

    public static function isAllowedPath(string $path): bool
    {
        return $path === '/user/tokens/verify'
            || preg_match('#^/zones/[A-Za-z0-9_-]{1,128}$#D', $path) === 1
            || preg_match(
                '#^/zones/[A-Za-z0-9_-]{1,128}/dns_records\?name\.(?:exact|endswith)=[A-Za-z0-9.%_-]+&per_page=100$#D',
                $path,
            ) === 1;
    }
}
