<?php

declare(strict_types=1);

use flight\util\Collection;
use PHPUnit\Framework\TestCase;
use YZERoller\Api\Support\CollectionHelper;

final class CollectionHelperTest extends TestCase
{
    public function testToArrayFromPlainArray(): void
    {
        $input = ['foo' => 'bar', 'baz' => 42];
        self::assertSame($input, CollectionHelper::toArray($input));
    }

    public function testToArrayFromCollection(): void
    {
        $data = ['foo' => 'bar', 'baz' => 42];
        $collection = new Collection($data);
        self::assertSame($data, CollectionHelper::toArray($collection));
    }

    public function testToArrayFromEmptyArray(): void
    {
        self::assertSame([], CollectionHelper::toArray([]));
    }
}
