<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Baota;

final class StreamBaotaHttpTransport implements BaotaHttpTransport
{
    /** @var array<string,string> */
    private array $cookies = [];
    private ?string $cookieOrigin = null;

    public function post(string $baseUrl, string $path, array $fields, float $timeoutSeconds): BaotaHttpResponse
    {
        try {
            $readOnlyTarget = BaotaEndpointPolicy::readOnlyTarget($path, $fields);
        } catch (\Throwable) {
            throw new BaotaTransportException('path_rejected');
        }
        BaotaEndpointPolicy::assertAllowed($baseUrl);
        $baseUrl = BaotaEndpointPolicy::normalize($baseUrl);
        if ($this->cookieOrigin !== $baseUrl) {
            $this->cookieOrigin = $baseUrl;
            $this->cookies = [];
        }
        $requestPath = $readOnlyTarget;
        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $cookieHeader = $this->cookieHeader();
        $cookie = $cookieHeader === null ? '' : "Cookie: {$cookieHeader}\r\n";
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\nUser-Agent: LandingOps/1.0\r\n{$cookie}Connection: close\r\n",
                'content' => $body,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
        ]);
        $stream = @fopen($baseUrl . $requestPath, 'rb', false, $context);
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
        $this->captureCookies(is_array($headers) ? $headers : []);
        return new BaotaHttpResponse($status, $response, self::headerValue($headers, 'Content-Type'));
    }

    /** @param list<string>|array<int,string> $headers */
    private function captureCookies(array $headers): void
    {
        foreach ($headers as $header) {
            if (is_string($header) && preg_match('~^Set-Cookie:\s*([^=;\s]+)=([^;\r\n]*)~i', $header, $matches) === 1
                && preg_match('/[\x00-\x1F\x7F]/', $matches[1] . $matches[2]) !== 1) {
                $this->cookies[$matches[1]] = $matches[2];
            }
        }
    }

    private function cookieHeader(): ?string
    {
        if ($this->cookies === []) {
            return null;
        }
        $pairs = [];
        foreach ($this->cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        return implode('; ', $pairs);
    }

    /** @param list<string>|array<int,string> $headers */
    private static function headerValue(array $headers, string $name): ?string
    {
        $value = null;
        foreach ($headers as $header) {
            if (is_string($header) && preg_match('~^' . preg_quote($name, '~') . ':\s*(.+)$~i', $header, $matches) === 1) {
                $value = trim($matches[1]);
            }
        }
        return $value;
    }
}
