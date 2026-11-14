<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

final class RemoteEvidenceSigner
{
    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function sign(array $evidence, string $key): array
    {
        $this->assertKey($key);
        unset($evidence['signature']);
        $evidence['signature'] = [
            'algorithm' => 'hmac-sha256',
            'key_id' => substr(hash('sha256', $key), 0, 16),
            'value' => hash_hmac('sha256', $this->canonicalJson($evidence), $key),
        ];
        return $evidence;
    }

    /** @param array<string,mixed> $evidence */
    public function verify(array $evidence, string $key): bool
    {
        $this->assertKey($key);
        $signature = $evidence['signature'] ?? null;
        if (!is_array($signature) || ($signature['algorithm'] ?? null) !== 'hmac-sha256'
            || ($signature['key_id'] ?? null) !== substr(hash('sha256', $key), 0, 16)
            || !is_string($signature['value'] ?? null)) {
            return false;
        }
        unset($evidence['signature']);
        return hash_equals(hash_hmac('sha256', $this->canonicalJson($evidence), $key), $signature['value']);
    }

    private function assertKey(string $key): void
    {
        if (strlen($key) < 32) {
            throw new DeploymentException('evidence_key_invalid', '只读证据签名密钥至少需要 32 字节。');
        }
    }

    /** @param array<string,mixed> $value */
    private function canonicalJson(array $value): string
    {
        $sorted = $this->sort($value);
        return json_encode($sorted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sort(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sort($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sort($item);
        }
        return $value;
    }
}
