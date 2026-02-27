<?php

declare(strict_types=1);

namespace YZERoller\Api\Security;

interface JoinRateLimiter
{
    public function allow(string $key): bool;
}

