<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use YZERoller\Api\Support\DateTimeFormatter;
use YZERoller\Api\Tests\Fixture\FixedClock;

final class DateTimeFormatterTest extends TestCase
{
    private function makeFormatter(string $fixedTime = '2024-06-15 10:30:00.123456'): DateTimeFormatter
    {
        $clock = new FixedClock(new DateTimeImmutable($fixedTime, new DateTimeZone('UTC')));

        return new DateTimeFormatter($clock);
    }

    public function testToRfc3339WithMicroseconds(): void
    {
        $formatter = $this->makeFormatter();
        $result = $formatter->toRfc3339('2024-01-02 12:34:56.789000');
        self::assertSame('2024-01-02T12:34:56.789Z', $result);
    }

    public function testToRfc3339WithoutMicroseconds(): void
    {
        $formatter = $this->makeFormatter();
        $result = $formatter->toRfc3339('2024-01-02 12:34:56');
        self::assertSame('2024-01-02T12:34:56.000Z', $result);
    }

    public function testToRfc3339EmptyStringFallsBackToClock(): void
    {
        $formatter = $this->makeFormatter('2024-06-15 10:30:00.000000');
        $result = $formatter->toRfc3339('');
        self::assertSame('2024-06-15T10:30:00.000Z', $result);
    }

    public function testToRfc3339UnparseableStringFallsBackToClock(): void
    {
        $formatter = $this->makeFormatter('2024-06-15 10:30:00.000000');
        $result = $formatter->toRfc3339('not-a-date');
        self::assertSame('2024-06-15T10:30:00.000Z', $result);
    }

    public function testToRfc3339OrNullWithValidDate(): void
    {
        $formatter = $this->makeFormatter();
        $result = $formatter->toRfc3339OrNull('2024-03-20 08:00:00.000000');
        self::assertSame('2024-03-20T08:00:00.000Z', $result);
    }

    public function testToRfc3339OrNullWithNull(): void
    {
        $formatter = $this->makeFormatter();
        self::assertNull($formatter->toRfc3339OrNull(null));
    }

    public function testToRfc3339OrNullWithEmptyString(): void
    {
        $formatter = $this->makeFormatter();
        self::assertNull($formatter->toRfc3339OrNull(''));
    }

    public function testToRfc3339OrNullWithUnparseableString(): void
    {
        $formatter = $this->makeFormatter();
        self::assertNull($formatter->toRfc3339OrNull('garbage'));
    }

    public function testToMysqlDateTimeWithExplicitValue(): void
    {
        $formatter = $this->makeFormatter();
        $dt = new DateTimeImmutable('2024-01-15 09:00:00.123456', new DateTimeZone('UTC'));
        $result = $formatter->toMysqlDateTime($dt);
        self::assertSame('2024-01-15 09:00:00.123456', $result);
    }

    public function testToMysqlDateTimeWithNullUsesClock(): void
    {
        $formatter = $this->makeFormatter('2024-06-15 10:30:00.000000');
        $result = $formatter->toMysqlDateTime();
        self::assertSame('2024-06-15 10:30:00.000000', $result);
    }
}
