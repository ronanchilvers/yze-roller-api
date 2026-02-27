<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use YZERoller\Api\Support\SessionStateParser;

final class SessionStateParserTest extends TestCase
{
    // parseSceneStrain

    public function testParseSceneStrainZero(): void
    {
        self::assertSame(0, SessionStateParser::parseSceneStrain('0'));
    }

    public function testParseSceneStrainPositive(): void
    {
        self::assertSame(7, SessionStateParser::parseSceneStrain('7'));
    }

    public function testParseSceneStrainNull(): void
    {
        self::assertSame(0, SessionStateParser::parseSceneStrain(null));
    }

    public function testParseSceneStrainNegativeString(): void
    {
        self::assertSame(0, SessionStateParser::parseSceneStrain('-1'));
    }

    public function testParseSceneStrainNonNumeric(): void
    {
        self::assertSame(0, SessionStateParser::parseSceneStrain('abc'));
    }

    public function testParseSceneStrainInteger(): void
    {
        self::assertSame(0, SessionStateParser::parseSceneStrain(5));
    }

    // extractStateId

    public function testExtractStateIdFromIntValue(): void
    {
        self::assertSame(42, SessionStateParser::extractStateId(['state_id' => 42]));
    }

    public function testExtractStateIdFromStringValue(): void
    {
        self::assertSame(42, SessionStateParser::extractStateId(['state_id' => '42']));
    }

    public function testExtractStateIdFromFalse(): void
    {
        self::assertNull(SessionStateParser::extractStateId(false));
    }

    public function testExtractStateIdMissingKey(): void
    {
        self::assertNull(SessionStateParser::extractStateId(['other' => 1]));
    }

    public function testExtractStateIdZero(): void
    {
        self::assertNull(SessionStateParser::extractStateId(['state_id' => 0]));
    }

    public function testExtractStateIdNegative(): void
    {
        self::assertNull(SessionStateParser::extractStateId(['state_id' => -1]));
    }

    // parseJoiningEnabled

    public function testParseJoiningEnabledTrue(): void
    {
        self::assertTrue(SessionStateParser::parseJoiningEnabled('true'));
    }

    public function testParseJoiningEnabledFalse(): void
    {
        self::assertFalse(SessionStateParser::parseJoiningEnabled('false'));
    }

    public function testParseJoiningEnabledNull(): void
    {
        self::assertFalse(SessionStateParser::parseJoiningEnabled(null));
    }

    public function testParseJoiningEnabledBoolTrue(): void
    {
        self::assertFalse(SessionStateParser::parseJoiningEnabled(true));
    }
}
