<?php

declare(strict_types=1);

namespace YZERoller\Api\Support;

final class JoinLinkBuilder
{
    public function __construct(
        private readonly string $siteUrl
    ) {
    }

    public function build(string $joinToken): string
    {
        return rtrim($this->siteUrl, '/') . '/join#join=' . $joinToken;
    }
}
