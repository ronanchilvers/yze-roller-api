<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests;

use PHPUnit\Framework\TestCase;
use YZERoller\Api\Security\FileJoinRateLimiter;

final class FileJoinRateLimiterTest extends TestCase
{
    private string $storageFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageFilePath = tempnam(sys_get_temp_dir(), 'yze_rl_');
    }

    protected function tearDown(): void
    {
        if (is_file($this->storageFilePath)) {
            unlink($this->storageFilePath);
        }
        parent::tearDown();
    }

    public function testAllowBlocksAfterMaxAttemptsWithinWindow(): void
    {
        $now = 1_000;
        $limiter = new FileJoinRateLimiter(
            $this->storageFilePath,
            2,
            60,
            static function () use (&$now): int {
                return $now;
            }
        );

        self::assertTrue($limiter->allow('join:tokenA:127.0.0.1'));
        self::assertTrue($limiter->allow('join:tokenA:127.0.0.1'));
        self::assertFalse($limiter->allow('join:tokenA:127.0.0.1'));
    }

    public function testAllowResetsAfterWindowElapses(): void
    {
        $now = 2_000;
        $limiter = new FileJoinRateLimiter(
            $this->storageFilePath,
            1,
            10,
            static function () use (&$now): int {
                return $now;
            }
        );

        self::assertTrue($limiter->allow('join:tokenA:127.0.0.1'));
        self::assertFalse($limiter->allow('join:tokenA:127.0.0.1'));

        $now += 11;

        self::assertTrue($limiter->allow('join:tokenA:127.0.0.1'));
    }

    public function testAllowIsScopedByKey(): void
    {
        $now = 3_000;
        $limiter = new FileJoinRateLimiter(
            $this->storageFilePath,
            1,
            60,
            static function () use (&$now): int {
                return $now;
            }
        );

        self::assertTrue($limiter->allow('join:tokenA:127.0.0.1'));
        self::assertTrue($limiter->allow('join:tokenB:127.0.0.1'));
        self::assertFalse($limiter->allow('join:tokenA:127.0.0.1'));
    }
}

