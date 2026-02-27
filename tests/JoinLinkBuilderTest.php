<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use YZERoller\Api\Support\JoinLinkBuilder;

final class JoinLinkBuilderTest extends TestCase
{
    public function testBuildWithNoTrailingSlash(): void
    {
        $builder = new JoinLinkBuilder('https://example.com');
        self::assertSame('https://example.com/join#join=mytoken', $builder->build('mytoken'));
    }

    public function testBuildStripsTrailingSlash(): void
    {
        $builder = new JoinLinkBuilder('https://example.com/');
        self::assertSame('https://example.com/join#join=mytoken', $builder->build('mytoken'));
    }

    public function testBuildWithSubpath(): void
    {
        $builder = new JoinLinkBuilder('https://example.com/app');
        self::assertSame('https://example.com/app/join#join=tok', $builder->build('tok'));
    }
}
