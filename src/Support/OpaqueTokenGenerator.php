<?php

declare(strict_types=1);

namespace YZERoller\Api\Support;

final class OpaqueTokenGenerator implements TokenGenerator
{
    public function generate(): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        if ($token === '') {
            throw new \RuntimeException('Token generator returned an empty token.');
        }

        return $token;
    }
}
