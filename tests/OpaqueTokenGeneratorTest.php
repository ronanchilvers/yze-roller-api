<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use YZERoller\Api\Support\OpaqueTokenGenerator;

final class OpaqueTokenGeneratorTest extends TestCase
{
    public function testGenerateReturnsNonEmptyString(): void
    {
        $generator = new OpaqueTokenGenerator();
        $token = $generator->generate();
        self::assertNotEmpty($token);
    }

    public function testGenerateReturnsUrlSafeCharactersOnly(): void
    {
        $generator = new OpaqueTokenGenerator();
        $token = $generator->generate();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-_]+$/', $token);
    }

    public function testGenerateReturnsDifferentTokensEachCall(): void
    {
        $generator = new OpaqueTokenGenerator();
        $token1 = $generator->generate();
        $token2 = $generator->generate();
        self::assertNotSame($token1, $token2);
    }
}
