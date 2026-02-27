<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests;

use PHPUnit\Framework\TestCase;
use YZERoller\Api\Auth\BearerToken;

final class BearerTokenTest extends TestCase
{
    public function testParseAuthorizationHeaderReturnsTokenForValidBearerHeader(): void
    {
        $token = BearerToken::parseAuthorizationHeader('Bearer abc.DEF-123_');

        self::assertSame('abc.DEF-123_', $token);
    }

    public function testParseAuthorizationHeaderIsCaseInsensitiveForBearerScheme(): void
    {
        $token = BearerToken::parseAuthorizationHeader('bearer my-token');

        self::assertSame('my-token', $token);
    }

    public function testParseAuthorizationHeaderReturnsNullForInvalidHeader(): void
    {
        self::assertNull(BearerToken::parseAuthorizationHeader('Basic abc123'));
        self::assertNull(BearerToken::parseAuthorizationHeader('Bearer'));
        self::assertNull(BearerToken::parseAuthorizationHeader(''));
    }

    public function testHashTokenReturnsRawBinarySha256(): void
    {
        $hash = BearerToken::hashToken('example-token');

        self::assertSame(hash('sha256', 'example-token', true), $hash);
        self::assertSame(32, strlen($hash));
        self::assertNotSame(hash('sha256', 'example-token', false), $hash);
    }

    public function testHashTokenRejectsEmptyToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Token must not be empty.');

        BearerToken::hashToken('');
    }
}
