<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Auth;

use LandingOps\ControlPlane\Exception\ApiException;

final class PasswordHasher
{
    public function hash(string $password): string
    {
        $this->validate($password);
        $algorithm = in_array('argon2id', password_algos(), true) ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);
        if (!is_string($hash)) {
            throw new ApiException(500, 'password_hash_failed', '无法安全保存密码。');
        }
        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        $algorithm = in_array('argon2id', password_algos(), true) ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_needs_rehash($hash, $algorithm);
    }

    public function validate(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 1024) {
            throw new ApiException(422, 'password_policy', '密码长度必须为 12 至 1024 字节。');
        }
    }
}
