<?php

declare(strict_types=1);

namespace YZERoller\Api\Auth;

final class BearerToken
{
    private function __construct()
    {
    }

    public static function parseAuthorizationHeader(string $header): ?string
    {
        $matches = [];
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public static function hashToken(string $token): string
    {
        if ($token === '') {
            throw new \InvalidArgumentException('Token must not be empty.');
        }

        return hash('sha256', $token, true);
    }
}
