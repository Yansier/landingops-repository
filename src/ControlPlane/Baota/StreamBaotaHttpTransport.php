<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Baota;

final class StreamBaotaHttpTransport implements BaotaHttpTransport
{
    public function post(string $baseUrl, string $path, array $fields, float $timeoutSeconds): BaotaHttpResponse
    {
        if (!in_array($path, ['/system', '/data', '/site'], true)) {
            throw new BaotaTransportException('path_rejected');
        }
        BaotaEndpointPolicy::assertAllowed($baseUrl);
        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\nConnection: close\r\n",
                'content' => $body,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
        ]);
        $stream = @fopen(rtrim($baseUrl, '/') . $path, 'rb', false, $context);
        if (!is_resource($stream)) {
            throw new BaotaTransportException('network');
        }
        $response = stream_get_contents($stream, 1024 * 1024 + 1);
        $metadata = stream_get_meta_data($stream);
        fclose($stream);
        if (!is_string($response) || strlen($response) > 1024 * 1024) {
            throw new BaotaTransportException('response_too_large');
        }
        $headers = $metadata['wrapper_data'] ?? [];
        $status = 0;
        foreach (is_array($headers) ? $headers : [] as $header) {
            if (is_string($header) && preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }
        if ($status < 100) {
            throw new BaotaTransportException('protocol');
        }
        return new BaotaHttpResponse($status, $response);
    }
}
