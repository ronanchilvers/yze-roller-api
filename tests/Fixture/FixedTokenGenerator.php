<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests\Fixture;

use YZERoller\Api\Support\TokenGenerator;

final class FixedTokenGenerator implements TokenGenerator
{
    /** @var string[] */
    private array $tokens;

    public function __construct(string ...$tokens)
    {
        $this->tokens = $tokens;
    }

    public function generate(): string
    {
        if ($this->tokens === []) {
            throw new \RuntimeException('No more tokens available.');
        }

        return array_shift($this->tokens);
    }
}
