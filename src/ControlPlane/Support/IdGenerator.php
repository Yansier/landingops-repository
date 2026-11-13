<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Support;

final class IdGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function generate(): string
    {
        $id = '';
        for ($index = 0; $index < 26; $index++) {
            $id .= self::ALPHABET[random_int(0, 31)];
        }
        return $id;
    }
}
