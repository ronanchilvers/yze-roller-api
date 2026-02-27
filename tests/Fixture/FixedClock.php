<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests\Fixture;

use DateTimeImmutable;
use YZERoller\Api\Support\Clock;

final class FixedClock implements Clock
{
    public function __construct(
        private readonly DateTimeImmutable $now
    ) {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
