<?php

declare(strict_types=1);

namespace YZERoller\Api\Support;

interface TokenGenerator
{
    /**
     * @throws \RuntimeException if the token could not be generated
     */
    public function generate(): string;
}
