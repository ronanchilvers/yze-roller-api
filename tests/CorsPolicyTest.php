<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests;

use PHPUnit\Framework\TestCase;
use YZERoller\Api\Http\CorsPolicy;

final class CorsPolicyTest extends TestCase
{
    public function testIsEnabledReturnsFalseWhenOriginsMissingOrEmpty(): void
    {
        self::assertFalse(CorsPolicy::isEnabled([]));
        self::assertFalse(CorsPolicy::isEnabled(['origins' => []]));
    }

    public function testIsEnabledReturnsTrueWhenOriginsProvided(): void
    {
        self::assertTrue(CorsPolicy::isEnabled([
            'origins' => ['https://app.example.com'],
        ]));
    }

    public function testResolveReturnsEmptyWhenOriginNotAllowed(): void
    {
        $headers = CorsPolicy::resolve([
            'origins' => ['https://app.example.com'],
        ], 'https://other.example.com');

        self::assertSame([], $headers);
    }

    public function testResolveReturnsExpectedHeadersForAllowedOrigin(): void
    {
        $headers = CorsPolicy::resolve([
            'origins' => ['https://app.example.com'],
            'methods' => ['GET', 'POST', 'OPTIONS'],
            'headers' => ['Content-Type', 'Authorization'],
            'expose_headers' => ['X-Request-Id'],
            'allow_credentials' => true,
            'max_age' => 600,
        ], 'https://app.example.com');

        self::assertSame('https://app.example.com', $headers['Access-Control-Allow-Origin']);
        self::assertSame('Origin', $headers['Vary']);
        self::assertSame('GET, POST, OPTIONS', $headers['Access-Control-Allow-Methods']);
        self::assertSame('Content-Type, Authorization', $headers['Access-Control-Allow-Headers']);
        self::assertSame('X-Request-Id', $headers['Access-Control-Expose-Headers']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertSame('600', $headers['Access-Control-Max-Age']);
    }

    public function testResolveWildcardReflectsRequestOrigin(): void
    {
        $headers = CorsPolicy::resolve([
            'origins' => ['*'],
        ], 'https://sub.example.com');

        self::assertSame('https://sub.example.com', $headers['Access-Control-Allow-Origin']);
    }
}

