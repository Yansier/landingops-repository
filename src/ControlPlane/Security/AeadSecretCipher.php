<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Security;

use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\Exception\LandingOpsException;

/**
 * 使用 AES-256-GCM 保存控制面机密。密钥只来自运行环境，密文使用记录级 AAD 绑定。
 */
final class AeadSecretCipher
{
    /** @param array<string,string> $keys raw 32-byte keys indexed by version */
    public function __construct(
        private readonly array $keys,
        private readonly string $activeVersion,
    ) {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            throw new LandingOpsException('当前 PHP 缺少 OpenSSL AEAD 支持。');
        }
        if (!isset($keys[$activeVersion]) || strlen($keys[$activeVersion]) !== 32) {
            throw new LandingOpsException('活动控制面主密钥无效。');
        }
        foreach ($keys as $version => $key) {
            if ($version === '' || strlen($version) > 40 || strlen($key) !== 32) {
                throw new LandingOpsException('控制面密钥环配置无效。');
            }
        }
    }

    public static function fromEnvironment(): ?self
    {
        $encoded = getenv('LANDINGOPS_SECRET_KEYS_JSON');
        $active = getenv('LANDINGOPS_SECRET_ACTIVE_VERSION');
        if (!is_string($encoded) || trim($encoded) === '' || !is_string($active) || trim($active) === '') {
            return null;
        }
        $decoded = json_decode($encoded, true);
        if (!is_array($decoded)) {
            throw new LandingOpsException('LANDINGOPS_SECRET_KEYS_JSON 必须是 JSON 对象。');
        }
        $keys = [];
        foreach ($decoded as $version => $base64) {
            $key = is_string($base64) ? base64_decode($base64, true) : false;
            if (!is_string($version) || !is_string($key) || strlen($key) !== 32) {
                throw new LandingOpsException('控制面密钥必须是 32 字节随机值的 Base64。');
            }
            $keys[$version] = $key;
        }
        return new self($keys, trim($active));
    }

    /** @return array{ciphertext:string,key_version:string} */
    public function encrypt(string $plaintext, string $aad): array
    {
        if ($plaintext === '') {
            throw new ApiException(422, 'cloudflare_token_invalid', 'Cloudflare API Token 不能为空。');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->keys[$this->activeVersion],
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            16,
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new LandingOpsException('Cloudflare Token 加密失败。');
        }
        return ['ciphertext' => "LO1" . $nonce . $tag . $ciphertext, 'key_version' => $this->activeVersion];
    }

    public function decrypt(string $envelope, string $keyVersion, string $aad): string
    {
        $key = $this->keys[$keyVersion] ?? null;
        if (!is_string($key) || strlen($envelope) < 32 || substr($envelope, 0, 3) !== 'LO1') {
            throw new LandingOpsException('Cloudflare Token 密文或密钥版本无效。');
        }
        $plaintext = openssl_decrypt(
            substr($envelope, 31),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            substr($envelope, 3, 12),
            substr($envelope, 15, 16),
            $aad,
        );
        if (!is_string($plaintext)) {
            throw new LandingOpsException('Cloudflare Token 认证解密失败。');
        }
        return $plaintext;
    }

    public function activeVersion(): string
    {
        return $this->activeVersion;
    }
}
