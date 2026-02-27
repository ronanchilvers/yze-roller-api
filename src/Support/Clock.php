<?php

declare(strict_types=1);

namespace YZERoller\Api\Support;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
